<?php

declare(strict_types=1);

namespace Swag\PayPal\Refund;

use Shopware\Core\Checkout\Order\Aggregate\OrderRefund\OrderRefundStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderRefund\OrderRefundEntity;
use Shopware\Core\Checkout\Refund\RefundHandler\PaymentRefundHandlerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Checkout\Refund\Exception\PaymentRefundProcessException;
use Swag\PayPal\PaymentsApi\Administration\Exception\RequiredParameterInvalidException;
use Swag\PayPal\Refund\Exception\NoRefundableResourceAvailableException;
use Swag\PayPal\Refund\Exception\ResourceMissingInCaptureException;
use Swag\PayPal\RestApi\V1\Api\Payment\Transaction\RelatedResource;
use Swag\PayPal\RestApi\V1\Api\Refund;
use Swag\PayPal\RestApi\V1\Api\Refund\Amount;
use Swag\PayPal\RestApi\V1\PaymentStatusV1;
use Swag\PayPal\RestApi\V1\Resource\PaymentResource;
use Swag\PayPal\RestApi\V1\Resource\SaleResource;
use Swag\PayPal\RestApi\V1\Resource\CaptureResource;
use Swag\PayPal\SwagPayPal;
use Swag\PayPal\Util\PriceFormatter;

class PayPalRefundHandler implements PaymentRefundHandlerInterface
{
    /**
     * @var EntityRepositoryInterface
     */
    private $orderRefundRepository;
    /**
     * @var PriceFormatter
     */
    private $priceFormatter;
    /**
     * @var PaymentResource
     */
    private $paymentResource;
    /**
     * @var SaleResource
     */
    private $saleResource;
    /**
     * @var CaptureResource
     */
    private $captureResource;
    /**
     * @var OrderRefundStateHandler
     */
    private $orderRefundStateHandler;

    public function __construct(
        EntityRepositoryInterface $orderRefundRepository,
        PaymentResource $paymentResource,
        SaleResource $saleResource,
        CaptureResource $captureResource,
        OrderRefundStateHandler $orderRefundStateHandler
    ) {
        $this->orderRefundRepository = $orderRefundRepository;
        $this->paymentResource = $paymentResource;
        $this->saleResource = $saleResource;
        $this->captureResource = $captureResource;
        $this->orderRefundStateHandler = $orderRefundStateHandler;
        $this->priceFormatter = new PriceFormatter();
    }

    public function refund(string $orderRefundTransactionId, Context $context): void
    {
        try {
            $criteria = new Criteria([$orderRefundTransactionId]);
            $criteria->addAssociations([
                'order.salesChannel',
                'order.currency',
                'transaction',
                'transactionCapture',
            ]);
            /** @var OrderRefundEntity $orderRefund */
            $orderRefund = $this->orderRefundRepository->search($criteria, $context)->first();

            [
                $resourceIdToRefund,
                $resourceType,
            ] = $this->getResourceIdAndTypeToRefund($orderRefund);
            $refund = $this->createRefund($orderRefund);

            $salesChannelId = $orderRefund->getOrder()->getSalesChannelId();
            switch ($resourceType) {
                case RelatedResource::SALE:
                    $refundResponse = $this->saleResource->refund(
                        $resourceIdToRefund,
                        $refund,
                        $salesChannelId
                    );
                    break;
                case RelatedResource::CAPTURE:
                    $refundResponse = $this->captureResource->refund(
                        $resourceIdToRefund,
                        $refund,
                        $salesChannelId
                    );
                    break;
                default:
                    throw new RequiredParameterInvalidException('resourceType');
            }
            $refundAmount = $this->priceFormatter->roundPrice(
                (float) $refundResponse->getAmount()->getTotal()
            );
            if ($refundAmount === $orderRefund->getAmount()) {
                $this->orderRefundStateHandler->complete($orderRefundTransactionId, $context);
            } else {
                $this->orderRefundStateHandler->fail($orderRefundTransactionId, $context);
            }
        } catch (\Throwable $e) {
            throw new PaymentRefundProcessException($orderRefundTransactionId, $e->getMessage());
        }
    }

    private function createRefund(OrderRefundEntity $orderRefund): Refund
    {
        $refundAmount = $this->priceFormatter->formatPrice($orderRefund->getAmount());
        $currency = $orderRefund->getOrder()->getCurrency()->getShortName();
        $options = $orderRefund->getOptions() ?? [];
        $invoiceNumber = $options['invoiceNumber'] ?? '';
        $description = $options['description'] ?? '';
        $reason = $options['reason'] ?? '';

        $refund = new Refund();

        if ($invoiceNumber !== '') {
            $refund->setInvoiceNumber($invoiceNumber);
        }

        if ($refundAmount !== '0.00') {
            $amount = new Amount();
            $amount->setTotal($refundAmount);
            $amount->setCurrency($currency);

            $refund->setAmount($amount);
        }

        if ($description !== '') {
            $refund->setDescription($description);
        }
        if ($reason !== '') {
            $refund->setReason($reason);
        }

        return $refund;
    }

    private function getResourceIdAndTypeToRefund(OrderRefundEntity $orderRefund): array
    {
        $salesChannelId = $orderRefund->getOrder()->getSalesChannelId();
        $capture = $orderRefund->getTransactionCapture();
        if ($capture !== null) {
            $resourceId = $capture->getExternalReference();
            $resourceType = $capture->getCustomFields()[SwagPayPal::ORDER_TRANSACTION_CAPTURE_CUSTOM_FIELDS_PAYPAL_RESOURCE_TYPE] ?? null;
            if ($resourceId === null || $resourceType === null) {
                throw new ResourceMissingInCaptureException($capture->getId());
            }

            return [
                $resourceId,
                $resourceType,
            ];
        }

        $orderTransaction = $orderRefund->getTransaction();
        $paymentId = $orderTransaction->getCustomFields()[SwagPayPal::ORDER_TRANSACTION_CUSTOM_FIELDS_PAYPAL_TRANSACTION_ID];
        $payment = $this->paymentResource->get($paymentId, $salesChannelId);
        $relatedResources = $payment->getTransactions()[0]->getRelatedResources();
        $resourcesAbleToBeRefunded = array_values(array_filter($relatedResources, function (RelatedResource $resource) {
            $isNonRefundedSale = $resource->getSale() && $resource->getSale()->getState() !== PaymentStatusV1::PAYMENT_SALE_REFUNDED;
            $isNonRefundedCapture = $resource->getCapture() && $resource->getCapture()->getState() !== PaymentStatusV1::PAYMENT_CAPTURE_REFUNDED;

            return $isNonRefundedSale || $isNonRefundedCapture;
        }));
        if (count($resourcesAbleToBeRefunded) === 0) {
            throw new NoRefundableResourceAvailableException($orderTransaction->getId());
        }

        $resourceToRefund = $resourcesAbleToBeRefunded[0];
        $resourceId = $resourceToRefund->getSale() ? $resourceToRefund->getSale()->getId() : $resourceToRefund->getCapture()->getId();
        $resourceType = $resourceToRefund->getSale() ? RelatedResource::SALE : RelatedResource::CAPTURE;

        return [
            $resourceId,
            $resourceType,
        ];
    }
}
