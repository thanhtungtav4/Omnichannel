import { cn } from '@/lib/utils';
import { MESSAGE_STATUS_VI, type ThreadMessage } from './lib';

export function DayDivider({ label }: { label: string }) {
    return (
        <div className="my-2 flex items-center justify-center">
            <span className="rounded-full bg-muted px-3 py-0.5 text-xs text-muted-foreground">
                {label}
            </span>
        </div>
    );
}

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
                        '[background-color:var(--status-danger-bg)] [color:var(--status-danger-fg)]',
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
