import { Link } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    ChevronDown,
    Copy,
    Flag,
    InboxIcon,
    Info,
    LoaderCircle,
    Mail,
    Maximize2,
    MessageCirclePlus,
    ImagePlus,
    Minimize2,
    MoreHorizontal,
    Phone,
    RotateCcw,
    Search,
    Send,
    Smile,
    Tag as TagIcon,
    Timer,
    UserRoundCheck,
    UserPlus,
    X,
} from 'lucide-react';
import { type FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { StatusBadge } from '@/components/admin/status-badge';
import { TagEditor } from '@/components/admin/tag-editor';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
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
import { MessageList } from './MessageBubble';
import { TransferSheet, CloseSheet } from './TransferSheet';
import {
    customerName,
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
    composerMode,
    onComposerModeChange,
    transferTo,
    focusMode,
    onToggleFocus,
    onReplyBodyChange,
    onReplyImageChange,
    onSubmitReply,
    onTransferToChange,
    onSubmitTransfer,
    onCloseConversation,
    onReopenConversation,
}: {
    activeConversation: ActiveConversation | null;
    agents: AgentOption[];
    replyBody: string;
    replyImage: File | null;
    replyError?: string;
    replyProcessing: boolean;
    composerMode: 'reply' | 'comment';
    onComposerModeChange: (mode: 'reply' | 'comment') => void;
    transferTo: string;
    focusMode: boolean;
    onToggleFocus: () => void;
    onReplyBodyChange: (body: string) => void;
    onReplyImageChange: (image: File | null) => void;
    onSubmitReply: (event: FormEvent) => void;
    onTransferToChange: (agentId: string) => void;
    onSubmitTransfer: (userId?: string) => void;
    onCloseConversation: () => void;
    onReopenConversation: () => void;
}) {
    // Hooks must run unconditionally (before any early return).
    const threadEndRef = useRef<HTMLDivElement>(null);
    const messageCount = activeConversation?.messages.length ?? 0;
    const activeId = activeConversation?.id;
    const prevCountRef = useRef(messageCount);

    // Auto-scroll to the newest message. On opening a conversation jump
    // instantly (auto); a later new message glides (smooth). rAF waits for the
    // messages to paint so the scroll lands at the true bottom.
    const justOpenedRef = useRef(true);
    useEffect(() => {
        justOpenedRef.current = true;
    }, [activeId]);
    useEffect(() => {
        const behavior = justOpenedRef.current ? 'auto' : 'smooth';
        justOpenedRef.current = false;
        requestAnimationFrame(() => {
            threadEndRef.current?.scrollIntoView({ behavior, block: 'end' });
        });
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
    // The observer root must be the ScrollArea's scroll viewport, not the
    // browser window, or "at bottom" is measured against the wrong container.
    const [atBottom, setAtBottom] = useState(true);
    useEffect(() => {
        const el = threadEndRef.current;
        if (!el) return;
        const root = el.closest('[data-radix-scroll-area-viewport]');
        const io = new IntersectionObserver(
            ([entry]) => setAtBottom(entry.isIntersecting),
            { root, threshold: 0.1 },
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

    // Mobile keyboard awareness — keep the composer above the on-screen
    // keyboard so the typed text stays in view. visualViewport fires on
    // mobile keyboards opening/closing; we mirror the keyboard height as
    // bottom-padding on the composer.
    useEffect(() => {
        if (typeof window === 'undefined' || !window.visualViewport) return;
        const vv = window.visualViewport;
        const onResize = () => {
            const offsetBottom =
                window.innerHeight - vv.height - vv.offsetTop;
            const isMobile = window.matchMedia('(max-width: 767px)').matches;
            const form = document.querySelector(
                'form[data-composer]',
            ) as HTMLElement | null;
            if (form && isMobile && offsetBottom > 80) {
                form.style.paddingBottom = `calc(12px + ${offsetBottom}px + env(safe-area-inset-bottom, 0px))`;
            } else if (form && isMobile) {
                form.style.paddingBottom = '';
            }
        };
        vv.addEventListener('resize', onResize);
        return () => vv.removeEventListener('resize', onResize);
    }, [activeId]);

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
    const [transferOpen, setTransferOpen] = useState(false);
    const [closeOpen, setCloseOpen] = useState(false);
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
    const contact = activeConversation.contact;

    return (
        <section className="flex min-h-0 flex-col overflow-hidden">
            <header className="flex shrink-0 flex-col gap-3 border-b p-3 xl:flex-row xl:items-center xl:justify-between">
                <div className="flex min-w-0 items-center gap-3">
                    <Link
                        href="/admin/inbox"
                        preserveScroll={false}
                        className="mr-1 flex size-11 shrink-0 items-center justify-center rounded-md hover:bg-muted lg:hidden"
                        title="Quay lại danh sách"
                        aria-label="Quay lại danh sách"
                    >
                        <ArrowLeft className="size-5" />
                    </Link>
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
                        <div className="flex min-w-0 flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                            {/* Compact channel dot (mockup). Hover shows full name. */}
                            <span
                                className={cn(
                                    'inline-block size-2 shrink-0 rounded-full',
                                    providerClass(activeConversation.channel),
                                )}
                                style={{
                                    backgroundColor: `var(--channel-${(
                                        activeConversation.channel ?? ''
                                    )
                                        .toLowerCase()
                                        .replace(/_.*$/, '')})`,
                                }}
                                title={providerLabel(activeConversation.channel)}
                                aria-label={providerLabel(activeConversation.channel)}
                            />
                            <span className="font-medium text-foreground">
                                {providerLabel(activeConversation.channel)}
                            </span>
                            <span>·</span>
                            {activeConversation.owner ? (
                                <span className="flex items-center gap-1">
                                    <span
                                        className={cn(
                                            'inline-block size-1.5 rounded-full',
                                            activeConversation.owner.online
                                                ? '[background-color:var(--status-ok-fg)]'
                                                : '[background-color:var(--status-idle-fg)]',
                                        )}
                                    />
                                    <span>
                                        {activeConversation.owner.name} đang xử lý
                                    </span>
                                </span>
                            ) : (
                                <span className="font-medium [color:var(--status-warn-fg)]">
                                    Chưa gán ai
                                </span>
                            )}
                        </div>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    {/* SLA countdown pill — visible on every open conversation
                        so the operator can see at a glance whether they're
                        tracking against the first-response SLA. */}
                    <SlaPill
                        state={activeConversation.slaState ?? 'OK'}
                        seconds={activeConversation.slaSeconds ?? null}
                    />

                    {/* Priority dot (mockup) — URGENT/HIGH/NORMAL visual signal
                        next to the SLA pill. Tighter than the full StatusBadge. */}
                    <PriorityDot priority={activeConversation.priority} />

                    {/* Inline assign — HubSpot puts the owner picker on the header. */}
                    <Select
                        value={
                            activeConversation.owner
                                ? String(activeConversation.owner.id)
                                : ''
                        }
                        onValueChange={(id) => {
                            onTransferToChange(id);
                            onSubmitTransfer(id);
                        }}
                    >
                        <SelectTrigger className="h-9 w-40">
                            <SelectValue placeholder="Chưa gán" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                {agents.map((agent) => (
                                    <SelectItem
                                        key={agent.id}
                                        value={String(agent.id)}
                                    >
                                        {agent.display_name ?? agent.name}
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        </SelectContent>
                    </Select>

                    {/* Mobile-only customer info sheet trigger. */}
                    <Sheet>
                        <SheetTrigger asChild>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                className="size-11 sm:size-9 lg:hidden"
                                title="Thông tin khách"
                                aria-label="Thông tin khách"
                            >
                                <Info />
                            </Button>
                        </SheetTrigger>
                        <SheetContent side="right" className="w-[300px] p-0">
                            <SheetHeader className="border-b">
                                <SheetTitle>Thông tin khách</SheetTitle>
                                <SheetDescription className="sr-only">
                                    Chi tiết liên hệ và hành động nhanh
                                </SheetDescription>
                            </SheetHeader>
                            <div className="flex flex-col gap-3 overflow-y-auto p-4 text-sm">
                                <div className="flex items-center gap-3">
                                    <Avatar className="size-12">
                                        {contact?.avatarUrl && (
                                            <AvatarImage src={contact.avatarUrl} alt={name} />
                                        )}
                                        <AvatarFallback>{initials(name)}</AvatarFallback>
                                    </Avatar>
                                    <div className="min-w-0">
                                        <p className="truncate font-semibold" title={name}>
                                            {name}
                                        </p>
                                        {contact?.source && (
                                            <p className="text-xs text-muted-foreground">
                                                Nguồn: {contact.source}
                                            </p>
                                        )}
                                    </div>
                                </div>
                                <ContactInfoRow
                                    icon={<Phone className="size-3.5" />}
                                    label="SĐT"
                                    value={contact?.phone}
                                    href={contact?.phone ? `tel:${contact.phone}` : undefined}
                                />
                                <ContactInfoRow
                                    icon={<Mail className="size-3.5" />}
                                    label="Email"
                                    value={contact?.email}
                                    href={contact?.email ? `mailto:${contact.email}` : undefined}
                                />
                                <PanelRow
                                    label="Tin gần nhất"
                                    value={contact?.lastInboundAt ?? '—'}
                                />
                                {contact?.id && (
                                    <Button asChild variant="outline" size="sm" className="mt-2">
                                        <Link href={`/admin/contacts/${contact.id}`}>
                                            <UserRoundCheck data-icon="inline-start" />
                                            Xem hồ sơ đầy đủ
                                        </Link>
                                    </Button>
                                )}
                            </div>
                        </SheetContent>
                    </Sheet>

                    {/* Search-in-thread button (also exposed in the kebab). */}
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="size-11 sm:size-9"
                        onClick={() => setShowSearch((v) => !v)}
                        title="Tìm trong hội thoại (⌘F)"
                        aria-label="Search in thread"
                    >
                        <Search />
                    </Button>

                    {/* Kebab menu — secondary actions (mockup). */}
                    <ThreadKebab
                        onFocusToggle={onToggleFocus}
                        isFocused={focusMode}
                        isClosed={activeConversation.status === 'CLOSED'}
                        onSearchToggle={() => setShowSearch((v) => !v)}
                        onTransfer={() => setTransferOpen(true)}
                        onClose={() => setCloseOpen(true)}
                        onReopen={onReopenConversation}
                        onMarkSpam={() => toast.error('Đã đánh dấu spam (mockup)')}
                    />

                    {activeConversation.status === 'CLOSED' ? (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onReopenConversation}
                        >
                            <RotateCcw data-icon="inline-start" />
                            Mở lại
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onCloseConversation}
                        >
                            <CheckCircle2 data-icon="inline-start" />
                            Đóng
                        </Button>
                    )}
                </div>
            </header>

            <div
                className={cn(
                    'grid min-h-0 flex-1',
                    focusMode
                        ? 'grid-cols-1'
                        : 'lg:grid-cols-[minmax(0,1fr)_300px] xl:grid-cols-[minmax(0,1fr)_320px]',
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
                            className="absolute bottom-24 left-1/2 z-10 -translate-x-1/2 rounded-full shadow-md min-h-11 sm:min-h-8"
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
                                <MessageList
                                    messages={shownMessages}
                                    isGroup={activeConversation.isGroup}
                                />
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

                    <form
                        data-composer
                        onSubmit={onSubmitReply}
                        className="relative shrink-0 border-t p-3 pb-[calc(12px+env(safe-area-inset-bottom,0px))]"
                    >
                        {/* Reply (to customer) vs Comment (internal note). */}
                        <div className="mb-2 inline-flex rounded-md border p-0.5 text-sm">
                            <button
                                type="button"
                                onClick={() => onComposerModeChange('reply')}
                                className={cn(
                                    'rounded px-3 py-1.5 font-medium min-h-9 sm:min-h-0 sm:py-1',
                                    composerMode === 'reply'
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground',
                                )}
                            >
                                Trả lời
                            </button>
                            <button
                                type="button"
                                onClick={() => onComposerModeChange('comment')}
                                className={cn(
                                    'rounded px-3 py-1.5 font-medium min-h-9 sm:min-h-0 sm:py-1',
                                    composerMode === 'comment'
                                        ? '[background-color:var(--status-warn-fg)] text-white'
                                        : 'text-muted-foreground',
                                )}
                            >
                                Ghi chú khách
                            </button>
                        </div>
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
                                    {composerMode === 'comment' ? (
                                        <span className="[color:var(--status-warn-fg)]">
                                            Ghi chú khách (lưu vào hồ sơ, chỉ nhân viên thấy)
                                        </span>
                                    ) : (
                                        <>
                                            Trả lời{' '}
                                            <span className="text-xs font-normal text-muted-foreground">
                                                (gõ / để chèn mẫu)
                                            </span>
                                        </>
                                    )}
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
                                        placeholder={
                                            composerMode === 'comment'
                                                ? 'Ghi chú cho đồng nghiệp — không gửi cho khách'
                                                : 'Nhập tin — Enter gửi, Shift+Enter xuống dòng'
                                        }
                                    />
                                    <InputGroupAddon align="inline-end">
                                        {composerMode === 'reply' && (
                                            <InputGroupButton
                                                type="button"
                                                variant="ghost"
                                                size="icon-xs"
                                                className="size-11 sm:size-6"
                                                onClick={() =>
                                                    fileInputRef.current?.click()
                                                }
                                                aria-label="Đính kèm ảnh"
                                            >
                                                <ImagePlus />
                                            </InputGroupButton>
                                        )}
                                        <InputGroupButton
                                            type="button"
                                            variant="ghost"
                                            size="icon-xs"
                                            className="size-11 sm:size-6"
                                            onClick={() =>
                                                setShowEmoji((v) => !v)
                                            }
                                            aria-label="Chèn emoji"
                                        >
                                            <Smile />
                                        </InputGroupButton>
                                        <InputGroupButton
                                            type="submit"
                                            className="min-h-11 sm:min-h-0"
                                            disabled={
                                                replyProcessing ||
                                                (!replyBody.trim() &&
                                                    (composerMode === 'comment' ||
                                                        !replyImage))
                                            }
                                        >
                                            {replyProcessing ? (
                                                <LoaderCircle data-icon="inline-start" />
                                            ) : (
                                                <Send data-icon="inline-start" />
                                            )}
                                            {composerMode === 'comment'
                                                ? 'Lưu ghi chú'
                                                : 'Gửi'}
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
                        'min-h-0 flex-col border-l bg-muted/20 shrink-0 lg:w-[300px] xl:w-[320px]',
                        focusMode ? 'hidden' : 'hidden lg:flex',
                    )}
                >
                    <ScrollArea className="min-h-0 flex-1">
                        <div className="flex flex-col gap-4 p-3 w-full min-w-0 overflow-hidden">
                            {/* About contact — HubSpot right-rail. */}
                            <div className="flex flex-col items-center gap-2 text-center w-full min-w-0">
                                <Avatar className="size-16">
                                    {activeConversation.contact?.avatarUrl && (
                                        <AvatarImage
                                            src={
                                                activeConversation.contact
                                                    .avatarUrl
                                            }
                                            alt={name}
                                        />
                                    )}
                                    <AvatarFallback className="text-lg">
                                        {initials(name)}
                                    </AvatarFallback>
                                </Avatar>
                                <div className="min-w-0 w-full px-4 text-center">
                                    <p className="truncate text-base font-semibold" title={name}>
                                        {name}
                                    </p>
                                    {activeConversation.contact?.source && (
                                        <p className="text-xs text-muted-foreground">
                                            Nguồn:{' '}
                                            {activeConversation.contact.source}
                                        </p>
                                    )}
                                </div>
                                {activeConversation.contact?.id && (
                                    <Button
                                        asChild
                                        variant="outline"
                                        size="sm"
                                        className="w-full"
                                    >
                                        <Link
                                            href={`/admin/contacts/${activeConversation.contact.id}`}
                                        >
                                            <UserRoundCheck data-icon="inline-start" />
                                            Xem hồ sơ đầy đủ
                                        </Link>
                                    </Button>
                                )}
                            </div>

                            <PanelSection title="Thông tin">
                                <ContactInfoRow
                                    icon={<Phone className="size-3.5" />}
                                    label="SĐT"
                                    value={activeConversation.contact?.phone}
                                    href={
                                        activeConversation.contact?.phone
                                            ? `tel:${activeConversation.contact.phone}`
                                            : undefined
                                    }
                                />
                                <ContactInfoRow
                                    icon={<Mail className="size-3.5" />}
                                    label="Email"
                                    value={activeConversation.contact?.email}
                                    href={
                                        activeConversation.contact?.email
                                            ? `mailto:${activeConversation.contact.email}`
                                            : undefined
                                    }
                                />
                                <PanelRow
                                    label="Tin gần nhất"
                                    value={
                                        activeConversation.contact
                                            ?.lastInboundAt ?? '—'
                                    }
                                />
                            </PanelSection>

                            {activeConversation.contact?.id && (
                                <PanelSection title="Tag">
                                    <TagEditor
                                        contactId={activeConversation.contact.id}
                                        tags={
                                            activeConversation.contact.tags ?? []
                                        }
                                    />
                                </PanelSection>
                            )}

                            {!!activeConversation.contact?.identities?.length && (
                                <PanelSection title="Định danh kênh">
                                    <div className="flex flex-wrap gap-1.5">
                                        {activeConversation.contact.identities.map(
                                            (id) => (
                                                <Badge
                                                    key={id.id}
                                                    variant="outline"
                                                    className={cn(
                                                        'max-w-full truncate',
                                                        providerClass(
                                                            id.provider,
                                                        ),
                                                    )}
                                                    title={id.providerUserId}
                                                >
                                                    {providerLabel(id.provider)}
                                                </Badge>
                                            ),
                                        )}
                                    </div>
                                </PanelSection>
                            )}

                            {!!activeConversation.contact?.leads?.length && (
                                <PanelSection title="Cơ hội / Lead">
                                    {activeConversation.contact.leads.map(
                                        (lead) => (
                                            <div
                                                key={lead.id}
                                                className="flex items-center justify-between gap-2 rounded-md border px-2 py-1.5 text-sm min-w-0 overflow-hidden"
                                            >
                                                <span className="min-w-0 truncate" title={lead.title}>
                                                    {lead.title}
                                                </span>
                                                <StatusBadge
                                                    status={lead.status}
                                                    className="shrink-0"
                                                />
                                            </div>
                                        ),
                                    )}
                                </PanelSection>
                            )}

                            {!!activeConversation.contact?.notes?.length && (
                                <PanelSection title="Ghi chú">
                                    {activeConversation.contact.notes.map(
                                        (note) => (
                                            <p
                                                key={note.id}
                                                className={cn(
                                                    'rounded-md border px-2 py-1.5 text-sm',
                                                    note.pinned
                                                        ? '[background-color:var(--status-warn-bg)] [border-color:var(--status-warn-border)]'
                                                        : 'bg-muted/40',
                                                )}
                                            >
                                                {note.body}
                                            </p>
                                        ),
                                    )}
                                </PanelSection>
                            )}

                            {!!activeConversation.contact?.otherConversations
                                ?.length && (
                                <PanelSection title="Hội thoại khác">
                                    {activeConversation.contact.otherConversations.map(
                                        (c) => (
                                            <Link
                                                key={c.id}
                                                href={`/admin/inbox?conversation=${c.id}`}
                                                preserveScroll
                                                className="flex items-center justify-between gap-2 rounded-md border px-2 py-1.5 text-sm hover:bg-accent"
                                            >
                                                <Badge
                                                    variant="outline"
                                                    className={cn(
                                                        'shrink-0',
                                                        providerClass(c.channel),
                                                    )}
                                                >
                                                    {providerLabel(c.channel)}
                                                </Badge>
                                                <span className="truncate text-xs text-muted-foreground">
                                                    {c.lastMessageAt ?? ''}
                                                </span>
                                            </Link>
                                        ),
                                    )}
                                </PanelSection>
                            )}

                            <Separator />

                            <FieldGroup>
                                <Field>
                                    <FieldLabel>Chuyển cho</FieldLabel>
                                    <div className="flex gap-2 w-full min-w-0">
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
                                                            value={String(
                                                                agent.id,
                                                            )}
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
                                            onClick={() => onSubmitTransfer()}
                                            disabled={!transferTo}
                                        >
                                            <UserRoundCheck data-icon="inline-start" />
                                            Chuyển
                                        </Button>
                                    </div>
                                </Field>
                            </FieldGroup>
                        </div>
                    </ScrollArea>
                </aside>
            </div>

            {/* Mockup §3.6 sheets. */}
            <TransferSheet
                open={transferOpen}
                onOpenChange={setTransferOpen}
                agents={agents}
                processing={false}
                onSubmit={({ agentId }) => {
                    setTransferOpen(false);
                    onTransferToChange(agentId);
                    onSubmitTransfer(agentId);
                }}
            />
            <CloseSheet
                open={closeOpen}
                onOpenChange={setCloseOpen}
                processing={false}
                onSubmit={(reason) => {
                    setCloseOpen(false);
                    void reason; // backend wires reason via TODO endpoint
                    onCloseConversation();
                }}
            />
        </section>
    );
}

function PanelSection({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-2">
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                {title}
            </p>
            {children}
        </div>
    );
}

function PanelRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-center justify-between gap-2 text-sm">
            <span className="text-muted-foreground">{label}</span>
            <span className="min-w-0 truncate text-right">{value}</span>
        </div>
    );
}

/**
 * Contact detail row with a tappable value (call / mail) and a copy button —
 * lets an agent act on a phone/email without leaving the inbox.
 */
function ContactInfoRow({
    icon,
    label,
    value,
    href,
}: {
    icon: React.ReactNode;
    label: string;
    value?: string | null;
    href?: string;
}) {
    if (!value) {
        return (
            <div className="flex items-center justify-between gap-2 text-sm">
                <span className="flex items-center gap-1.5 text-muted-foreground">
                    {icon}
                    {label}
                </span>
                <span className="text-muted-foreground">—</span>
            </div>
        );
    }
    return (
        <div className="flex items-center justify-between gap-2 text-sm">
            <span className="flex shrink-0 items-center gap-1.5 text-muted-foreground">
                {icon}
                {label}
            </span>
            <span className="flex min-w-0 items-center gap-1">
                {href ? (
                    <a
                        href={href}
                        className="min-w-0 truncate text-primary hover:underline"
                        title={value}
                    >
                        {value}
                    </a>
                ) : (
                    <span className="min-w-0 truncate">{value}</span>
                )}
                <button
                    type="button"
                    onClick={() => {
                        navigator.clipboard?.writeText(value);
                        toast.success(`Đã copy ${label}`);
                    }}
                    className="flex size-6 shrink-0 items-center justify-center rounded hover:bg-accent"
                    aria-label={`Copy ${label}`}
                    title={`Copy ${label}`}
                >
                    <Copy className="size-3" />
                </button>
            </span>
        </div>
    );
}

/* ── SLA countdown pill (mockup §3.1) ────────────────────────────────────── */
function SlaPill({
    state,
    seconds,
}: {
    state: 'OK' | 'DUE_SOON' | 'BREACHED' | string;
    seconds: number | null;
}) {
    if (seconds === null) return null;
    const tone = state === 'BREACHED' ? 'danger' : state === 'DUE_SOON' ? 'warn' : 'ok';
    const text = slaText(state, seconds);
    const toneCls = {
        danger:
            '[background-color:var(--status-danger-bg)] [border-color:var(--status-danger-border)] [color:var(--status-danger-fg)] animate-sla-blink',
        warn: '[background-color:var(--status-warn-bg)] [border-color:var(--status-warn-border)] [color:var(--status-warn-fg)]',
        ok: '[background-color:var(--status-ok-bg)] [border-color:var(--status-ok-border)] [color:var(--status-ok-fg)]',
    }[tone as 'ok' | 'warn' | 'danger'];
    return (
        <span
            title="Phản hồi đầu tiên SLA"
            className={cn(
                'inline-flex items-center gap-1 rounded border px-1.5 py-0.5 [font-family:var(--font-mono)] [font-variant-numeric:tabular-nums] text-[11px] font-semibold',
                toneCls,
            )}
        >
            <Timer className="size-2.5" />
            {text}
        </span>
    );
}

function slaText(state: string, secs: number): string {
    const m = Math.floor(Math.abs(secs) / 60);
    const s = Math.abs(secs) % 60;
    const stamp = `${m}:${s.toString().padStart(2, '0')}`;
    if (state === 'BREACHED') return `Trễ ${stamp}`;
    if (state === 'DUE_SOON') return `Còn ${stamp}`;
    return stamp;
}

/* ── Priority dot (compact version of StatusBadge — mockup) ───────────────── */
function PriorityDot({ priority }: { priority: string }) {
    const map: Record<string, { tone: 'danger' | 'warn' | 'idle'; label: string }> = {
        URGENT: { tone: 'danger', label: 'Khẩn' },
        HIGH: { tone: 'warn', label: 'Cao' },
        NORMAL: { tone: 'idle', label: 'Thường' },
        LOW: { tone: 'idle', label: 'Thấp' },
    };
    const entry = map[priority] ?? { tone: 'idle' as const, label: priority };
    const toneCls = {
        danger: '[background-color:var(--status-danger-bg)] [border-color:var(--status-danger-border)] [color:var(--status-danger-fg)]',
        warn: '[background-color:var(--status-warn-bg)] [border-color:var(--status-warn-border)] [color:var(--status-warn-fg)]',
        idle: 'border-border text-muted-foreground',
    }[entry.tone];
    return (
        <span
            title={`Ưu tiên: ${entry.label}`}
          className={cn(
                'inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[11px] font-semibold',
                toneCls,
            )}
        >
            <span className="size-1.5 rounded-full bg-current" />
            {entry.label}
        </span>
    );
}

/* ── Kebab menu — secondary actions (mockup §3.4) ────────────────────────── */
function ThreadKebab({
    onFocusToggle,
    isFocused,
    isClosed,
    onSearchToggle,
    onTransfer,
    onClose,
    onReopen,
    onMarkSpam,
}: {
    onFocusToggle: () => void;
    isFocused: boolean;
    isClosed: boolean;
    onSearchToggle: () => void;
    onTransfer: () => void;
    onClose: () => void;
    onReopen: () => void;
    onMarkSpam: () => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-11 sm:size-9"
                    title="Thêm"
                    aria-label="More actions"
                >
                    <MoreHorizontal className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuItem onSelect={onSearchToggle}>
                    <Search />
                    <span>Tìm trong hội thoại</span>
                    <span className="ml-auto [font-family:var(--font-mono)] text-[10px] text-muted-foreground">
                        ⌘F
                    </span>
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() => toast.info('Mở panel gắn nhãn (TODO)')}
                >
                    <TagIcon />
                    <span>Gắn nhãn</span>
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() => toast.info('Mở CRM để thêm khách (TODO)')}
                >
                    <UserPlus />
                    <span>Thêm vào CRM</span>
                </DropdownMenuItem>
                {!isClosed && (
                    <DropdownMenuItem onSelect={onTransfer}>
                        <UserRoundCheck />
                        <span>Chuyển hội thoại</span>
                    </DropdownMenuItem>
                )}
                <DropdownMenuSeparator />
                {isClosed ? (
                    <DropdownMenuItem
                        onSelect={() => toast.info('Mở lại hội thoại (TODO)')}
                    >
                        <RotateCcw />
                        <span>Mở lại hội thoại</span>
                    </DropdownMenuItem>
                ) : (
                    <DropdownMenuItem
                        onSelect={() => toast.info('Mở form đóng (TODO)')}
                    >
                        <CheckCircle2 />
                        <span>Đóng hội thoại</span>
                    </DropdownMenuItem>
                )}
                <DropdownMenuItem onSelect={onFocusToggle}>
                    <Maximize2 />
                    <span>{isFocused ? 'Thoát toàn màn hình' : 'Phóng to'}</span>
                    <span className="ml-auto [font-family:var(--font-mono)] text-[10px] text-muted-foreground">
                        Esc
                    </span>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    onSelect={onMarkSpam}
                    className="text-destructive focus:text-destructive"
                >
                    <Flag />
                    <span>Đánh dấu spam</span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
