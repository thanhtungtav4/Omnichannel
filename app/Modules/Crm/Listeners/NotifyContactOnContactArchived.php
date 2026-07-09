<?php

namespace App\Modules\Crm\Listeners;

use App\Modules\Channels\Services\MiniAppOutboundNotifier;
use App\Modules\Crm\Events\ContactArchived;
use App\Modules\Crm\Models\Contact;
use Illuminate\Support\Facades\Log;

/**
 * Maps ContactArchived → Mini App template (spec 15 § C4).
 *
 * Sends a "we removed your data" template to the contact's Zalo OA
 * identity. The event fires BEFORE the row is hard-deleted (see
 * Crm\Http\Controllers\ContactController::destroy) so the listener
 * can still load the contact + identities.
 *
 * Best-effort: failures are logged and dropped. The archive itself
 * always succeeds.
 */
class NotifyContactOnContactArchived
{
    public function __construct(private readonly MiniAppOutboundNotifier $notifier) {}

    public function handle(ContactArchived $event): void
    {
        try {
            // Load with identities so the notifier can find the ZALO_OA one.
            $contact = Contact::query()
                ->with('identities')
                ->find($event->contactId);
            if ($contact === null) {
                return;
            }

            $this->notifier->notifyContact(
                $contact,
                'contact_archived',
                [
                    'archived_at' => now()->toIso8601String(),
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('NotifyContactOnContactArchived failed', [
                'contact_id' => $event->contactId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
