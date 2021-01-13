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
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\PayPal\Checkout\PayPalOrderTransactionCaptureService;
use Swag\PayPal\PaymentsApi\Builder\OrderPaymentBuilderInterface;
use Swag\PayPal\PaymentsApi\Patch\OrderNumberPatchBuilder;
use Swag\PayPal\PaymentsApi\Patch\PayerInfoPatchBuilder;
use Swag\PayPal\PaymentsApi\Patch\ShippingAddressPatchBuilder;
use Swag\PayPal\RestApi\Exception\PayPalApiException;
use Swag\PayPal\RestApi\PartnerAttributionId;
use Swag\PayPal\RestApi\V1\Api\Patch;
use Swag\PayPal\RestApi\V1\Api\Payment;
use Swag\PayPal\RestApi\V1\Api\Payment\PaymentInstruction;
use Swag\PayPal\RestApi\V1\Api\Payment\Transaction\RelatedResource;
use Swag\PayPal\RestApi\V1\Api\Payment\Transaction\RelatedResource\Capture;
use Swag\PayPal\RestApi\V1\Api\Payment\Transaction\RelatedResource\Sale;
use Swag\PayPal\RestApi\V1\PaymentIntentV1;
use Swag\PayPal\RestApi\V1\PaymentStatusV1;
use Swag\PayPal\RestApi\V1\Resource\PaymentResource;
use Swag\PayPal\Setting\Service\SettingsServiceInterface;
use Swag\PayPal\SwagPayPal;
use Symfony\Component\HttpFoundation\RedirectResponse;

class PlusPuiHandler
{
    public const PAYPAL_PAYMENT_ID_INPUT_NAME = 'paypalPaymentId';
    public const PAYPAL_PAYMENT_TOKEN_INPUT_NAME = 'paypalToken';

    /**
     * @var PaymentResource
     */
    private $paymentResource;

    /**
     * @var EntityRepositoryInterface
     */
    private $orderTransactionRepo;

    /**
     * @var OrderPaymentBuilderInterface
     */
    private $paymentBuilder;

    /**
     * @var OrderNumberPatchBuilder
     */
    private $orderNumberPatchBuilder;

    /**
     * @var PayerInfoPatchBuilder
     */
    private $payerInfoPatchBuilder;

    /**
     * @var ShippingAddressPatchBuilder
     */
    private $shippingAddressPatchBuilder;

    /**
     * @var SettingsServiceInterface
     */
    private $settingsService;

    /**
     * @var OrderTransactionStateHandler
     */
    private $orderTransactionStateHandler;

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

    /**
     * @var EntityRepositoryInterface
     */
    private $orderTransactionCaptureRepository;

    public function __construct(
        PaymentResource $paymentResource,
        EntityRepositoryInterface $orderTransactionRepo,
        OrderPaymentBuilderInterface $paymentBuilder,
        PayerInfoPatchBuilder $payerInfoPatchBuilder,
        OrderNumberPatchBuilder $orderNumberPatchBuilder,
        ShippingAddressPatchBuilder $shippingAddressPatchBuilder,
        SettingsServiceInterface $settingsService,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        LoggerInterface $logger,
        OrderTransactionCaptureStateHandler $orderTransactionCaptureStateHandler,
        OrderTransactionCaptureService $orderTransactionCaptureService,
        PayPalOrderTransactionCaptureService $payPalOrderTransactionCaptureService,
        EntityRepositoryInterface $orderTransactionCaptureRepository
    ) {
        $this->paymentResource = $paymentResource;
        $this->orderTransactionRepo = $orderTransactionRepo;
        $this->paymentBuilder = $paymentBuilder;
        $this->orderNumberPatchBuilder = $orderNumberPatchBuilder;
        $this->payerInfoPatchBuilder = $payerInfoPatchBuilder;
        $this->shippingAddressPatchBuilder = $shippingAddressPatchBuilder;
        $this->settingsService = $settingsService;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->logger = $logger;
        $this->orderTransactionCaptureStateHandler = $orderTransactionCaptureStateHandler;
        $this->orderTransactionCaptureService = $orderTransactionCaptureService;
        $this->payPalOrderTransactionCaptureService = $payPalOrderTransactionCaptureService;
        $this->orderTransactionCaptureRepository = $orderTransactionCaptureRepository;
    }

    public function handlePlusPayment(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        CustomerEntity $customer
    ): RedirectResponse {
        $paypalPaymentId = $dataBag->get(self::PAYPAL_PAYMENT_ID_INPUT_NAME);
        $paypalToken = $dataBag->get(self::PAYPAL_PAYMENT_TOKEN_INPUT_NAME);
        $this->addPayPalTransactionId($transaction, $paypalPaymentId, $salesChannelContext->getContext(), $paypalToken);

        $patches = [
            $this->shippingAddressPatchBuilder->createShippingAddressPatch($customer),
            $this->payerInfoPatchBuilder->createPayerInfoPatch($customer),
        ];

        $this->patchPayPalPayment(
            $patches,
            $paypalPaymentId,
            $salesChannelContext->getSalesChannel()->getId(),
            $transaction->getOrderTransaction()->getId()
        );

        return new RedirectResponse('plusPatched');
    }

    /**
     * @throws AsyncPaymentProcessException
     */
    public function handlePuiPayment(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext,
        CustomerEntity $customer
    ): Payment {
        $payment = $this->paymentBuilder->getPayment($transaction, $salesChannelContext);
        $payment->getPayer()->setExternalSelectedFundingInstrumentType(PaymentInstruction::TYPE_INVOICE);
        $payment->getApplicationContext()->setLocale('de_DE');

        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
        $orderTransactionId = $transaction->getOrderTransaction()->getId();

        try {
            $response = $this->paymentResource->create(
                $payment,
                $salesChannelId,
                PartnerAttributionId::PAYPAL_CLASSIC
            );
        } catch (\Exception $e) {
            throw new AsyncPaymentProcessException(
                $orderTransactionId,
                \sprintf('An error occurred during the communication with PayPal%s%s', PHP_EOL, $e->getMessage())
            );
        }

        $paypalPaymentId = $response->getId();
        $patches = [
            $this->shippingAddressPatchBuilder->createShippingAddressPatch($customer),
            $this->payerInfoPatchBuilder->createPayerInfoPatch($customer),
        ];

        $this->patchPayPalPayment($patches, $paypalPaymentId, $salesChannelId, $orderTransactionId);

        $context = $salesChannelContext->getContext();
        $this->addPayPalTransactionId($transaction, $paypalPaymentId, $context);

        return $response;
    }

    /**
     * @throws AsyncPaymentFinalizeException
     */
    public function handleFinalizePayment(
        AsyncPaymentTransactionStruct $transaction,
        string $salesChannelId,
        Context $context,
        string $paymentId,
        string $payerId,
        string $partnerAttributionId,
        bool $orderNumberSendNeeded
    ): void {
        $transactionId = $transaction->getOrderTransaction()->getId();
        $settings = $this->settingsService->getSettings($salesChannelId);
        $orderNumber = $transaction->getOrder()->getOrderNumber();

        if ($orderNumberSendNeeded && $orderNumber !== null && $settings->getSendOrderNumber()) {
            $orderNumberPrefix = (string) $settings->getOrderNumberPrefix();
            $orderNumber = $orderNumberPrefix . $orderNumber;

            try {
                $this->paymentResource->patch(
                    [
                        $this->orderNumberPatchBuilder->createOrderNumberPatch($orderNumber),
                    ],
                    $paymentId,
                    $salesChannelId
                );
            } catch (\Exception $e) {
                throw new AsyncPaymentFinalizeException(
                    $transactionId,
                    \sprintf('An error occurred during the communication with PayPal%s%s', PHP_EOL, $e->getMessage())
                );
            }
        }

        try {
            $response = $this->processPayPalPlusPayment(
                $transactionId,
                $payerId,
                $paymentId,
                $salesChannelId,
                $partnerAttributionId,
                $context
            );
        } catch (PayPalApiException $e) {
            $parameters = $e->getParameters();
            if (!isset($parameters['name']) || $parameters['name'] !== PayPalApiException::ERROR_CODE_DUPLICATE_ORDER_NUMBER) {
                throw $e;
            }

            $this->logger->warning($e->getMessage(), ['orderNumber' => $orderNumber]);

            $this->paymentResource->patch(
                [
                    $this->orderNumberPatchBuilder->createOrderNumberPatch(null),
                ],
                $paymentId,
                $salesChannelId
            );

            $response = $this->processPayPalPlusPayment(
                $transactionId,
                $payerId,
                $paymentId,
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

        $paymentState = $this->getPaymentState($response);

        // apply the payment status if its completed by PayPal
        if ($paymentState === PaymentStatusV1::PAYMENT_COMPLETED) {
            $this->orderTransactionStateHandler->paid($transactionId, $context);
        }

        if ($paymentState === PaymentStatusV1::PAYMENT_DENIED) {
            $this->orderTransactionStateHandler->fail($transactionId, $context);
        }

        $this->savePaymentInstructions($response, $transactionId, $context);
    }

    /**
     * @param Patch[] $patches
     *
     * @throws AsyncPaymentProcessException
     */
    private function patchPayPalPayment(
        array $patches,
        string $paypalPaymentId,
        string $salesChannelId,
        string $orderTransactionId
    ): void {
        try {
            $this->paymentResource->patch($patches, $paypalPaymentId, $salesChannelId);
        } catch (\Exception $e) {
            throw new AsyncPaymentProcessException(
                $orderTransactionId,
                \sprintf('An error occurred during the communication with PayPal%s%s', PHP_EOL, $e->getMessage())
            );
        }
    }

    private function addPayPalTransactionId(
        AsyncPaymentTransactionStruct $transaction,
        string $paypalPaymentId,
        Context $context,
        ?string $paypalToken = null
    ): void {
        $customFields = [
            SwagPayPal::ORDER_TRANSACTION_CUSTOM_FIELDS_PAYPAL_TRANSACTION_ID => $paypalPaymentId,
        ];

        if ($paypalToken !== null) {
            $customFields[SwagPayPal::ORDER_TRANSACTION_CUSTOM_FIELDS_PAYPAL_TOKEN] = $paypalToken;
        }

        $data = [
            'id' => $transaction->getOrderTransaction()->getId(),
            'customFields' => $customFields,
        ];
        $this->orderTransactionRepo->update([$data], $context);
    }

    private function getPaymentState(Payment $payment): string
    {
        $intent = $payment->getIntent();
        $relatedResource = $payment->getTransactions()[0]->getRelatedResources()[0];
        $paymentState = '';

        switch ($intent) {
            case PaymentIntentV1::SALE:
                $sale = $relatedResource->getSale();
                if ($sale !== null) {
                    $paymentState = $sale->getState();
                }

                break;
            case PaymentIntentV1::AUTHORIZE:
                $authorization = $relatedResource->getAuthorization();
                if ($authorization !== null) {
                    $paymentState = $authorization->getState();
                }

                break;
            case PaymentIntentV1::ORDER:
                $order = $relatedResource->getOrder();
                if ($order !== null) {
                    $paymentState = $order->getState();
                }

                break;
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

    private function processPayPalPlusPayment(
        string $transactionId,
        string $payerId,
        string $paymentId,
        string $salesChannelId,
        string $partnerAttributionId,
        Context $context
    ): Payment {
        $orderTransactionCaptureId = $this->orderTransactionCaptureService->createOrderTransactionCaptureForFullAmount(
            $transactionId,
            $context
        );
        try {
            $response = $this->paymentResource->execute(
                $payerId,
                $paymentId,
                $salesChannelId,
                $partnerAttributionId
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
