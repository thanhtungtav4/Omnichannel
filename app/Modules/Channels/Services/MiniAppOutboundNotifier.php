<?php

namespace App\Modules\Channels\Services;

use App\Modules\Channels\Jobs\MiniAppNotificationJob;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboundMiniAppNotification;
use App\Modules\Crm\Models\Contact;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Platform\Services\WorkspaceSettings;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * CRM → Mini App re-engagement chokepoint (spec 15 § C4).
 *
 * Callers (event listeners, controllers) hit `notifyContact()` and let
 * this service decide whether to dispatch. The whole point is to keep
 * "is this contact notify-able?" out of every callsite — the rule is
 * "contact must have a ZALO_OA identity, AND the workspace must have a
 * ZALO_OA channel account, AND the template code must be configured".
 *
 * No-ops silently: if any of the above fail, the call returns false
 * and nothing is queued. Listeners must treat notify_contact as
 * best-effort — never block a domain event on a Mini App send.
 *
 * Why this lives in Channels\\Services (not Crm): the actual SEND goes
 * through the ZaloOaAdapter (Channels-owned). Putting the orchestrator
 * next to the adapter keeps the dependency arrow one-way.
 *
 * Not `final` — listener tests swap a recording stub via the container.
 */
class MiniAppOutboundNotifier
{
    /**
     * Try to notify the contact via their Zalo Mini App.
     *
     * Returns the queued notification row, or null when the contact
     * cannot be notified (no OA identity / no OA account / no template
     * mapping). The audit row IS created even when no queue job runs —
     * ops can see "we tried to notify this contact for this template but
     * the workspace isn't set up".
     */
    public function notifyContact(Contact $contact, string $templateCode, array $params = []): ?OutboundMiniAppNotification
    {
        // 1. Resolve the OA recipient id (Zalo-side user_id, not our internal id).
        $oaIdentity = $contact->identities->firstWhere('provider', 'ZALO_OA');
        if ($oaIdentity === null) {
            $this->auditNoOp($contact, $templateCode, 'no_zalo_oa_identity');

            return null;
        }
        $oaUserId = (string) $oaIdentity->provider_user_id;

        // 2. Resolve the workspace's ZALO_OA channel account. If the
        // workspace hasn't connected a Zalo OA yet, no-op.
        $account = ChannelAccount::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $contact->workspace_id)
            ->where('provider', 'ZALO_OA')
            ->where('status', '!=', 'DISABLED')
            ->first();
        if ($account === null) {
            $this->auditNoOp($contact, $templateCode, 'no_zalo_oa_account');

            return null;
        }

        // 3. Resolve the template id from workspace_settings.miniapp.templates.
        $templateId = $this->resolveTemplateId($contact->workspace_id, $templateCode);
        if ($templateId === null) {
            $this->auditNoOp($contact, $templateCode, 'template_not_configured');

            return null;
        }

        // 4. Per-workspace rate limit (spec 15 § C4 outbound safety).
        //    Keyed by workspace_id, not contact_id, because a misbehaving
        //    trigger surface (e.g. a script firing LeadStatusChanged in a
        //    loop) would otherwise burn through the bucket per-contact.
        //    Runs LAST so a workspace without a configured OA isn't
        //    punished for its first N attempts.
        $rateKey = 'miniapp:outbound:'.$contact->workspace_id;
        if (RateLimiter::tooManyAttempts($rateKey, AppServiceProvider::miniappOutboundLimit())) {
            $this->auditNoOp($contact, $templateCode, 'rate_limited');

            return null;
        }
        RateLimiter::hit($rateKey, 60);

        // 4. Queue the audit row + dispatch the job.
        $row = OutboundMiniAppNotification::create([
            'workspace_id' => $contact->workspace_id,
            'contact_id' => $contact->id,
            'oa_user_id' => $oaUserId,
            'template_code' => $templateCode,
            'params' => array_merge(['oa_template_id' => $templateId], $params),
            'status' => 'QUEUED',
            'attempts' => 0,
            'queued_at' => now(),
        ]);

        MiniAppNotificationJob::dispatch($row->id);

        return $row;
    }

    /**
     * Look up the OA template id for this template_code in the workspace's
     * settings. Returns null when no mapping exists or the mapping is
     * malformed — the caller treats null as "we can't notify, log + skip".
     */
    private function resolveTemplateId(string $workspaceId, string $templateCode): ?string
    {
        $workspace = Workspace::query()->find($workspaceId);
        if ($workspace === null) {
            return null;
        }

        $settings = app(WorkspaceSettings::class);
        $mapping = $settings->get($workspace, 'miniapp.templates', []);

        if (! is_array($mapping) || ! isset($mapping[$templateCode])) {
            return null;
        }

        $entry = $mapping[$templateCode];
        if (! is_array($entry) || empty($entry['oa_template_id'])) {
            return null;
        }

        return (string) $entry['oa_template_id'];
    }

    /**
     * Write a FAILED audit row when we decline to queue. This is the
     * "no_op" trail ops can grep to find "why didn't the contact get
     * this notification?". The job isn't dispatched because there's
     * nothing useful for it to do.
     */
    private function auditNoOp(Contact $contact, string $templateCode, string $reason): void
    {
        try {
            OutboundMiniAppNotification::create([
                'workspace_id' => $contact->workspace_id,
                'contact_id' => $contact->id,
                'oa_user_id' => '',
                'template_code' => $templateCode,
                'params' => ['reason' => $reason],
                'status' => 'FAILED',
                'last_error' => $reason,
                'attempts' => 0,
                'queued_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Audit failures must never block the request.
            Log::warning('MiniAppOutboundNotifier: no-op audit failed', [
                'contact' => $contact->id,
                'template' => $templateCode,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
