import { Head } from '@inertiajs/react';
import { Clock3, Route, ShieldCheck, UsersRound } from 'lucide-react';
import { StatusBadge, StatusDot } from '@/components/admin/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { QueueMemberSummary } from '@/types';

type RoutingQueue = {
    id: string;
    name: string;
    mode: string;
    status: string;
    timeoutSeconds: number;
    maxActivePerAgent: number;
    requiresOnline: boolean;
    members: QueueMemberSummary[];
};

type RoutingProps = {
    queues: RoutingQueue[];
};

export default function Routing({ queues }: RoutingProps) {
    return (
        <>
            <Head title="Routing" />

            <main className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div className="min-w-0">
                        <p className="text-sm text-muted-foreground">
                            Assignment Engine / support distribution
                        </p>
                        <h1 className="truncate text-2xl font-semibold tracking-tight">
                            Routing queues
                        </h1>
                    </div>
                    <Button disabled>
                        <Route data-icon="inline-start" />
                        New queue
                    </Button>
                </div>

                <section className="grid gap-4 xl:grid-cols-2">
                    {queues.map((queue) => (
                        <Card key={queue.id}>
                            <CardHeader className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                <div className="min-w-0">
                                    <CardTitle className="truncate">
                                        {queue.name}
                                    </CardTitle>
                                    <CardDescription>
                                        Sticky owner first, then least recently
                                        assigned active member.
                                    </CardDescription>
                                </div>
                                <StatusBadge status={queue.status} />
                            </CardHeader>
                            <CardContent className="flex flex-col gap-5">
                                <div className="grid gap-3 md:grid-cols-3">
                                    <div className="rounded-md border p-3">
                                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                            <Route className="size-4" />
                                            Mode
                                        </div>
                                        <p className="mt-2 truncate font-medium">
                                            {queue.mode}
                                        </p>
                                    </div>
                                    <div className="rounded-md border p-3">
                                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                            <UsersRound className="size-4" />
                                            Max active
                                        </div>
                                        <p className="mt-2 [font-family:var(--font-mono)] font-medium tabular-nums">
                                            {queue.maxActivePerAgent}
                                        </p>
                                    </div>
                                    <div className="rounded-md border p-3">
                                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                            <Clock3 className="size-4" />
                                            Timeout
                                        </div>
                                        <p className="mt-2 [font-family:var(--font-mono)] font-medium tabular-nums">
                                            {queue.timeoutSeconds}s
                                        </p>
                                    </div>
                                </div>

                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge variant="outline">
                                        <ShieldCheck className="size-3" />
                                        Online required:{' '}
                                        {queue.requiresOnline ? 'yes' : 'no'}
                                    </Badge>
                                    <Badge variant="outline">
                                        Members: {queue.members.length}
                                    </Badge>
                                </div>

                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Member</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead className="text-right">
                                                Last assigned
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {queue.members.map((member) => (
                                            <TableRow key={member.id}>
                                                <TableCell className="max-w-[260px]">
                                                    <div className="flex min-w-0 items-center gap-2">
                                                        <StatusDot
                                                            status={
                                                                member.status
                                                            }
                                                        />
                                                        <span className="truncate font-medium">
                                                            {member.name ??
                                                                'Unknown member'}
                                                        </span>
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={member.status}
                                                    />
                                                </TableCell>
                                                <TableCell className="text-right text-xs text-muted-foreground">
                                                    {member.lastAssignedAt ??
                                                        '-'}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    ))}
                </section>
            </main>
        </>
    );
}
