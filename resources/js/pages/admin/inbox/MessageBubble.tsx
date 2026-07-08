import {
    AlertTriangle,
    Archive,
    CheckCircle2,
    Pin,
    Send,
    StickyNote,
    UserRoundCheck,
} from 'lucide-react';
import { type ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { MESSAGE_STATUS_VI, type ThreadMessage } from './lib';

/* ── Day divider ─────────────────────────────────────────────────────────── */
export function DayDivider({ label }: { label: string }) {
    return (
        <div className="my-2 flex items-center justify-content-center">
            <span className="rounded-full bg-muted px-3 py-0.5 text-xs font-medium text-muted-foreground">
                {label}
            </span>
        </div>
    );
}

/* ── System event pill (assignment, close, send-success) ─────────────────── */
export function SystemEvent({
    icon = 'info',
    text,
    tone = 'idle',
}: {
    icon?: 'info' | 'assignment' | 'sent' | 'closed' | 'failed';
    text: string;
    tone?: 'idle' | 'ok' | 'danger';
}) {
    const Icon = matchIcon(icon);
    const colorClass =
        tone === 'danger'
            ? '[color:var(--status-danger-fg)] [border-color:var(--status-danger-border)]'
            : tone === 'ok'
              ? '[color:var(--status-ok-fg)] [border-color:var(--status-ok-border)]'
              : 'text-muted-foreground border-border';
    return (
        <div className="my-2 flex items-center justify-content-center">
            <span
                className={cn(
                    'inline-flex items-center gap-1.5 rounded-full border bg-card px-2.5 py-0.5 text-xs',
                    colorClass,
                )}
            >
                <Icon className="size-3" />
                {text}
            </span>
        </div>
    );
}

function matchIcon(name: string) {
    switch (name) {
        case 'assignment':
            return UserRoundCheck;
        case 'sent':
            return Send;
        case 'closed':
            return Archive;
        case 'failed':
            return AlertTriangle;
        case 'closed-success':
            return CheckCircle2;
        default:
            return CheckCircle2;
    }
}

/* ── Internal note bubble ─────────────────────────────────────────────────── */
export function NoteBubble({
    author,
    body,
    pinned = false,
}: {
    author?: string | null;
    body: string;
    pinned?: boolean;
}) {
    return (
        <div className="my-2 flex items-start gap-2 rounded-lg border [background-color:var(--status-warn-bg)] [border-color:var(--status-warn-border)] [color:var(--status-warn-fg)] px-3 py-2 text-xs max-w-[78%] mx-auto">
            <StickyNote className="mt-0.5 size-3.5 shrink-0" />
            <div className="flex-1 min-w-0">
                <div className="font-semibold text-[11px] flex items-center gap-1">
                    {pinned && <Pin className="size-2.5" />}
                    {author && <span>{author}</span>}
                    <span>· ghi chú nội bộ</span>
                </div>
                <div className="mt-0.5 whitespace-pre-wrap [overflow-wrap:anywhere]">
                    {body}
                </div>
            </div>
        </div>
    );
}

/* ── Regular message bubble (text/image/file + delivery state) ──────────── */
export function MessageBubble({
    message,
    isGroup,
}: {
    message: ThreadMessage;
    isGroup?: boolean;
}) {
    const isOutbound = message.direction === 'OUTBOUND';
    const failed =
        message.status === 'FAILED' || message.outboxStatus === 'FAILED';
    const rawStatus = message.outboxStatus ?? message.status;
    const statusLabel = MESSAGE_STATUS_VI[rawStatus ?? ''] ?? rawStatus;

    // In a group, inbound bodies are "Sender: text" — split so the name renders
    // as a small header above the message, like a real group chat.
    let senderName: string | null = null;
    let body = message.body ?? '';
    if (isGroup && !isOutbound && body.includes(': ')) {
        const idx = body.indexOf(': ');
        senderName = body.slice(0, idx);
        body = body.slice(idx + 2);
    }

    return (
        <div
            className={cn(
                'flex min-w-0',
                isOutbound ? 'justify-end' : 'justify-start',
            )}
        >
            <div
                className={cn(
                    'flex min-w-0 max-w-[78%] flex-col gap-1.5 rounded-2xl px-3.5 py-2 text-sm',
                    isOutbound
                        ? 'rounded-br-sm bg-primary text-primary-foreground'
                        : 'rounded-bl-sm bg-muted',
                    failed &&
                        '[background-color:var(--status-danger-bg)] [color:var(--status-danger-fg)] border [border-color:var(--status-danger-border)]',
                )}
            >
                {senderName && (
                    <span className="text-xs font-semibold text-primary">
                        {senderName}
                    </span>
                )}
                {/* Image / sticker preview */}
                {message.attachmentUrl &&
                ['IMAGE', 'STICKER'].includes(message.messageType ?? '') ? (
                    <a
                        href={message.attachmentUrl}
                        target="_blank"
                        rel="noreferrer"
                        className="block"
                    >
                        <img
                            src={message.attachmentUrl}
                            alt={body || 'Hình ảnh'}
                            loading="lazy"
                            className="max-h-64 max-w-full rounded-lg object-cover"
                        />
                    </a>
                ) : message.attachmentUrl &&
                  ['VIDEO', 'FILE', 'AUDIO'].includes(
                      message.messageType ?? '',
                  ) ? (
                    <a
                        href={message.attachmentUrl}
                        target="_blank"
                        rel="noreferrer"
                        className="underline [overflow-wrap:anywhere]"
                    >
                        {body || 'Tải tệp'}
                    </a>
                ) : (
                    <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">
                        {body || `[${message.messageType ?? 'không hỗ trợ'}]`}
                    </p>
                )}
                {message.outboxError ? (
                    <p className="text-xs opacity-90">⚠ {message.outboxError}</p>
                ) : null}
                <div
                    className={cn(
                        'flex items-center justify-end gap-2 text-xs sm:text-[11px]',
                        isOutbound && !failed
                            ? 'text-primary-foreground/70'
                            : 'text-muted-foreground',
                    )}
                >
                    <span>{message.timeLabel ?? '-'}</span>
                    {/* Only outbound shows a delivery state; inbound is implicitly received. */}
                    {isOutbound && (
                        <span className="inline-flex items-center gap-1">
                            {failed && <span aria-hidden>·</span>}
                            {statusLabel}
                        </span>
                    )}
                </div>
            </div>
        </div>
    );
}

/* ── List wrapper: inserts day dividers and routes to the right bubble ─── */
export function MessageList({
    messages,
    isGroup,
}: {
    messages: ThreadMessage[];
    isGroup?: boolean;
}) {
    // Group consecutive messages with the same dateIso so we only emit one
    // divider per day. The server already orders messages oldest → newest.
    const rendered: ReactNode[] = [];
    let lastDate: string | null = null;

    messages.forEach((message, idx) => {
        const date = message.dateIso ?? null;
        if (date && date !== lastDate) {
            rendered.push(
                <DayDivider
                    key={`day-${idx}`}
                    label={formatDayLabel(date)}
                />,
            );
            lastDate = date;
        }

        // Route by kind. Mockup uses 'system' for assignment/close/sent
        // events and 'note' for internal notes between agents.
        const kind = (message as ThreadMessage & { kind?: string }).kind;
        if (kind === 'system' || message.senderType === 'SYSTEM') {
            rendered.push(
                <SystemEvent
                    key={message.id}
                    icon={systemIconFor(message.body ?? '')}
                    text={message.body ?? ''}
                    tone={systemToneFor(message.body ?? '')}
                />,
            );
            return;
        }
        if (kind === 'note' || message.messageType === 'NOTE') {
            rendered.push(
                <NoteBubble
                    key={message.id}
                    author={message.senderName ?? undefined}
                    body={message.body ?? ''}
                />,
            );
            return;
        }
        rendered.push(
            <MessageBubble
                key={message.id}
                message={message}
                isGroup={isGroup}
            />,
        );
    });

    return <>{rendered}</>;
}

function formatDayLabel(date: string): string {
    // date is YYYY-MM-DD. Compare to today and yesterday in local tz.
    const today = new Date().toISOString().slice(0, 10);
    if (date === today) return 'Hôm nay';
    const yesterday = new Date(Date.now() - 86_400_000)
        .toISOString()
        .slice(0, 10);
    if (date === yesterday) return 'Hôm qua';
    return date;
}

function systemIconFor(body: string):
    | 'info'
    | 'assignment'
    | 'sent'
    | 'closed'
    | 'failed'
    | 'closed-success' {
    if (/gán|assign/i.test(body)) return 'assignment';
    if (/gửi đến|sent|delivered/i.test(body)) return 'sent';
    if (/đóng.*lỗi|failed/i.test(body)) return 'failed';
    if (/đóng hội thoại|closed/i.test(body)) return 'closed';
    if (/gửi|send/i.test(body)) return 'sent';
    return 'info';
}

function systemToneFor(body: string): 'idle' | 'ok' | 'danger' {
    if (/lỗi|failed/i.test(body)) return 'danger';
    if (/gán|gửi|đã|đóng hội thoại/i.test(body)) return 'ok';
    return 'idle';
}