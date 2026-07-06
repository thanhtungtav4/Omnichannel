import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    InboxIcon,
    LoaderCircle,
    RefreshCcw,
    Search,
} from 'lucide-react';
import { type FormEvent, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import {
    Alert,
    AlertDescription,
    AlertTitle,
} from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
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
    providerLabel,
    type QueueTab,
    queueTabValue,
} from './inbox/lib';
import {
    ConversationRow,
    Metric,
    QueueSkeleton,
    QueueTabTrigger,
} from './inbox/QueueParts';
import { ThreadPanel } from './inbox/ThreadPanel';

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

            <main className="flex h-[calc(100vh-var(--topbar-height))] flex-col gap-3 overflow-hidden bg-background p-3 md:p-4">
                <section className="grid shrink-0 gap-2 md:grid-cols-4">
                    <Metric label="Đang mở" value={stats.open} status="OPEN" />
                    <Metric
                        label="Chờ trả lời"
                        value={stats.waitingAgent}
                        status="WAITING_AGENT"
                    />
                    <Metric
                        label="Chưa gán"
                        value={stats.unassigned}
                        status={stats.unassigned > 0 ? 'DUE_SOON' : 'OK'}
                    />
                    <Metric
                        label="Gửi lỗi"
                        value={stats.failedOutbox}
                        status={stats.failedOutbox > 0 ? 'FAILED' : 'OK'}
                    />
                </section>

                {(stats.failedOutbox > 0 || stats.unassigned > 0) && (
                    <Alert className="shrink-0 py-2">
                        <AlertTriangle />
                        <AlertTitle>Cần xử lý</AlertTitle>
                        <AlertDescription>
                            {stats.failedOutbox > 0 && (
                                <span>{stats.failedOutbox} tin gửi lỗi. </span>
                            )}
                            {stats.unassigned > 0 && (
                                <span>
                                    {stats.unassigned} hội thoại chưa gán.
                                </span>
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                <section
                    className={cn(
                        'grid min-h-0 flex-1 overflow-hidden rounded-lg border bg-card',
                        focusMode
                            ? 'grid-cols-1'
                            : 'lg:grid-cols-[380px_minmax(0,1fr)] xl:grid-cols-[420px_minmax(0,1fr)]',
                    )}
                >
                    {!focusMode && (
                        <aside className="flex min-h-0 flex-col border-b lg:border-r lg:border-b-0">
                            <div className="flex items-start justify-between gap-3 border-b p-3">
                                <div className="min-w-0">
                                    <h1 className="truncate text-base font-semibold">
                                        Hộp thư
                                    </h1>
                                    <p className="truncate text-sm text-muted-foreground">
                                        {filteredConversations.length}/
                                        {conversations.length} hội thoại
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    onClick={refreshNow}
                                    aria-label="Làm mới"
                                    disabled={isRefreshing}
                                >
                                    {isRefreshing ? (
                                        <LoaderCircle data-icon="icon" />
                                    ) : (
                                        <RefreshCcw data-icon="icon" />
                                    )}
                                </Button>
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
                                            label="Chờ trả lời"
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

                    <ThreadPanel
                        focusMode={focusMode}
                        onToggleFocus={() => setFocusMode((v) => !v)}
                        activeConversation={activeConversation}
                        agents={agents}
                        replyBody={replyForm.data.body}
                        replyImage={replyForm.data.image}
                        replyError={replyForm.errors.body}
                        replyProcessing={replyForm.processing}
                        composerMode={composerMode}
                        onComposerModeChange={setComposerMode}
                        transferTo={transferTo}
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
                    />
                </section>
            </main>
        </>
    );
}
