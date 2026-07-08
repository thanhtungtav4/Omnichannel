<?php

namespace App\Modules\Channels\Services\Shopee;

use RuntimeException;

/**
 * Thrown when Shopee's token endpoint returns an error or a malformed
 * response. The controller maps this to a generic admin-facing message;
 * the specific reason is logged at WARN level.
 */
class ShopeeTokenException extends RuntimeException
{
}