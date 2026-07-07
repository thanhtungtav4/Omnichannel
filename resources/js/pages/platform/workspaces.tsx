import { Head, useForm, usePage } from '@inertiajs/react';
import { Building2, ExternalLink, Plus } from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';
import { toast } from 'sonner';
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
import { InputGroup, InputGroupInput } from '@/components/ui/input-group';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type Workspace = {
    id: string;
    name: string;
    slug: string;
    status: string;
    url: string;
    users_count: number;
    created_at: string | null;
};

type PageProps = {
    workspaces: Workspace[];
    tenantDomain: string;
    flash?: { status?: string | null };
};

export default function Workspaces() {
    const { workspaces, tenantDomain, flash } = usePage<PageProps>().props;
    const [open, setOpen] = useState(false);

    const form = useForm({
        name: '',
        slug: '',
        owner_name: '',
        owner_email: '',
        owner_password: '',
        owner_password_confirmation: '',
    });

    useEffect(() => {
        if (flash?.status) {
            toast.success(flash.status);
        }
    }, [flash?.status]);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/admin/workspaces', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    }

    return (
        <>
            <Head title="Workspaces" />

            <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div className="min-w-0">
                        <p className="text-sm text-muted-foreground">
                            Platform admin
                        </p>
                        <h1 className="truncate text-2xl font-semibold tracking-tight">
                            Workspaces
                        </h1>
                    </div>
                    <Dialog open={open} onOpenChange={setOpen}>
                        <DialogTrigger asChild>
                            <Button>
                                <Plus data-icon="inline-start" />
                                New workspace
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <form onSubmit={submit}>
                                <DialogHeader>
                                    <DialogTitle>Create workspace</DialogTitle>
                                    <DialogDescription>
                                        Provisions a tenant and its first owner
                                        account. The owner signs in at{' '}
                                        <span className="font-mono">
                                            {form.data.slug || 'slug'}.
                                            {tenantDomain}
                                        </span>
                                        .
                                    </DialogDescription>
                                </DialogHeader>

                                <FieldGroup className="py-4">
                                    <Field data-invalid={!!form.errors.name}>
                                        <FieldLabel htmlFor="ws_name">
                                            Workspace name
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupInput
                                                id="ws_name"
                                                value={form.data.name}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'name',
                                                        e.target.value,
                                                    )
                                                }
                                                aria-invalid={!!form.errors.name}
                                            />
                                        </InputGroup>
                                        <FieldError
                                            errors={[
                                                { message: form.errors.name },
                                            ]}
                                        />
                                    </Field>

                                    <Field data-invalid={!!form.errors.slug}>
                                        <FieldLabel htmlFor="ws_slug">
                                            Subdomain slug
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupInput
                                                id="ws_slug"
                                                value={form.data.slug}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'slug',
                                                        e.target.value,
                                                    )
                                                }
                                                aria-invalid={!!form.errors.slug}
                                                placeholder="acme"
                                            />
                                        </InputGroup>
                                        <FieldError
                                            errors={[
                                                { message: form.errors.slug },
                                            ]}
                                        />
                                    </Field>

                                    <Field
                                        data-invalid={!!form.errors.owner_name}
                                    >
                                        <FieldLabel htmlFor="owner_name">
                                            Owner name
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupInput
                                                id="owner_name"
                                                value={form.data.owner_name}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'owner_name',
                                                        e.target.value,
                                                    )
                                                }
                                                aria-invalid={
                                                    !!form.errors.owner_name
                                                }
                                            />
                                        </InputGroup>
                                        <FieldError
                                            errors={[
                                                {
                                                    message:
                                                        form.errors.owner_name,
                                                },
                                            ]}
                                        />
                                    </Field>

                                    <Field
                                        data-invalid={!!form.errors.owner_email}
                                    >
                                        <FieldLabel htmlFor="owner_email">
                                            Owner email
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupInput
                                                id="owner_email"
                                                type="email"
                                                value={form.data.owner_email}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'owner_email',
                                                        e.target.value,
                                                    )
                                                }
                                                aria-invalid={
                                                    !!form.errors.owner_email
                                                }
                                            />
                                        </InputGroup>
                                        <FieldError
                                            errors={[
                                                {
                                                    message:
                                                        form.errors.owner_email,
                                                },
                                            ]}
                                        />
                                    </Field>

                                    <Field
                                        data-invalid={
                                            !!form.errors.owner_password
                                        }
                                    >
                                        <FieldLabel htmlFor="owner_password">
                                            Owner password
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupInput
                                                id="owner_password"
                                                type="password"
                                                value={form.data.owner_password}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'owner_password',
                                                        e.target.value,
                                                    )
                                                }
                                                aria-invalid={
                                                    !!form.errors.owner_password
                                                }
                                            />
                                        </InputGroup>
                                        <FieldError
                                            errors={[
                                                {
                                                    message:
                                                        form.errors
                                                            .owner_password,
                                                },
                                            ]}
                                        />
                                    </Field>

                                    <Field>
                                        <FieldLabel htmlFor="owner_password_confirmation">
                                            Confirm password
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupInput
                                                id="owner_password_confirmation"
                                                type="password"
                                                value={
                                                    form.data
                                                        .owner_password_confirmation
                                                }
                                                onChange={(e) =>
                                                    form.setData(
                                                        'owner_password_confirmation',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </InputGroup>
                                    </Field>
                                </FieldGroup>

                                <DialogFooter>
                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                    >
                                        Create workspace
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Tenants</CardTitle>
                        <CardDescription>
                            {workspaces.length} workspace(s)
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {workspaces.length === 0 ? (
                            <Empty>
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        <Building2 />
                                    </EmptyMedia>
                                    <EmptyTitle>No workspaces yet</EmptyTitle>
                                    <EmptyDescription>
                                        Create the first tenant to get started.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Subdomain</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">
                                            Users
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Open
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {workspaces.map((ws) => (
                                        <TableRow key={ws.id}>
                                            <TableCell className="font-medium">
                                                {ws.name}
                                            </TableCell>
                                            <TableCell className="font-mono text-muted-foreground">
                                                {ws.slug}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    status={ws.status}
                                                    label={ws.status}
                                                />
                                            </TableCell>
                                            <TableCell className="text-right font-mono [font-variant-numeric:tabular-nums]">
                                                {ws.users_count}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Button
                                                    asChild
                                                    variant="ghost"
                                                    size="sm"
                                                >
                                                    <a
                                                        href={ws.url}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                    >
                                                        <ExternalLink data-icon="inline-start" />
                                                        Visit
                                                    </a>
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </main>
        </>
    );
}
