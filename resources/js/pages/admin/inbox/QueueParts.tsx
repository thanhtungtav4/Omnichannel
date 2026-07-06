import { Link } from '@inertiajs/react';
import { StatusBadge, StatusDot } from '@/components/admin/status-badge';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
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
        <TabsTrigger value={value} className="min-w-0 gap-1">
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
                'flex min-h-[104px] flex-col gap-2 border-b px-3 py-3 transition-colors hover:bg-accent focus-visible:bg-accent focus-visible:outline-none',
                active && 'bg-accent',
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
                    {conversation.isUnanswered && (
                        <span className="absolute -right-0.5 -top-0.5 size-2.5 rounded-full [background-color:var(--status-ok-fg)] ring-2 ring-background" />
                    )}
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
