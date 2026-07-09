<?php

namespace App\Modules\Channels\Jobs;

use App\Modules\Channels\Adapters\ZaloOaAdapter;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboundMiniAppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Send one Mini App template message (spec 15 § C4).
 *
 * Loaded from `outbound_miniapp_notifications.id` (the audit row written
 * by MiniAppOutboundNotifier::notifyContact). The job walks QUEUED →
 * SENT/FAILED with up to 3 attempts on transient errors (token refresh,
 * network blip). Most template sends are non-retryable — the spec is
 * clear on that: a user who blocked the OA, or a template that was
 * rejected by Zalo, won't succeed on retry.
 */
class MiniAppNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Spec: 3 attempts on transient failure. */
    public int $tries = 3;

    /** Backoff for retryable failures. */
    public array $backoff = [60, 300];

    public function __construct(public readonly string $notificationId) {}

    public function handle(): void
    {
        $row = OutboundMiniAppNotification::query()->find($this->notificationId);
        if ($row === null) {
            // Audit row vanished (workspace deleted, etc.) — nothing to do.
            return;
        }

        if (in_array($row->status, ['SENT'], true)) {
            return;
        }

        $row->forceFill([
            'status' => 'SENDING',
            'attempts' => $row->attempts + 1,
        ])->save();

        // Resolve the workspace's ZALO_OA channel account at job time, not
        // at dispatch time — the account may have been reconnected since
        // the row was queued.
        $account = ChannelAccount::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $row->workspace_id)
            ->where('provider', 'ZALO_OA')
            ->where('status', '!=', 'DISABLED')
            ->first();

        if ($account === null) {
            $this->markFailed($row, 'no_zalo_oa_account', false);

            return;
        }

        $params = $row->params ?? [];
        $templateId = (string) ($params['oa_template_id'] ?? '');
        if ($templateId === '') {
            $this->markFailed($row, 'template_id_missing', false);

            return;
        }

        // Strip our internal oa_template_id from the template_data payload —
        // Zalo OA's template_data is the user-facing parameter map (per the
        // template's variables), not the template id itself.
        $templateData = $params;
        unset($templateData['oa_template_id']);

        try {
            $result = app(ZaloOaAdapter::class)->sendTemplateMessage(
                $account,
                $row->oa_user_id,
                $templateId,
                $templateData,
            );
        } catch (Throwable $e) {
            $this->markFailed($row, 'EXCEPTION:'.$e->getMessage(), true);

            return;
        }

        if ($result['ok']) {
            $row->forceFill([
                'status' => 'SENT',
                'sent_at' => now(),
                'last_error' => null,
            ])->save();

            return;
        }

        $this->markFailed(
            $row,
            ($result['error_code'] ?? 'SEND_FAILED').': '.($result['error_message'] ?? ''),
            (bool) ($result['retryable'] ?? false),
        );
    }

    private function markFailed(OutboundMiniAppNotification $row, string $reason, bool $retryable): void
    {
        $shouldRetry = $retryable && $row->attempts < $this->tries;
        $row->forceFill([
            'status' => $shouldRetry ? 'RETRYING' : 'FAILED',
            'last_error' => mb_substr($reason, 0, 500),
        ])->save();

        if ($shouldRetry && $this->job) {
            $backoff = $this->backoff[$row->attempts - 1] ?? 60;
            $this->release($backoff);
        }
    }
}
