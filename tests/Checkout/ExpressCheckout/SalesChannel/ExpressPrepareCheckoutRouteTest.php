<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PayPal\Test\Checkout\ExpressCheckout\SalesChannel;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\SalesChannel\AccountService;
use Shopware\Core\Checkout\Customer\SalesChannel\RegisterRoute;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\PayPal\Checkout\ExpressCheckout\ExpressCheckoutData;
use Swag\PayPal\Checkout\ExpressCheckout\SalesChannel\ExpressPrepareCheckoutRoute;
use Swag\PayPal\Checkout\Payment\PayPalPaymentHandler;
use Swag\PayPal\Setting\Settings;
use Swag\PayPal\Test\Helper\CheckoutRouteTrait;
use Swag\PayPal\Test\Mock\PayPal\Client\_fixtures\V2\GetOrderCapture;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ExpressPrepareCheckoutRouteTest extends TestCase
{
    use CheckoutRouteTrait;

    public const TEST_PAYMENT_ID_WITHOUT_STATE = 'testPaymentIdWithoutState';
    public const TEST_PAYMENT_ID_WITH_COUNTRY_WITHOUT_STATES = 'testPaymentIdWithCountryWithoutStates';
    public const TEST_PAYMENT_ID_WITH_STATE_NOT_FOUND = 'testPaymentIdWithStateNotFound';

    public function testPrepare(): void
    {
        $salesChannelContext = $this->getSalesChannelContext();
        $this->getSystemConfigService()->set(
            'core.loginRegistration.requireDataProtectionCheckbox',
            true,
            Defaults::SALES_CHANNEL
        );

        $request = new Request([], [
            PayPalPaymentHandler::PAYPAL_REQUEST_PARAMETER_TOKEN => GetOrderCapture::ID,
        ]);

        /** @var CartService $cartService */
        $cartService = $this->getContainer()->get(CartService::class);
        $response = $this->createRoute($cartService)->prepareCheckout($salesChannelContext, $request);
        $content = $response->getContent();
        static::assertNotFalse($content);

        static::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $customer = $this->assertCustomer($salesChannelContext->getContext());

        static::assertSame(GetOrderCapture::PAYER_NAME_GIVEN_NAME, $customer->getFirstName());
        static::assertSame(GetOrderCapture::PAYER_NAME_SURNAME, $customer->getLastName());

        $addresses = $customer->getAddresses();
        static::assertNotNull($addresses);

        $address = $addresses->first();
        static::assertNotNull($address);

        static::assertSame(GetOrderCapture::PAYER_ADDRESS_ADDRESS_LINE_1, $address->getStreet());
        static::assertSame(GetOrderCapture::PAYER_ADDRESS_ADMIN_AREA_2, $address->getCity());
        $country = $address->getCountry();
        static::assertNotNull($country);
        static::assertSame('USA', $country->getTranslation('name'));
        $countryState = $address->getCountryState();
        static::assertNotNull($countryState);
        static::assertSame('New York', $countryState->getName());
        static::assertSame(GetOrderCapture::PAYER_PHONE_NUMBER, $address->getPhoneNumber());

        $cartToken = $response->getToken();
        /** @var ExpressCheckoutData|null $ecsCartExtension */
        $ecsCartExtension = $cartService->getCart($cartToken, $salesChannelContext)
            ->getExtension(ExpressPrepareCheckoutRoute::PAYPAL_EXPRESS_CHECKOUT_CART_EXTENSION_ID);

        static::assertInstanceOf(ExpressCheckoutData::class, $ecsCartExtension);
        static::assertSame(GetOrderCapture::ID, $ecsCartExtension->getPaypalOrderId());
    }

    public function testPrepareWithoutStateFromPayPal(): void
    {
        $this->assertNoState(self::TEST_PAYMENT_ID_WITHOUT_STATE);
    }

    public function testPrepareWithCountryWithoutStates(): void
    {
        $this->assertNoState(self::TEST_PAYMENT_ID_WITH_COUNTRY_WITHOUT_STATES);
    }

    public function testPrepareWithStateNotFound(): void
    {
        $this->assertNoState(self::TEST_PAYMENT_ID_WITH_STATE_NOT_FOUND);
    }

    private function createRoute(?CartService $cartService = null): ExpressPrepareCheckoutRoute
    {
        $settings = $this->createSystemConfigServiceMock([
            Settings::CLIENT_ID => 'testClientId',
            Settings::CLIENT_SECRET => 'testClientSecret',
        ]);
        if ($cartService === null) {
            /** @var CartService $cartService */
            $cartService = $this->getContainer()->get(CartService::class);
        }
        /** @var RegisterRoute $registerRoute */
        $registerRoute = $this->getContainer()->get(RegisterRoute::class);
        /** @var EntityRepositoryInterface $countryRepo */
        $countryRepo = $this->getContainer()->get('country.repository');
        /** @var EntityRepositoryInterface $salutationRepo */
        $salutationRepo = $this->getContainer()->get('salutation.repository');
        /** @var AccountService $accountService */
        $accountService = $this->getContainer()->get(AccountService::class);
        /** @var SalesChannelContextFactory $salesChannelContextFactory */
        $salesChannelContextFactory = $this->getContainer()->get(SalesChannelContextFactory::class);
        /** @var SystemConfigService $systemConfigService */
        $systemConfigService = $this->getContainer()->get(SystemConfigService::class);

        $orderResource = $this->createOrderResource($settings);

        return new ExpressPrepareCheckoutRoute(
            $registerRoute,
            $countryRepo,
            $salutationRepo,
            $accountService,
            $salesChannelContextFactory,
            $orderResource,
            $cartService,
            $systemConfigService,
            new NullLogger()
        );
    }

    private function assertCustomer(Context $context): CustomerEntity
    {
        /** @var EntityRepositoryInterface $customerRepo */
        $customerRepo = $this->getContainer()->get('customer.repository');

        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('email', GetOrderCapture::PAYER_EMAIL_ADDRESS))
            ->addAssociation('addresses.country')
            ->addAssociation('addresses.countryState')
            ->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        /** @var CustomerEntity|null $customer */
        $customer = $customerRepo->search($criteria, $context)->first();
        static::assertNotNull($customer);

        return $customer;
    }

    private function assertNoState(string $testPaypalOrderId): void
    {
        $salesChannelContext = $this->getSalesChannelContext();

        $request = new Request([], [
            PayPalPaymentHandler::PAYPAL_REQUEST_PARAMETER_TOKEN => $testPaypalOrderId,
        ]);

        $response = $this->createRoute()->prepareCheckout($salesChannelContext, $request);
        $content = $response->getContent();
        static::assertNotFalse($content);

        static::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $addresses = $this->assertCustomer($salesChannelContext->getContext())->getAddresses();
        static::assertNotNull($addresses);

        $address = $addresses->first();
        static::assertNotNull($address);

        $countryState = $address->getCountryState();
        static::assertNull($countryState);
    }

    private function getSystemConfigService(): SystemConfigService
    {
        /** @var SystemConfigService $systemConfigService */
        $systemConfigService = $this->getContainer()->get(SystemConfigService::class);

        return $systemConfigService;
    }
}
