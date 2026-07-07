import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    KanbanSquare,
    MessageSquare,
    Pencil,
    Pin,
    RefreshCw,
    StickyNote,
    Trash2,
} from 'lucide-react';
import { toast } from 'sonner';
import { type FormEvent, useState } from 'react';
import { StatusBadge } from '@/components/admin/status-badge';
import { TagEditor } from '@/components/admin/tag-editor';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type Identity = { provider: string; displayName?: string | null; providerUserId?: string | null };
type ConversationItem = { id: string; channel?: string | null; status: string; lastMessageAt?: string | null };
type LeadItem = { id: string; title: string; status: string; source?: string | null; valueAmount?: number | null; lastActivityAt?: string | null };
type Note = { id: string; body: string; pinned: boolean; author?: string | null; createdAt?: string | null };

type Props = {
    contact: {
        id: string;
        name: string;
        avatarUrl?: string | null;
        phone?: string | null;
        email?: string | null;
        source: string;
        status: string;
        tags?: string[];
        owner?: string | null;
        lastInboundAt?: string | null;
        hasZalo?: boolean;
        identities: Identity[];
    };
    conversations: ConversationItem[];
    leads: LeadItem[];
    notes: Note[];
};

function NotesSection({ contactId, notes }: { contactId: string; notes: Note[] }) {
    const form = useForm({ body: '', pinned: false as boolean });

    function submit(event: FormEvent) {
        event.preventDefault();
        if (!form.data.body.trim()) return;
        form.post(`/api/admin/contacts/${contactId}/notes`, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <StickyNote className="size-4 text-muted-foreground" />
                    Ghi chú CSKH
                </CardTitle>
                <CardDescription>
                    Ghi chú tổng quan về khách để cả team nắm bối cảnh.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                <form onSubmit={submit} className="flex flex-col gap-2">
                    <Textarea
                        rows={2}
                        value={form.data.body}
                        onChange={(e) => form.setData('body', e.target.value)}
                        placeholder="Thêm ghi chú về khách (sở thích, lịch sử, lưu ý...)"
                    />
                    <div className="flex items-center justify-between">
                        <label className="flex items-center gap-2 text-xs text-muted-foreground">
                            <input
                                type="checkbox"
                                checked={form.data.pinned}
                                onChange={(e) =>
                                    form.setData('pinned', e.target.checked)
                                }
                            />
                            Ghim lên đầu
                        </label>
                        <Button
                            type="submit"
                            size="sm"
                            disabled={form.processing || !form.data.body.trim()}
                        >
                            Thêm ghi chú
                        </Button>
                    </div>
                </form>

                {notes.length ? (
                    <div className="flex flex-col gap-2">
                        {notes.map((n) => (
                            <div
                                key={n.id}
                                className={cn(
                                    'flex flex-col gap-1 rounded-md border p-2.5 text-sm',
                                    n.pinned &&
                                        '[border-color:var(--status-warn-border)] [background-color:var(--status-warn-bg)]',
                                )}
                            >
                                <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">
                                    {n.body}
                                </p>
                                <div className="flex items-center justify-between text-xs text-muted-foreground">
                                    <span className="flex items-center gap-1">
                                        {n.pinned && (
                                            <Pin className="size-3 [color:var(--status-warn-fg)]" />
                                        )}
                                        {n.author ?? 'Ẩn danh'} · {n.createdAt}
                                    </span>
                                    <AlertDialog>
                                        <AlertDialogTrigger asChild>
                                            <button
                                                type="button"
                                                className="hover:underline"
                                            >
                                                Xoá
                                            </button>
                                        </AlertDialogTrigger>
                                        <AlertDialogContent>
                                            <AlertDialogHeader>
                                                <AlertDialogTitle>
                                                    Xoá ghi chú này?
                                                </AlertDialogTitle>
                                                <AlertDialogDescription>
                                                    Ghi chú sẽ bị xoá vĩnh viễn khỏi hồ sơ khách.
                                                </AlertDialogDescription>
                                            </AlertDialogHeader>
                                            <AlertDialogFooter>
                                                <AlertDialogCancel>
                                                    Huỷ
                                                </AlertDialogCancel>
                                                <AlertDialogAction
                                                    onClick={() =>
                                                        router.delete(
                                                            `/api/admin/contact-notes/${n.id}`,
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                    className="bg-destructive text-white hover:bg-destructive/90"
                                                >
                                                    Xoá
                                                </AlertDialogAction>
                                            </AlertDialogFooter>
                                        </AlertDialogContent>
                                    </AlertDialog>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="text-xs text-muted-foreground">
                        Chưa có ghi chú nào.
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

function initials(name: string) {
    return name.split(' ').filter(Boolean).map((p) => p[0]).join('').slice(0, 2).toUpperCase();
}

export default function ContactShow({
    contact,
    conversations,
    leads,
    notes,
}: Props) {
    return (
        <>
            <Head title={contact.name} />

            <main className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex items-center gap-3">
                    <Button asChild variant="outline" size="icon">
                        <Link href="/admin/contacts" aria-label="Back to contacts">
                            <ArrowLeft />
                        </Link>
                    </Button>
                    <Avatar className="size-11">
                        {contact.avatarUrl && (
                            <AvatarImage
                                src={contact.avatarUrl}
                                alt={contact.name}
                            />
                        )}
                        <AvatarFallback>{initials(contact.name)}</AvatarFallback>
                    </Avatar>
                    <div className="min-w-0 flex-1">
                        <h1 className="truncate text-2xl font-semibold tracking-tight">
                            {contact.name}
                        </h1>
                        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <StatusBadge status={contact.status} />
                            <StatusBadge status={contact.source} />
                            <span>Owner: {contact.owner ?? 'Unassigned'}</span>
                        </div>
                        <div className="mt-2">
                            <TagEditor
                                contactId={contact.id}
                                tags={contact.tags ?? []}
                            />
                        </div>
                    </div>
                    {contact.hasZalo && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                router.post(
                                    `/api/admin/contacts/${contact.id}/refresh-profile`,
                                    {},
                                    {
                                        preserveScroll: true,
                                        onSuccess: () =>
                                            toast.success(
                                                'Đã cập nhật hồ sơ Zalo',
                                            ),
                                        onError: () =>
                                            toast.error('Cập nhật thất bại'),
                                    },
                                )
                            }
                        >
                            <RefreshCw data-icon="inline-start" />
                            Cập nhật hồ sơ Zalo
                        </Button>
                    )}
                    <AlertDialog>
                        <AlertDialogTrigger asChild>
                            <Button
                                variant="outline"
                                size="sm"
                                className="[color:var(--status-danger-fg)]"
                            >
                                <Trash2 data-icon="inline-start" />
                                Delete
                            </Button>
                        </AlertDialogTrigger>
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>
                                    Xoá khách “{contact.name}”?
                                </AlertDialogTitle>
                                <AlertDialogDescription>
                                    Thao tác này xoá vĩnh viễn khách cùng toàn bộ định danh kênh và liên kết. Không thể hoàn tác.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Huỷ</AlertDialogCancel>
                                <AlertDialogAction
                                    onClick={() =>
                                        router.delete(`/api/admin/contacts/${contact.id}`)
                                    }
                                    className="bg-destructive text-white hover:bg-destructive/90"
                                >
                                    <Trash2 data-icon="inline-start" />
                                    Xoá vĩnh viễn
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                </div>

                <div className="grid min-w-0 gap-4 lg:grid-cols-[320px_minmax(0,1fr)]">
                    {/* Profile */}
                    <Card className="min-w-0">
                        <CardHeader className="flex flex-row items-start justify-between gap-2">
                            <div className="min-w-0">
                                <CardTitle>Profile</CardTitle>
                                <CardDescription>Contact details & channel identities.</CardDescription>
                            </div>
                            <EditContactDialog contact={contact} />
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3 text-sm">
                            <Row label="Phone" value={contact.phone ?? '—'} />
                            <Row label="Email" value={contact.email ?? '—'} />
                            <Row label="Last inbound" value={contact.lastInboundAt ?? '—'} />
                            <div className="pt-2">
                                <p className="mb-2 text-xs font-medium text-muted-foreground">
                                    Channel identities
                                </p>
                                {contact.identities.length ? (
                                    <div className="flex flex-col gap-2">
                                        {contact.identities.map((id, i) => (
                                            <div key={i} className="flex min-w-0 items-center justify-between gap-2">
                                                <StatusBadge status={id.provider} className="shrink-0" />
                                                <span className="min-w-0 truncate text-xs text-muted-foreground">
                                                    {id.displayName ?? id.providerUserId}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <span className="text-xs text-muted-foreground">None</span>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex min-w-0 flex-col gap-4">
                        <NotesSection contactId={contact.id} notes={notes} />

                        {/* Conversations */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <MessageSquare className="size-4 text-muted-foreground" />
                                    Conversations
                                </CardTitle>
                                <CardDescription>Chat history across channels. Click to open in the inbox.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                {conversations.length ? (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Channel</TableHead>
                                                <TableHead>Status</TableHead>
                                                <TableHead className="text-right">Last message</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {conversations.map((c) => (
                                                <TableRow
                                                    key={c.id}
                                                    className="cursor-pointer"
                                                    onClick={() => {
                                                        window.location.href = `/admin/inbox?conversation=${c.id}`;
                                                    }}
                                                >
                                                    <TableCell>
                                                        <StatusBadge status={c.channel ?? 'CHANNEL'} />
                                                    </TableCell>
                                                    <TableCell>
                                                        <StatusBadge status={c.status} />
                                                    </TableCell>
                                                    <TableCell className="text-right text-xs text-muted-foreground">
                                                        {c.lastMessageAt ?? '-'}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                ) : (
                                    <Empty>
                                        <EmptyHeader>
                                            <EmptyMedia variant="icon">
                                                <MessageSquare />
                                            </EmptyMedia>
                                            <EmptyTitle>No conversations</EmptyTitle>
                                            <EmptyDescription>
                                                This contact has not messaged yet.
                                            </EmptyDescription>
                                        </EmptyHeader>
                                    </Empty>
                                )}
                            </CardContent>
                        </Card>

                        {/* Leads */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <KanbanSquare className="size-4 text-muted-foreground" />
                                    Leads
                                </CardTitle>
                                <CardDescription>Sales opportunities for this contact.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                {leads.length ? (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Lead</TableHead>
                                                <TableHead>Status</TableHead>
                                                <TableHead className="text-right">Value</TableHead>
                                                <TableHead className="text-right">Activity</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {leads.map((l) => (
                                                <TableRow key={l.id}>
                                                    <TableCell className="max-w-[240px]">
                                                        <span className="block truncate" title={l.title}>
                                                            {l.title}
                                                        </span>
                                                    </TableCell>
                                                    <TableCell>
                                                        <StatusBadge status={l.status} />
                                                    </TableCell>
                                                    <TableCell className="text-right [font-family:var(--font-mono)] tabular-nums">
                                                        {l.valueAmount ?? '—'}
                                                    </TableCell>
                                                    <TableCell className="text-right text-xs text-muted-foreground">
                                                        {l.lastActivityAt ?? '-'}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                ) : (
                                    <Empty>
                                        <EmptyHeader>
                                            <EmptyMedia variant="icon">
                                                <KanbanSquare />
                                            </EmptyMedia>
                                            <EmptyTitle>No leads</EmptyTitle>
                                            <EmptyDescription>
                                                A lead is created automatically on first contact.
                                            </EmptyDescription>
                                        </EmptyHeader>
                                    </Empty>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </main>
        </>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex min-w-0 items-center justify-between gap-2">
            <span className="shrink-0 text-xs text-muted-foreground">{label}</span>
            <span className="min-w-0 truncate font-medium">{value}</span>
        </div>
    );
}

function EditContactDialog({
    contact,
}: {
    contact: { id: string; name: string; phone?: string | null; email?: string | null };
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        full_name: contact.name ?? '',
        phone: contact.phone ?? '',
        email: contact.email ?? '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(`/api/admin/contacts/${contact.id}`, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    <Pencil data-icon="inline-start" />
                    Sửa
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Sửa liên hệ</DialogTitle>
                        <DialogDescription>
                            Cập nhật tên, số điện thoại và email của khách.
                        </DialogDescription>
                    </DialogHeader>
                    <FieldGroup className="py-4">
                        <Field data-invalid={!!form.errors.full_name}>
                            <FieldLabel htmlFor="e_name">Họ tên</FieldLabel>
                            <Input
                                id="e_name"
                                value={form.data.full_name}
                                onChange={(e) => form.setData('full_name', e.target.value)}
                                aria-invalid={!!form.errors.full_name}
                            />
                            <FieldError errors={[{ message: form.errors.full_name }]} />
                        </Field>
                        <Field data-invalid={!!form.errors.phone}>
                            <FieldLabel htmlFor="e_phone">Số điện thoại</FieldLabel>
                            <Input
                                id="e_phone"
                                value={form.data.phone}
                                onChange={(e) => form.setData('phone', e.target.value)}
                                placeholder="0912..."
                                aria-invalid={!!form.errors.phone}
                            />
                            <FieldError errors={[{ message: form.errors.phone }]} />
                        </Field>
                        <Field data-invalid={!!form.errors.email}>
                            <FieldLabel htmlFor="e_email">Email</FieldLabel>
                            <Input
                                id="e_email"
                                type="email"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                aria-invalid={!!form.errors.email}
                            />
                            <FieldError errors={[{ message: form.errors.email }]} />
                        </Field>
                    </FieldGroup>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={form.processing}>
                            Huỷ
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Lưu
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
