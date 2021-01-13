<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PayPal\Refund\Exception;

use Shopware\Core\Framework\ShopwareHttpException;
use Symfony\Component\HttpFoundation\Response;

class ResourceMissingInCaptureException extends ShopwareHttpException
{
    public function __construct(string $orderTransactionCaptureId)
    {
        parent::__construct(
            'The resource to refund is missing in capture "{{ orderTransactionCaptureId }}"',
            ['orderTransactionCaptureId' => $orderTransactionCaptureId]
        );
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_BAD_REQUEST;
    }

    public function getErrorCode(): string
    {
        return 'SWAG_PAYPAL__RESOURCE_MISSING_IN_CAPTURE';
    }
}
