<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PayPal\Util\Lifecycle;

use Shopware\Core\Checkout\Refund\PaymentMethodRefundConfigService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\PayPal\Checkout\Payment\PayPalPaymentHandler;
use Swag\PayPal\Checkout\Payment\PayPalPuiPaymentHandler;
use Swag\PayPal\RestApi\V1\Api\Payment\ApplicationContext as ApplicationContextV1;
use Swag\PayPal\RestApi\V1\PaymentIntentV1;
use Swag\PayPal\RestApi\V2\Api\Order\ApplicationContext as ApplicationContextV2;
use Swag\PayPal\RestApi\V2\PaymentIntentV2;
use Swag\PayPal\Setting\Exception\PayPalSettingsInvalidException;
use Swag\PayPal\Setting\Service\SettingsService;
use Swag\PayPal\SwagPayPal;
use Swag\PayPal\Util\PaymentMethodUtil;
use Swag\PayPal\Webhook\WebhookServiceInterface;

class Update
{
    /**
     * @var SystemConfigService
     */
    private $systemConfig;

    /**
     * @var EntityRepositoryInterface
     */
    private $customFieldRepository;

    /**
     * @var WebhookServiceInterface|null
     */
    private $webhookService;

    /**
     * @var EntityRepositoryInterface
     */
    private $paymentRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $salesChannelRepository;

    /**
     * @var PaymentMethodRefundConfigService
     */
    private $paymentMethodRefundConfigService;

    public function __construct(
        SystemConfigService $systemConfig,
        EntityRepositoryInterface $paymentRepository,
        EntityRepositoryInterface $customFieldRepository,
        ?WebhookServiceInterface $webhookService,
        EntityRepositoryInterface $salesChannelRepository,
        PaymentMethodRefundConfigService $paymentMethodRefundConfigService
    ) {
        $this->systemConfig = $systemConfig;
        $this->customFieldRepository = $customFieldRepository;
        $this->webhookService = $webhookService;
        $this->paymentRepository = $paymentRepository;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->paymentMethodRefundConfigService = $paymentMethodRefundConfigService;
    }

    public function update(UpdateContext $updateContext): void
    {
        if (\version_compare($updateContext->getCurrentPluginVersion(), '1.1.0', '<')) {
            $this->updateTo110();
        }

        if (\version_compare($updateContext->getCurrentPluginVersion(), '1.3.0', '<')) {
            $this->updateTo130();
        }

        if (\version_compare($updateContext->getCurrentPluginVersion(), '1.7.0', '<')) {
            $this->updateTo170();
        }

        if (\version_compare($updateContext->getCurrentPluginVersion(), '1.7.2', '<')) {
            $this->updateTo172($updateContext->getContext());
        }

        if (\version_compare($updateContext->getCurrentPluginVersion(), '2.0.0', '<')) {
            $this->updateTo200($updateContext->getContext());
        }

        $this->upsertPaymentMethodRefundConfigs($updateContext->getContext());
    }

    private function updateTo110(): void
    {
        $this->systemConfig->set(SettingsService::SYSTEM_CONFIG_DOMAIN . 'installmentBannerEnabled', true);
    }

    private function updateTo130(): void
    {
        if (!$this->systemConfig->get(SettingsService::SYSTEM_CONFIG_DOMAIN . 'sandbox')) {
            return;
        }

        $previousClientId = $this->systemConfig->get(SettingsService::SYSTEM_CONFIG_DOMAIN . 'clientId');
        $previousClientSecret = $this->systemConfig->get(SettingsService::SYSTEM_CONFIG_DOMAIN . 'clientSecret');
        $previousClientIdSandbox = $this->systemConfig->get(SettingsService::SYSTEM_CONFIG_DOMAIN . 'clientIdSandbox');
        $previousClientSecretSandbox = $this->systemConfig->get(SettingsService::SYSTEM_CONFIG_DOMAIN . 'clientSecretSandbox');

        if ($previousClientId && $previousClientSecret
            && $previousClientIdSandbox === null && $previousClientSecretSandbox === null) {
            $this->systemConfig->set(SettingsService::SYSTEM_CONFIG_DOMAIN . 'clientIdSandbox', $previousClientId);
            $this->systemConfig->set(SettingsService::SYSTEM_CONFIG_DOMAIN . 'clientSecretSandbox', $previousClientSecret);
            $this->systemConfig->set(SettingsService::SYSTEM_CONFIG_DOMAIN . 'clientId', '');
            $this->systemConfig->set(SettingsService::SYSTEM_CONFIG_DOMAIN . 'clientSecret', '');
        }
    }

    private function updateTo170(): void
    {
        if ($this->webhookService === null) {
            // If the WebhookService is `null`, the plugin is deactivated.
            return;
        }

        try {
            $this->webhookService->registerWebhook(null);
        } catch (PayPalSettingsInvalidException $exception) {
            // do nothing, if the plugin is not correctly configured
        }
    }

    private function updateTo172(Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', SwagPayPal::ORDER_TRANSACTION_CUSTOM_FIELDS_PAYPAL_TRANSACTION_ID));

        $customFieldIds = $this->customFieldRepository->searchIds($criteria, $context);

        if ($customFieldIds->getTotal() === 0) {
            return;
        }

        $data = \array_map(static function ($id) {
            return ['id' => $id];
        }, $customFieldIds->getIds());
        $this->customFieldRepository->delete($data, $context);
    }

    private function updateTo200(Context $context): void
    {
        $this->changePaymentHandlerIdentifier($context);
        $this->migrateIntentSetting($context);
        $this->migrateLandingPageSetting($context);
    }

    private function upsertPaymentMethodRefundConfigs(Context $context): void
    {
        $paymentMethodUtil = new PaymentMethodUtil($this->paymentRepository, $this->salesChannelRepository);

        $payPalPaymentMethodId = $paymentMethodUtil->getPayPalPaymentMethodId($context);
        $this->paymentMethodRefundConfigService->upsertPaymentMethodRefundConfigFromYaml(
            $payPalPaymentMethodId,
            'swag_paypal_options',
            __DIR__ . '/../../Refund/Configs/paypal-refund-config-options.yaml',
            $context
        );

        $payPalPuiPaymentMethodId = $paymentMethodUtil->getPayPalPuiPaymentMethodId($context);
        $this->paymentMethodRefundConfigService->upsertPaymentMethodRefundConfigFromYaml(
            $payPalPuiPaymentMethodId,
            'swag_paypal_options',
            __DIR__ . '/../../Refund/Configs/paypal-refund-config-options.yaml',
            $context
        );
    }

    private function changePaymentHandlerIdentifier(Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', 'Swag\PayPal\Payment\PayPalPaymentHandler'));

        $payPalPaymentMethodId = $this->paymentRepository->searchIds($criteria, $context)->firstId();
        $payPalData = null;
        if ($payPalPaymentMethodId !== null) {
            $payPalData = [
                'id' => $payPalPaymentMethodId,
                'handlerIdentifier' => PayPalPaymentHandler::class,
            ];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', 'Swag\PayPal\Payment\PayPalPuiPaymentHandler'));

        $payPalPuiPaymentMethodId = $this->paymentRepository->searchIds($criteria, $context)->firstId();
        $payPalPuiData = null;
        if ($payPalPuiPaymentMethodId !== null) {
            $payPalPuiData = [
                'id' => $payPalPuiPaymentMethodId,
                'handlerIdentifier' => PayPalPuiPaymentHandler::class,
            ];
        }

        $data = [];
        if ($payPalData !== null) {
            $data[] = $payPalData;
        }

        if ($payPalPuiData !== null) {
            $data[] = $payPalPuiData;
        }

        if ($data === []) {
            return;
        }

        $this->paymentRepository->upsert($data, $context);
    }

    private function migrateIntentSetting(Context $context): void
    {
        $salesChannelIds = $this->getSalesChannelIds($context);
        $settingKey = SettingsService::SYSTEM_CONFIG_DOMAIN . 'intent';

        foreach ($salesChannelIds as $salesChannelId) {
            $intent = $this->getConfigValue($salesChannelId, $settingKey);
            if ($intent === null) {
                continue;
            }

            if (!\in_array($intent, PaymentIntentV1::INTENTS, true)) {
                throw new \RuntimeException('Invalid value for "' . $settingKey . '" setting');
            }

            if ($intent === PaymentIntentV1::SALE) {
                $this->systemConfig->set($settingKey, PaymentIntentV2::CAPTURE, $salesChannelId);

                continue;
            }

            $this->systemConfig->set($settingKey, PaymentIntentV2::AUTHORIZE, $salesChannelId);
        }
    }

    private function migrateLandingPageSetting(Context $context): void
    {
        $salesChannelIds = $this->getSalesChannelIds($context);
        $settingKey = SettingsService::SYSTEM_CONFIG_DOMAIN . 'landingPage';

        foreach ($salesChannelIds as $salesChannelId) {
            $landingPage = $this->getConfigValue($salesChannelId, $settingKey);
            if ($landingPage === null) {
                continue;
            }

            if (!\in_array($landingPage, ApplicationContextV1::LANDING_PAGE_TYPES, true)) {
                throw new \RuntimeException('Invalid value for "' . $settingKey . '" setting');
            }

            if ($landingPage === ApplicationContextV1::LANDING_PAGE_TYPE_LOGIN) {
                $this->systemConfig->set($settingKey, ApplicationContextV2::LANDING_PAGE_TYPE_LOGIN, $salesChannelId);

                continue;
            }

            $this->systemConfig->set($settingKey, ApplicationContextV2::LANDING_PAGE_TYPE_BILLING, $salesChannelId);
        }
    }

    private function getSalesChannelIds(Context $context): array
    {
        $salesChannelIds = $this->salesChannelRepository->searchIds(new Criteria(), $context)->getIds();
        $salesChannelIds[] = null; // Global config for all sales channels

        return $salesChannelIds;
    }

    private function getConfigValue(?string $salesChannelId, string $settingKey): ?string
    {
        $settings = $this->systemConfig->getDomain(SettingsService::SYSTEM_CONFIG_DOMAIN, $salesChannelId);
        if ($settings === []) {
            return null; // Config for this sales channel is inherited, so no need to update
        }

        if (!\array_key_exists($settingKey, $settings)) {
            return null; // Sales channel specific config does not contain $settingKey
        }

        return $settings[$settingKey];
    }
}
