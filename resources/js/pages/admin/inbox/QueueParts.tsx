import { Link } from '@inertiajs/react';
import {
    MessageCircleX,
    type LucideIcon,
    UserRoundX,
} from 'lucide-react';
import { StatusBadge, StatusDot } from '@/components/admin/status-badge';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';
import type { ConversationSummary } from '@/types';
import {
    customerName,
    initials,
    providerClass,
    providerLabel,
    type QueueTab,
} from './lib';

export function Metric({
    label,
    value,
    status,
}: {
    label: string;
    value: number;
    status: string;
}) {
    return (
        <div className="flex min-h-16 items-center justify-between gap-3 rounded-lg border bg-card px-3 py-2">
            <div className="min-w-0">
                <p className="truncate text-sm text-muted-foreground">{label}</p>
                <p className="[font-family:var(--font-mono)] text-xl font-semibold tabular-nums">
                    {value}
                </p>
            </div>
            <StatusBadge status={status} />
        </div>
    );
}

export function QueueTabTrigger({
    value,
    label,
    count,
}: {
    value: QueueTab;
    label: string;
    count: number;
}) {
    return (
        <TabsTrigger value={value} className="min-w-0 gap-1 min-h-10 sm:min-h-0">
            <span className="truncate">{label}</span>
            <span className="[font-family:var(--font-mono)] tabular-nums text-muted-foreground">
                {count}
            </span>
        </TabsTrigger>
    );
}

export function QueueSkeleton() {
    return (
        <div className="flex flex-col gap-3 p-4">
            {Array.from({ length: 7 }).map((_, index) => (
                <div key={index} className="flex items-center gap-3">
                    <Skeleton className="size-9 rounded-full" />
                    <div className="flex min-w-0 flex-1 flex-col gap-2">
                        <Skeleton className="h-4 w-2/3" />
                        <Skeleton className="h-3 w-full" />
                    </div>
                </div>
            ))}
        </div>
    );
}

/**
 * Compact stat pill (icon + tabular count) for the queue-header strip.
 * Operator-scan design: no label, full meaning on tooltip / aria-label.
 * Color = semantic status (danger / warn / info / idle / ok).
 *
 * When `active` is true the pill is rendered as a filled background to show
 * the current drill-down filter.
 */
export function StatPill({
    icon: Icon,
    count,
    tone,
    label,
    active = false,
    onClick,
    pulse = false,
}: {
    icon: LucideIcon;
    count: number;
    tone: 'ok' | 'warn' | 'danger' | 'info' | 'idle';
    label: string;
    active?: boolean;
    onClick?: () => void;
    pulse?: boolean;
}) {
    // The base visual style: icon + bold number, mono font for tabular nums.
    // Hover/active adds a soft background to indicate clickability.
    const toneColor = `var(--status-${tone}-fg)`;
    const toneBg = `var(--status-${tone}-bg)`;
    const toneBorder = `var(--status-${tone}-border)`;

    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={onClick}
            title={label}
            aria-label={label}
            aria-pressed={active}
            className={cn(
                'h-auto gap-1.5 rounded-md px-2 py-1 [font-family:var(--font-mono)] [font-variant-numeric:tabular-nums] text-[11px]',
                'transition-colors',
                // Idle tone is muted; colored tones light up their value.
                count === 0 && 'opacity-50',
                active && 'border',
            )}
            style={{
                color: count > 0 ? toneColor : undefined,
                backgroundColor: active ? toneBg : undefined,
                borderColor: active ? toneBorder : undefined,
            }}
        >
            <Icon
                className={cn(
                    'size-3 stroke-[2.25]',
                    pulse && count > 0 && 'animate-pulse',
                )}
                aria-hidden
            />
            <strong className="text-[11px] font-bold leading-none">
                {count}
            </strong>
        </Button>
    );
}

export { MessageCircleX, UserRoundX };

/* Sub-stat pill — mockup §3.5: 4 colored pills under the queue
   filter tabs. Tone picks the status color; click toggles a
   filter (or a hint toast for failed). */
export function SubStatPill({
    tone,
    count,
    label,
    active = false,
    onClick,
}: {
    tone: 'ok' | 'info' | 'warn' | 'danger';
    count: number;
    label: string;
    active?: boolean;
    onClick?: () => void;
}) {
    const toneClass = {
        ok: '[color:var(--status-ok-fg)] [border-color:var(--status-ok-border)] [background-color:var(--status-ok-bg)]',
        info: '[color:var(--status-info-fg)] [border-color:var(--status-info-border)] [background-color:var(--status-info-bg)]',
        warn: '[color:var(--status-warn-fg)] [border-color:var(--status-warn-border)] [background-color:var(--status-warn-bg)]',
        danger:
            '[color:var(--status-danger-fg)] [border-color:var(--status-danger-border)] [background-color:var(--status-danger-bg)]',
    }[tone];
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-[11px] font-medium tabular-nums transition-colors',
                toneClass,
                active && 'ring-2 ring-offset-1 ring-primary',
            )}
            aria-pressed={active}
        >
            <span className="font-semibold">{count}</span>
            <span className="font-normal opacity-90">{label}</span>
        </button>
    );
}

export function ConversationRow({
    conversation,
    active,
}: {
    conversation: ConversationSummary;
    active: boolean;
}) {
    const name = customerName(conversation);
    const lastDirection =
        conversation.lastDirection === 'OUTBOUND' ? 'Bạn: ' : '';

    return (
        <Link
            href={`/admin/inbox?conversation=${conversation.id}`}
            preserveScroll
            className={cn(
                'flex min-h-[104px] flex-col gap-2 border-b px-3 py-3 transition-all focus-visible:outline-none border-l-4 border-l-transparent',
                active ? 'bg-background border-l-primary shadow-sm' : 'hover:bg-background/40',
                conversation.isUnanswered && !active && 'bg-primary/5',
            )}
        >
            <div className="flex items-start gap-3">
                <div className="relative">
                    <Avatar className="size-9">
                        {conversation.contact?.avatarUrl && (
                            <AvatarImage
                                src={conversation.contact.avatarUrl}
                                alt={name}
                            />
                        )}
                        <AvatarFallback>{initials(name)}</AvatarFallback>
                    </Avatar>
                    {conversation.isUnanswered &&
                        (conversation.unreadCount && conversation.unreadCount > 0 ? (
                            <span className="absolute -right-1.5 -top-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold tabular-nums text-primary-foreground ring-2 ring-background">
                                {conversation.unreadCount > 99
                                    ? '99+'
                                    : conversation.unreadCount}
                            </span>
                        ) : (
                            <span className="absolute -right-0.5 -top-0.5 size-2.5 rounded-full [background-color:var(--status-ok-fg)] ring-2 ring-background" />
                        ))}
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex items-center justify-between gap-2">
                        <div className="flex min-w-0 items-center gap-2">
                            <StatusDot status={conversation.slaState} />
                            <span
                                className={cn(
                                    'truncate',
                                    conversation.isUnanswered
                                        ? 'font-semibold'
                                        : 'font-medium',
                                )}
                            >
                                {name}
                            </span>
                        </div>
                        <span className="[font-family:var(--font-mono)] shrink-0 text-xs tabular-nums text-muted-foreground">
                            {conversation.lastMessageAt ?? '-'}
                        </span>
                    </div>
                    <p
                        className={cn(
                            'mt-1 line-clamp-2 text-sm',
                            conversation.isUnanswered
                                ? 'text-foreground'
                                : 'text-muted-foreground',
                        )}
                    >
                        {lastDirection}
                        {conversation.lastMessage || 'Chưa có tin'}
                    </p>
                </div>
            </div>

            <div className="flex items-center justify-between gap-2 pl-12">
                <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                    <Badge
                        variant="outline"
                        className={cn(
                            'max-w-32 truncate',
                            providerClass(conversation.channel),
                        )}
                    >
                        {providerLabel(conversation.channel)}
                    </Badge>
                    <StatusBadge
                        status={conversation.status}
                        className="max-w-32"
                    />
                    {conversation.lastMessageStatus && (
                        <StatusBadge
                            status={conversation.lastMessageStatus}
                            className="max-w-28"
                        />
                    )}
                </div>
                <span className="max-w-28 truncate text-xs text-muted-foreground">
                    {conversation.owner?.name ?? 'Chưa gán'}
                </span>
            </div>
        </Link>
    );
}
