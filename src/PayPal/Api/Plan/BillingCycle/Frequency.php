<?php
declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PayPal\PayPal\Api\Plan\BillingCycle;

use Swag\PayPal\PayPal\Api\Common\PayPalStruct;

class Frequency extends PayPalStruct
{
    /** @var string */
    protected $intervalUnit;

    /** @var int */
    protected $intervalCount;

    public function getIntervalUnit(): string
    {
        return $this->intervalUnit;
    }

    public function setIntervalUnit(string $intervalUnit): self
    {
        $this->intervalUnit = $intervalUnit;

        return $this;
    }

    public function getIntervalCount(): int
    {
        return $this->intervalCount;
    }

    public function setIntervalCount(int $intervalCount): self
    {
        $this->intervalCount = $intervalCount;

        return $this;
    }
}
