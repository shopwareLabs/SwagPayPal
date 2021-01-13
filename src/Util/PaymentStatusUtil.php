<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PayPal\Util;

use Shopware\Core\Checkout\Cart\Exception\OrderNotFoundException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Exception\InvalidOrderException;
use Shopware\Core\Checkout\Payment\Exception\InvalidTransactionException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Swag\PayPal\RestApi\V1\Api\Capture;
use Swag\PayPal\RestApi\V1\Api\Payment;
use Swag\PayPal\RestApi\V1\Api\Refund;
use Swag\PayPal\RestApi\V1\Api\Webhook;
use Swag\PayPal\RestApi\V1\PaymentStatusV1;

class PaymentStatusUtil
{
    /**
     * @var EntityRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var OrderTransactionStateHandler
     */
    private $orderTransactionStateHandler;

    /**
     * @var PriceFormatter
     */
    private $priceFormatter;

    /**
     * @var EntityRepositoryInterface
     */
    private $orderTransactionRepository;

    /**
     * @var OrderTransactionCaptureStateHandler
     */
    private $orderTransactionCaptureStateHandler;

    public function __construct(
        EntityRepositoryInterface $orderRepository,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        PriceFormatter $priceFormatter,
        EntityRepositoryInterface $orderTransactionRepository,
        OrderTransactionCaptureStateHandler $orderTransactionCaptureStateHandler
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->priceFormatter = $priceFormatter;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->orderTransactionCaptureStateHandler = $orderTransactionCaptureStateHandler;
    }

    public function applyVoidStateToOrder(string $orderId, Context $context): void
    {
        $transaction = $this->getOrderTransaction($orderId, $context);

        $this->orderTransactionStateHandler->cancel($transaction->getId(), $context);
    }

    public function applyCaptureState(string $orderId, Capture $captureResponse, Context $context): void
    {
        $transaction = $this->getOrderTransaction($orderId, $context);
        $transactionId = $transaction->getId();
        $stateMachineState = $transaction->getStateMachineState();
        if ($stateMachineState === null) {
            throw new InvalidTransactionException($transactionId);
        }

        if ($captureResponse->isFinalCapture()) {
            $this->reopenTransaction($stateMachineState, $transactionId, $context);
            // If the previous state is "paid_partially", "paid" is currently not allowed as direct transition
            $this->orderTransactionStateHandler->process($transactionId, $context);
            $this->orderTransactionStateHandler->paid($transactionId, $context);

            return;
        }

        // TODO PPI-59 - Do transition even if transaction is already partially paid.
        if ($stateMachineState->getTechnicalName() !== OrderTransactionStates::STATE_PARTIALLY_PAID) {
            $this->reopenTransaction($stateMachineState, $transactionId, $context);
            $this->orderTransactionStateHandler->payPartially($transactionId, $context);
        }
    }

    public function applyRefundStateToPayment(string $orderId, Refund $refundResponse, Context $context): void
    {
        $transaction = $this->getOrderTransaction($orderId, $context);
        $transactionId = $transaction->getId();

        $refundAmount = $this->priceFormatter->roundPrice(
            (float) $refundResponse->getTotalRefundedAmount()->getValue()
        );

        if ($refundAmount === $transaction->getAmount()->getTotalPrice()) {
            $this->orderTransactionStateHandler->refund($transactionId, $context);

            return;
        }

        $this->setPartiallyRefundedState($transaction->getStateMachineState(), $transactionId, $context);
    }

    public function applyRefundStateToCapture(
        string $orderId,
        Refund $refundResponse,
        Payment $paymentResponse,
        Context $context
    ): void {
        $transaction = $this->getOrderTransaction($orderId, $context);
        $transactionId = $transaction->getId();

        $refundAmount = $this->priceFormatter->roundPrice(
            (float) $refundResponse->getTotalRefundedAmount()->getValue()
        );

        if ($refundAmount === $transaction->getAmount()->getTotalPrice()) {
            $this->orderTransactionStateHandler->refund($transactionId, $context);

            return;
        }

        $capturedAmount = 0.0;
        $relatedResources = $paymentResponse->getTransactions()[0]->getRelatedResources();
        foreach ($relatedResources as $relatedResource) {
            $capture = $relatedResource->getCapture();
            if ($capture === null) {
                continue;
            }

            $capturedAmount += (float) $capture->getAmount()->getTotal();
        }

        if ($refundAmount === $capturedAmount) {
            $this->orderTransactionStateHandler->refund($transactionId, $context);

            return;
        }

        $this->setPartiallyRefundedState($transaction->getStateMachineState(), $transactionId, $context);
    }

    public function applySaleStateToOrderTransactionCapture(
        string $transactionId,
        Webhook\Resource $sale,
        Context $context
    ): void {
        $transaction = $this->getOrderTransactionById($transactionId, $context);
        if ($transaction->getCaptures() === null) {
            return;
        }
        $orderTransactionCapture = $transaction
            ->getCaptures()
            ->filterByExternalReference($sale->getId())
            ->first();
        if ($orderTransactionCapture === null) {
            return;
        }
        $orderTransactionCaptureState = $orderTransactionCapture->getStateMachineState()->getTechnicalName();
        if ($orderTransactionCaptureState !== OrderTransactionCaptureStates::STATE_PENDING) {
            return;
        }
        switch ($sale->getState()) {
            case PaymentStatusV1::PAYMENT_SALE_COMPLETED:
                $this->orderTransactionCaptureStateHandler->complete($orderTransactionCapture->getId(), $context);
                break;
            case PaymentStatusV1::PAYMENT_SALE_DENIED:
                $this->orderTransactionCaptureStateHandler->fail($orderTransactionCapture->getId(), $context);
                break;
        }
    }

    private function reopenTransaction(
        StateMachineStateEntity $stateMachineState,
        string $transactionId,
        Context $context
    ): void {
        $refundStates = [OrderTransactionStates::STATE_PARTIALLY_REFUNDED, OrderTransactionStates::STATE_REFUNDED];
        if (\in_array($stateMachineState->getTechnicalName(), $refundStates, true)) {
            $this->orderTransactionStateHandler->reopen($transactionId, $context);
        }
    }

    private function setPartiallyRefundedState(
        ?StateMachineStateEntity $stateMachineState,
        string $transactionId,
        Context $context
    ): void {
        if ($stateMachineState === null) {
            throw new InvalidTransactionException($transactionId);
        }

        // TODO PPI-59 - Do transition even if transaction is already partially refunded.
        if ($stateMachineState->getTechnicalName() !== OrderTransactionStates::STATE_PARTIALLY_REFUNDED) {
            $this->orderTransactionStateHandler->refundPartially($transactionId, $context);
        }
    }

    /**
     * @throws OrderNotFoundException
     * @throws InvalidOrderException
     */
    private function getOrderTransaction(string $orderId, Context $context): OrderTransactionEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('transactions');
        $criteria->getAssociation('transactions')->addSorting(new FieldSorting('createdAt'));
        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search($criteria, $context)->first();

        if ($order === null) {
            throw new OrderNotFoundException($orderId);
        }

        $transactionCollection = $order->getTransactions();

        if ($transactionCollection === null) {
            throw new InvalidOrderException($orderId);
        }

        $transaction = $transactionCollection->last();

        if ($transaction === null) {
            throw new InvalidOrderException($orderId);
        }

        return $transaction;
    }

    private function getOrderTransactionById(string $orderTransactionId, Context $context): OrderTransactionEntity
    {
        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('captures.stateMachineState');
        /** @var OrderTransactionEntity|null $transaction */
        $transaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        if ($transaction === null) {
            throw new InvalidTransactionException($orderTransactionId);
        }

        return $transaction;
    }
}
