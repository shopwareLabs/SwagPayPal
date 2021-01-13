<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PayPal\Webhook\Handler;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Swag\PayPal\RestApi\PayPalApiStruct;
use Swag\PayPal\RestApi\V2\Api\Order\PurchaseUnit\Payments\Capture;
use Swag\PayPal\RestApi\V2\Api\Webhook as WebhookV2;
use Swag\PayPal\Util\PaymentStatusUtilV2;
use Swag\PayPal\Webhook\Exception\WebhookException;
use Swag\PayPal\Webhook\WebhookEventTypes;

class CaptureDenied extends AbstractWebhookHandler
{
    /**
     * @var PaymentStatusUtilV2
     */
    private $paymentStatusUtil;

    public function __construct(
        EntityRepositoryInterface $orderTransactionRepository,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        PaymentStatusUtilV2 $paymentStatusUtil
    ) {
        parent::__construct($orderTransactionRepository, $orderTransactionStateHandler);
        $this->paymentStatusUtil = $paymentStatusUtil;
    }

    public function getEventType(): string
    {
        return WebhookEventTypes::PAYMENT_CAPTURE_DENIED;
    }

    /**
     * @param WebhookV2 $webhook
     */
    public function invoke(PayPalApiStruct $webhook, Context $context): void
    {
        /** @var Capture|null $capture */
        $capture = $webhook->getResource();
        if ($capture === null) {
            throw new WebhookException($this->getEventType(), 'Given webhook does not have needed resource data');
        }
        $orderTransaction = $this->getOrderTransactionV2($capture, $context);

        $this->paymentStatusUtil->applyCaptureStateToOrderTransactionCapture(
            $orderTransaction->getId(),
            $capture,
            $context
        );

        $this->orderTransactionStateHandler->cancel($orderTransaction->getId(), $context);
    }
}
