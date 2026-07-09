<?php

namespace App\Modules\Crm\Events;

use App\Modules\Crm\Models\Contact;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A contact was archived / deleted (spec 15 § C4).
 *
 * Fired by Crm\Http\Controllers\ContactController::destroy. The Mini App
 * listener maps this to a "we removed your data" template so the user
 * gets a heads-up — required by VN PDPA-ish norms even if our legal
 * stance doesn't formalize it.
 *
 * The event fires BEFORE the row is deleted so the listener can read
 * the contact's identities + workspace. After the listener returns,
 * the row is gone.
 */
class ContactArchived
{
    use Dispatchable;

    public function __construct(
        public readonly string $contactId,
        public readonly string $workspaceId,
    ) {}

    public static function fromContact(Contact $contact): self
    {
        return new self($contact->id, (string) $contact->workspace_id);
    }
}
