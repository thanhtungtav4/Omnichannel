<?php

namespace App\Modules\Channels\Services\TikTok;

use RuntimeException;

/**
 * Thrown when a TikTok OAuth state token fails validation: missing,
 * expired, malformed, or already consumed. Mirrors `InvalidShopeeStateException`.
 */
class InvalidTikTokStateException extends RuntimeException
{
}