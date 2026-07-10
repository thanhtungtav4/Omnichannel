import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    ChevronDown,
    KanbanSquare,
    MessageSquare,
    Pencil,
    Pin,
    Plus,
    RefreshCw,
    StickyNote,
    Trash2,
} from 'lucide-react';
import {  useState } from 'react';
import type {FormEvent} from 'react';
import { toast } from 'sonner';
import { StatusBadge } from '@/components/admin/status-badge';
import { TagEditor } from '@/components/admin/tag-editor';
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
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
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
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

type Identity = { provider: string; displayName?: string | null; providerUserId?: string | null };
type ConversationItem = { id: string; channel?: string | null; status: string; lastMessageAt?: string | null };
type LeadItem = { id: string; title: string; status: string; source?: string | null; valueAmount?: number | null; lastActivityAt?: string | null };
type Note = { id: string; body: string; pinned: boolean; author?: string | null; createdAt?: string | null };

type AgentLite = { id: number; name: string };

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
        ownerId?: number | null;
        lastInboundAt?: string | null;
        hasZalo?: boolean;
        identities: Identity[];
    };
    conversations: ConversationItem[];
    leads: LeadItem[];
    notes: Note[];
    agents: AgentLite[];
};

const STATUS_OPTIONS = [
    { value: 'ACTIVE', label: 'Đang hoạt động' },
    { value: 'ARCHIVED', label: 'Đã lưu trữ' },
    { value: 'BLOCKED', label: 'Bị chặn' },
] as const;

function StatusDropdown({ contactId, status }: { contactId: string; status: string }) {
    function change(next: string) {
        if (next === status) {
return;
}

        router.put(
            `/api/admin/contacts/${contactId}/status`,
            { status: next },
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Đã cập nhật trạng thái.'),
                onError: () => toast.error('Không thể cập nhật trạng thái.'),
            },
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="inline-flex items-center gap-1 rounded-md border px-1 py-0.5 hover:bg-muted"
                    aria-label="Đổi trạng thái khách"
                >
                    <StatusBadge status={status} />
                    <ChevronDown className="size-3.5 text-muted-foreground" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
                {STATUS_OPTIONS.map((o) => (
                    <DropdownMenuItem
                        key={o.value}
                        onSelect={(e) => {
                            e.preventDefault();
                            change(o.value);
                        }}
                    >
                        {o.value === status && (
                            <Check className="size-3.5 [color:var(--status-ok-fg)]" />
                        )}
                        {o.label}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function OwnerPicker({
    contactId,
    ownerId,
    agents,
}: {
    contactId: string;
    ownerId?: number | null;
    agents: AgentLite[];
}) {
    // `_none` as the sentinel for "Unassigned" because Radix Select doesn't
    // allow an empty string value alongside SelectItem children.
    const value = ownerId == null ? '_none' : String(ownerId);

    function change(next: string) {
        const payload = next === '_none' ? { owner_id: null } : { owner_id: Number(next) };
        router.put(`/api/admin/contacts/${contactId}/owner`, payload, {
            preserveScroll: true,
            onSuccess: () => toast.success('Đã cập nhật owner.'),
            onError: () => toast.error('Không thể cập nhật owner.'),
        });
    }

    return (
        <Select value={value} onValueChange={change}>
            <SelectTrigger size="sm" className="h-7 w-[160px] text-xs">
                <SelectValue placeholder="Unassigned" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="_none">Unassigned</SelectItem>
                {agents.map((a) => (
                    <SelectItem key={a.id} value={String(a.id)}>
                        {a.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function CreateLeadDialog({ contactId }: { contactId: string }) {
    const [open, setOpen] = useState(false);
    const form = useForm<{ title: string; value_amount: string }>({
        title: '',
        value_amount: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        // Coerce empty string to null so the backend gets the right type
        // (numeric|null) instead of an empty-string 422.
        form.transform((data) => ({
            title: data.title,
            value_amount: data.value_amount === '' ? null : Number(data.value_amount),
        }));
        form.post(`/api/admin/contacts/${contactId}/leads`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
            onError: () => toast.error('Không thể mở lead.'),
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">
                    <Plus data-icon="inline-start" />
                    Tạo lead
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Mở lead cho khách</DialogTitle>
                        <DialogDescription>
                            Sales sẽ tự mở lead khi qualify xong khách này. Lead mới
                            sẽ vào pipeline mặc định, stage đầu tiên, gắn với bạn.
                        </DialogDescription>
                    </DialogHeader>
                    <FieldGroup className="py-4">
                        <Field data-invalid={!!form.errors.title}>
                            <FieldLabel htmlFor="lead_title">Tiêu đề lead</FieldLabel>
                            <Input
                                id="lead_title"
                                value={form.data.title}
                                onChange={(e) => form.setData('title', e.target.value)}
                                placeholder="VD: Tư vấn gói Pro Q3"
                                aria-invalid={!!form.errors.title}
                            />
                            <FieldError errors={[{ message: form.errors.title }]} />
                        </Field>
                        <Field data-invalid={!!form.errors.value_amount}>
                            <FieldLabel htmlFor="lead_value">Giá trị ước tính (VND)</FieldLabel>
                            <Input
                                id="lead_value"
                                type="number"
                                min="0"
                                step="1000"
                                value={form.data.value_amount}
                                onChange={(e) => form.setData('value_amount', e.target.value)}
                                placeholder="Không bắt buộc"
                                aria-invalid={!!form.errors.value_amount}
                            />
                            <FieldError errors={[{ message: form.errors.value_amount }]} />
                        </Field>
                    </FieldGroup>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                            disabled={form.processing}
                        >
                            Huỷ
                        </Button>
                        <Button type="submit" disabled={form.processing || !form.data.title.trim()}>
                            Tạo lead
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function NoteRow({
    note,
    canEdit,
}: {
    note: Note;
    canEdit: boolean;
}) {
    const [editing, setEditing] = useState(false);
    const form = useForm({ body: note.body, pinned: note.pinned });

    function startEdit() {
        form.setData({ body: note.body, pinned: note.pinned });
        setEditing(true);
    }

    function cancel() {
        setEditing(false);
        form.reset();
        form.clearErrors();
    }

    function save() {
        router.put(
            `/api/admin/contact-notes/${note.id}`,
            { body: form.data.body, pinned: form.data.pinned },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setEditing(false);
                    toast.success('Đã cập nhật ghi chú.');
                },
                onError: () => toast.error('Không thể cập nhật ghi chú.'),
            },
        );
    }

    if (editing) {
        return (
            <div className="flex flex-col gap-2 rounded-md border p-2.5">
                <Textarea
                    rows={3}
                    value={form.data.body}
                    onChange={(e) => form.setData('body', e.target.value)}
                />
                <label className="flex items-center gap-2 text-xs text-muted-foreground">
                    <input
                        type="checkbox"
                        checked={form.data.pinned}
                        onChange={(e) => form.setData('pinned', e.target.checked)}
                    />
                    Ghim lên đầu
                </label>
                <div className="flex items-center justify-end gap-1.5">
                    <Button type="button" size="sm" variant="outline" onClick={cancel}>
                        Huỷ
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        onClick={save}
                        disabled={!form.data.body.trim()}
                    >
                        Lưu
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <div
            className={cn(
                'flex flex-col gap-1 rounded-md border p-2.5 text-sm',
                note.pinned &&
                    '[border-color:var(--status-warn-border)] [background-color:var(--status-warn-bg)]',
            )}
        >
            <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{note.body}</p>
            <div className="flex items-center justify-between text-xs text-muted-foreground">
                <span className="flex items-center gap-1">
                    {note.pinned && (
                        <Pin className="size-3 [color:var(--status-warn-fg)]" />
                    )}
                    {note.author ?? 'Ẩn danh'} · {note.createdAt}
                </span>
                <div className="flex items-center gap-2">
                    {canEdit && (
                        <button
                            type="button"
                            onClick={startEdit}
                            className="inline-flex items-center gap-1 hover:underline"
                        >
                            <Pencil className="size-3" />
                            Sửa
                        </button>
                    )}
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
                                <AlertDialogTitle>Xoá ghi chú này?</AlertDialogTitle>
                                <AlertDialogDescription>
                                    Ghi chú sẽ bị xoá vĩnh viễn khỏi hồ sơ khách.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Huỷ</AlertDialogCancel>
                                <AlertDialogAction
                                    onClick={() =>
                                        router.delete(
                                            `/api/admin/contact-notes/${note.id}`,
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
        </div>
    );
}

function NotesSection({
    contactId,
    notes,
    isOwnerOrLead,
}: {
    contactId: string;
    notes: Note[];
    /** Show the "Sửa" button on every note — true when current user can
     *  edit any note (owner/admin/support_lead). Otherwise authors can still
     *  edit their own via a separate code path (kept in this cut as the
     *  controller allows it; UI shows for everyone for now since this view
     *  is operator-only). */
    isOwnerOrLead?: boolean;
}) {
    const form = useForm({ body: '', pinned: false as boolean });

    function submit(event: FormEvent) {
        event.preventDefault();

        if (!form.data.body.trim()) {
return;
}

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
                            <NoteRow
                                key={n.id}
                                note={n}
                                canEdit={!!isOwnerOrLead}
                            />
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
    agents,
}: Props) {
    const [refreshingZalo, setRefreshingZalo] = useState(false);

    async function refreshZaloProfile() {
        setRefreshingZalo(true);

        try {
            const csrf =
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute('content') ?? '';
            const res = await fetch(
                `/api/admin/contacts/${contact.id}/refresh-profile`,
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf,
                    },
                },
            );
            const data = await res.json().catch(() => ({}));

            if (!res.ok || data.ok !== true) {
                throw new Error(data.message ?? 'Không cập nhật được hồ sơ Zalo.');
            }

            toast.success(data.message ?? 'Đã cập nhật hồ sơ Zalo.');
            router.reload({ only: ['contact'] });
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Không cập nhật được hồ sơ Zalo.',
            );
        } finally {
            setRefreshingZalo(false);
        }
    }

    return (
        <>
            <Head title={contact.name} />

            <main className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-wrap items-center gap-3">
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
                        <div className="mt-1 flex min-w-0 flex-wrap items-center gap-1.5 text-sm text-muted-foreground">
                            <StatusDropdown contactId={contact.id} status={contact.status} />
                            <StatusBadge status={contact.source} />
                            <span>·</span>
                            <span className="text-xs">Owner:</span>
                            <OwnerPicker
                                contactId={contact.id}
                                ownerId={contact.ownerId}
                                agents={agents}
                            />
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
                            onClick={refreshZaloProfile}
                            disabled={refreshingZalo}
                        >
                            <RefreshCw
                                data-icon="inline-start"
                                className={refreshingZalo ? 'animate-spin' : undefined}
                            />
                            {refreshingZalo ? 'Đang cập nhật…' : 'Cập nhật hồ sơ Zalo'}
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
                            <CardHeader className="flex flex-row items-center justify-between gap-2">
                                <div className="min-w-0">
                                    <CardTitle className="flex items-center gap-2">
                                        <KanbanSquare className="size-4 text-muted-foreground" />
                                        Leads
                                    </CardTitle>
                                    <CardDescription>Sales opportunities for this contact.</CardDescription>
                                </div>
                                <CreateLeadDialog contactId={contact.id} />
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
                                                Mở lead cho khách khi bắt đầu qualify.
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