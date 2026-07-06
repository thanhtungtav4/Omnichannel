import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, GripVertical } from 'lucide-react';
import { type DragEvent, useState } from 'react';
import { StatusBadge } from '@/components/admin/status-badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type Lead = {
    id: string;
    title: string;
    status: string;
    source?: string | null;
    valueAmount?: number | null;
    contact?: string | null;
    contactId?: string | null;
    owner?: string | null;
    lastActivityAt?: string | null;
};

type Props = {
    columns: string[];
    leadsByStatus: Record<string, Lead[]>;
};

const COLUMN_LABELS: Record<string, string> = {
    NEW: 'Mới',
    QUALIFYING: 'Đang tư vấn',
    OPEN: 'Quan tâm',
    WON: 'Chốt',
    LOST: 'Mất',
};

function money(v?: number | null) {
    return v === null || v === undefined ? '—' : Number(v).toLocaleString('vi-VN');
}

export default function Leads({ columns, leadsByStatus }: Props) {
    const [dragOver, setDragOver] = useState<string | null>(null);

    function onDrop(event: DragEvent, status: string) {
        event.preventDefault();
        setDragOver(null);
        const id = event.dataTransfer.getData('text/lead-id');
        const from = event.dataTransfer.getData('text/lead-status');
        if (!id || from === status) return;
        router.put(
            `/api/admin/leads/${id}/status`,
            { status },
            { preserveScroll: true, preserveState: false },
        );
    }

    return (
        <>
            <Head title="Lead pipeline" />
            <main className="flex min-h-0 flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <p className="text-sm text-muted-foreground">CRM Core / pipeline</p>
                        <h1 className="text-2xl font-semibold tracking-tight">Lead pipeline</h1>
                    </div>
                    <Button asChild variant="outline">
                        <Link href="/admin/contacts">
                            Contacts
                            <ArrowRight data-icon="inline-end" />
                        </Link>
                    </Button>
                </div>

                <div className="grid flex-1 gap-3 overflow-x-auto md:grid-cols-3 xl:grid-cols-5">
                    {columns.map((status) => {
                        const items = leadsByStatus[status] ?? [];
                        return (
                            <div
                                key={status}
                                onDragOver={(e) => {
                                    e.preventDefault();
                                    setDragOver(status);
                                }}
                                onDragLeave={() => setDragOver(null)}
                                onDrop={(e) => onDrop(e, status)}
                                className={cn(
                                    'flex min-h-[200px] flex-col gap-2 rounded-lg border bg-muted/30 p-2',
                                    dragOver === status && 'ring-2 ring-primary',
                                )}
                            >
                                <div className="flex items-center justify-between px-1 py-1">
                                    <span className="text-sm font-semibold">
                                        {COLUMN_LABELS[status] ?? status}
                                    </span>
                                    <span className="[font-family:var(--font-mono)] text-xs tabular-nums text-muted-foreground">
                                        {items.length}
                                    </span>
                                </div>

                                {items.map((lead) => (
                                    <div
                                        key={lead.id}
                                        draggable
                                        onDragStart={(e) => {
                                            e.dataTransfer.setData('text/lead-id', lead.id);
                                            e.dataTransfer.setData('text/lead-status', lead.status);
                                        }}
                                        className="flex cursor-grab flex-col gap-2 rounded-md border bg-card p-3 shadow-sm active:cursor-grabbing"
                                    >
                                        <div className="flex items-start gap-2">
                                            <GripVertical className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                            <span className="line-clamp-2 text-sm font-medium">
                                                {lead.title}
                                            </span>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                            {lead.source && <StatusBadge status={lead.source} />}
                                            <span className="[font-family:var(--font-mono)] tabular-nums">
                                                {money(lead.valueAmount)}
                                            </span>
                                        </div>
                                        {lead.contactId ? (
                                            <Link
                                                href={`/admin/contacts/${lead.contactId}`}
                                                className="truncate text-xs text-primary hover:underline"
                                            >
                                                {lead.contact ?? 'Contact'}
                                            </Link>
                                        ) : (
                                            <span className="truncate text-xs text-muted-foreground">
                                                {lead.contact ?? '—'}
                                            </span>
                                        )}
                                    </div>
                                ))}

                                {items.length === 0 && (
                                    <p className="px-1 py-4 text-center text-xs text-muted-foreground">
                                        Kéo lead vào đây
                                    </p>
                                )}
                            </div>
                        );
                    })}
                </div>
            </main>
        </>
    );
}
