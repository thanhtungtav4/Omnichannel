<?php

use App\Modules\Channels\Jobs\RefreshZaloAccessTokenJob;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Services\TelegramWebhookRegistrar;
use App\Modules\Inbox\Services\ConversationSlaMonitor;
use App\Modules\Routing\Models\AgentPresence;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Flip agents to OFFLINE when their heartbeat goes stale (>90s), so the
// assignment engine stops routing customers to agents who closed the CRM.
Schedule::call(function () {
    AgentPresence::query()
        ->where('status', '!=', 'OFFLINE')
        ->where('last_seen_at', '<', now()->subSeconds(90))
        ->update(['status' => 'OFFLINE']);
})->everyMinute();

// Refresh Zalo OA access tokens before they expire. Without this the token
// silently dies and every outbound send fails. Dispatch a refresh job for any
// ZALO_OA account whose token expires within the next hour (or is unknown).
Schedule::call(function () {
    ChannelAccount::query()
        ->where('provider', 'ZALO_OA')
        ->whereIn('status', ['ACTIVE', 'DEGRADED'])
        ->each(function (ChannelAccount $account) {
            $expiresAt = data_get($account->settings, 'token_expires_at');
            $dueSoon = ! $expiresAt || now()->addHour()->gte($expiresAt);
            if ($dueSoon) {
                RefreshZaloAccessTokenJob::dispatch($account->id);
            }
        });
})->everyFifteenMinutes();

// SLA sweep: flag conversations past their response target, and re-attempt
// assignment for anyone stuck waiting for an agent (assign failed at ingest
// because no agent was eligible, or the owner went offline since).
Schedule::call(function () {
    app(ConversationSlaMonitor::class)->sweep();
})->everyMinute();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('channels:telegram-webhook {account : Telegram channel account UUID} {--url= : Public HTTPS webhook URL override} {--drop-pending : Drop pending Telegram updates}', function () {
    $account = ChannelAccount::query()
        ->where('provider', 'TELEGRAM')
        ->findOrFail($this->argument('account'));

    $result = app(TelegramWebhookRegistrar::class)->register(
        $account,
        $this->option('url') ?: null,
        (bool) $this->option('drop-pending'),
    );

    $this->info('Telegram webhook registered.');
    $this->line('URL: '.$result['url']);
    $this->line('Allowed updates: '.implode(', ', $result['allowed_updates']));
})->purpose('Register a Telegram webhook with secret_token for a channel account');
