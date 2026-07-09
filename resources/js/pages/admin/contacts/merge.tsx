import { Head, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, GitMerge, Search } from 'lucide-react';
import { useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';

/**
 * Spec 15 § C5 — contacts merge UI.
 *
 * Layout:
 *   1. Duplicate groups (server-computed) — one card per cluster.
 *      Each cluster has a "winner_suggestion" (earliest-created) plus
 *      candidates. User picks winners + losers per cluster.
 *   2. Preview panel — side-by-side comparison using the controller's
 *      preview endpoint (no DB writes; read-only diff).
 *   3. Commit button — POSTs to /api/admin/contacts/{winner}/merge.
 *
 * Conflict policy is encoded server-side in ContactMerger; this UI
 * just shows what the policy will produce so the user can sanity-
 * check before committing.
 */

type ContactSummary = {
    id: string;
    full_name: string;
    phone: string | null;
    phone_normalized: string | null;
    email: string | null;
    source: string;
    status: string;
    created_at: string | null;
    last_inbound_at: string | null;
    identities_count: number;
};

type DuplicateGroup = {
    match_key: string;
    match_type: 'phone' | 'email';
    winner_suggestion: ContactSummary;
    candidates: ContactSummary[];
    count: number;
};

type MergedFields = {
    full_name: string;
    phone: string | null;
    phone_normalized: string | null;
    email: string | null;
    avatar_url: string | null;
    tags: string[];
    status: string;
    owner_id: number | null;
    last_inbound_at: string | null;
    attributes: Record<string, unknown>;
    consent_given_at: string | null;
    consent_text: string | null;
    consent_ip: string | null;
    consent_user_agent: string | null;
    source_detail: string | null;
};

type PreviewPayload = {
    winner_id: string;
    loser_ids: string[];
    merged_fields: MergedFields;
    diffs: Record<string, { winner: unknown; losers: { id: string; name: string | null; value: unknown }[]; merged: unknown }>;
};

type Props = {
    duplicateGroups: DuplicateGroup[];
};

export default function ContactsMergePage({ duplicateGroups }: Props) {
    // Selected contact ids: which ones will be losers in the merge.
    // The winner is the first id in the list, also selectable but it
    // doesn't really make sense to "merge into yourself" — we surface
    // that as a separate validation in the controller.
    const [pickedWinnerId, setPickedWinnerId] = useState<string | null>(null);
    const [pickedLoserIds, setPickedLoserIds] = useState<string[]>([]);
    const [preview, setPreview] = useState<PreviewPayload | null>(null);
    const [previewing, setPreviewing] = useState(false);
    const [committing, setCommitting] = useState(false);

    function toggleLoser(id: string) {
        if (id === pickedWinnerId) {
return;
}

        setPickedLoserIds((prev) =>
            prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id],
        );
        // Preview is stale — drop it.
        setPreview(null);
    }

    function pickWinner(id: string) {
        setPickedWinnerId(id);
        // Drop losers that are no longer in the same cluster as the new winner.
        const cluster = duplicateGroups.find((g) =>
            g.candidates.some((c) => c.id === id),
        );
        const validIds = cluster
            ? new Set(cluster.candidates.map((c) => c.id))
            : new Set([id]);
        setPickedLoserIds((prev) => prev.filter((x) => validIds.has(x) && x !== id));
        setPreview(null);
    }

    async function loadPreview() {
        if (!pickedWinnerId || pickedLoserIds.length === 0) {
return;
}

        setPreviewing(true);

        try {
            const res = await fetch(
                `/api/admin/contacts/${pickedWinnerId}/merge/preview`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ loser_ids: pickedLoserIds }),
                },
            );

            if (!res.ok) {
                setPreview(null);

                return;
            }

            const body = (await res.json()) as { data: PreviewPayload };
            setPreview(body.data);
        } finally {
            setPreviewing(false);
        }
    }

    function commit() {
        if (!pickedWinnerId || pickedLoserIds.length === 0) {
return;
}

        setCommitting(true);
        router.post(
            `/api/admin/contacts/${pickedWinnerId}/merge`,
            { loser_ids: pickedLoserIds },
            {
                preserveScroll: true,
                onFinish: () => setCommitting(false),
            },
        );
    }

    return (
        <>
            <Head title="Gộp liên hệ" />
            <div className="space-y-6 p-6">
                <div className="space-y-1">
                    <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                        <GitMerge className="h-5 w-5" />
                        Gộp liên hệ
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Khi cùng một khách hàng xuất hiện ở nhiều nguồn (web
                        form + Zalo OA Mini App + Zalo cá nhân), gộp lại để
                        hồ sơ gọn gàng. Liên hệ thắng giữ hội thoại + leads;
                        các liên hệ thua bị xóa.
                    </p>
                </div>

                <Alert>
                    <AlertTriangle className="h-4 w-4" />
                    <AlertTitle>Không hoàn tác</AlertTitle>
                    <AlertDescription className="text-sm">
                        Sau khi gộp, các liên hệ thua bị xóa vĩnh viễn.
                        Timeline audit (timeline_activities) vẫn giữ nguyên
                        — mọi sự kiện lịch sử vẫn thuộc về người tạo.
                    </AlertDescription>
                </Alert>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Liên hệ trùng</CardTitle>
                            <CardDescription>
                                Hệ thống tìm theo phone_normalized (ưu tiên)
                                và email (case-insensitive). Liên hệ cũ nhất
                                trong mỗi cụm được gợi ý làm winner.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {duplicateGroups.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    Không có cụm trùng lặp nào trong workspace
                                    này.
                                </p>
                            )}

                            {duplicateGroups.map((g) => (
                                <DuplicateGroupCard
                                    key={g.match_key}
                                    group={g}
                                    pickedWinnerId={pickedWinnerId}
                                    pickedLoserIds={pickedLoserIds}
                                    onPickWinner={pickWinner}
                                    onToggleLoser={toggleLoser}
                                />
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Preview trước khi gộp</CardTitle>
                            <CardDescription>
                                Server-side preview — không ghi DB. Kiểm tra
                                các trường trước khi commit.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {!pickedWinnerId ||
                            pickedLoserIds.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Chọn winner + ít nhất 1 loser ở cột bên
                                    trái.
                                </p>
                            ) : (
                                <>
                                    <Button
                                        onClick={loadPreview}
                                        disabled={previewing}
                                        variant="outline"
                                        className="w-full"
                                    >
                                        <Search data-icon="inline-start" />
                                        {previewing
                                            ? 'Đang tính preview…'
                                            : 'Xem trước kết quả gộp'}
                                    </Button>

                                    {preview && (
                                        <PreviewPanel preview={preview} />
                                    )}

                                    {preview && (
                                        <Button
                                            onClick={commit}
                                            disabled={committing}
                                            className="w-full"
                                        >
                                            <CheckCircle2 data-icon="inline-start" />
                                            {committing
                                                ? 'Đang gộp…'
                                                : `Gộp ${pickedLoserIds.length} liên hệ vào winner`}
                                        </Button>
                                    )}
                                </>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

function DuplicateGroupCard({
    group,
    pickedWinnerId,
    pickedLoserIds,
    onPickWinner,
    onToggleLoser,
}: {
    group: DuplicateGroup;
    pickedWinnerId: string | null;
    pickedLoserIds: string[];
    onPickWinner: (id: string) => void;
    onToggleLoser: (id: string) => void;
}) {
    return (
        <div className="rounded-lg border border-border bg-card/50 p-4">
            <div className="mb-3 flex items-center justify-between">
                <span className="text-xs uppercase tracking-wide text-muted-foreground">
                    Trùng {group.match_type === 'phone' ? 'SĐT' : 'Email'} ·{' '}
                    {group.count} liên hệ
                </span>
            </div>
            <ul className="space-y-2">
                {group.candidates.map((c) => {
                    const isWinner = pickedWinnerId === c.id;
                    const isLoser = pickedLoserIds.includes(c.id);

                    return (
                        <li
                            key={c.id}
                            className={
                                'flex items-start gap-2 rounded-md border p-2 ' +
                                (isWinner
                                    ? 'border-emerald-500 bg-emerald-50/40 dark:bg-emerald-950/20'
                                    : isLoser
                                      ? 'border-amber-500 bg-amber-50/40 dark:bg-amber-950/20'
                                      : 'border-border')
                            }
                        >
                            <div className="flex flex-col gap-1.5 pt-0.5">
                                <label className="flex items-center gap-1.5 text-xs">
                                    <input
                                        type="radio"
                                        name={`winner-${group.match_key}`}
                                        checked={isWinner}
                                        onChange={() => onPickWinner(c.id)}
                                        className="h-3.5 w-3.5 accent-emerald-600"
                                    />
                                    <span className="text-emerald-700 dark:text-emerald-400">
                                        Winner
                                    </span>
                                </label>
                                <label className="flex items-center gap-1.5 text-xs">
                                    <Checkbox
                                        checked={isLoser}
                                        onCheckedChange={() =>
                                            onToggleLoser(c.id)
                                        }
                                    />
                                    <span className="text-amber-700 dark:text-amber-400">
                                        Loser
                                    </span>
                                </label>
                            </div>
                            <div className="flex-1 space-y-0.5">
                                <div className="text-sm font-medium">
                                    {c.full_name}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {c.phone || c.email || '—'} · {c.source}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {c.identities_count} identity
                                    {c.last_inbound_at && (
                                        <>
                                            {' · '}
                                            last inbound{' '}
                                            {new Date(
                                                c.last_inbound_at,
                                            ).toLocaleString()}
                                        </>
                                    )}
                                </div>
                            </div>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

function PreviewPanel({ preview }: { preview: PreviewPayload }) {
    const fields = preview.merged_fields;
    const rows: Array<[string, string]> = [
        ['Họ tên', fields.full_name],
        [
            'SĐT',
            fields.phone ? `${fields.phone}${fields.phone_normalized ? ` (${fields.phone_normalized})` : ''}` : '—',
        ],
        ['Email', fields.email ?? '—'],
        ['Avatar', fields.avatar_url ?? '—'],
        ['Tags', fields.tags.join(', ') || '—'],
        ['Status', fields.status],
        ['Source detail', fields.source_detail ?? '—'],
        [
            'Last inbound',
            fields.last_inbound_at
                ? new Date(fields.last_inbound_at).toLocaleString()
                : '—',
        ],
        [
            'Consent given',
            fields.consent_given_at
                ? new Date(fields.consent_given_at).toLocaleString()
                : '—',
        ],
        ['Consent IP', fields.consent_ip ?? '—'],
        ['Consent UA', fields.consent_user_agent ?? '—'],
    ];

    return (
        <div className="space-y-3">
            <h4 className="text-sm font-semibold">Sau khi gộp</h4>
            <dl className="divide-y divide-border rounded-md border border-border text-sm">
                {rows.map(([label, value]) => (
                    <div
                        key={label}
                        className="flex items-start gap-3 px-3 py-2"
                    >
                        <dt className="w-32 shrink-0 text-xs text-muted-foreground">
                            {label}
                        </dt>
                        <dd className="flex-1 break-all">
                            <code className="font-mono text-xs">{value}</code>
                        </dd>
                    </div>
                ))}
            </dl>

            {Object.keys(preview.diffs).length > 0 && (
                <details className="rounded-md border border-border p-3 text-xs">
                    <summary className="cursor-pointer font-medium">
                        {Object.keys(preview.diffs).length} trường có xung đột
                        (xem diff chi tiết)
                    </summary>
                    <div className="mt-2 space-y-2">
                        {Object.entries(preview.diffs).map(([field, d]) => (
                            <div key={field}>
                                <div className="font-medium">{field}</div>
                                <div className="ml-2 text-muted-foreground">
                                    winner: {formatValue(d.winner)}
                                </div>
                                <div className="ml-2 text-muted-foreground">
                                    losers:{' '}
                                    {d.losers
                                        .map(
                                            (l) =>
                                                `${l.name ?? l.id} = ${formatValue(l.value)}`,
                                        )
                                        .join(' | ')}
                                </div>
                                <div className="ml-2 text-emerald-700 dark:text-emerald-400">
                                    → merged: {formatValue(d.merged)}
                                </div>
                            </div>
                        ))}
                    </div>
                </details>
            )}
        </div>
    );
}

function formatValue(v: unknown): string {
    if (v === null || v === undefined) {
return '∅';
}

    if (typeof v === 'string') {
return v;
}

    return JSON.stringify(v);
}

function csrfToken(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}