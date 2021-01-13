<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PayPal\Checkout\Payment\Handler;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\PayPal\Checkout\PayPalOrderTransactionCaptureService;
use Swag\PayPal\OrdersApi\Builder\OrderFromOrderBuilder;
use Swag\PayPal\OrdersApi\Patch\CustomIdPatchBuilder;
use Swag\PayPal\OrdersApi\Patch\OrderNumberPatchBuilder;
use Swag\PayPal\RestApi\Exception\PayPalApiException;
use Swag\PayPal\RestApi\PartnerAttributionId;
use Swag\PayPal\RestApi\V2\Api\Order\ApplicationContext;
use Swag\PayPal\RestApi\V2\Api\Order as PayPalOrder;
use Swag\PayPal\RestApi\V2\PaymentIntentV2;
use Swag\PayPal\RestApi\V2\PaymentStatusV2;
use Swag\PayPal\RestApi\V2\Resource\OrderResource;
use Swag\PayPal\Setting\Service\SettingsServiceInterface;
use Symfony\Component\HttpFoundation\Response;

class PayPalHandler extends AbstractPaymentHandler
{
    /**
     * @var OrderFromOrderBuilder
     */
    private $orderBuilder;

    /**
     * @var OrderResource
     */
    private $orderResource;

    /**
     * @var OrderTransactionStateHandler
     */
    private $orderTransactionStateHandler;

    /**
     * @var SettingsServiceInterface
     */
    private $settingsService;

    /**
     * @var OrderNumberPatchBuilder
     */
    private $orderNumberPatchBuilder;

    /**
     * @var CustomIdPatchBuilder
     */
    private $customIdPatchBuilder;

    /**
     * @var LoggerInterface
     */
    private $logger;

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

    public function __construct(
        EntityRepositoryInterface $orderTransactionRepo,
        OrderFromOrderBuilder $orderBuilder,
        OrderResource $orderResource,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        SettingsServiceInterface $settingsService,
        OrderNumberPatchBuilder $orderNumberPatchBuilder,
        CustomIdPatchBuilder $customIdPatchBuilder,
        LoggerInterface $logger,
        OrderTransactionCaptureStateHandler $orderTransactionCaptureStateHandler,
        OrderTransactionCaptureService $orderTransactionCaptureService,
        PayPalOrderTransactionCaptureService $payPalOrderTransactionCaptureService
    ) {
        parent::__construct($orderTransactionRepo);
        $this->orderBuilder = $orderBuilder;
        $this->orderResource = $orderResource;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->settingsService = $settingsService;
        $this->orderNumberPatchBuilder = $orderNumberPatchBuilder;
        $this->customIdPatchBuilder = $customIdPatchBuilder;
        $this->logger = $logger;
        $this->orderTransactionCaptureStateHandler = $orderTransactionCaptureStateHandler;
        $this->orderTransactionCaptureService = $orderTransactionCaptureService;
        $this->payPalOrderTransactionCaptureService = $payPalOrderTransactionCaptureService;
    }

    /**
     * @throws AsyncPaymentProcessException
     */
    public function handlePayPalOrder(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext,
        CustomerEntity $customer
    ): PayPalOrder {
        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
        $orderTransactionId = $transaction->getOrderTransaction()->getId();

        $paypalOrder = $this->orderBuilder->getOrder(
            $transaction,
            $salesChannelContext,
            $customer
        );
        $paypalOrder->getApplicationContext()->setUserAction(ApplicationContext::USER_ACTION_PAY_NOW);

        try {
            $paypalOrderResponse = $this->orderResource->create(
                $paypalOrder,
                $salesChannelId,
                PartnerAttributionId::PAYPAL_CLASSIC
            );
        } catch (\Exception $e) {
            throw new AsyncPaymentProcessException(
                $orderTransactionId,
                \sprintf('An error occurred during the communication with PayPal%s%s', PHP_EOL, $e->getMessage())
            );
        }

        $this->addPayPalOrderId(
            $orderTransactionId,
            $paypalOrderResponse->getId(),
            PartnerAttributionId::PAYPAL_CLASSIC,
            $salesChannelContext->getContext()
        );

        return $paypalOrderResponse;
    }

    /**
     * @throws AsyncPaymentFinalizeException
     */
    public function handleFinalizeOrder(
        AsyncPaymentTransactionStruct $transaction,
        string $paypalOrderId,
        string $salesChannelId,
        Context $context,
        string $partnerAttributionId,
        bool $orderDataPatchNeeded
    ): void {
        $transactionId = $transaction->getOrderTransaction()->getId();
        $orderNumber = $transaction->getOrder()->getOrderNumber();

        if ($orderDataPatchNeeded) {
            $patches = [$this->customIdPatchBuilder->createCustomIdPatch($transactionId)];

            $settings = $this->settingsService->getSettings($salesChannelId);
            if ($orderNumber !== null && $settings->getSendOrderNumber()) {
                $orderNumberPrefix = (string) $settings->getOrderNumberPrefix();
                $orderNumber = $orderNumberPrefix . $orderNumber;
                $patches[] = $this->orderNumberPatchBuilder->createOrderNumberPatch($orderNumber);
            }

            try {
                $this->orderResource->update(
                    $patches,
                    $paypalOrderId,
                    $salesChannelId,
                    $partnerAttributionId
                );
            } catch (\Exception $e) {
                throw new AsyncPaymentFinalizeException(
                    $transactionId,
                    \sprintf('An error occurred during the communication with PayPal%s%s', PHP_EOL, $e->getMessage())
                );
            }
        }

        $paypalOrder = $this->orderResource->get($paypalOrderId, $salesChannelId);

        try {
            $this->processPayPalOrder(
                $paypalOrder,
                $transactionId,
                $salesChannelId,
                $partnerAttributionId,
                $context
            );
        } catch (PayPalApiException $e) {
            if ($e->getStatusCode() !== Response::HTTP_UNPROCESSABLE_ENTITY
                || (\strpos($e->getMessage(), PayPalApiException::ERROR_CODE_DUPLICATE_INVOICE_ID) === false)) {
                throw $e;
            }

            $this->logger->warning($e->getMessage(), ['orderNumber' => $orderNumber]);

            $this->orderResource->update(
                [$this->orderNumberPatchBuilder->createRemoveOrderNumberPatch()],
                $paypalOrderId,
                $salesChannelId,
                $partnerAttributionId
            );

            $this->processPayPalOrder(
                $paypalOrder,
                $transactionId,
                $salesChannelId,
                $partnerAttributionId,
                $context
            );
        } catch (\Exception $e) {
            throw new AsyncPaymentFinalizeException(
                $transactionId,
                \sprintf('An error occurred during the communication with PayPal%s%s', PHP_EOL, $e->getMessage())
            );
        }
    }

    private function processPayPalOrder(
        PayPalOrder $paypalOrder,
        string $transactionId,
        string $salesChannelId,
        string $partnerAttributionId,
        Context $context
    ): void {
        if ($paypalOrder->getIntent() !== PaymentIntentV2::CAPTURE) {
            $this->orderResource->authorize($paypalOrder->getId(), $salesChannelId, $partnerAttributionId);

            return;
        }

        $orderTransactionCaptureId = $this->orderTransactionCaptureService->createOrderTransactionCaptureForFullAmount(
            $transactionId,
            $context
        );
        try {
            $response = $this->orderResource->capture($paypalOrder->getId(), $salesChannelId, $partnerAttributionId);
        } catch (PayPalApiException $apiException) {
            $this->orderTransactionCaptureStateHandler->fail($orderTransactionCaptureId, $context);

            throw $apiException;
        }
        $paypalCapture = $response->getPurchaseUnits()[0]->getPayments()->getCaptures()[0];
        $paypalCaptureId = $paypalCapture->getId();
        $this->payPalOrderTransactionCaptureService->addPayPalResourceToOrderTransactionCapture(
            $orderTransactionCaptureId,
            $paypalCaptureId,
            PaymentIntentV2::CAPTURE,
            $context
        );
        if ($paypalCapture->getStatus() === PaymentStatusV2::ORDER_CAPTURE_COMPLETED) {
            $this->orderTransactionCaptureStateHandler->complete($orderTransactionCaptureId, $context);
        }

        if ($response->getStatus() === PaymentStatusV2::ORDER_COMPLETED) {
            $this->orderTransactionStateHandler->paid($transactionId, $context);
        }
    }
}
