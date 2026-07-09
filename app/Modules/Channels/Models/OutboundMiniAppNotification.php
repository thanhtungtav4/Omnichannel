<?php

namespace App\Modules\Channels\Models;

use App\Modules\Crm\Models\Contact;
use App\Modules\Platform\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per Mini App template-message attempt (spec 15 § C4).
 *
 * Lifecycle:
 *   QUEUED  — MiniAppOutboundNotifier::notifyContact wrote the row and
 *             dispatched MiniAppNotificationJob (pending).
 *   SENT    — adapter returned ok=true.
 *   FAILED  — adapter returned ok=false after retries; last_error populated.
 *
 * Read-only after creation. Status flips via the job; no UI editing path.
 */
class OutboundMiniAppNotification extends Model
{
    use BelongsToWorkspace, HasUuids;

    /**
     * The migration uses `queued_at` + `sent_at` instead of Laravel's
     * default created_at / updated_at columns. Disabling timestamps tells
     * Eloquent not to try to stamp them.
     */
    public $timestamps = false;

    /**
     * Override Laravel's pluralization — `OutboundMiniAppNotification` would
     * otherwise become `outbound_mini_app_notifications`. The migration
     * names the table `outbound_miniapp_notifications` to match the spec.
     */
    protected $table = 'outbound_miniapp_notifications';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function channelAccount(): BelongsTo
    {
        // No FK because the workspace's ZALO_OA channel account is not
        // necessarily tied to a single notification row — we resolve it
        // by (workspace_id, provider=ZALO_OA) at job time.
        return $this->belongsTo(ChannelAccount::class);
    }
}
