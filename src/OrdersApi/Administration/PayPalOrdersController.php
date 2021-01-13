<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PayPal\OrdersApi\Administration;

use OpenApi\Annotations as OA;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureStateHandler;
use Shopware\Core\Checkout\Payment\Exception\InvalidTransactionException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\Annotation\Acl;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Swag\PayPal\Checkout\PayPalOrderTransactionCaptureService;
use Swag\PayPal\OrdersApi\Administration\Service\CaptureRefundCreator;
use Swag\PayPal\RestApi\Exception\PayPalApiException;
use Swag\PayPal\RestApi\PartnerAttributionId;
use Swag\PayPal\RestApi\V1\Api\Payment\Transaction\RelatedResource;
use Swag\PayPal\RestApi\V2\PaymentStatusV2;
use Swag\PayPal\RestApi\V2\Resource\AuthorizationResource;
use Swag\PayPal\RestApi\V2\Resource\CaptureResource;
use Swag\PayPal\RestApi\V2\Resource\OrderResource;
use Swag\PayPal\RestApi\V2\Resource\RefundResource;
use Swag\PayPal\Util\PaymentStatusUtilV2;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @RouteScope(scopes={"api"})
 */
class PayPalOrdersController extends AbstractController
{
    public const REQUEST_PARAMETER_CURRENCY = 'currency';
    public const REQUEST_PARAMETER_AMOUNT = 'amount';
    public const REQUEST_PARAMETER_INVOICE_NUMBER = 'invoiceNumber';
    public const REQUEST_PARAMETER_NOTE_TO_PAYER = 'noteToPayer';
    public const REQUEST_PARAMETER_PARTNER_ATTRIBUTION_ID = 'partnerAttributionId';
    public const REQUEST_PARAMETER_IS_FINAL = 'isFinal';

    /**
     * @var OrderResource
     */
    private $orderResource;

    /**
     * @var AuthorizationResource
     */
    private $authorizationResource;

    /**
     * @var CaptureResource
     */
    private $captureResource;

    /**
     * @var EntityRepositoryInterface
     */
    private $orderTransactionRepository;

    /**
     * @var RefundResource
     */
    private $refundResource;

    /**
     * @var PaymentStatusUtilV2
     */
    private $paymentStatusUtil;

    /**
     * @var CaptureRefundCreator
     */
    private $captureRefundCreator;

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
        OrderResource $orderResource,
        AuthorizationResource $authorizationResource,
        CaptureResource $captureResource,
        RefundResource $refundResource,
        EntityRepositoryInterface $orderTransactionRepository,
        PaymentStatusUtilV2 $paymentStatusUtil,
        CaptureRefundCreator $captureRefundCreator,
        OrderTransactionCaptureStateHandler $orderTransactionCaptureStateHandler,
        OrderTransactionCaptureService $orderTransactionCaptureService,
        PayPalOrderTransactionCaptureService $payPalOrderTransactionCaptureService
    ) {
        $this->orderResource = $orderResource;
        $this->authorizationResource = $authorizationResource;
        $this->captureResource = $captureResource;
        $this->refundResource = $refundResource;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->paymentStatusUtil = $paymentStatusUtil;
        $this->captureRefundCreator = $captureRefundCreator;
        $this->orderTransactionCaptureStateHandler = $orderTransactionCaptureStateHandler;
        $this->orderTransactionCaptureService = $orderTransactionCaptureService;
        $this->payPalOrderTransactionCaptureService = $payPalOrderTransactionCaptureService;
    }

    /**
     * @OA\Get(
     *     path="/paypal-v2/order/{orderTransactionId}/{paypalOrderId}",
     *     description="Loads the order details of the given PayPal order ID",
     *     operationId="orderDetails",
     *     tags={"Admin API", "PayPal"},
     *     @OA\Parameter(
     *         parameter="orderTransactionId",
     *         name="orderTransactionId",
     *         in="path",
     *         description="ID of the order transaction which contains the PayPal payment",
     *         @OA\Schema(type="string"),
     *         required=true
     *     ),
     *     @OA\Parameter(
     *         parameter="paypalOrderId",
     *         name="paypalOrderId",
     *         in="path",
     *         description="ID of the PayPal order",
     *         @OA\Schema(type="string"),
     *         required=true
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="Details of the PayPal order",
     *         @OA\JsonContent(type="array")
     *     )
     * )
     * @Route(
     *     "/api/v{version}/paypal-v2/order/{orderTransactionId}/{paypalOrderId}",
     *      name="api.paypal_v2.order_details",
     *      methods={"GET"}
     * )
     * @Acl({"order.viewer"})
     */
    public function orderDetails(string $orderTransactionId, string $paypalOrderId, Context $context): JsonResponse
    {
        $paypalOrder = $this->orderResource->get(
            $paypalOrderId,
            $this->getSalesChannelId($orderTransactionId, $context)
        );

        return new JsonResponse($paypalOrder);
    }

    /**
     * @OA\Get(
     *     path="/paypal-v2/authorization/{orderTransactionId}/{authorizationId}",
     *     description="Loads the authorization details of the given PayPal authorization ID",
     *     operationId="authorizationDetails",
     *     tags={"Admin API", "PayPal"},
     *     @OA\Parameter(
     *         parameter="orderTransactionId",
     *         name="orderTransactionId",
     *         in="path",
     *         description="ID of the order transaction which contains the PayPal payment",
     *         @OA\Schema(type="string"),
     *         required=true
     *     ),
     *     @OA\Parameter(
     *         parameter="authorizationId",
     *         name="authorizationId",
     *         in="path",
     *         description="ID of the PayPal authorization",
     *         @OA\Schema(type="string"),
     *         required=true
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="Details of the PayPal authorization",
     *         @OA\JsonContent(type="array")
     *     )
     * )
     * @Route(
     *     "/api/v{version}/paypal-v2/authorization/{orderTransactionId}/{authorizationId}",
     *      name="api.paypal_v2.authorization_details",
     *      methods={"GET"}
     * )
     * @Acl({"order.viewer"})
     */
    public function authorizationDetails(string $orderTransactionId, string $authorizationId, Context $context): JsonResponse
    {
        $authorization = $this->authorizationResource->get(
            $authorizationId,
            $this->getSalesChannelId($orderTransactionId, $context)
        );

        return new JsonResponse($authorization);
    }

    /**
     * @OA\Get(
     *     path="/paypal-v2/capture/{orderTransactionId}/{captureId}",
     *     description="Loads the capture details of the given PayPal capture ID",
     *     operationId="captureDetails",
     *     tags={"Admin API", "PayPal"},
     *     @OA\Parameter(
     *         parameter="orderTransactionId",
     *         name="orderTransactionId",
     *         in="path",
     *         description="ID of the order transaction which contains the PayPal payment",
     *         @OA\Schema(type="string"),
     *         required=true
     *     ),
     *     @OA\Parameter(
     *         parameter="captureId",
     *         name="captureId",
     *         in="path",
     *         description="ID of the PayPal capture",
     *         @OA\Schema(type="string"),
     *         required=true
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="Details of the PayPal capture",
     *         @OA\JsonContent(type="array")
     *     )
     * )
     * @Route(
     *     "/api/v{version}/paypal-v2/capture/{orderTransactionId}/{captureId}",
     *      name="api.paypal_v2.capture_details",
     *      methods={"GET"}
     * )
     * @Acl({"order.viewer"})
     */
    public function captureDetails(string $orderTransactionId, string $captureId, Context $context): JsonResponse
    {
        $capture = $this->captureResource->get(
            $captureId,
            $this->getSalesChannelId($orderTransactionId, $context)
        );

        return new JsonResponse($capture);
    }

    /**
     * @OA\Get(
     *     path="/paypal-v2/refund/{orderTransactionId}/{refundId}",
     *     description="Loads the refund details of the given PayPal refund ID",
     *     operationId="refundDetails",
     *     tags={"Admin API", "PayPal"},
     *     @OA\Parameter(
     *         parameter="orderTransactionId",
     *         name="orderTransactionId",
     *         in="path",
     *         description="ID of the order transaction which contains the PayPal payment",
     *         @OA\Schema(type="string"),
     *         required=true
     *     ),
     *     @OA\Parameter(
     *         parameter="refundId",
     *         name="refundId",
     *         in="path",
     *         description="ID of the PayPal refund",
     *         @OA\Schema(type="string"),
     *         required=true
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="Details of the PayPal refund",
     *         @OA\JsonContent(type="array")
     *     )
     * )
     * @Route(
     *     "/api/v{version}/paypal-v2/refund/{orderTransactionId}/{refundId}",
     *      name="api.paypal_v2.refund_details",
     *      methods={"GET"}
     * )
     * @Acl({"order.viewer"})
     */
    public function refundDetails(string $orderTransactionId, string $refundId, Context $context): JsonResponse
    {
        $refund = $this->refundResource->get(
            $refundId,
            $this->getSalesChannelId($orderTransactionId, $context)
        );

        return new JsonResponse($refund);
    }

    /**
     * @OA\Post(
     *     path="/_action/paypal-v2/refund-capture/{orderTransactionId}/{captureId}/{paypalOrderId}",
     *     description="Refunds the PayPal capture and sets the state of the Shopware order transaction accordingly",
     *     operationId="refundCapture",
     *     tags={"Admin API", "PayPal"},
     *     @OA\Parameter(
     *         parameter="orderTransactionId",
     *         name="orderTransactionId",
     *         in="path",
     *         description="ID of the order transaction which contains the PayPal payment",
     *         @OA\Schema(type="string"),
     *         required=true
     *     ),
     *     @OA\Parameter(
     *         parameter="captureId",
     *         name="captureId",
     *         in="path",
     *         description="ID of the PayPal capture",
     *         @OA\Schema(type="string"),
     *         required=true
     *     ),
     *     @OA\Parameter(
     *         parameter="paypalOrderId",
     *         name="paypalOrderId",
     *         in="path",
     *         description="ID of the PayPal order",
     *         @OA\Schema(type="string"),
     *         required=true
     *     ),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="partnerAttributionId", description="Partner Attribution ID. See Swag\PayPal\RestApi\PartnerAttributionId", type="string"),
     *             @OA\Property(property="amount", description="Amount which should be refunded", type="string"),
     *             @OA\Property(property="currency", description="Currency of the refund", type="string"),
     *             @OA\Property(property="invoiceNumber", description="Invoice number of the refund", type="string"),
     *             @OA\Property(property="noteToPayer", description="A note to the payer sent with the refund", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="Details of the PayPal refund",
     *         @OA\JsonContent(type="array")
     *     )
     * )
     * @Route(
     *     "/api/v{version}/_action/paypal-v2/refund-capture/{orderTransactionId}/{captureId}/{paypalOrderId}",
     *     name="api.action.paypal_v2.refund_capture",
     *     methods={"POST"}
     * )
     * @Acl({"order.editor"})
     */
    public function refundCapture(
        string $orderTransactionId,
        string $captureId,
        string $paypalOrderId,
        Context $context,
        Request $request
    ): JsonResponse {
        $refund = $this->captureRefundCreator->createRefund($request);
        $salesChannelId = $this->getSalesChannelId($orderTransactionId, $context);

        $refundResponse = $this->captureResource->refund(
            $captureId,
            $refund,
            $salesChannelId,
            $this->getPartnerAttributionId($request),
            false
        );

        $order = $this->orderResource->get($paypalOrderId, $salesChannelId);

        $this->paymentStatusUtil->applyRefundState($orderTransactionId, $refundResponse, $order, $context);

        return new JsonResponse($refundResponse);
    }

    /**
     * @OA\Post(
     *     path="/_action/paypal-v2/capture-authorization/{orderTransactionId}/{authorizationId}",
     *     description="Captures the PayPal authorization and sets the state of the Shopware order transaction accordingly",
     *     operationId="captureAuthorization",
     *     tags={"Admin API", "PayPal"},
     *     @OA\Parameter(
     *         parameter="orderTransactionId",
     *         name="orderTransactionId",
     *         in="path",
     *         description="ID of the order transaction which contains the PayPal payment",
     *         @OA\Schema(type="string"),
     *         required=true
     *     ),
     *     @OA\Parameter(
     *         parameter="authorizationId",
     *         name="authorizationId",
     *         in="path",
     *         description="ID of the PayPal authorization",
     *         @OA\Schema(type="string"),
     *         required=true
     *     ),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="partnerAttributionId", description="Partner Attribution ID. See Swag\PayPal\RestApi\PartnerAttributionId", type="string"),
     *             @OA\Property(property="amount", description="Amount which should be captured", type="string"),
     *             @OA\Property(property="currency", description="Currency of the capture", type="string"),
     *             @OA\Property(property="invoiceNumber", description="Invoice number of the capture", type="string"),
     *             @OA\Property(property="noteToPayer", description="A note to the payer sent with the capture", type="string"),
     *             @OA\Property(property="isFinal", description="Define if this is the final capture", type="boolean")
     *         )
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="Details of the PayPal capture",
     *         @OA\JsonContent(type="array")
     *     )
     * )
     * @Route(
     *     "/api/v{version}/_action/paypal-v2/capture-authorization/{orderTransactionId}/{authorizationId}",
     *     name="api.action.paypal_v2.capture_authorization",
     *     methods={"POST"}
     * )
     * @Acl({"order.editor"})
     */
    public function captureAuthorization(
        string $orderTransactionId,
        string $authorizationId,
        Context $context,
        Request $request
    ): JsonResponse {
        $capture = $this->captureRefundCreator->createCapture($request);

        if ($capture->getAmount() !== null) {
            $orderTransactionCaptureId = $this->orderTransactionCaptureService->createOrderTransactionCaptureForCustomAmount(
                $orderTransactionId,
                (float)$capture->getAmount()->getValue(),
                $context
            );
        } else {
            $orderTransactionCaptureId = $this->orderTransactionCaptureService->createOrderTransactionCaptureForFullAmount(
                $orderTransactionId,
                $context
            );
        }

        try {
            $captureResponse = $this->authorizationResource->capture(
                $authorizationId,
                $capture,
                $this->getSalesChannelId($orderTransactionId, $context),
                $this->getPartnerAttributionId($request),
                false
            );
        } catch (PayPalApiException $apiException) {
            $this->orderTransactionCaptureStateHandler->fail($orderTransactionCaptureId, $context);

            throw $apiException;
        }
        $paypalCaptureId = $captureResponse->getId();
        $this->payPalOrderTransactionCaptureService->addPayPalResourceToOrderTransactionCapture(
            $orderTransactionCaptureId,
            $paypalCaptureId,
            RelatedResource::CAPTURE,
            $context
        );
        if ($captureResponse->getStatus() === PaymentStatusV2::ORDER_CAPTURE_COMPLETED) {
            $this->orderTransactionCaptureStateHandler->complete($orderTransactionCaptureId, $context);
        }

        $this->paymentStatusUtil->applyCaptureState($orderTransactionId, $captureResponse, $context);

        return new JsonResponse($captureResponse);
    }

    /**
     * @OA\Post(
     *     path="/_action/paypal-v2/void-authorization/{orderTransactionId}/{authorizationId}",
     *     description="Voids the PayPal authorization and sets the state of the Shopware order transaction accordingly",
     *     operationId="voidAuthorization",
     *     tags={"Admin API", "PayPal"},
     *     @OA\Parameter(
     *         parameter="orderTransactionId",
     *         name="orderTransactionId",
     *         in="path",
     *         description="ID of the order transaction which contains the PayPal payment",
     *         @OA\Schema(type="string"),
     *         required=true
     *     ),
     *     @OA\Parameter(
     *         parameter="authorizationId",
     *         name="authorizationId",
     *         in="path",
     *         description="ID of the PayPal authorization",
     *         @OA\Schema(type="string"),
     *         required=true
     *     ),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="partnerAttributionId", description="Partner Attribution ID. See Swag\PayPal\RestApi\PartnerAttributionId", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response="204",
     *         description="Returns status 204 if the voidance was succesful",
     *     )
     * )
     * @Route(
     *     "/api/v{version}/_action/paypal-v2/void-authorization/{orderTransactionId}/{authorizationId}",
     *     name="api.action.paypal_v2.void_authorization",
     *     methods={"POST"}
     * )
     * @Acl({"order.editor"})
     */
    public function voidAuthorization(
        string $orderTransactionId,
        string $authorizationId,
        Context $context,
        Request $request
    ): Response {
        $this->authorizationResource->void(
            $authorizationId,
            $this->getSalesChannelId($orderTransactionId, $context),
            $this->getPartnerAttributionId($request)
        );

        $this->paymentStatusUtil->applyVoidState($orderTransactionId, $context);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function getSalesChannelId(string $orderTransactionId, Context $context): string
    {
        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('order');
        /** @var OrderTransactionEntity|null $orderTransaction */
        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        if ($orderTransaction === null) {
            throw new InvalidTransactionException($orderTransactionId);
        }

        $order = $orderTransaction->getOrder();

        if ($order === null) {
            throw new InvalidTransactionException($orderTransactionId);
        }

        return $order->getSalesChannelId();
    }

    private function getPartnerAttributionId(Request $request): string
    {
        return (string) $request->request->get(
            self::REQUEST_PARAMETER_PARTNER_ATTRIBUTION_ID,
            PartnerAttributionId::PAYPAL_CLASSIC
        );
    }
}
