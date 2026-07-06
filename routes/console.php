<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Services\TelegramWebhookRegistrar;
use App\Modules\Routing\Models\AgentPresence;

// Flip agents to OFFLINE when their heartbeat goes stale (>90s), so the
// assignment engine stops routing customers to agents who closed the CRM.
Schedule::call(function () {
    AgentPresence::query()
        ->where('status', '!=', 'OFFLINE')
        ->where('last_seen_at', '<', now()->subSeconds(90))
        ->update(['status' => 'OFFLINE']);
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
