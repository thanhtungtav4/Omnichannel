<?php

namespace App\Modules\Channels\Services\Shopee;

use RuntimeException;

/**
 * Thrown when a Shopee OAuth state token fails validation: missing,
 * expired, malformed, or already consumed. Distinct from a network or
 * Shopee-side error so the controller can map it to a clear UI message
 * ("Your session expired — please try connecting Shopee again").
 */
class InvalidShopeeStateException extends RuntimeException
{
}