<?php

namespace App\Modules\Channels\Services\TikTok;

use RuntimeException;

/**
 * Thrown when TikTok's token endpoint returns an error or a malformed
 * response. The controller maps this to a generic admin-facing message;
 * the specific reason is logged at WARN level.
 *
 * Mirrors ShopeeTokenException.
 */
class TikTokTokenException extends RuntimeException
{
}