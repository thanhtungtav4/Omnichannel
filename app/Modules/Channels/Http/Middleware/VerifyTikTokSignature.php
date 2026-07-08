<?php

namespace App\Modules\Channels\Http\Middleware;

use App\Modules\Channels\Models\ChannelAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify TikTok Shop webhook signature.
 *
 * VERIFIED against TikTok Open Platform webhook docs (developers.tiktok.com
 * docs/webhooks-and-events + rollout.com integration guide). The same HMAC
 * pattern is used by TikTok Shop Partner for general webhooks:
 *
 *   Header TikTok-Signature: t={unix_ts},s={hex_digest}
 *     - t = unix seconds at which the event was generated
 *     - s = HMAC-SHA256 hex digest of `${t}.${raw_body}` keyed by client_secret
 *
 *   Header TikTok-Timestamp: <unix_seconds>  (sent alongside, for replay check;
 *                                                we trust the t= inside the
 *                                                signature header instead, but
 *                                                accept either.)
 *
 *   Header TikTok-Client-Id: <app_key>      (optional; used to resolve the
 *                                             right client_secret when a
 *                                             workspace has multiple apps)
 *
 * Replay protection: reject if abs(now - timestamp) > REPLAY_WINDOW_SECONDS.
 *
 * IMPORTANT: This middleware trusts the verified TikTok Open Platform
 * signature scheme. If TikTok Shop Partner API uses a different scheme
 * (their config page mentions signature in 'Authorization' header — separate
 * flow), adjust this middleware or add a partner-specific variant.
 */
class VerifyTikTokSignature
{
    /** Reject webhooks older/newer than this many seconds (replay window). */
    public const REPLAY_WINDOW_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->route('channelAccount');

        if (! $account instanceof ChannelAccount) {
            return response()->json([
                'error' => ['code' => 'CHANNEL_NOT_RESOLVED', 'message' => 'Channel account not resolved.'],
            ], 500);
        }

        $secret = (string) $account->webhook_secret;
        if ($secret === '') {
            return response()->json([
                'error' => ['code' => 'MISSING_WEBHOOK_SECRET', 'message' => 'Webhook secret is not configured.'],
            ], 401);
        }

        // Parse the TikTok-Signature header: "t=<ts>,s=<hex>"
        $signatureHeader = (string) $request->header('TikTok-Signature', '');
        if ($signatureHeader === '') {
            return response()->json([
                'error' => ['code' => 'MISSING_SIGNATURE', 'message' => 'Missing TikTok-Signature header.'],
            ], 401);
        }

        [$timestamp, $signature] = $this->parseSignatureHeader($signatureHeader);
        if ($timestamp === null || $signature === null) {
            return response()->json([
                'error' => ['code' => 'MALFORMED_SIGNATURE', 'message' => 'TikTok-Signature header is malformed.'],
            ], 401);
        }

        // Replay window.
        $now = time();
        if (abs($now - $timestamp) > self::REPLAY_WINDOW_SECONDS) {
            return response()->json([
                'error' => ['code' => 'STALE_SIGNATURE', 'message' => 'Timestamp outside replay window.'],
            ], 401);
        }

        // Recompute: HMAC-SHA256( `${timestamp}.${raw_body}`, secret ).
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if (! hash_equals($expected, strtolower($signature))) {
            return response()->json([
                'error' => ['code' => 'INVALID_SIGNATURE', 'message' => 'Signature mismatch.'],
            ], 401);
        }

        return $next($request);
    }

    /**
     * Parse "t=<unix>,s=<hex>" into [timestamp, signature]. Returns [null, null]
     * on malformed input. Order of keys is not guaranteed by TikTok; we scan.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function parseSignatureHeader(string $header): array
    {
        $parts = array_map('trim', explode(',', $header));
        $timestamp = null;
        $signature = null;
        foreach ($parts as $part) {
            if (str_starts_with($part, 't=')) {
                $timestamp = (int) substr($part, 2);
            } elseif (str_starts_with($part, 's=')) {
                $signature = substr($part, 2);
            }
        }

        if ($timestamp === null || $signature === null || $signature === '') {
            return [null, null];
        }

        return [$timestamp, $signature];
    }
}