<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PayPal;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Refund\PaymentMethodRefundConfigService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\System\CustomField\CustomFieldDefinition;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\PayPal\Util\Lifecycle\ActivateDeactivate;
use Swag\PayPal\Util\Lifecycle\InstallUninstall;
use Swag\PayPal\Util\Lifecycle\Update;
use Swag\PayPal\Webhook\WebhookService;
use Symfony\Component\DependencyInjection\ContainerInterface;

class SwagPayPal extends Plugin
{
    public const ORDER_TRANSACTION_CUSTOM_FIELDS_PAYPAL_TRANSACTION_ID = 'swag_paypal_transaction_id';
    public const ORDER_TRANSACTION_CUSTOM_FIELDS_PAYPAL_TOKEN = 'swag_paypal_token';
    public const ORDER_TRANSACTION_CUSTOM_FIELDS_PAYPAL_PUI_INSTRUCTION = 'swag_paypal_pui_payment_instruction';
    public const ORDER_TRANSACTION_CUSTOM_FIELDS_PAYPAL_ORDER_ID = 'swag_paypal_order_id';
    public const ORDER_TRANSACTION_CUSTOM_FIELDS_PAYPAL_PARTNER_ATTRIBUTION_ID = 'swag_paypal_partner_attribution_id';
    public const ORDER_TRANSACTION_CAPTURE_CUSTOM_FIELDS_PAYPAL_RESOURCE_TYPE = 'swag_paypal_resource_type';
    public const SALES_CHANNEL_TYPE_POS = '1ce0868f406d47d98cfe4b281e62f099';
    public const SALES_CHANNEL_POS_EXTENSION = 'paypalPosSalesChannel';
    public const PRODUCT_LOG_POS_EXTENSION = 'paypalPosLog';
    public const PRODUCT_SYNC_POS_EXTENSION = 'paypalPosSync';
    public const POS_PARTNER_CLIENT_ID = '456dadab-3085-4fa3-bf2b-a2efd01c3593';
    public const POS_PARTNER_IDENTIFIER = 'shopware';

    private const PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_READ = 'swag_paypal_pos_sales_channel:read';
    private const PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_UPDATE = 'swag_paypal_pos_sales_channel:update';
    private const PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_CREATE = 'swag_paypal_pos_sales_channel:create';
    private const PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_DELETE = 'swag_paypal_pos_sales_channel:delete';
    private const PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_RUN_READ = 'swag_paypal_pos_sales_channel_run:read';
    private const PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_RUN_UPDATE = 'swag_paypal_pos_sales_channel_run:update';
    private const PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_RUN_CREATE = 'swag_paypal_pos_sales_channel_run:create';
    private const PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_RUN_DELETE = 'swag_paypal_pos_sales_channel_run:delete';
    private const PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_RUN_LOG_READ = 'swag_paypal_pos_sales_channel_run_log:read';

    private const PAYPAL_POS_SALES_CHANNEL_PRIVILEGES = [
        self::PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_READ,
        self::PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_UPDATE,
        self::PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_CREATE,
        self::PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_DELETE,
        self::PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_RUN_READ,
        self::PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_RUN_UPDATE,
        self::PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_RUN_CREATE,
        self::PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_RUN_DELETE,
        self::PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_RUN_LOG_READ,
    ];

    /**
     * @var ActivateDeactivate
     */
    private $activateDeactivate;

    /**
     * @Required
     */
    public function setActivateDeactivate(ActivateDeactivate $activateDeactivate): void
    {
        $this->activateDeactivate = $activateDeactivate;
    }

    public function install(InstallContext $installContext): void
    {
        /** @var EntityRepositoryInterface $systemConfigRepository */
        $systemConfigRepository = $this->container->get('system_config.repository');
        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        /** @var EntityRepositoryInterface $salesChannelRepository */
        $salesChannelRepository = $this->container->get('sales_channel.repository');
        /** @var EntityRepositoryInterface $ruleRepository */
        $ruleRepository = $this->container->get('rule.repository');
        /** @var EntityRepositoryInterface $countryRepository */
        $countryRepository = $this->container->get('country.repository');
        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        /** @var SystemConfigService $systemConfigService */
        $systemConfigService = $this->container->get(SystemConfigService::class);
        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);
        /** @var PaymentMethodRefundConfigService $paymentMethodRefundConfigService */
        $paymentMethodRefundConfigService = $this->container->get(PaymentMethodRefundConfigService::class);

        (new InstallUninstall(
            $systemConfigRepository,
            $paymentRepository,
            $salesChannelRepository,
            $ruleRepository,
            $countryRepository,
            $pluginIdProvider,
            $systemConfigService,
            $connection,
            $paymentMethodRefundConfigService,
            static::class
        ))->install($installContext->getContext());

        parent::install($installContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        $context = $uninstallContext->getContext();
        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        /** @var EntityRepositoryInterface $salesChannelRepository */
        $salesChannelRepository = $this->container->get('sales_channel.repository');

        if ($uninstallContext->keepUserData()) {
            parent::uninstall($uninstallContext);

            return;
        }

        /** @var EntityRepositoryInterface $systemConfigRepository */
        $systemConfigRepository = $this->container->get('system_config.repository');
        /** @var EntityRepositoryInterface $countryRepository */
        $countryRepository = $this->container->get('country.repository');
        /** @var EntityRepositoryInterface $ruleRepository */
        $ruleRepository = $this->container->get('rule.repository');
        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        /** @var SystemConfigService $systemConfigService */
        $systemConfigService = $this->container->get(SystemConfigService::class);
        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);
        /** @var PaymentMethodRefundConfigService $paymentMethodRefundConfigService */
        $paymentMethodRefundConfigService = $this->container->get(PaymentMethodRefundConfigService::class);

        (new InstallUninstall(
            $systemConfigRepository,
            $paymentRepository,
            $salesChannelRepository,
            $ruleRepository,
            $countryRepository,
            $pluginIdProvider,
            $systemConfigService,
            $connection,
            $paymentMethodRefundConfigService,
            static::class
        ))->uninstall($context);

        parent::uninstall($uninstallContext);
    }

    public function update(UpdateContext $updateContext): void
    {
        /** @var SystemConfigService $systemConfigService */
        $systemConfigService = $this->container->get(SystemConfigService::class);
        /** @var EntityRepositoryInterface $customFieldRepository */
        $customFieldRepository = $this->container->get(\sprintf('%s.repository', (new CustomFieldDefinition())->getEntityName()));
        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        /** @var WebhookService|null $webhookService */
        $webhookService = $this->container->get(WebhookService::class, ContainerInterface::NULL_ON_INVALID_REFERENCE);
        /** @var EntityRepositoryInterface $salesChannelRepository */
        $salesChannelRepository = $this->container->get('sales_channel.repository');
        /** @var PaymentMethodRefundConfigService $paymentMethodRefundConfigService */
        $paymentMethodRefundConfigService = $this->container->get(PaymentMethodRefundConfigService::class);

        (new Update(
            $systemConfigService,
            $paymentRepository,
            $customFieldRepository,
            $webhookService,
            $salesChannelRepository,
            $paymentMethodRefundConfigService,
        ))->update($updateContext);

        $this->addCustomPrivileges();

        parent::update($updateContext);
    }

    public function activate(ActivateContext $activateContext): void
    {
        $this->activateDeactivate->activate($activateContext->getContext());
        $this->addCustomPrivileges();

        parent::activate($activateContext);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        $this->activateDeactivate->deactivate($deactivateContext->getContext());
        $this->removeCustomPrivileges();

        parent::deactivate($deactivateContext);
    }

    public function enrichPrivileges(): array
    {
        return [
            'sales_channel.viewer' => [
                self::PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_READ,
                self::PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_RUN_READ,
                self::PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_RUN_UPDATE,
                self::PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_RUN_CREATE,
                self::PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_RUN_LOG_READ,
                'sales_channel_payment_method:read',
            ],
            'sales_channel.editor' => [
                self::PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_UPDATE,
                self::PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_RUN_DELETE,
                'payment_method:update',
            ],
            'sales_channel.creator' => [
                self::PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_CREATE,
                'payment_method:create',
                'shipping_method:create',
                'delivery_time:create',
            ],
            'sales_channel.deleter' => [
                self::PAYPAL_POS_SALES_CHANNEL_PRIVILEGE_DELETE,
            ],
        ];
    }

    private function addCustomPrivileges(): void
    {
        if (\method_exists(Plugin::class, 'enrichPrivileges')) {
            return;
        }

        if (!\method_exists($this, 'addPrivileges')) {
            return;
        }

        foreach ($this->enrichPrivileges() as $role => $privileges) {
            $this->addPrivileges($role, $privileges);
        }
    }

    private function removeCustomPrivileges(): void
    {
        if (\method_exists(Plugin::class, 'enrichPrivileges')) {
            return;
        }

        if (!\method_exists($this, 'removePrivileges')) {
            return;
        }

        $this->removePrivileges(self::PAYPAL_POS_SALES_CHANNEL_PRIVILEGES);
    }
}
