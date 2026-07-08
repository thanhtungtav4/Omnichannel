<?php

namespace App\Modules\Channels\Adapters;

use App\Modules\Channels\Contracts\ChannelAdapter;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboxMessage;
use RuntimeException;

/**
 * TikTok Shop Chat adapter (cut 1, specs/13_TIKTOK_SHOP_VN.md).
 *
 * Skeleton — every method throws so the ChannelAdapterRegistry can route a
 * TIKTOK_SHOP account to this adapter without crashing, but a stray call
 * fails loud instead of silently no-op'ing. Methods are filled in
 * week-by-week per specs/13 milestones:
 *
 *   W2 G1.1: token refresh + OAuth state — outside the adapter (in jobs)
 *   W3 G1.2: normalizeInbound()
 *   W4 G1.3: buildOutboundPayload() + sendOutbound()
 *
 * Two OPEN SPIKES (W1) before W2 code:
 *   1. Confirm auth model: full seller OAuth vs app-only auth for chat.
 *   2. Confirm webhook signature scheme (header name + HMAC input format).
 *   Both documented in spec 13 § Verification. Update this file's normalize
 *   + send methods when those are confirmed.
 *
 * The exception message is intentionally explicit so support engineers can
 * diagnose "why didn't this TikTok webhook get ingested" without reading
 * source.
 */
class TikTokShopAdapter implements ChannelAdapter
{
    /** Message types we KNOW HOW to render in the Inbox in cut 1. */
    private const SUPPORTED_TYPES = ['text', 'image'];

    public function normalizeInbound(ChannelAccount $account, array $payload): array
    {
        throw new RuntimeException(sprintf(
            'TikTokShopAdapter::normalizeInbound is not implemented yet for channel account %s. '.
            'See specs/13_TIKTOK_SHOP_VN.md milestone W3 G1.2. '.
            'Open spikes: auth model + signature scheme (W1.1).',
            $account->id,
        ));
    }

    public function buildOutboundPayload(ChannelAccount $account, OutboxMessage $message): array
    {
        throw new RuntimeException(sprintf(
            'TikTokShopAdapter::buildOutboundPayload is not implemented yet for channel account %s. '.
            'See specs/13_TIKTOK_SHOP_VN.md milestone W4 G1.3.',
            $account->id,
        ));
    }

    public function sendOutbound(ChannelAccount $account, array $payload): array
    {
        throw new RuntimeException(sprintf(
            'TikTokShopAdapter::sendOutbound is not implemented yet for channel account %s. '.
            'See specs/13_TIKTOK_SHOP_VN.md milestone W4 G1.3.',
            $account->id,
        ));
    }
}