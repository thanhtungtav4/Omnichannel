import { Link } from '@inertiajs/react';
import {
    CheckCircle2,
    ChevronDown,
    InboxIcon,
    LoaderCircle,
    Maximize2,
    MessageCirclePlus,
    ImagePlus,
    Minimize2,
    Search,
    Send,
    Smile,
    UserRoundCheck,
    Wifi,
    X,
} from 'lucide-react';
import { type FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { StatusBadge } from '@/components/admin/status-badge';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    Field,
    FieldDescription,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
    InputGroupTextarea,
} from '@/components/ui/input-group';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import type { ActiveConversation, AgentOption } from '@/types';
import { DayDivider, MessageBubble } from './MessageBubble';
import {
    customerName,
    groupByDay,
    initials,
    providerClass,
    providerLabel,
    EMOJIS,
    QUICK_TEMPLATES,
} from './lib';

export function ThreadPanel({
    activeConversation,
    agents,
    replyBody,
    replyImage,
    replyError,
    replyProcessing,
    transferTo,
    focusMode,
    onToggleFocus,
    onReplyBodyChange,
    onReplyImageChange,
    onSubmitReply,
    onTransferToChange,
    onSubmitTransfer,
    onCloseConversation,
}: {
    activeConversation: ActiveConversation | null;
    agents: AgentOption[];
    replyBody: string;
    replyImage: File | null;
    replyError?: string;
    replyProcessing: boolean;
    transferTo: string;
    focusMode: boolean;
    onToggleFocus: () => void;
    onReplyBodyChange: (body: string) => void;
    onReplyImageChange: (image: File | null) => void;
    onSubmitReply: (event: FormEvent) => void;
    onTransferToChange: (agentId: string) => void;
    onSubmitTransfer: () => void;
    onCloseConversation: () => void;
}) {
    // Hooks must run unconditionally (before any early return).
    const threadEndRef = useRef<HTMLDivElement>(null);
    const messageCount = activeConversation?.messages.length ?? 0;
    const activeId = activeConversation?.id;
    const prevCountRef = useRef(messageCount);

    // Auto-scroll to newest only on open or when a NEW message arrives at the
    // bottom — not when older messages are prepended on scroll-up.
    useEffect(() => {
        threadEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messageCount, activeId]);

    // Toast when a new inbound message arrives.
    useEffect(() => {
        if (messageCount > prevCountRef.current) {
            const last = activeConversation?.messages[messageCount - 1];
            if (last?.direction === 'INBOUND') {
                toast(`Tin mới: ${last.body ?? ''}`.slice(0, 80));
            }
        }
        prevCountRef.current = messageCount;
    }, [messageCount, activeConversation]);

    // Show a "scroll to bottom" button when the end marker isn't visible.
    const [atBottom, setAtBottom] = useState(true);
    useEffect(() => {
        const el = threadEndRef.current;
        if (!el) return;
        const io = new IntersectionObserver(
            ([entry]) => setAtBottom(entry.isIntersecting),
            { threshold: 0.1 },
        );
        io.observe(el);
        return () => io.disconnect();
    }, [activeId]);

    // Older messages loaded on scroll-up, kept in local state and prepended to
    // the server-provided page. Reset when the conversation changes.
    const [olderMessages, setOlderMessages] = useState<
        ActiveConversation['messages']
    >([]);
    const [hasMore, setHasMore] = useState(false);
    const [loadingOlder, setLoadingOlder] = useState(false);
    useEffect(() => {
        setOlderMessages([]);
        setHasMore(activeConversation?.hasMoreMessages ?? false);
    }, [activeId, activeConversation?.hasMoreMessages]);

    const topSentinelRef = useRef<HTMLDivElement>(null);
    async function loadOlder() {
        if (loadingOlder || !hasMore || !activeId) return;
        setLoadingOlder(true);
        const shown = [...olderMessages, ...(activeConversation?.messages ?? [])];
        const before = shown[0]?.id;
        try {
            const res = await fetch(
                `/api/admin/conversations/${activeId}/messages-older?before=${before}`,
            );
            const data = await res.json();
            setOlderMessages((prev) => [...data.messages, ...prev]);
            setHasMore(data.hasMore);
        } finally {
            setLoadingOlder(false);
        }
    }

    // Trigger load when the top sentinel scrolls into view.
    useEffect(() => {
        const el = topSentinelRef.current;
        if (!el || !hasMore) return;
        const io = new IntersectionObserver(
            ([entry]) => entry.isIntersecting && loadOlder(),
            { threshold: 0.5 },
        );
        io.observe(el);
        return () => io.disconnect();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [hasMore, olderMessages.length, activeId]);

    // Full thread = older (client-loaded) + current server page, deduped.
    const allMessages = useMemo(() => {
        const seen = new Set<string>();
        return [...olderMessages, ...(activeConversation?.messages ?? [])].filter(
            (m) => (seen.has(m.id) ? false : seen.add(m.id)),
        );
    }, [olderMessages, activeConversation?.messages]);

    // Search-in-thread: filters the loaded messages by body text (only what's
    // already loaded; scroll up first to search older tin).
    // ponytail: client-side over loaded page; add a server search if threads get huge.
    const [search, setSearch] = useState('');
    const [showSearch, setShowSearch] = useState(false);
    const [showEmoji, setShowEmoji] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);
    // Object URL for the pending image preview; revoked on change.
    const [imagePreview, setImagePreview] = useState<string | null>(null);
    useEffect(() => {
        if (!replyImage) {
            setImagePreview(null);
            return;
        }
        const url = URL.createObjectURL(replyImage);
        setImagePreview(url);
        return () => URL.revokeObjectURL(url);
    }, [replyImage]);
    useEffect(() => {
        setSearch('');
        setShowSearch(false);
    }, [activeId]);
    const shownMessages = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return allMessages;
        return allMessages.filter((m) => m.body?.toLowerCase().includes(q));
    }, [allMessages, search]);

    if (!activeConversation) {
        return (
            <Empty className="min-h-[620px] border-0">
                <EmptyHeader>
                    <EmptyMedia variant="icon">
                        <MessageCirclePlus />
                    </EmptyMedia>
                    <EmptyTitle>Chưa chọn hội thoại</EmptyTitle>
                    <EmptyDescription>
                        Chọn 1 hội thoại bên trái để mở.
                    </EmptyDescription>
                </EmptyHeader>
            </Empty>
        );
    }

    const name = customerName(activeConversation);

    return (
        <section className="flex min-h-0 flex-col overflow-hidden">
            <header className="flex shrink-0 flex-col gap-3 border-b p-3 xl:flex-row xl:items-center xl:justify-between">
                <div className="flex min-w-0 items-center gap-3">
                    <Avatar className="size-10">
                        {activeConversation.contact?.avatarUrl && (
                            <AvatarImage
                                src={activeConversation.contact.avatarUrl}
                                alt={name}
                            />
                        )}
                        <AvatarFallback>{initials(name)}</AvatarFallback>
                    </Avatar>
                    <div className="min-w-0">
                        <h2 className="truncate text-base font-semibold">
                            {name}
                        </h2>
                        <div className="flex min-w-0 flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <Badge
                                variant="outline"
                                className={cn(
                                    'max-w-40 truncate',
                                    providerClass(activeConversation.channel),
                                )}
                            >
                                {providerLabel(activeConversation.channel)}
                            </Badge>
                            <span className="flex items-center gap-1 truncate">
                                {activeConversation.owner && (
                                    <span
                                        className={cn(
                                            'size-2 rounded-full',
                                            activeConversation.owner.online
                                                ? '[background-color:var(--status-ok-fg)]'
                                                : '[background-color:var(--status-idle-fg)]',
                                        )}
                                        title={
                                            activeConversation.owner.online
                                                ? 'Đang online'
                                                : 'Offline'
                                        }
                                    />
                                )}
                                {activeConversation.owner
                                    ? `${activeConversation.owner.name} đang xử lý`
                                    : 'Chưa gán'}
                            </span>
                        </div>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge status={activeConversation.priority} />
                    <StatusBadge status={activeConversation.status} />
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        onClick={() => setShowSearch((v) => !v)}
                        title="Tìm trong hội thoại"
                        aria-label="Search in thread"
                    >
                        <Search />
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        onClick={onToggleFocus}
                        title={focusMode ? 'Thoát toàn màn (Esc)' : 'Toàn màn hình'}
                        aria-label={focusMode ? 'Exit focus mode' : 'Focus mode'}
                    >
                        {focusMode ? <Minimize2 /> : <Maximize2 />}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onCloseConversation}
                    >
                        <CheckCircle2 data-icon="inline-start" />
                        Đóng
                    </Button>
                </div>
            </header>

            <div
                className={cn(
                    'grid min-h-0 flex-1',
                    focusMode
                        ? 'grid-cols-1'
                        : 'xl:grid-cols-[minmax(0,1fr)_280px]',
                )}
            >
                <div className="relative flex min-h-0 min-w-0 flex-col">
                    {showSearch && (
                        <div className="flex shrink-0 items-center gap-2 border-b p-2">
                            <InputGroup className="flex-1">
                                <InputGroupInput
                                    autoFocus
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder="Tìm trong tin đã tải…"
                                />
                                <InputGroupAddon align="inline-start">
                                    <Search />
                                </InputGroupAddon>
                            </InputGroup>
                            <span className="[font-family:var(--font-mono)] shrink-0 text-xs tabular-nums text-muted-foreground">
                                {search.trim() ? shownMessages.length : ''}
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                onClick={() => {
                                    setSearch('');
                                    setShowSearch(false);
                                }}
                                aria-label="Close search"
                            >
                                <X />
                            </Button>
                        </div>
                    )}
                    {!atBottom && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="absolute bottom-24 left-1/2 z-10 -translate-x-1/2 rounded-full shadow-md"
                            onClick={() =>
                                threadEndRef.current?.scrollIntoView({
                                    behavior: 'smooth',
                                })
                            }
                        >
                            <ChevronDown data-icon="inline-start" />
                            Xuống cuối
                        </Button>
                    )}
                    <ScrollArea className="min-h-0 w-full min-w-0 flex-1 p-4">
                        <div className="flex min-w-0 flex-col gap-1.5">
                            {hasMore && (
                                <div
                                    ref={topSentinelRef}
                                    className="flex justify-center py-2 text-xs text-muted-foreground"
                                >
                                    {loadingOlder
                                        ? 'Đang tải tin cũ…'
                                        : 'Kéo lên để xem tin cũ hơn'}
                                </div>
                            )}
                            {shownMessages.length > 0 ? (
                                groupByDay(shownMessages).map((group) => (
                                    <div
                                        key={group.date}
                                        className="flex min-w-0 flex-col gap-1.5"
                                    >
                                        <DayDivider label={group.label} />
                                        {group.messages.map((message) => (
                                            <MessageBubble
                                                key={message.id}
                                                message={message}
                                                isGroup={activeConversation.isGroup}
                                            />
                                        ))}
                                    </div>
                                ))
                            ) : (
                                <Empty className="border-0 py-12">
                                    <EmptyHeader>
                                        <EmptyMedia variant="icon">
                                            <InboxIcon />
                                        </EmptyMedia>
                                        <EmptyTitle>Chưa có tin nhắn</EmptyTitle>
                                        <EmptyDescription>
                                            Tin của khách sẽ hiện ở đây.
                                        </EmptyDescription>
                                    </EmptyHeader>
                                </Empty>
                            )}
                            <div ref={threadEndRef} />
                        </div>
                    </ScrollArea>

                    <form onSubmit={onSubmitReply} className="relative shrink-0 border-t p-3">
                        {/* Emoji picker: toggled by the smile button. */}
                        {showEmoji && (
                            <div className="absolute bottom-full right-3 z-10 mb-1 grid max-w-72 grid-cols-10 gap-0.5 rounded-lg border bg-popover p-2 shadow-md">
                                {EMOJIS.map((e) => (
                                    <button
                                        key={e}
                                        type="button"
                                        onClick={() => {
                                            onReplyBodyChange(replyBody + e);
                                            setShowEmoji(false);
                                        }}
                                        className="rounded-md p-1 text-lg hover:bg-accent"
                                    >
                                        {e}
                                    </button>
                                ))}
                            </div>
                        )}
                        {/* Quick-reply picker: shows when the composer starts with "/". */}
                        {replyBody.startsWith('/') && (
                            <div className="absolute bottom-full left-3 right-3 z-10 mb-1 max-h-56 overflow-auto rounded-lg border bg-popover p-1 shadow-md">
                                {QUICK_TEMPLATES.filter((t) =>
                                    t.key.includes(
                                        replyBody.slice(1).toLowerCase(),
                                    ) ||
                                    t.label
                                        .toLowerCase()
                                        .includes(replyBody.slice(1).toLowerCase()),
                                ).map((t) => (
                                    <button
                                        key={t.key}
                                        type="button"
                                        onClick={() => onReplyBodyChange(t.text)}
                                        className="flex w-full flex-col items-start gap-0.5 rounded-md px-2 py-1.5 text-left text-sm hover:bg-accent"
                                    >
                                        <span className="font-medium">
                                            /{t.key} · {t.label}
                                        </span>
                                        <span className="line-clamp-1 text-xs text-muted-foreground">
                                            {t.text}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        )}
                        <input
                            ref={fileInputRef}
                            type="file"
                            accept="image/*"
                            hidden
                            onChange={(event) => {
                                onReplyImageChange(
                                    event.target.files?.[0] ?? null,
                                );
                                event.target.value = ''; // allow re-picking same file
                            }}
                        />
                        {imagePreview && (
                            <div className="relative mb-2 inline-block">
                                <img
                                    src={imagePreview}
                                    alt="Ảnh đính kèm"
                                    className="max-h-32 rounded-lg border object-cover"
                                />
                                <Button
                                    type="button"
                                    variant="secondary"
                                    size="icon"
                                    className="absolute -right-2 -top-2 size-6 rounded-full shadow"
                                    onClick={() => onReplyImageChange(null)}
                                    aria-label="Bỏ ảnh"
                                >
                                    <X />
                                </Button>
                            </div>
                        )}
                        <FieldGroup className="gap-3">
                            <Field data-invalid={!!replyError}>
                                <FieldLabel htmlFor="reply-body">
                                    Trả lời{' '}
                                    <span className="text-xs font-normal text-muted-foreground">
                                        (gõ / để chèn mẫu)
                                    </span>
                                </FieldLabel>
                                <InputGroup className="items-stretch">
                                    <InputGroupTextarea
                                        id="reply-body"
                                        name="body"
                                        rows={3}
                                        value={replyBody}
                                        onChange={(event) =>
                                            onReplyBodyChange(event.target.value)
                                        }
                                        onKeyDown={(event) => {
                                            // Enter sends; Shift+Enter newline. Don't
                                            // send while the "/" template picker is open.
                                            if (
                                                event.key === 'Enter' &&
                                                !event.shiftKey &&
                                                !event.nativeEvent.isComposing &&
                                                !replyBody.startsWith('/')
                                            ) {
                                                event.preventDefault();
                                                if (
                                                    replyBody.trim() &&
                                                    !replyProcessing
                                                ) {
                                                    onSubmitReply(
                                                        event as unknown as FormEvent,
                                                    );
                                                }
                                            }
                                        }}
                                        aria-invalid={!!replyError}
                                        placeholder="Nhập tin — Enter gửi, Shift+Enter xuống dòng"
                                    />
                                    <InputGroupAddon align="inline-end">
                                        <InputGroupButton
                                            type="button"
                                            variant="ghost"
                                            size="icon-xs"
                                            onClick={() =>
                                                fileInputRef.current?.click()
                                            }
                                            aria-label="Đính kèm ảnh"
                                        >
                                            <ImagePlus />
                                        </InputGroupButton>
                                        <InputGroupButton
                                            type="button"
                                            variant="ghost"
                                            size="icon-xs"
                                            onClick={() =>
                                                setShowEmoji((v) => !v)
                                            }
                                            aria-label="Chèn emoji"
                                        >
                                            <Smile />
                                        </InputGroupButton>
                                        <InputGroupButton
                                            type="submit"
                                            disabled={
                                                replyProcessing ||
                                                (!replyBody.trim() &&
                                                    !replyImage)
                                            }
                                        >
                                            {replyProcessing ? (
                                                <LoaderCircle data-icon="inline-start" />
                                            ) : (
                                                <Send data-icon="inline-start" />
                                            )}
                                            Gửi
                                        </InputGroupButton>
                                    </InputGroupAddon>
                                </InputGroup>
                                {replyError ? (
                                    <FieldDescription>
                                        {replyError}
                                    </FieldDescription>
                                ) : null}
                            </Field>
                        </FieldGroup>
                    </form>
                </div>

                <aside
                    className={cn(
                        'min-h-0 flex-col border-l',
                        focusMode ? 'hidden' : 'hidden xl:flex',
                    )}
                >
                    <div className="flex flex-col gap-3 border-b p-3">
                        <div className="flex items-center gap-2">
                            <Wifi />
                            <h3 className="text-sm font-medium">Trạng thái</h3>
                        </div>
                        <div className="grid grid-cols-2 gap-2">
                            <StatusBadge status={activeConversation.status} />
                            <StatusBadge status={activeConversation.priority} />
                        </div>
                        <Separator />
                        <div className="flex flex-col gap-1 text-sm">
                            <span className="text-muted-foreground">Liên hệ</span>
                            <span className="truncate">
                                {activeConversation.contact?.phone ??
                                    activeConversation.contact?.email ??
                                    'Chưa có liên hệ'}
                            </span>
                        </div>
                        {activeConversation.contact?.id && (
                            <Button asChild variant="outline" size="sm">
                                <Link
                                    href={`/admin/contacts/${activeConversation.contact.id}`}
                                >
                                    <UserRoundCheck data-icon="inline-start" />
                                    Xem hồ sơ khách
                                </Link>
                            </Button>
                        )}
                    </div>

                    <div className="flex flex-col gap-3 p-3">
                        <FieldGroup>
                            <Field>
                                <FieldLabel>Chuyển cho</FieldLabel>
                                <div className="flex gap-2">
                                    <Select
                                        value={transferTo}
                                        onValueChange={onTransferToChange}
                                    >
                                        <SelectTrigger className="min-w-0 flex-1">
                                            <SelectValue placeholder="Chọn nhân viên" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectGroup>
                                                {agents.map((agent) => (
                                                    <SelectItem
                                                        key={agent.id}
                                                        value={String(agent.id)}
                                                    >
                                                        {agent.display_name ??
                                                            agent.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectGroup>
                                        </SelectContent>
                                    </Select>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={onSubmitTransfer}
                                        disabled={!transferTo}
                                    >
                                        <UserRoundCheck data-icon="inline-start" />
                                        Chuyển
                                    </Button>
                                </div>
                            </Field>
                        </FieldGroup>
                    </div>
                </aside>
            </div>
        </section>
    );
}
