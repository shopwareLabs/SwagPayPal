<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PayPal\Checkout\Payment;

use Shopware\Core\Checkout\Cart\Exception\CustomerNotLoggedInException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\PayPal\Checkout\Payment\Handler\PlusPuiHandler;
use Swag\PayPal\Checkout\PayPalOrderTransactionCaptureService;
use Swag\PayPal\RestApi\Exception\PayPalApiException;
use Swag\PayPal\RestApi\V1\Api\Payment;
use Swag\PayPal\RestApi\V1\Api\Payment\PaymentInstruction;
use Swag\PayPal\RestApi\V1\Api\Payment\Transaction\RelatedResource;
use Swag\PayPal\RestApi\V1\Api\Payment\Transaction\RelatedResource\Capture;
use Swag\PayPal\RestApi\V1\Api\Payment\Transaction\RelatedResource\Sale;
use Swag\PayPal\RestApi\V1\PaymentStatusV1;
use Swag\PayPal\RestApi\V1\Resource\PaymentResource;
use Swag\PayPal\SwagPayPal;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class PayPalPuiPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    /**
     * @var PlusPuiHandler
     */
    private $plusPuiHandler;

    /**
     * @var PaymentResource
     */
    private $paymentResource;

    /**
     * @var OrderTransactionStateHandler
     */
    private $orderTransactionStateHandler;

    /**
     * @var EntityRepositoryInterface
     */
    private $orderTransactionRepo;

    /**
     * @var OrderTransactionCaptureStateHandler
     */
    private $orderTransactionCaptureStateHandler;

    /**
     * @var OrderTransactionCaptureService
     */
    private $orderTransactionCaptureService;

    /**
     * @var PayPalOrderTransactionCaptureService
     */
    private $payPalOrderTransactionCaptureService;

    /**
     * @var EntityRepositoryInterface
     */
    private $orderTransactionCaptureRepository;

    public function __construct(
        PlusPuiHandler $plusPuiHandler,
        PaymentResource $paymentResource,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        EntityRepositoryInterface $orderTransactionRepo,
        OrderTransactionCaptureStateHandler $orderTransactionCaptureStateHandler,
        OrderTransactionCaptureService $orderTransactionCaptureService,
        PayPalOrderTransactionCaptureService $payPalOrderTransactionCaptureService,
        EntityRepositoryInterface $orderTransactionCaptureRepository
    ) {
        $this->plusPuiHandler = $plusPuiHandler;
        $this->paymentResource = $paymentResource;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->orderTransactionRepo = $orderTransactionRepo;
        $this->orderTransactionCaptureStateHandler = $orderTransactionCaptureStateHandler;
        $this->orderTransactionCaptureService = $orderTransactionCaptureService;
        $this->payPalOrderTransactionCaptureService = $payPalOrderTransactionCaptureService;
        $this->orderTransactionCaptureRepository = $orderTransactionCaptureRepository;
    }

    /**
     * @throws AsyncPaymentProcessException
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        $transactionId = $transaction->getOrderTransaction()->getId();
        $customer = $salesChannelContext->getCustomer();
        if ($customer === null) {
            throw new AsyncPaymentProcessException(
                $transactionId,
                (new CustomerNotLoggedInException())->getMessage()
            );
        }

        $this->orderTransactionStateHandler->process($transactionId, $salesChannelContext->getContext());

        try {
            $response = $this->plusPuiHandler->handlePuiPayment($transaction, $salesChannelContext, $customer);
        } catch (\Exception $e) {
            throw new AsyncPaymentProcessException($transactionId, $e->getMessage());
        }

        return new RedirectResponse($response->getLinks()[1]->getHref());
    }

    /**
     * @throws AsyncPaymentFinalizeException
     * @throws CustomerCanceledAsyncPaymentException
     */
    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        $transactionId = $transaction->getOrderTransaction()->getId();
        $context = $salesChannelContext->getContext();

        if ($request->query->getBoolean(PayPalPaymentHandler::PAYPAL_REQUEST_PARAMETER_CANCEL)) {
            throw new CustomerCanceledAsyncPaymentException(
                $transactionId,
                'Customer canceled the payment on the PayPal page'
            );
        }

        $payerId = $request->query->get(PayPalPaymentHandler::PAYPAL_REQUEST_PARAMETER_PAYER_ID);
        $paymentId = $request->query->get(PayPalPaymentHandler::PAYPAL_REQUEST_PARAMETER_PAYMENT_ID);

        try {
            $response = $this->processPayPalPuiPayment(
                $transactionId,
                $payerId,
                $paymentId,
                $salesChannelContext
            );
        } catch (\Exception $e) {
            throw new AsyncPaymentFinalizeException(
                $transactionId,
                \sprintf('An error occurred during the communication with PayPal%s%s', PHP_EOL, $e->getMessage())
            );
        }

        $paymentState = $this->getPaymentState($response);

        // apply the payment status if its completed by PayPal
        if ($paymentState === PaymentStatusV1::PAYMENT_COMPLETED) {
            $this->orderTransactionStateHandler->paid($transactionId, $context);
        }

        $this->savePaymentInstructions($response, $transactionId, $context);
    }

    private function getPaymentState(Payment $payment): string
    {
        $paymentState = '';
        $sale = $payment->getTransactions()[0]->getRelatedResources()[0]->getSale();
        if ($sale !== null) {
            $paymentState = $sale->getState();
        }

        return $paymentState;
    }

    private function savePaymentInstructions(Payment $payment, string $transactionId, Context $context): void
    {
        $paymentInstructions = $payment->getPaymentInstruction();
        if ($paymentInstructions === null
            || $paymentInstructions->getInstructionType() !== PaymentInstruction::TYPE_INVOICE
        ) {
            return;
        }

        $this->orderTransactionRepo->update([[
            'id' => $transactionId,
            'customFields' => [
                SwagPayPal::ORDER_TRANSACTION_CUSTOM_FIELDS_PAYPAL_PUI_INSTRUCTION => $paymentInstructions,
            ],
        ]], $context);
    }

    private function processPayPalPuiPayment(
        string $transactionId,
        string $payerId,
        string $paymentId,
        SalesChannelContext $salesChannelContext
    ): Payment {
        $context = $salesChannelContext->getContext();
        $orderTransactionCaptureId = $this->orderTransactionCaptureService->createOrderTransactionCaptureForFullAmount(
            $transactionId,
            $context
        );
        try {
            $response = $this->paymentResource->execute(
                $payerId,
                $paymentId,
                $salesChannelContext->getSalesChannel()->getId()
            );
        } catch (PayPalApiException $apiException) {
            $this->orderTransactionCaptureStateHandler->fail($orderTransactionCaptureId, $context);

            throw $apiException;
        }
        $relatedResource = $response->getTransactions()[0]->getRelatedResources()[0];
        /** @var Capture|Sale|null $paypalResource */
        $paypalResource = null;
        if ($relatedResource->getCapture() !== null) {
            $paypalResourceType = RelatedResource::CAPTURE;
            $paypalResource = $relatedResource->getCapture();
            $paypalResourceCompletedState = PaymentStatusV1::PAYMENT_CAPTURE_COMPLETED;
        } elseif ($relatedResource->getSale() !== null) {
            $paypalResourceType = RelatedResource::SALE;
            $paypalResource = $relatedResource->getSale();
            $paypalResourceCompletedState = PaymentStatusV1::PAYMENT_SALE_COMPLETED;
        } else {
            $this->orderTransactionCaptureRepository->delete([['id' => $orderTransactionCaptureId]], $context);

            return $response;
        }

        $this->payPalOrderTransactionCaptureService->addPayPalResourceToOrderTransactionCapture(
            $orderTransactionCaptureId,
            $paypalResource->getId(),
            $paypalResourceType,
            $context
        );
        if ($paypalResource->getState() === $paypalResourceCompletedState) {
            $this->orderTransactionCaptureStateHandler->complete($orderTransactionCaptureId, $context);
        }

        return $response;
    }
}
