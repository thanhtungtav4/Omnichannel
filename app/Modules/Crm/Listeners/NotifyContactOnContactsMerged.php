<?php

namespace App\Modules\Crm\Listeners;

use App\Modules\Channels\Services\MiniAppOutboundNotifier;
use App\Modules\Crm\Events\ContactsMerged;
use App\Modules\Crm\Models\Contact;
use Illuminate\Support\Facades\Log;

/**
 * Maps ContactsMerged → Mini App template (spec 15 § C5 follow-through).
 *
 * Sends a "your accounts have been combined" template to the surviving
 * contact's Zalo OA identity. Best-effort: failures are logged and
 * dropped — the merge itself already committed and we don't want
 * to surface notify failures.
 */
class NotifyContactOnContactsMerged
{
    public function __construct(private readonly MiniAppOutboundNotifier $notifier) {}

    public function handle(ContactsMerged $event): void
    {
        try {
            $contact = Contact::query()->find($event->winnerId);
            if ($contact === null) {
                return;
            }

            $this->notifier->notifyContact(
                $contact,
                'contacts_merged',
                [
                    'merged_count' => count($event->loserIds),
                    'merged_ids' => $event->loserIds,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('NotifyContactOnContactsMerged failed', [
                'winner_id' => $event->winnerId,
                'loser_count' => count($event->loserIds),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
