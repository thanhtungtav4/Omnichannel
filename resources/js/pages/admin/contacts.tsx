import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRight, ContactRound, KanbanSquare, Plus } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { StatusBadge } from '@/components/admin/status-badge';
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
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { InputGroup, InputGroupInput } from '@/components/ui/input-group';
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
import type { ContactSummary, LeadSummary } from '@/types';

type ContactsProps = {
    contacts: ContactSummary[];
    leads: LeadSummary[];
};

function money(value?: string | number | null) {
    if (value === null || value === undefined) {
        return '-';
    }

    return Number(value).toLocaleString('vi-VN');
}

function NewContactDialog() {
    const [open, setOpen] = useState(false);
    const form = useForm({ full_name: '', phone: '', email: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/api/admin/contacts', {
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <Plus data-icon="inline-start" />
                    New contact
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>New contact</DialogTitle>
                        <DialogDescription>
                            Create a customer record manually. Inbound messages
                            create contacts automatically.
                        </DialogDescription>
                    </DialogHeader>
                    <FieldGroup className="py-4">
                        <Field data-invalid={!!form.errors.full_name}>
                            <FieldLabel htmlFor="c_name">Full name</FieldLabel>
                            <InputGroup>
                                <InputGroupInput
                                    id="c_name"
                                    value={form.data.full_name}
                                    onChange={(e) => form.setData('full_name', e.target.value)}
                                    aria-invalid={!!form.errors.full_name}
                                />
                            </InputGroup>
                            <FieldError errors={[{ message: form.errors.full_name }]} />
                        </Field>
                        <Field data-invalid={!!form.errors.phone}>
                            <FieldLabel htmlFor="c_phone">Phone</FieldLabel>
                            <InputGroup>
                                <InputGroupInput
                                    id="c_phone"
                                    value={form.data.phone}
                                    onChange={(e) => form.setData('phone', e.target.value)}
                                    placeholder="0912..."
                                />
                            </InputGroup>
                            <FieldError errors={[{ message: form.errors.phone }]} />
                        </Field>
                        <Field data-invalid={!!form.errors.email}>
                            <FieldLabel htmlFor="c_email">Email</FieldLabel>
                            <InputGroup>
                                <InputGroupInput
                                    id="c_email"
                                    type="email"
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                />
                            </InputGroup>
                            <FieldError errors={[{ message: form.errors.email }]} />
                        </Field>
                    </FieldGroup>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={form.processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Create
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Contacts({ contacts, leads }: ContactsProps) {
    const title = 'Contacts';

    return (
        <>
            <Head title={title} />

            <main className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div className="min-w-0">
                        <p className="text-sm text-muted-foreground">
                            CRM Core / customer records
                        </p>
                        <h1 className="truncate text-2xl font-semibold tracking-tight">
                            {title}
                        </h1>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <Link href="/admin/inbox">
                                <ArrowRight data-icon="inline-start" />
                                Open inbox
                            </Link>
                        </Button>
                        <NewContactDialog />
                    </div>
                </div>

                <Card>
                    <CardHeader className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div className="min-w-0">
                            <CardTitle>Customer database</CardTitle>
                            <CardDescription>
                                Contacts matched by provider identity, phone or
                                email.
                            </CardDescription>
                        </div>
                        <StatusBadge
                            status="ACTIVE"
                            label={`${contacts.length} records`}
                        />
                    </CardHeader>
                    <CardContent>
                        {contacts.length ? (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Phone / email</TableHead>
                                        <TableHead>Owner</TableHead>
                                        <TableHead>Source</TableHead>
                                        <TableHead>Identities</TableHead>
                                        <TableHead className="text-right">
                                            Last inbound
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {contacts.map((contact) => (
                                        <TableRow
                                            key={contact.id}
                                            className="cursor-pointer"
                                            onClick={() => {
                                                window.location.href = `/admin/contacts/${contact.id}`;
                                            }}
                                        >
                                            <TableCell className="max-w-[240px]">
                                                <div className="flex min-w-0 flex-col gap-1">
                                                    <span className="truncate font-medium">
                                                        {contact.name}
                                                    </span>
                                                    <StatusBadge
                                                        status={contact.status}
                                                        className="max-w-[120px]"
                                                    />
                                                </div>
                                            </TableCell>
                                            <TableCell className="max-w-[240px]">
                                                <div className="flex min-w-0 flex-col gap-1 text-sm">
                                                    <span className="truncate">
                                                        {contact.phone ??
                                                            'No phone'}
                                                    </span>
                                                    <span className="truncate text-muted-foreground">
                                                        {contact.email ??
                                                            'No email'}
                                                    </span>
                                                </div>
                                            </TableCell>
                                            <TableCell className="max-w-[180px] truncate">
                                                {contact.owner ?? 'Unassigned'}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    status={contact.source}
                                                />
                                            </TableCell>
                                            <TableCell className="text-right [font-family:var(--font-mono)] tabular-nums">
                                                {contact.identities}
                                            </TableCell>
                                            <TableCell className="text-right text-xs text-muted-foreground">
                                                {contact.lastInboundAt ?? '-'}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        ) : (
                            <Empty>
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        <ContactRound />
                                    </EmptyMedia>
                                    <EmptyTitle>No contacts yet</EmptyTitle>
                                    <EmptyDescription>
                                        Inbound Zalo/Telegram messages will
                                        create contacts automatically.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div className="min-w-0">
                            <CardTitle>Open leads from conversations</CardTitle>
                            <CardDescription>
                                Sales context linked to support conversations.
                            </CardDescription>
                        </div>
                        <Button asChild variant="outline" size="sm">
                            <Link href="/admin/leads">
                                Lead view
                                <ArrowRight data-icon="inline-end" />
                            </Link>
                        </Button>
                    </CardHeader>
                    <CardContent>
                        {leads.length ? (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Lead</TableHead>
                                        <TableHead>Contact</TableHead>
                                        <TableHead>Owner</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">
                                            Value
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Activity
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {leads.map((lead) => (
                                        <TableRow key={lead.id}>
                                            <TableCell className="max-w-[320px]">
                                                <div className="flex min-w-0 flex-col gap-1">
                                                    <span className="truncate font-medium">
                                                        {lead.title}
                                                    </span>
                                                    <span className="truncate text-xs text-muted-foreground">
                                                        {lead.source}
                                                    </span>
                                                </div>
                                            </TableCell>
                                            <TableCell className="max-w-[200px] truncate">
                                                {lead.contact}
                                            </TableCell>
                                            <TableCell className="max-w-[180px] truncate">
                                                {lead.owner ?? 'Unassigned'}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    status={lead.status}
                                                />
                                            </TableCell>
                                            <TableCell className="text-right [font-family:var(--font-mono)] tabular-nums">
                                                {money(lead.valueAmount)}
                                            </TableCell>
                                            <TableCell className="text-right text-xs text-muted-foreground">
                                                {lead.lastActivityAt ?? '-'}
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
                                    <EmptyTitle>No active leads</EmptyTitle>
                                    <EmptyDescription>
                                        New inbound conversations will open a
                                        lead in the default pipeline.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        )}
                    </CardContent>
                </Card>
            </main>
        </>
    );
}
