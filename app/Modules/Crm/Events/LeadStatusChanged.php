<?php

namespace App\Modules\Crm\Events;

use App\Modules\Crm\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A lead moved to a new pipeline status (spec 15 § C4).
 *
 * The Mini App listener uses this to dispatch a template notification
 * to the lead's contact when the status change is meaningful (WON/LOST)
 * AND the operator checked `notify_user` on the update dialog.
 *
 * Dispatched from Crm\Http\Controllers\LeadController::updateStatus.
 */
class LeadStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $leadId,
        public readonly string $previousStatus,
        public readonly string $newStatus,
        public readonly bool $notifyUser,
    ) {}

    public static function fromLead(Lead $lead, string $previousStatus, bool $notifyUser): self
    {
        return new self(
            $lead->id,
            $previousStatus,
            (string) $lead->status,
            $notifyUser,
        );
    }
}
