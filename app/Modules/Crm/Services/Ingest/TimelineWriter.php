<?php

namespace App\Modules\Crm\Services\Ingest;

use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\TimelineActivity;

/**
 * Writes `timeline_activities` rows for contact ingestion events.
 *
 * Why a dedicated writer (and not inline in ContactIngestor): the verb
 * shape, metadata shape, and "skip if matched" rule need to stay consistent
 * across all ingestion paths (webhook today, web form + Mini App in C4).
 * One place to bump them.
 */
final class TimelineWriter
{
    /**
     * Record a CONTACT_INGESTED timeline entry. Only fires when a NEW
     * contact was created; matches don't pollute the timeline with
     * duplicates.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordIngest(Contact $contact, array $payload): void
    {
        TimelineActivity::create([
            'workspace_id' => $contact->workspace_id,
            // actor_id intentionally null — system action.
            'subject_type' => 'crm.contact',
            'subject_id' => $contact->id,
            'module' => 'crm',
            'type' => 'CONTACT_INGESTED',
            'title' => 'Contact ingested from '.$payload['source'],
            'body' => $payload['source_detail'] ?? null,
            'metadata' => array_filter([
                'source' => $payload['source'] ?? null,
                'source_detail' => $payload['source_detail'] ?? null,
                'ingest_event_id' => $payload['ingest_event_id'] ?? null,
                'identity_provider' => $payload['external_identity']['provider'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            'occurred_at' => $payload['last_inbound_at'] ?? now(),
        ]);
    }
}