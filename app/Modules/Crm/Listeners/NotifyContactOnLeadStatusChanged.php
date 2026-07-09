<?php

namespace App\Modules\Crm\Listeners;

use App\Modules\Channels\Services\MiniAppOutboundNotifier;
use App\Modules\Crm\Events\LeadStatusChanged;
use App\Modules\Crm\Models\Lead;
use Illuminate\Support\Facades\Log;

/**
 * Maps LeadStatusChanged → Mini App template (spec 15 § C4).
 *
 * Only meaningful transitions trigger a notification:
 *   anything → WON  → "lead_won" template
 *   anything → LOST → "lead_lost" template
 *
 * The user toggles `notify_user` on the kanban update dialog; if they
 * don't check it, we don't fire. Best-effort: notify failures don't
 * block or retry the lead update.
 */
class NotifyContactOnLeadStatusChanged
{
    /**
     * Status transitions that justify a Mini App template.
     */
    private const NOTIFIABLE_TARGETS = [
        'WON' => 'lead_won',
        'LOST' => 'lead_lost',
    ];

    public function __construct(private readonly MiniAppOutboundNotifier $notifier) {}

    public function handle(LeadStatusChanged $event): void
    {
        if (! $event->notifyUser) {
            return;
        }

        $templateCode = self::NOTIFIABLE_TARGETS[$event->newStatus] ?? null;
        if ($templateCode === null) {
            return;
        }

        try {
            $lead = Lead::query()->with('contact')->find($event->leadId);
            if ($lead === null || $lead->contact === null) {
                return;
            }

            $this->notifier->notifyContact(
                $lead->contact,
                $templateCode,
                [
                    'lead_id' => $lead->id,
                    'lead_title' => $lead->title,
                    'previous_status' => $event->previousStatus,
                    'new_status' => $event->newStatus,
                ],
            );
        } catch (\Throwable $e) {
            // Best-effort — log and move on. The lead update already
            // committed; we don't want to surface notify failures.
            Log::warning('NotifyContactOnLeadStatusChanged failed', [
                'lead_id' => $event->leadId,
                'template' => $templateCode,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
