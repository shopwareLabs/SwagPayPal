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
use Swag\PayPal\RestApi\V1\Api\Webhook as WebhookV1;
use Swag\PayPal\Util\PaymentStatusUtil;
use Swag\PayPal\Webhook\WebhookEventTypes;

class SaleDenied extends AbstractWebhookHandler
{
    /**
     * @var PaymentStatusUtil
     */
    private $paymentStatusUtil;

    public function __construct(
        EntityRepositoryInterface $orderTransactionRepository,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        PaymentStatusUtil $paymentStatusUtil
    ) {
        parent::__construct($orderTransactionRepository, $orderTransactionStateHandler);
        $this->paymentStatusUtil = $paymentStatusUtil;
    }

    public function getEventType(): string
    {
        return WebhookEventTypes::PAYMENT_SALE_DENIED;
    }

    /**
     * @param WebhookV1 $webhook
     */
    public function invoke(PayPalApiStruct $webhook, Context $context): void
    {
        $orderTransaction = $this->getOrderTransaction($webhook, $context);

        $this->paymentStatusUtil->applySaleStateToOrderTransactionCapture(
            $orderTransaction->getId(),
            $webhook->getResource(),
            $context
        );

        $this->orderTransactionStateHandler->cancel($orderTransaction->getId(), $context);
    }
}
