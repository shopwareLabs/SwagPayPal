<?php

declare(strict_types=1);

namespace Swag\PayPal\Checkout;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Swag\PayPal\SwagPayPal;

class PayPalOrderTransactionCaptureService
{
    /**
     * @var EntityRepositoryInterface
     */
    private $orderTransactionCaptureRepository;

    public function __construct(
        EntityRepositoryInterface $orderTransactionCaptureRepository
    ) {
        $this->orderTransactionCaptureRepository = $orderTransactionCaptureRepository;
    }

    public function addPayPalResourceToOrderTransactionCapture(
        string $orderTransactionCaptureId,
        string $paypalResourceId,
        string $paypalResourceType,
        Context $context
    ): void {
        $data = [
            'id' => $orderTransactionCaptureId,
            'externalReference' => $paypalResourceId,
            'customFields' => [
                SwagPayPal::ORDER_TRANSACTION_CAPTURE_CUSTOM_FIELDS_PAYPAL_RESOURCE_TYPE => $paypalResourceType,
            ],
        ];
        $this->orderTransactionCaptureRepository->update([$data], $context);
    }
}
