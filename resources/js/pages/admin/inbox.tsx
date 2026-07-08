import { Head, router, useForm } from '@inertiajs/react';
import {
    InboxIcon,
    LoaderCircle,
    MessageCircleX,
    RefreshCcw,
    Search,
    UserRoundX,
} from 'lucide-react';
import { type FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { useIsMobile } from '@/hooks/use-mobile';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import {
    Avatar,
    AvatarFallback,
    AvatarImage,
} from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Tabs, TabsList } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';
import { close, reply, transfer } from '@/routes/admin/conversations';
import type { ActiveConversation, AgentOption, ConversationSummary } from '@/types';
import {
    type InboxStats,
    providerClass,
    providerLabel,
    type QueueTab,
    queueTabValue,
} from './inbox/lib';
import {
    ConversationRow,
    QueueSkeleton,
    QueueTabTrigger,
    StatPill,
} from './inbox/QueueParts';
import { InboxBottomNav } from './inbox/InboxBottomNav';
import { ThreadPanel } from './inbox/ThreadPanel';
import { CustomerPanel } from './inbox/CustomerPanel';

type InboxProps = {
    stats: InboxStats;
    conversations: ConversationSummary[];
    activeConversation: ActiveConversation | null;
    agents: AgentOption[];
};

export default function Inbox({
    stats,
    conversations,
    activeConversation,
    agents,
}: InboxProps) {
    const [transferTo, setTransferTo] = useState<string>('');
    const [query, setQuery] = useState('');
    const [tab, setTab] = useState<QueueTab>('all');
    // Drill-down filter set by clicking a stat pill in the queue-header.
    // 'unassigned' = conversations with no owner assigned. 'failed' = a
    // pill reserved for per-conversation failed-message count (backend
    // join not wired yet — for now clicking it surfaces a hint via toast).
    const [statFilter, setStatFilter] = useState<'failed' | 'unassigned' | null>(
        null,
    );
    const [isRefreshing, setIsRefreshing] = useState(false);
    // Focus mode hides the queue + side panel so the thread gets the full width.
    const [focusMode, setFocusMode] = useState(false);
    const replyForm = useForm<{ body: string; image: File | null }>({
        body: '',
        image: null,
    });
    // 'reply' = sent to the customer; 'comment' = internal note between agents.
    const [composerMode, setComposerMode] = useState<'reply' | 'comment'>(
        'reply',
    );
    const currentOwnerId = activeConversation?.owner?.id;

    // On mobile the list and the thread are separate screens. The backend
    // auto-selects the most recent conversation, so opening /admin/inbox with
    // no ?conversation should still land on the LIST first — only show the
    // thread once a row is explicitly tapped (URL carries ?conversation).
    const hasExplicitConversation =
        typeof window !== 'undefined' &&
        new URLSearchParams(window.location.search).has('conversation');
    const showThread = !!activeConversation && hasExplicitConversation;
    const isMobile = useIsMobile();

    // Mobile view-state: 'queue' (default) | 'thread' | 'customer'.
    // On desktop all 3 panes are visible simultaneously so this is a no-op.
    // The bottom nav drives these transitions; tap on a conversation row
    // jumps to 'thread' on mobile.
    type MobileView = 'queue' | 'thread' | 'customer';
    const [mobileView, setMobileView] = useState<MobileView>(
        showThread ? 'thread' : 'queue',
    );
    // Sync mobileView when user taps a conversation (URL changes).
    useEffect(() => {
        if (isMobile && showThread) setMobileView('thread');
        if (isMobile && !showThread && mobileView === 'thread') setMobileView('queue');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [showThread, isMobile]);
    // Reset to queue when growing past the breakpoint so we don't strand
    // the user on a mobile-only view.
    useEffect(() => {
        if (!isMobile) setMobileView('queue');
    }, [isMobile]);

    const setView = (v: MobileView) => {
        setMobileView(v);
        // Switching to customer on mobile closes the thread so the
        // sheet sits over the queue.
        if (v === 'customer') {
            setMobileView('customer');
        }
        if (v === 'queue') {
            router.visit('/admin/inbox', { preserveScroll: true });
        }
    };

    // ⌘K / Ctrl+K opens the command palette (jump to any conversation).
    const [paletteOpen, setPaletteOpen] = useState(false);
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setPaletteOpen((v) => !v);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    // Esc exits focus mode.
    useEffect(() => {
        if (!focusMode) return;
        const onKey = (e: KeyboardEvent) =>
            e.key === 'Escape' && setFocusMode(false);
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [focusMode]);

    // Poll every 3s for new messages / queue changes.
    useEffect(() => {
        const interval = window.setInterval(() => {
            router.reload({
                only: ['stats', 'conversations', 'activeConversation'],
            });
        }, 3000);
        return () => window.clearInterval(interval);
    }, []);

    // Notify on a NEW inbound message. Snapshot each conversation's last inbound
    // text; when a poll brings a different inbound line (new thread, or a new
    // customer message on an existing thread), play a short beep + toast. The
    // first render only seeds the snapshot so we don't ping on page load.
    const seenInbound = useRef<Map<string, string> | null>(null);
    useEffect(() => {
        const snapshot = new Map<string, string>();
        for (const c of conversations) {
            if (c.lastDirection === 'INBOUND') {
                snapshot.set(c.id, c.lastMessage ?? '');
            }
        }

        const prev = seenInbound.current;
        seenInbound.current = snapshot;
        if (prev === null) return; // seed only, no ping on first load

        const hasNew = [...snapshot].some(
            ([id, text]) => prev.get(id) !== text,
        );
        if (!hasNew) return;

        toast('Tin nhắn mới');
        try {
            const AudioCtx =
                window.AudioContext ??
                (window as unknown as { webkitAudioContext: typeof AudioContext })
                    .webkitAudioContext;
            const ctx = new AudioCtx();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.frequency.value = 880;
            gain.gain.setValueAtTime(0.15, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3);
            osc.connect(gain).connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.3);
        } catch {
            // Audio blocked (no user gesture yet) — the toast still shows.
        }
    }, [conversations]);

    // Presence heartbeat: mark the agent ONLINE every 20s while the inbox is open
    // so auto-assignment only routes to agents who are actually present.
    useEffect(() => {
        const csrf =
            document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content') ?? '';
        const beat = () =>
            fetch('/api/admin/presence/heartbeat', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf },
                keepalive: true,
            }).catch(() => {});
        beat();
        const interval = window.setInterval(beat, 20_000);
        const goOffline = () =>
            navigator.sendBeacon?.('/api/admin/presence/offline');
        window.addEventListener('beforeunload', goOffline);
        return () => {
            window.clearInterval(interval);
            window.removeEventListener('beforeunload', goOffline);
        };
    }, []);

    // Total unread across all conversations (drives the bottom-nav badge).
    const totalUnread = useMemo(
        () => conversations.reduce((sum, c) => sum + (c.unreadCount ?? 0), 0),
        [conversations],
    );

    const filteredConversations = useMemo(() => {
        const normalizedQuery = query.trim().toLowerCase();
        return conversations.filter((conversation) => {
            const tabState = queueTabValue(conversation, currentOwnerId);
            const matchesTab =
                tab === 'all' ||
                (tab === 'mine' && tabState.mine) ||
                (tab === 'waiting' && tabState.waiting) ||
                (tab === 'priority' && tabState.priority);
            if (!matchesTab) return false;
            // Stat-pill drill-down filter (orthogonal to tab).
            // 'failed' requires per-conversation outbox data not yet wired
            // to the queue — clicking it shows a toast instead of filtering.
            if (statFilter === 'unassigned' && conversation.owner) {
                return false;
            }
            if (!normalizedQuery) return true;
            return [
                conversation.contact?.name,
                conversation.contact?.phone,
                conversation.contact?.email,
                conversation.lastMessage,
                conversation.channelName,
                providerLabel(conversation.channel),
            ]
                .filter(Boolean)
                .join(' ')
                .toLowerCase()
                .includes(normalizedQuery);
        });
    }, [conversations, currentOwnerId, query, tab]);

    const tabCounts = useMemo(() => {
        return conversations.reduce(
            (counts, conversation) => {
                const tabState = queueTabValue(conversation, currentOwnerId);
                counts.all += 1;
                counts.mine += tabState.mine ? 1 : 0;
                counts.waiting += tabState.waiting ? 1 : 0;
                counts.priority += tabState.priority ? 1 : 0;
                return counts;
            },
            { all: 0, mine: 0, waiting: 0, priority: 0 },
        );
    }, [conversations, currentOwnerId]);

    function submitReply(event: FormEvent) {
        event.preventDefault();
        if (!activeConversation) return;

        // Comment mode = internal note (no image, not sent to the customer).
        if (composerMode === 'comment') {
            if (!replyForm.data.body.trim()) return;
            router.post(
                `/api/admin/conversations/${activeConversation.id}/comment`,
                { body: replyForm.data.body },
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        replyForm.reset();
                        toast.success('Đã thêm ghi chú nội bộ');
                    },
                    onError: () => toast.error('Lỗi thêm ghi chú'),
                },
            );
            return;
        }

        if (!replyForm.data.body.trim() && !replyForm.data.image) return;
        replyForm.post(reply.url(activeConversation.id), {
            preserveScroll: true,
            forceFormData: true, // multipart for the image upload
            onSuccess: () => {
                replyForm.reset();
                toast.success('Đã gửi tin');
            },
            onError: () => toast.error('Gửi lỗi'),
        });
    }

    function submitTransfer(userId?: string) {
        const target = userId ?? transferTo;
        if (!activeConversation || !target) return;
        router.post(
            transfer.url(activeConversation.id),
            { user_id: target },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setTransferTo('');
                    toast.success('Đã chuyển hội thoại');
                },
                onError: () => toast.error('Chuyển thất bại'),
            },
        );
    }

    function closeConversation() {
        if (!activeConversation) return;
        router.post(
            close.url(activeConversation.id),
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Đã đóng hội thoại'),
                onError: () => toast.error('Đóng thất bại'),
            },
        );
    }

    function reopenConversation() {
        if (!activeConversation) return;
        router.post(
            `/api/admin/conversations/${activeConversation.id}/reopen`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Đã mở lại hội thoại'),
                onError: () => toast.error('Mở lại thất bại'),
            },
        );
    }

    function refreshNow() {
        setIsRefreshing(true);
        router.reload({
            only: ['stats', 'conversations', 'activeConversation'],
            onFinish: () => setIsRefreshing(false),
        });
    }

    return (
        <>
            <Head title="Hộp thư đa kênh" />

            <Dialog open={paletteOpen} onOpenChange={setPaletteOpen}>
                <DialogContent
                    showCloseButton={false}
                    className="!top-[12vh] !translate-y-0 !left-1/2 !-translate-x-1/2 w-[560px] max-w-[90vw] gap-0 overflow-hidden p-0"
                >
                    <Command
                        className="border-0 **:data-[slot=command-input-wrapper]:h-12"
                        filter={false}
                    >
                        <CommandInput placeholder="Tìm khách, SĐT, kênh, mã hội thoại…" />
                        <CommandList className="max-h-[420px]">
                            <CommandEmpty>Không tìm thấy.</CommandEmpty>
                            <CommandGroup heading="Hội thoại">
                                {conversations.map((c) => (
                                    <CommandItem
                                        key={c.id}
                                        value={`${c.contact?.name ?? ''} ${c.contact?.phone ?? ''} ${c.channelName ?? ''} ${c.lastMessage ?? ''}`}
                                        onSelect={() => {
                                            setPaletteOpen(false);
                                            router.visit(
                                                `/admin/inbox?conversation=${c.id}`,
                                            );
                                        }}
                                    >
                                        <Avatar className="size-6 text-[10px]">
                                            {c.contact?.avatarUrl && (
                                                <AvatarImage
                                                    src={c.contact.avatarUrl}
                                                    alt={c.contact?.name ?? ''}
                                                />
                                            )}
                                            <AvatarFallback>
                                                {(c.contact?.name ?? '?')
                                                    .split(' ')
                                                    .map((p) => p[0])
                                                    .join('')
                                                    .slice(0, 2)
                                                    .toUpperCase()}
                                            </AvatarFallback>
                                        </Avatar>
                                        <div className="min-w-0 flex-1">
                                            <div className="truncate font-medium">
                                                {c.contact?.name ?? 'Khách'}
                                            </div>
                                            {c.lastMessage && (
                                                <div className="truncate text-xs text-muted-foreground">
                                                    {c.lastMessage}
                                                </div>
                                            )}
                                        </div>
                                        <div className="ml-auto flex shrink-0 items-center gap-1.5">
                                            <Badge
                                                variant="outline"
                                                className={cn(
                                                    'shrink-0 text-[10px]',
                                                    providerClass(c.channel),
                                                )}
                                            >
                                                {providerLabel(c.channel)}
                                            </Badge>
                                            {!!c.unreadCount &&
                                                c.unreadCount > 0 && (
                                                    <span className="rounded-full bg-destructive px-1.5 text-[10px] font-semibold tabular-nums text-destructive-foreground">
                                                        {c.unreadCount > 99
                                                            ? '99+'
                                                            : c.unreadCount}
                                                    </span>
                                                )}
                                        </div>
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        </CommandList>
                    </Command>
                </DialogContent>
            </Dialog>

            <main
                data-mobile-view={isMobile ? mobileView : undefined}
                className="flex h-[calc(100vh-var(--topbar-height))] flex-col gap-3 overflow-hidden bg-background p-3 pb-[calc(64px+env(safe-area-inset-bottom,0px))] md:pb-4"
            >

                <section
                    className={cn(
                        'grid min-h-0 flex-1 overflow-hidden rounded-lg border bg-card',
                        // Mockup §3.5:
                        //   desktop (≥ xl, ≥1280): 380px | 1fr | 340px   (queue | thread | customer)
                        //   tablet  (md..xl, 768..1279): 340px | 1fr      (queue | thread; customer as right-drawer — see CustomerPanel.tsx `xl:flex`)
                        //   mobile  (< md): 1fr; panes mutually exclusive
                        //   focus-mode (mockup line 367-369): 380px | 1fr  (queue + thread kept, customer dropped)
                        focusMode
                            ? 'lg:grid-cols-[380px_minmax(0,1fr)] xl:grid-cols-[380px_minmax(0,1fr)] grid-cols-1'
                            : activeConversation
                              ? 'lg:grid-cols-[340px_minmax(0,1fr)] xl:grid-cols-[380px_minmax(0,1fr)_340px] grid-cols-1'
                              : 'grid-cols-1',
                    )}
                >
                    {!focusMode && (
                        <aside className={cn(
                            "flex min-h-0 flex-col border-b lg:border-r lg:border-b-0 bg-muted/20",
                            // On mobile: queue hidden when in 'thread' view.
                            // On lg+: queue hidden only when an explicit thread
                            // is open (so landing on /admin/inbox shows the list).
                            isMobile
                                ? (mobileView === 'queue' ? 'flex w-full' : 'hidden')
                                : (showThread ? 'hidden lg:flex' : 'flex w-full')
                        )}>
                            <div className="flex items-center justify-between gap-2 border-b p-3">
                                <div className="min-w-0 flex-1">
                                    <h1 className="truncate text-base font-semibold">
                                        Hộp thư
                                    </h1>
                                    <p className="truncate text-xs text-muted-foreground tabular-nums">
                                        {stats.open}/{conversations.length} mở · {stats.waitingAgent} chờ
                                    </p>
                                </div>
                                <div className="flex items-center gap-0.5">
                                    <StatPill
                                        icon={MessageCircleX}
                                        count={stats.failedOutbox}
                                        tone="danger"
                                        pulse
                                        label="Tin nhắn gửi đi lỗi — bấm để xem"
                                        active={statFilter === 'failed'}
                                        onClick={() => {
                                            if (stats.failedOutbox === 0) return;
                                            // Per-conversation failed-message data isn't
                                            // wired to the queue yet. Surface a hint and
                                            // keep the existing failedOutbox count visible
                                            // for ops to dig via OPS_WEBHOOKS.md.
                                            toast.error(
                                                `${stats.failedOutbox} tin gửi đi lỗi — xem docs/OPS_WEBHOOKS.md §Troubleshooting.`,
                                                { duration: 5000 },
                                            );
                                        }}
                                    />
                                    <StatPill
                                        icon={UserRoundX}
                                        count={stats.unassigned}
                                        tone="warn"
                                        label="Chưa gán nhân viên — bấm để lọc"
                                        active={statFilter === 'unassigned'}
                                        onClick={() =>
                                            setStatFilter((current) =>
                                                current === 'unassigned'
                                                    ? null
                                                    : 'unassigned',
                                            )
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-8"
                                        onClick={refreshNow}
                                        aria-label="Làm mới"
                                        disabled={isRefreshing}
                                    >
                                        {isRefreshing ? (
                                            <LoaderCircle className="size-3.5" />
                                        ) : (
                                            <RefreshCcw className="size-3.5" />
                                        )}
                                    </Button>
                                </div>
                            </div>

                            <div className="flex flex-col gap-3 border-b p-3">
                                <InputGroup>
                                    <InputGroupInput
                                        value={query}
                                        onChange={(event) =>
                                            setQuery(event.target.value)
                                        }
                                        placeholder="Tìm khách, SĐT, kênh..."
                                    />
                                    <InputGroupAddon align="inline-start">
                                        <Search />
                                    </InputGroupAddon>
                                    <InputGroupAddon align="inline-end">
                                        <button
                                            type="button"
                                            onClick={() => setPaletteOpen(true)}
                                            className="hidden rounded border px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground sm:inline"
                                            title="Tìm nhanh (⌘K)"
                                        >
                                            ⌘K
                                        </button>
                                    </InputGroupAddon>
                                </InputGroup>

                                <Tabs
                                    value={tab}
                                    onValueChange={(value) =>
                                        setTab(value as QueueTab)
                                    }
                                >
                                    <TabsList className="grid h-auto w-full grid-cols-4">
                                        <QueueTabTrigger
                                            value="all"
                                            label="Tất cả"
                                            count={tabCounts.all}
                                        />
                                        <QueueTabTrigger
                                            value="mine"
                                            label="Của tôi"
                                            count={tabCounts.mine}
                                        />
                                        <QueueTabTrigger
                                            value="waiting"
                                            label="Chờ"
                                            count={tabCounts.waiting}
                                        />
                                        <QueueTabTrigger
                                            value="priority"
                                            label="Ưu tiên"
                                            count={tabCounts.priority}
                                        />
                                    </TabsList>
                                </Tabs>
                            </div>

                            <ScrollArea className="min-h-0 flex-1">
                                {isRefreshing && conversations.length === 0 ? (
                                    <QueueSkeleton />
                                ) : filteredConversations.length > 0 ? (
                                    <div className="flex flex-col">
                                        {filteredConversations.map(
                                            (conversation) => (
                                                <ConversationRow
                                                    key={conversation.id}
                                                    conversation={conversation}
                                                    active={
                                                        activeConversation?.id ===
                                                        conversation.id
                                                    }
                                                />
                                            ),
                                        )}
                                    </div>
                                ) : (
                                    <Empty className="border-0 py-12">
                                        <EmptyHeader>
                                            <EmptyMedia variant="icon">
                                                <InboxIcon />
                                            </EmptyMedia>
                                            <EmptyTitle>
                                                Chưa có hội thoại
                                            </EmptyTitle>
                                            <EmptyDescription>
                                                Đổi bộ lọc hoặc chờ tin mới.
                                            </EmptyDescription>
                                        </EmptyHeader>
                                    </Empty>
                                )}
                            </ScrollArea>
                        </aside>
                    )}

{activeConversation && (
                        <>
                            {/*
                              A1 fix: render ThreadPanel and CustomerPanel as
                              DIRECT children of the section grid (mockup
                              line 1699-1797 places 3 panes as siblings,
                              not inside a wrapper). The wrapper around
                              these two was collapsing both into the same
                              grid cell because its Tailwind class was
                              `grid grid-rows-1` with no `grid-cols-*`,
                              which defaulted to one column → thread and
                              customer stacked instead of splitting into
                              cols 2 and 3 at xl.

                              ThreadPanel still needs responsive hide
                              logic (mobile shows one pane at a time;
                              on lg+ thread hides when no conversation
                              is selected, matching the original wrapper
                              behavior). CustomerPanel handles its own
                              responsive via internal `xl:flex` aside
                              + `md:hidden` bottom sheet — no change.
                            */}
                            <div
                                data-pane="thread"
                                className={cn(
                                    'flex min-h-0 min-w-0 flex-col overflow-hidden',
                                    isMobile
                                        ? (mobileView === 'thread' ||
                                          mobileView === 'customer'
                                            ? 'flex'
                                            : 'hidden')
                                        : (showThread
                                            ? 'flex'
                                            : 'hidden lg:flex'),
                                )}
                            >
                                <ThreadPanel
                                    focusMode={focusMode}
                                    onToggleFocus={() =>
                                        setFocusMode((v) => !v)
                                    }
                                    activeConversation={activeConversation}
                                    agents={agents}
                                    replyBody={replyForm.data.body}
                                    replyImage={replyForm.data.image}
                                    replyError={replyForm.errors.body}
                                    replyProcessing={replyForm.processing}
                                    composerMode={composerMode}
                                    onComposerModeChange={setComposerMode}
                                    onReplyBodyChange={(body) =>
                                        replyForm.setData('body', body)
                                    }
                                    onReplyImageChange={(image) =>
                                        replyForm.setData('image', image)
                                    }
                                    onSubmitReply={submitReply}
                                    onTransferToChange={setTransferTo}
                                    onSubmitTransfer={submitTransfer}
                                    onCloseConversation={closeConversation}
                                    onReopenConversation={reopenConversation}
                                />
                            </div>
                            {!focusMode && (
                                <CustomerPanel
                                    activeConversation={activeConversation}
                                    agents={agents}
                                    mobileView={mobileView}
                                    onMobileClose={() => setView('queue')}
                                />
                            )}
                        </>
                    )}
                </section>

                {/* Mobile-only bottom nav. Pinned at viewport bottom; the
                    inbox-wrap above has padding-bottom equal to the nav
                    height so content never sits under it. Hidden on md+. */}
                <InboxBottomNav
                    unreadCount={totalUnread}
                    isOnline={true}
                    activeView={mobileView}
                    onQueue={() => setView('queue')}
                    onCustomer={() => setView('customer')}
                    onSearch={() => setPaletteOpen((v) => !v)}
                />
            </main>
        </>
    );
}
