import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    MessageSquareText,
    RadioTower,
    RefreshCcw,
    Route,
    UsersRound,
} from 'lucide-react';
import { StatusBadge, StatusDot } from '@/components/admin/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type {
    AgentSummary,
    ChannelSummary,
    ConversationSummary,
    CrmStat,
    QueueSummary,
} from '@/types';

type OverviewProps = {
    stats: CrmStat[];
    channels: ChannelSummary[];
    queues: QueueSummary[];
    agents: AgentSummary[];
    recentConversations: ConversationSummary[];
    failedEvents: number;
    failedOutbox: number;
};

function metricIcon(label: string) {
    if (label.includes('conversation')) {
        return MessageSquareText;
    }

    if (label.includes('Waiting')) {
        return UsersRound;
    }

    if (label.includes('SLA')) {
        return AlertTriangle;
    }

    return Route;
}

function providerLabel(provider?: string | null) {
    return provider === 'ZALO'
        ? 'Zalo OA'
        : provider === 'TELEGRAM'
          ? 'Telegram'
          : 'Channel';
}

export default function Overview({
    stats,
    channels,
    queues,
    agents,
    recentConversations,
    failedEvents,
    failedOutbox,
}: OverviewProps) {
    const hasFailures = failedEvents > 0 || failedOutbox > 0;

    return (
        <>
            <Head title="CRM Cockpit" />

            <main className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div className="min-w-0">
                        <p className="text-sm text-muted-foreground">
                            Modular CRM / Omnichannel inbox
                        </p>
                        <h1 className="truncate text-2xl font-semibold tracking-tight">
                            Operations cockpit
                        </h1>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button asChild variant="outline">
                            <Link href="/admin/inbox">
                                <MessageSquareText data-icon="inline-start" />
                                Open inbox
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href="/admin/channels">
                                <RefreshCcw data-icon="inline-start" />
                                Check channels
                            </Link>
                        </Button>
                    </div>
                </div>

                {hasFailures && (
                    <Alert className="[border-color:var(--status-danger-border)] [background-color:var(--status-danger-bg)]">
                        <AlertTriangle />
                        <AlertTitle>Production attention needed</AlertTitle>
                        <AlertDescription>
                            {failedEvents} failed inbound event(s) and{' '}
                            {failedOutbox} failed outbound message(s) need
                            replay or retry.
                        </AlertDescription>
                    </Alert>
                )}

                <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    {stats.map((stat) => {
                        const Icon = metricIcon(stat.label);

                        return (
                            <Card key={stat.label} className="gap-4 py-4">
                                <CardHeader className="flex flex-row items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <CardDescription className="truncate">
                                            {stat.label}
                                        </CardDescription>
                                        <CardTitle className="[font-family:var(--font-mono)] text-3xl tabular-nums">
                                            {stat.value}
                                        </CardTitle>
                                    </div>
                                    <div className="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                                        <Icon className="size-4" />
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <p className="truncate text-sm text-muted-foreground">
                                        {stat.hint}
                                    </p>
                                </CardContent>
                            </Card>
                        );
                    })}
                </section>

                <section className="grid gap-4 xl:grid-cols-[minmax(0,1.25fr)_minmax(360px,0.75fr)]">
                    <Card className="min-w-0">
                        <CardHeader className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div className="min-w-0">
                                <CardTitle>Recent work queue</CardTitle>
                                <CardDescription>
                                    Open conversations sorted by last customer
                                    activity.
                                </CardDescription>
                            </div>
                            <Button asChild variant="outline" size="sm" className="min-h-9 sm:min-h-8">
                                <Link href="/admin/inbox">
                                    View all
                                    <ArrowRight data-icon="inline-end" />
                                </Link>
                            </Button>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Customer</TableHead>
                                        <TableHead>Channel</TableHead>
                                        <TableHead>Owner</TableHead>
                                        <TableHead>SLA</TableHead>
                                        <TableHead className="text-right">
                                            Last
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {recentConversations.map((conversation) => (
                                        <TableRow key={conversation.id}>
                                            <TableCell className="max-w-[260px]">
                                                <Link
                                                    href={`/admin/inbox?conversation=${conversation.id}`}
                                                    className="flex min-w-0 flex-col gap-1"
                                                >
                                                    <span className="truncate font-medium">
                                                        {conversation.contact
                                                            ?.name ??
                                                            conversation.subject ??
                                                            'Unknown customer'}
                                                    </span>
                                                    <span className="truncate text-xs text-muted-foreground">
                                                        {
                                                            conversation.lastMessage
                                                        }
                                                    </span>
                                                </Link>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <StatusDot
                                                        status={
                                                            conversation.channel ===
                                                            'ZALO'
                                                                ? 'DUE_SOON'
                                                                : 'OK'
                                                        }
                                                    />
                                                    <span className="truncate">
                                                        {providerLabel(
                                                            conversation.channel,
                                                        )}
                                                    </span>
                                                </div>
                                            </TableCell>
                                            <TableCell className="max-w-[160px] truncate">
                                                {conversation.owner?.name ??
                                                    'Unassigned'}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    status={
                                                        conversation.slaState
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell className="text-right [font-family:var(--font-mono)] text-xs text-muted-foreground tabular-nums">
                                                {conversation.lastMessageAt}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <div className="grid gap-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <RadioTower className="size-4 text-muted-foreground" />
                                    Channel health
                                </CardTitle>
                                <CardDescription>
                                    Provider freshness and visible breakpoints.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-3">
                                {channels.map((channel) => (
                                    <div
                                        key={channel.id}
                                        className="flex items-center justify-between gap-3 border-b pb-3 last:border-b-0 last:pb-0"
                                    >
                                        <div className="min-w-0">
                                            <div className="flex items-center gap-2">
                                                <StatusDot
                                                    status={channel.status}
                                                />
                                                <span className="truncate font-medium">
                                                    {channel.name}
                                                </span>
                                            </div>
                                            <p className="truncate text-xs text-muted-foreground">
                                                Last webhook{' '}
                                                {channel.lastWebhookAt ??
                                                    'never'}
                                            </p>
                                        </div>
                                        <StatusBadge status={channel.status} />
                                    </div>
                                ))}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <UsersRound className="size-4 text-muted-foreground" />
                                    Agent workload
                                </CardTitle>
                                <CardDescription>
                                    Online state and active conversation load.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-4">
                                {agents.map((agent) => {
                                    const value = Math.min(
                                        agent.active * 20,
                                        100,
                                    );

                                    return (
                                        <div
                                            key={agent.id}
                                            className="flex flex-col gap-2"
                                        >
                                            <div className="flex items-center justify-between gap-3">
                                                <div className="flex min-w-0 items-center gap-2">
                                                    <StatusDot
                                                        status={agent.status}
                                                    />
                                                    <span className="truncate font-medium">
                                                        {agent.name}
                                                    </span>
                                                </div>
                                                <span className="[font-family:var(--font-mono)] text-sm text-muted-foreground tabular-nums">
                                                    {agent.active}/5
                                                </span>
                                            </div>
                                            <Progress value={value} />
                                        </div>
                                    );
                                })}
                            </CardContent>
                        </Card>
                    </div>
                </section>

                <section className="grid gap-4 xl:grid-cols-2">
                    {queues.map((queue) => (
                        <Card key={queue.id}>
                            <CardHeader className="flex flex-row items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <CardTitle className="truncate">
                                        {queue.name}
                                    </CardTitle>
                                    <CardDescription className="truncate">
                                        {queue.mode} / max active{' '}
                                        {queue.maxActive}
                                    </CardDescription>
                                </div>
                                <StatusBadge status={queue.status} />
                            </CardHeader>
                            <CardContent className="flex items-center justify-between gap-3">
                                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                    <Route className="size-4" />
                                    <span>
                                        {queue.members} member(s), online
                                        required:{' '}
                                        {queue.requiresOnline ? 'yes' : 'no'}
                                    </span>
                                </div>
                                <Button asChild variant="outline" size="sm" className="min-h-9 sm:min-h-8">
                                    <Link href="/admin/routing">
                                        Configure
                                        <ArrowRight data-icon="inline-end" />
                                    </Link>
                                </Button>
                            </CardContent>
                        </Card>
                    ))}
                </section>
            </main>
        </>
    );
}
