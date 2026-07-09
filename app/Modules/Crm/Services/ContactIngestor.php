<?php

namespace App\Modules\Crm\Services;

use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ContactIngestEvent;
use App\Modules\Crm\Models\ExternalIdentity;
use App\Modules\Crm\Services\Ingest\IdentityMatcher;
use App\Modules\Crm\Services\Ingest\OwnerResolver;
use App\Modules\Crm\Services\Ingest\TimelineWriter;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * Single chokepoint for creating or matching a Contact across every
 * ingestion source (Zalo/Telegram today, website forms + Zalo Mini App in
 * C4). Owns the dedup, identity-match, contact-create, attributes/consent
 * write, owner resolution, ingest-event audit, and timeline record.
 *
 * NOT a DB-transaction owner: callers (InboundMessageIngestor, the public
 * ingest endpoint in C3) wrap the call in their own transaction so the
 * contact row rolls back if a sibling write (lead, conversation, message)
 * fails. ContactIngestor just does its writes inside whatever transaction
 * the caller is in.
 *
 * ponytail: we deliberately do NOT throw on dedup-hit — callers want the
 * existing Contact back, not an exception. The dedup record IS written so
 * the unique key keeps a paper trail even on the no-op path.
 */
final class ContactIngestor
{
    /** Public-source constants — used for per-source validation rules. */
    public const SOURCE_WEBSITE_FORM = 'WEBSITE_FORM';

    public const SOURCE_ZALO_MINIAPP = 'ZALO_MINIAPP';

    public const ALLOWED_PUBLIC_SOURCES = [
        self::SOURCE_WEBSITE_FORM,
        self::SOURCE_ZALO_MINIAPP,
    ];

    public function __construct(
        private readonly IdentityMatcher $identities,
        private readonly OwnerResolver $owners,
        private readonly TimelineWriter $timeline,
    ) {}

    /**
     * Find-or-create a Contact for an ingestion event.
     *
     * @param  array{
     *     workspace_id: string,
     *     source: string,
     *     source_detail?: ?string,
     *     full_name?: ?string,
     *     phone?: ?string,
     *     email?: ?string,
     *     avatar_url?: ?string,
     *     external_identity?: ?array{provider: string, provider_account_id: string, provider_user_id: string, display_name?: ?string, avatar_url?: ?string},
     *     attributes?: array,
     *     consent?: ?array{given_at?: ?string, text?: ?string, ip?: ?string, user_agent?: ?string},
     *     owner_id?: ?int,
     *     last_inbound_at?: ?\DateTimeInterface|string,
     *     ingest_event_id?: ?string,
     * }  $payload
     */
    public function ingest(array $payload): Contact
    {
        return $this->ingestWithStatus($payload)['contact'];
    }

    /**
     * Same flow as ingest() but exposes the outcome flags so callers can
     * tell dedup-hit / matched-update / new-create apart (the public
     * ingest endpoint uses this to return 201 vs 200).
     *
     * @param  array<string, mixed>  $payload  same shape as ingest()
     * @return array{contact: Contact, created: bool, dedup_hit: bool}
     */
    public function ingestWithStatus(array $payload): array
    {
        // ---- 1. dedup ----------------------------------------------------
        // If the same (source, source_event_id) was already ingested, return
        // the contact that owns it. We deliberately do NOT throw — callers
        // want the existing Contact, not an exception, so they can keep
        // writing the message/conversation against the same row.
        $dedup = $this->dedupHit($payload);
        if ($dedup !== null) {
            return ['contact' => $dedup, 'created' => false, 'dedup_hit' => true];
        }

        // ---- 2. match ----------------------------------------------------
        $contact = $this->identities->match($payload);

        $isNew = $contact === null;

        if ($isNew) {
            $contact = Contact::create(array_filter([
                'workspace_id' => $payload['workspace_id'],
                'source' => $payload['source'],
                'source_detail' => $payload['source_detail'] ?? null,
                'full_name' => $payload['full_name'] ?? null,
                'phone' => $payload['phone'] ?? null,
                'email' => $payload['email'] ?? null,
                'avatar_url' => $payload['avatar_url'] ?? null,
                'attributes' => $payload['attributes'] ?? [],
                'consent_given_at' => $payload['consent']['given_at'] ?? null,
                'consent_text' => $payload['consent']['text'] ?? null,
                'consent_ip' => $payload['consent']['ip'] ?? null,
                'consent_user_agent' => $payload['consent']['user_agent'] ?? null,
                'owner_id' => $payload['owner_id'] ?? null,
                'last_inbound_at' => $payload['last_inbound_at'] ?? null,
                'status' => 'ACTIVE',
            ], fn ($v) => $v !== null && $v !== ''));
        } else {
            // Matched — refresh fields that are source-trustable and
            // missing on the existing row. Don't overwrite name/avatar
            // blindly: callers (e.g. InboundMessageIngestor) do their own
            // targeted updates for those.
            $this->updateMatchedContact($contact, $payload);
        }

        // ---- 3. identity row --------------------------------------------
        // If the payload carries an external_identity and we don't already
        // have that exact identity for the contact, attach it. The unique
        // index on (workspace, provider, provider_account_id,
        // provider_user_id) protects against duplicate inserts if two
        // concurrent ingestions race.
        $identity = $payload['external_identity'] ?? null;
        if ($identity && ! empty($identity['provider']) && ! empty($identity['provider_account_id']) && ! empty($identity['provider_user_id'])) {
            $this->attachIdentity($contact, $identity);
        }

        // ---- 4. owner ----------------------------------------------------
        // For a brand-new contact, OwnerResolver decides who owns it. For a
        // match, we don't change ownership here — the caller (or a
        // deliberate UI action) is responsible for reassignment.
        if ($isNew) {
            $workspace = Workspace::query()->findOrFail($payload['workspace_id']);
            $ownerId = $this->owners->resolve($payload, $workspace);
            if ($ownerId !== null && $contact->owner_id === null) {
                $contact->forceFill(['owner_id' => $ownerId])->save();
            }
        }

        // ---- 5. ingest_events -------------------------------------------
        if (! empty($payload['ingest_event_id'])) {
            $this->recordIngestEvent($contact, $payload);
        }

        // ---- 6. timeline -------------------------------------------------
        if ($isNew) {
            $this->timeline->recordIngest($contact, $payload);
        }

        return ['contact' => $contact->fresh(), 'created' => $isNew, 'dedup_hit' => false];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dedupHit(array $payload): ?Contact
    {
        $eventId = $payload['ingest_event_id'] ?? null;
        if (! is_string($eventId) || $eventId === '') {
            return null;
        }

        $row = ContactIngestEvent::query()
            ->where('workspace_id', $payload['workspace_id'])
            ->where('source', $payload['source'])
            ->where('source_event_id', $eventId)
            ->first();

        return $row?->contact;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function updateMatchedContact(Contact $contact, array $payload): void
    {
        $updates = [];

        // Source-detail: only fill if empty (first-write-wins).
        if (empty($contact->source_detail) && ! empty($payload['source_detail'])) {
            $updates['source_detail'] = $payload['source_detail'];
        }

        // Attributes: shallow merge, payload wins. Keeps existing keys the
        // payload doesn't carry (a Telegram contact that later gets a
        // website-form submission keeps its provider_user_id if the form
        // doesn't include it).
        $existingAttrs = $contact->attributes ?? [];
        $incomingAttrs = $payload['attributes'] ?? [];
        if (! empty($incomingAttrs)) {
            $updates['attributes'] = array_merge($existingAttrs, $incomingAttrs);
        }

        // Consent: only fill fields that are still null. Consent is a
        // first-write-wins surface — a later ingest that didn't capture
        // consent doesn't overwrite the original capture.
        $consent = $payload['consent'] ?? [];
        if (! empty($consent)) {
            if (empty($contact->consent_given_at) && ! empty($consent['given_at'])) {
                $updates['consent_given_at'] = $consent['given_at'];
            }
            if (empty($contact->consent_text) && ! empty($consent['text'])) {
                $updates['consent_text'] = $consent['text'];
            }
            if (empty($contact->consent_ip) && ! empty($consent['ip'])) {
                $updates['consent_ip'] = $consent['ip'];
            }
            if (empty($contact->consent_user_agent) && ! empty($consent['user_agent'])) {
                $updates['consent_user_agent'] = $consent['user_agent'];
            }
        }

        // Phone / email: only fill if currently empty (a Zalo-derived
        // phone is the same source of truth as a web form phone, so we
        // don't overwrite a verified one with an unverified one).
        if (empty($contact->phone) && ! empty($payload['phone'])) {
            $updates['phone'] = $payload['phone'];
        }
        if (empty($contact->email) && ! empty($payload['email'])) {
            $updates['email'] = $payload['email'];
        }

        // last_inbound_at: take the latest so the dashboard sorts correctly.
        $inboundAt = $payload['last_inbound_at'] ?? null;
        if ($inboundAt !== null) {
            $incoming = $inboundAt instanceof Carbon ? $inboundAt : Carbon::parse($inboundAt);
            $existing = $contact->last_inbound_at;
            if ($existing === null || $incoming->greaterThan($existing)) {
                $updates['last_inbound_at'] = $incoming;
            }
        }

        if (! empty($updates)) {
            $contact->forceFill($updates)->save();
        }
    }

    /**
     * @param  array{provider: string, provider_account_id: string, provider_user_id: string, display_name?: ?string, avatar_url?: ?string}  $identity
     */
    private function attachIdentity(Contact $contact, array $identity): void
    {
        // firstOrCreate is cleaner than try/catch: the unique index is the
        // truth, but we probe before INSERT so concurrent ingestions don't
        // race the DB into raising an exception in the middle of an outer
        // transaction (where it would abort the whole ingest).
        ExternalIdentity::query()->withoutWorkspaceScope()->firstOrCreate(
            [
                'workspace_id' => $contact->workspace_id,
                'provider' => $identity['provider'],
                'provider_account_id' => $identity['provider_account_id'],
                'provider_user_id' => $identity['provider_user_id'],
            ],
            [
                'contact_id' => $contact->id,
                'display_name' => $identity['display_name'] ?? null,
                'avatar_url' => $identity['avatar_url'] ?? null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordIngestEvent(Contact $contact, array $payload): void
    {
        // sha256 of the canonical payload. Public ingest callers (Cut 3) pass
        // `client_attributes` separately from server-augmented `attributes`
        // so retries with the same client payload hash identically. Older
        // paths (Zalo/Telegram webhook) only carry `attributes` — we hash
        // on whatever's in `client_attributes`, falling back to `attributes`.
        $hashable = [
            'source' => $payload['source'],
            'source_detail' => $payload['source_detail'] ?? null,
            'full_name' => $payload['full_name'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'email' => $payload['email'] ?? null,
            'external_identity' => $payload['external_identity'] ?? null,
            'attributes' => $payload['client_attributes'] ?? ($payload['attributes'] ?? []),
        ];
        $hash = hash('sha256', json_encode($hashable, JSON_THROW_ON_ERROR));

        // updateOrCreate is preferred over try/catch UniqueConstraintViolation:
        // the unique index is the truth, and probing first avoids aborting an
        // outer transaction on a collision (Postgres aborts the whole tx on
        // any constraint violation).
        ContactIngestEvent::query()->withoutWorkspaceScope()->updateOrCreate(
            [
                'workspace_id' => $contact->workspace_id,
                'source' => $payload['source'],
                'source_event_id' => $payload['ingest_event_id'],
            ],
            [
                'contact_id' => $contact->id,
                'payload_hash' => $hash,
                'ip' => $payload['consent']['ip'] ?? null,
                'user_agent' => $payload['consent']['user_agent'] ?? null,
                'received_at' => now(),
            ],
        );
    }
}