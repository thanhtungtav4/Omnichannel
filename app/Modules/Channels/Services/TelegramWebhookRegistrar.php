<?php

namespace App\Modules\Channels\Services;

use App\Modules\Channels\Models\ChannelAccount;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class TelegramWebhookRegistrar
{
    private const ALLOWED_UPDATES = [
        'message',
        'edited_message',
        'callback_query',
        'my_chat_member',
    ];

    /**
     * @return array<string, mixed>
     */
    public function register(ChannelAccount $account, ?string $webhookUrl = null, bool $dropPendingUpdates = false): array
    {
        if ($account->provider !== 'TELEGRAM') {
            throw new RuntimeException('Telegram webhook registration requires a TELEGRAM channel account.');
        }

        $token = Arr::get($account->credentials ?? [], 'bot_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Telegram bot token is missing.');
        }

        $url = $webhookUrl ?: route('webhooks.telegram', $account, absolute: true);
        if (! str_starts_with($url, 'https://')) {
            throw new RuntimeException('Telegram webhook URL must be HTTPS. Pass --url with your public HTTPS tunnel/domain.');
        }

        $secret = $account->webhook_secret ?: Str::random(48);
        $payload = [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => self::ALLOWED_UPDATES,
            'drop_pending_updates' => $dropPendingUpdates,
        ];

        $response = Http::asJson()
            ->timeout(15)
            ->post("https://api.telegram.org/bot{$token}/setWebhook", $payload);
        $body = $response->json();

        if (! $response->successful() || Arr::get($body, 'ok') !== true) {
            $message = (string) (Arr::get($body, 'description') ?: 'Telegram setWebhook request failed.');

            $account->forceFill([
                'status' => 'DEGRADED',
                'last_health_check_at' => now(),
                'last_error_code' => (string) (Arr::get($body, 'error_code') ?: $response->status()),
                'last_error_message' => $message,
            ])->save();

            throw new RuntimeException($message);
        }

        $account->forceFill([
            'status' => 'ACTIVE',
            'webhook_secret' => $secret,
            'webhook_url' => $url,
            'last_health_check_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        return [
            'url' => $url,
            'allowed_updates' => self::ALLOWED_UPDATES,
            'telegram_response' => $body,
        ];
    }
}
