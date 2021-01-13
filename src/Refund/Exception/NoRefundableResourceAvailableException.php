<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PayPal\Refund\Exception;

use Shopware\Core\Framework\ShopwareHttpException;
use Symfony\Component\HttpFoundation\Response;

class NoRefundableResourceAvailableException extends ShopwareHttpException
{
    public function __construct(string $orderTransactionId)
    {
        parent::__construct(
            'No resource to refund available for order transaction "{{ orderTransactionId }}"',
            ['orderTransactionId' => $orderTransactionId]
        );
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_BAD_REQUEST;
    }

    public function getErrorCode(): string
    {
        return 'SWAG_PAYPAL__NO_REFUNDABLE_RESOURCE_AVAILABLE';
    }
}
