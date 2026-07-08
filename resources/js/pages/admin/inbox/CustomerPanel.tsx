import {
    Briefcase,
    Mail,
    MapPin,
    MessageCircle,
    Phone,
    Pin,
    Send,
    StickyNote,
    UserRound,
    UserRoundCheck,
} from 'lucide-react';
import { Link } from '@inertiajs/react';
import { TagEditor } from '@/components/admin/tag-editor';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';
import type { ActiveConversation, AgentOption } from '@/types';
import { customerName, initials, providerClass, providerLabel } from './lib';

/* ── Mockup §3.5: right-side customer panel with 4 tabs ─────────────── */

export function CustomerPanel({
    activeConversation,
    mobileView = 'queue',
    onMobileClose,
}: {
    activeConversation: ActiveConversation | null;
    agents: AgentOption[];
    /** On mobile, only render the bottom-sheet when view === 'customer'. */
    mobileView?: 'queue' | 'thread' | 'customer';
    /** Called when the sheet's backdrop is tapped (close action). */
    onMobileClose?: () => void;
}) {
    const contact = activeConversation?.contact;
    const name = customerName(activeConversation);
    if (!contact || !activeConversation) {
        return (
            <>
                {/* Desktop placeholder (xl+) — only shown when conversation is open. */}
                <aside className="hidden min-h-0 min-w-0 flex-col overflow-hidden border-l bg-card xl:flex">
                    <div className="flex flex-1 items-center justify-center p-6 text-center text-sm text-muted-foreground">
                        Chọn hội thoại để xem hồ sơ khách.
                    </div>
                </aside>
                {/* Mobile placeholder — empty sheet hidden. */}
                <div
                    data-mobile-open="false"
                    aria-hidden
                    className="fixed bottom-[calc(56px+env(safe-area-inset-bottom,0px))] left-0 right-0 z-[60] hidden translate-y-[calc(100%+56px+env(safe-area-inset-bottom,0px))] flex-col bg-card [border-top:1px_solid_var(--border)] shadow-[0_-8px_32px_rgb(0_0_0/0.18)] [border-top-left-radius:16px] [border-top-right-radius:16px] transition-transform duration-300 md:hidden"
                />
            </>
        );
    }

    const isMobileOpen = mobileView === 'customer';

    return (
        <>
            {/* Desktop: 3rd column in the grid (xl+). */}
            <aside className="hidden min-h-0 min-w-0 flex-col overflow-hidden border-l bg-card xl:flex">
            <Tabs defaultValue="profile" className="flex min-h-0 flex-1 flex-col">
                <TabsList className="grid h-auto w-full grid-cols-4 rounded-none border-b bg-card p-0">
                    <TabsTrigger
                        value="profile"
                        className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:shadow-none"
                    >
                        Hồ sơ
                    </TabsTrigger>
                    <TabsTrigger
                        value="activity"
                        className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:shadow-none"
                    >
                        Hoạt động
                    </TabsTrigger>
                    <TabsTrigger
                        value="deal"
                        className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:shadow-none"
                    >
                        Cơ hội
                    </TabsTrigger>
                    <TabsTrigger
                        value="conversations"
                        className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:shadow-none"
                    >
                        Hội thoại
                    </TabsTrigger>
                </TabsList>

                <TabsContent
                    value="profile"
                    className="m-0 flex-1 overflow-y-auto p-4 data-[state=inactive]:hidden"
                >
                    <ProfileTab contact={contact} name={name} owner={activeConversation.owner} />
                </TabsContent>
                <TabsContent
                    value="activity"
                    className="m-0 flex-1 overflow-y-auto p-4 data-[state=inactive]:hidden"
                >
                    <ActivityTab
                        conversation={activeConversation}
                    />
                </TabsContent>
                <TabsContent
                    value="deal"
                    className="m-0 flex-1 overflow-y-auto p-4 data-[state=inactive]:hidden"
                >
                    <DealTab
                        contact={contact}
                    />
                </TabsContent>
                <TabsContent
                    value="conversations"
                    className="m-0 flex-1 overflow-y-auto p-4 data-[state=inactive]:hidden"
                >
                    <ConversationsTab
                        contact={contact}
                        activeId={activeConversation.id}
                    />
                </TabsContent>
            </Tabs>
        </aside>

            {/* Mobile: bottom sheet (md- hidden via @container; shown below xl). */}
            <div
                role="dialog"
                aria-modal="true"
                aria-label="Hồ sơ khách"
                data-mobile-open={isMobileOpen ? 'true' : 'false'}
                className="fixed bottom-[calc(56px+env(safe-area-inset-bottom,0px))] left-0 right-0 z-[60] flex max-h-[calc(100dvh-var(--topbar-height)-56px-env(safe-area-inset-bottom,0px))] flex-col overflow-hidden [border-top:1px_solid_var(--border)] bg-card shadow-[0_-8px_32px_rgb(0_0_0/0.18)] [border-top-left-radius:16px] [border-top-right-radius:16px] transition-transform duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] md:hidden"
                style={{
                    transform: isMobileOpen
                        ? 'translateY(0)'
                        : 'translateY(calc(100% + 56px + env(safe-area-inset-bottom, 0px)))',
                }}
            >
                {/* Drag handle visual cue */}
                <div className="flex justify-center py-1.5">
                    <span
                        aria-hidden
                        className="h-1 w-9 rounded-full bg-muted-foreground/45"
                    />
                </div>
                {onMobileClose && (
                    <button
                        type="button"
                        onClick={onMobileClose}
                        aria-label="Đóng hồ sơ khách"
                        className="absolute right-2 top-1.5 grid size-8 place-items-center rounded-md text-muted-foreground hover:bg-muted"
                    >
                        ×
                    </button>
                )}
                <Tabs defaultValue="profile" className="flex min-h-0 flex-1 flex-col">
                    <TabsList className="grid h-auto w-full grid-cols-4 rounded-none border-b bg-card p-0">
                        <TabsTrigger
                            value="profile"
                            className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:shadow-none"
                        >
                            Hồ sơ
                        </TabsTrigger>
                        <TabsTrigger
                            value="activity"
                            className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:shadow-none"
                        >
                            Hoạt động
                        </TabsTrigger>
                        <TabsTrigger
                            value="deal"
                            className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:shadow-none"
                        >
                            Cơ hội
                        </TabsTrigger>
                        <TabsTrigger
                            value="conversations"
                            className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:shadow-none"
                        >
                            Hội thoại
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent
                        value="profile"
                        className="m-0 flex-1 overflow-y-auto p-4 data-[state=inactive]:hidden"
                    >
                        <ProfileTab contact={contact} name={name} owner={activeConversation.owner} />
                    </TabsContent>
                    <TabsContent
                        value="activity"
                        className="m-0 flex-1 overflow-y-auto p-4 data-[state=inactive]:hidden"
                    >
                        <ActivityTab conversation={activeConversation} />
                    </TabsContent>
                    <TabsContent
                        value="deal"
                        className="m-0 flex-1 overflow-y-auto p-4 data-[state=inactive]:hidden"
                    >
                        <DealTab contact={contact} />
                    </TabsContent>
                    <TabsContent
                        value="conversations"
                        className="m-0 flex-1 overflow-y-auto p-4 data-[state=inactive]:hidden"
                    >
                        <ConversationsTab contact={contact} activeId={activeConversation.id} />
                    </TabsContent>
                </Tabs>
            </div>

            {/* Mobile backdrop — dims page when sheet is open. */}
            {isMobileOpen && (
                <button
                    type="button"
                    aria-label="Đóng hồ sơ khách"
                    onClick={onMobileClose}
                    className="fixed inset-0 z-[55] cursor-default bg-black/40 md:hidden"
                />
            )}
        </>
    );
}

/* ── Profile ──────────────────────────────────────────────────────────── */
function ProfileTab({
    contact,
    name,
    owner,
}: {
    contact: NonNullable<ActiveConversation['contact']>;
    name: string;
    owner: ActiveConversation['owner'];
}) {
    const tags = contact.tags ?? [];
    const vocab = contact.tagVocabulary ?? [];
    const identities = contact.identities ?? [];
    const notes = contact.notes ?? [];

    return (
        <div className="flex flex-col gap-5 text-sm">
            {/* Avatar + name + source */}
            <div className="flex flex-col items-center gap-2 text-center">
                <Avatar className="size-14 text-base">
                    {contact.avatarUrl && (
                        <AvatarImage src={contact.avatarUrl} alt={name} />
                    )}
                    <AvatarFallback>{initials(name)}</AvatarFallback>
                </Avatar>
                <div className="min-w-0">
                    <p className="font-semibold">{name}</p>
                    {contact.source && (
                        <p className="text-xs text-muted-foreground">
                            Nguồn: {providerLabel(contact.source)}
                        </p>
                    )}
                </div>
                <div className="flex w-full gap-1.5">
                    <Button asChild variant="outline" size="sm" className="flex-1">
                        <a href={contact.phone ? `tel:${contact.phone}` : '#'}>
                            <Phone data-icon="inline-start" />
                            Gọi
                        </a>
                    </Button>
                    <Button asChild variant="outline" size="sm" className="flex-1">
                        <Link href={`/admin/contacts/${contact.id}`}>
                            <UserRound data-icon="inline-start" />
                            Hồ sơ
                        </Link>
                    </Button>
                </div>
            </div>

            {/* Thông tin */}
            <div>
                <SectionTitle>Thông tin</SectionTitle>
                <Field icon={<Phone className="size-3" />} label="SĐT">
                    {contact.phone ? (
                        <a href={`tel:${contact.phone}`} className="truncate text-primary hover:underline" title={contact.phone}>
                            {contact.phone}
                        </a>
                    ) : (
                        <Empty>—</Empty>
                    )}
                </Field>
                <Field icon={<Mail className="size-3" />} label="Email">
                    {contact.email ? (
                        <a href={`mailto:${contact.email}`} className="truncate text-primary hover:underline" title={contact.email}>
                            {contact.email}
                        </a>
                    ) : (
                        <Empty>—</Empty>
                    )}
                </Field>
                <Field icon={<MapPin className="size-3" />} label="Khu vực">
                    <Empty>—</Empty>
                </Field>
                <Field icon={<MessageCircle className="size-3" />} label="Tin gần nhất">
                    {contact.lastInboundAt ?? <Empty>—</Empty>}
                </Field>
                <Field icon={<Briefcase className="size-3" />} label="Nhân viên">
                    {owner ? (
                        <span className="flex items-center gap-1.5">
                            <span
                                className={cn(
                                    'inline-block size-1.5 rounded-full',
                                    owner.online
                                        ? '[background-color:var(--status-ok-fg)]'
                                        : '[background-color:var(--status-idle-fg)]',
                                )}
                            />
                            {owner.name}
                        </span>
                    ) : (
                        <span className="font-medium [color:var(--status-warn-fg)]">Chưa gán</span>
                    )}
                </Field>
            </div>

            {/* Tag */}
            <div>
                <SectionTitle>Tag</SectionTitle>
                <TagEditor
                    contactId={contact.id}
                    tags={tags}
                    suggestions={vocab}
                />
            </div>

            {/* Định danh kênh */}
            <div>
                <SectionTitle>Định danh kênh</SectionTitle>
                <div className="flex flex-col gap-1.5">
                    {identities.length === 0 && <Empty>Chưa liên kết.</Empty>}
                    {identities.map((identity) => (
                        <div
                            key={identity.id}
                            className="inline-flex items-center gap-1.5 rounded border px-1.5 py-0.5 text-xs [font-family:var(--font-mono)]"
                        >
                            <span
                                className={cn(
                                    'inline-block size-1.5 rounded-full',
                                    providerClass(identity.provider),
                                )}
                            />
                            {providerLabel(identity.provider)} ·{' '}
                            <span className="text-muted-foreground">
                                {identity.providerUserId}
                            </span>
                        </div>
                    ))}
                </div>
            </div>

            {/* Ghi chú nhanh (mockup = first pinned note, or all recent) */}
            {notes.length > 0 && (
                <div>
                    <SectionTitle>Ghi chú nhanh</SectionTitle>
                    <div className="flex flex-col gap-1.5">
                        {notes.slice(0, 3).map((note) => (
                            <div
                                key={note.id}
                                className="flex items-start gap-1.5 rounded border [background-color:var(--status-warn-bg)] [border-color:var(--status-warn-border)] [color:var(--status-warn-fg)] px-2.5 py-1.5 text-xs"
                            >
                                <Pin className="mt-0.5 size-3 shrink-0" />
                                <div className="min-w-0">
                                    <div className="font-semibold text-[11px]">
                                        {note.pinned && (
                                            <Pin className="mr-1 inline size-2.5" />
                                        )}
                                        Ghi chú
                                    </div>
                                    <p className="mt-0.5 whitespace-pre-wrap">
                                        {note.body}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

/* ── Activity ─────────────────────────────────────────────────────────── */
function ActivityTab({
    conversation,
}: {
    conversation: ActiveConversation;
}) {
    // Derive activity timeline from system events in messages. For cut 1 this is
    // sufficient; cut 2 will introduce a dedicated activity_events table for
    // CRM-level events (lead created, tag changed, etc.).
    const events: Array<{
        id: string;
        icon: typeof UserRoundCheck;
        title: string;
        meta: string;
        body?: string;
        tone: 'info' | 'ok' | 'warn' | 'danger';
    }> = [];

    conversation.messages.forEach((m) => {
        const kind = (m as { kind?: string }).kind;
        if (kind !== 'system') return;
        const time = m.timeLabel ?? '—';
        const body = m.body ?? '';
        if (/gán|assign/i.test(body)) {
            events.push({
                id: m.id,
                icon: UserRoundCheck,
                title: 'Auto-assigned',
                meta: `${time} · bởi hệ thống`,
                tone: 'ok',
            });
        } else if (/gửi đến|sent|delivered/i.test(body)) {
            events.push({
                id: m.id,
                icon: Send,
                title: 'Đã gửi đến khách',
                meta: `${time}`,
                tone: 'ok',
            });
        } else if (/đóng hội thoại|closed/i.test(body)) {
            events.push({
                id: m.id,
                icon: StickyNote,
                title: 'Đã đóng hội thoại',
                meta: `${time}`,
                tone: 'info',
            });
        } else {
            events.push({
                id: m.id,
                icon: MessageCircle,
                title: body || 'Hoạt động',
                meta: time,
                tone: 'info',
            });
        }
    });

    if (events.length === 0) {
        return (
            <p className="py-12 text-center text-sm text-muted-foreground">
                Chưa có hoạt động nào.
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-3">
            <SectionTitle>Hoạt động ({events.length})</SectionTitle>
            <div className="flex flex-col">
                {events.map((e, idx) => (
                    <div
                        key={e.id}
                        className="relative flex gap-2 pb-3 last:pb-0"
                    >
                        {idx < events.length - 1 && (
                            <span
                                aria-hidden
                                className="absolute left-[10px] top-5 bottom-0 w-px bg-border"
                            />
                        )}
                        <span
                            className={cn(
                                'z-[1] grid size-5 shrink-0 place-items-center rounded-full',
                                e.tone === 'ok' && '[background-color:var(--status-ok-bg)] [color:var(--status-ok-fg)]',
                                e.tone === 'warn' && '[background-color:var(--status-warn-bg)] [color:var(--status-warn-fg)]',
                                e.tone === 'danger' && '[background-color:var(--status-danger-bg)] [color:var(--status-danger-fg)]',
                                e.tone === 'info' && '[background-color:var(--status-info-bg)] [color:var(--status-info-fg)]',
                            )}
                        >
                            <e.icon className="size-3" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <div className="text-xs font-medium">{e.title}</div>
                            <div className="mt-0.5 text-[11px] text-muted-foreground">
                                {e.meta}
                            </div>
                            {e.body && (
                                <div className="mt-1 text-xs text-muted-foreground">
                                    {e.body}
                                </div>
                            )}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

/* ── Deal ────────────────────────────────────────────────────────────── */
function DealTab({
    contact,
}: {
    contact: NonNullable<ActiveConversation['contact']>;
}) {
    const openLeads = (contact.leads ?? []).filter(
        (l) => !['WON', 'LOST'].includes(l.status),
    );

    return (
        <div className="flex flex-col gap-4 text-sm">
            <div>
                <SectionTitle>Cơ hội đang mở</SectionTitle>
                {openLeads.length === 0 ? (
                    <Empty>Chưa có cơ hội.</Empty>
                ) : (
                    <div className="flex flex-col gap-2">
                        {openLeads.map((lead) => (
                            <div
                                key={lead.id}
                                className="flex flex-col gap-2 rounded border p-2.5"
                            >
                                {/* Title + status */}
                                <div className="flex items-center gap-2">
                                    <span className="flex-1 truncate text-xs font-medium">
                                        Lead · {lead.title}
                                    </span>
                                    <span
                                        className={cn(
                                            'shrink-0 rounded border px-1.5 py-0.5 text-[10px] font-semibold',
                                            lead.status === 'OPEN' &&
                                                '[background-color:var(--status-info-bg)] [border-color:var(--status-info-border)] [color:var(--status-info-fg)]',
                                            lead.status === 'QUALIFYING' &&
                                                '[background-color:var(--status-warn-bg)] [border-color:var(--status-warn-border)] [color:var(--status-warn-fg)]',
                                            lead.status === 'NEW' &&
                                                '[background-color:var(--status-idle-bg)] [border-color:var(--status-idle-border)] [color:var(--status-idle-fg)]',
                                        )}
                                    >
                                        {lead.status}
                                    </span>
                                </div>

                                {/* Kanban strip — current stage highlighted. */}
                                {lead.stage && lead.pipeline && (
                                    <KanbanStrip
                                        currentStage={lead.stage}
                                        pipelineName={lead.pipeline.name}
                                    />
                                )}

                                {/* Meta: pipeline · stage · value */}
                                <div className="flex items-center gap-1 text-[11px] text-muted-foreground">
                                    <span className="truncate">
                                        {lead.pipeline?.name ?? 'Pipeline'}
                                    </span>
                                    <span>·</span>
                                    <span className="truncate font-medium text-foreground">
                                        {lead.stage?.name ?? '—'}
                                    </span>
                                    {lead.valueAmount && (
                                        <>
                                            <span>·</span>
                                            <span className="[font-family:var(--font-mono)] font-semibold tabular-nums [color:var(--foreground)]">
                                                {Number(lead.valueAmount).toLocaleString('vi-VN')} ₫
                                            </span>
                                        </>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            <div>
                <SectionTitle>Pipeline</SectionTitle>
                <Field icon={<Briefcase className="size-3" />} label="Pipeline">
                    {openLeads[0]?.pipeline?.name ?? <Empty>—</Empty>}
                </Field>
                <Field icon={<UserRound className="size-3" />} label="Owner">
                    <Empty>—</Empty>
                </Field>
                <Field icon={<MapPin className="size-3" />} label="Nguồn">
                    {providerLabel(contact.source)}
                </Field>
            </div>
        </div>
    );
}

/* ── Kanban strip — 6-cell progress bar (mockup §3.5) ─────────────────── */
function KanbanStrip({
    currentStage,
    pipelineName,
}: {
    currentStage: { sortOrder: number; statusGroup: string; name: string };
    pipelineName: string;
}) {
    // Derive a synthetic 6-cell strip from the current stage's sortOrder.
    // This works whether the pipeline has 5 or 6 stages — we render the
    // current stage as the cell index (sort_order), with surrounding cells
    // grayed out. For a more precise strip we'd fetch the full stage list
    // from the pipeline, but this gives a faithful visual at the cheapest cost.
    const total = 6;
    const idx = Math.min(Math.max(currentStage.sortOrder - 1, 0), total - 1);

    return (
        <div
            className="flex gap-0.5"
            role="progressbar"
            aria-label={`Pipeline ${pipelineName}, hiện tại: ${currentStage.name}`}
            aria-valuenow={idx + 1}
            aria-valuemin={1}
            aria-valuemax={total}
        >
            {Array.from({ length: total }).map((_, i) => {
                const isCurrent = i === idx;
                const isWon = currentStage.statusGroup === 'WON' && i <= idx;
                const isLost = currentStage.statusGroup === 'LOST' && i === idx;
                const isPast = i < idx;
                return (
                    <span
                        key={i}
                        title={isCurrent ? `Hiện tại: ${currentStage.name}` : undefined}
                        className={cn(
                            'h-1 flex-1 rounded-sm bg-muted',
                            isCurrent && !isLost && '[background-color:var(--primary)]',
                            isWon && '[background-color:var(--status-ok-fg)]',
                            isLost && '[background-color:var(--status-danger-fg)]',
                            isPast && !isWon && !isLost && '[background-color:var(--primary)]',
                        )}
                    />
                );
            })}
        </div>
    );
}

/* ── Conversations ────────────────────────────────────────────────────── */
function ConversationsTab({
    contact,
    activeId,
}: {
    contact: NonNullable<ActiveConversation['contact']>;
    activeId: string;
}) {
    const others = (contact.otherConversations ?? []).filter(
        (c) => c.id !== activeId,
    );

    return (
        <div className="flex flex-col gap-2 text-sm">
            <SectionTitle>
                Hội thoại khác{others.length > 0 ? ` (${others.length})` : ''}
            </SectionTitle>
            {others.length === 0 ? (
                <Empty>Khách chỉ có hội thoại này.</Empty>
            ) : (
                <div className="flex flex-col gap-1.5">
                    {others.map((c) => (
                        <Link
                            key={c.id}
                            href={`/admin/inbox?conversation=${c.id}`}
                            className="flex items-center gap-2 rounded border p-2 hover:bg-accent"
                        >
                            <span
                                className={cn(
                                    'inline-block size-1.5 shrink-0 rounded-full',
                                    providerClass(c.channel),
                                )}
                            />
                            <span className="text-xs font-medium">
                                {providerLabel(c.channel)}
                            </span>
                            <span className="flex-1 truncate text-xs text-muted-foreground">
                                {c.preview ?? '—'}
                            </span>
                            <span className="shrink-0 text-[11px] text-muted-foreground [font-family:var(--font-mono)]">
                                {c.lastMessageAt ?? '—'}
                            </span>
                        </Link>
                    ))}
                </div>
            )}
        </div>
    );
}

/* ── Helpers ─────────────────────────────────────────────────────────── */
function SectionTitle({ children }: { children: React.ReactNode }) {
    return (
        <div className="mb-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {children}
        </div>
    );
}

function Field({
    icon,
    label,
    children,
}: {
    icon: React.ReactNode;
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-center gap-2 border-b border-dashed py-1.5 text-sm first:pt-0 last:border-0">
            <span className="flex w-20 shrink-0 items-center gap-1 text-xs text-muted-foreground">
                {icon}
                {label}
            </span>
            <span className="min-w-0 flex-1 truncate">{children}</span>
        </div>
    );
}

function Empty({ children }: { children: React.ReactNode }) {
    return <span className="text-muted-foreground">{children}</span>;
}