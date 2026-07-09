import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    ChevronLeft,
    ChevronRight,
    ContactRound,
    KanbanSquare,
    Plus,
    Search,
    X,
} from 'lucide-react';
import {  useEffect, useMemo, useRef, useState } from 'react';
import type {FormEvent} from 'react';
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
import { Input } from '@/components/ui/input';
import { InputGroup, InputGroupInput } from '@/components/ui/input-group';
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
import { cn } from '@/lib/utils';
import type {
    ContactFilters,
    ContactSummary,
    LeadSummary,
    Paginated,
} from '@/types';

type AgentLite = { id: number; name: string };

type ContactsProps = {
    contacts: Paginated<ContactSummary>;
    filters: ContactFilters;
    agents: AgentLite[];
    leads: LeadSummary[];
};

const STATUS_OPTIONS = [
    { value: 'ACTIVE', label: 'Đang hoạt động' },
    { value: 'ARCHIVED', label: 'Đã lưu trữ' },
    { value: 'BLOCKED', label: 'Bị chặn' },
] as const;

const SOURCE_OPTIONS = [
    { value: 'MANUAL', label: 'Nhập tay' },
    { value: 'TELEGRAM', label: 'Telegram' },
    { value: 'ZALO_PERSONAL', label: 'Zalo cá nhân' },
    { value: 'ZALO_OA', label: 'Zalo OA' },
    { value: 'FACEBOOK', label: 'Facebook' },
    { value: 'IMPORT', label: 'Import' },
    { value: 'API', label: 'API' },
] as const;

const SORTABLE: Record<string, string> = {
    name: 'full_name',
    lastInboundAt: 'last_inbound_at',
    createdAt: 'created_at',
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
                                    onChange={(e) =>
                                        form.setData('full_name', e.target.value)
                                    }
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
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                            disabled={form.processing}
                        >
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

function FiltersToolbar({
    filters,
    agents,
}: {
    filters: ContactFilters;
    agents: AgentLite[];
}) {
    // Local search box: debounced push to URL so we don't hammer the server
    // on every keystroke. 300ms is the sweet spot for "feels instant but
    // doesn't refetch mid-word".
    const [search, setSearch] = useState(filters.q);
    const timer = useRef<number | null>(null);

    useEffect(() => {
        // Sync external URL changes (e.g. user clicks pagination or "Clear
        // filters") back into the local input. React docs explicitly carve
        // this case out as the legitimate use of an effect-driven setState
        // — see "adjusting state when a prop changes" in
        // react.dev/learn/you-might-not-need-an-effect.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setSearch(filters.q);
    }, [filters.q]);

    useEffect(() => {
        if (search === filters.q) {
return;
}

        if (timer.current) {
window.clearTimeout(timer.current);
}

        timer.current = window.setTimeout(() => {
            applyFilters({ q: search }, filters);
        }, 300);

        return () => {
            if (timer.current) {
window.clearTimeout(timer.current);
}
        };
        // We intentionally depend only on `search` — calling applyFilters with
        // the latest filters prop would re-run on every filter change.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const hasFilters =
        filters.q ||
        filters.status ||
        filters.source ||
        filters.owner_id ||
        filters.tag;

    return (
        <div className="flex flex-wrap items-center gap-2 pb-3">
            <InputGroup className="w-64">
                <Search
                    className="size-4 text-muted-foreground"
                  data-icon="inline-start"
                />
                <InputGroupInput
                    placeholder="Tìm tên, SĐT, email…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                />
                {search && (
                    <button
                        type="button"
                        onClick={() => setSearch('')}
                        className="text-muted-foreground hover:text-foreground"
                        aria-label="Clear search"
                    >
                        <X className="size-4" />
                    </button>
                )}
            </InputGroup>

            <FilterSelect
                value={filters.status}
                placeholder="Trạng thái"
                emptyLabel="Tất cả trạng thái"
                options={STATUS_OPTIONS}
                onChange={(v) => applyFilters({ status: v }, filters)}
            />

            <FilterSelect
                value={filters.source}
                placeholder="Nguồn"
                emptyLabel="Tất cả nguồn"
                options={SOURCE_OPTIONS}
                onChange={(v) => applyFilters({ source: v }, filters)}
            />

            <Select
                value={filters.owner_id || '_all'}
                onValueChange={(v) =>
                    applyFilters({ owner_id: v === '_all' ? '' : v }, filters)
                }
            >
                <SelectTrigger size="sm" className="min-w-[140px]">
                    <SelectValue placeholder="Owner" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="_all">Tất cả owner</SelectItem>
                    <SelectItem value="null">Không phân công</SelectItem>
                    {agents.map((a) => (
                        <SelectItem key={a.id} value={String(a.id)}>
                            {a.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <Input
                placeholder="Tag…"
                className="h-8 w-32"
                defaultValue={filters.tag}
                onBlur={(e) => applyFilters({ tag: e.target.value.trim() }, filters)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                        applyFilters(
                            { tag: (e.target as HTMLInputElement).value.trim() },
                            filters,
                        );
                    }
                }}
            />

            <Select
                value={String(filters.per_page)}
                onValueChange={(v) =>
                    applyFilters({ per_page: Number(v) }, filters)
                }
            >
                <SelectTrigger size="sm" className="w-[100px]">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="25">25 / trang</SelectItem>
                    <SelectItem value="50">50 / trang</SelectItem>
                    <SelectItem value="100">100 / trang</SelectItem>
                </SelectContent>
            </Select>

            {hasFilters && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() =>
                        applyFilters(
                            { q: '', status: '', source: '', owner_id: '', tag: '' },
                            filters,
                        )
                    }
                    className="text-muted-foreground"
                >
                    <X data-icon="inline-start" />
                    Xoá bộ lọc
                </Button>
            )}
        </div>
    );
}

function FilterSelect<T extends string>({
    value,
    placeholder,
    emptyLabel,
    options,
    onChange,
}: {
    value: string;
    placeholder: string;
    emptyLabel: string;
    options: readonly { value: T; label: string }[];
    onChange: (v: string) => void;
}) {
    return (
        <Select
            value={value || '_all'}
            onValueChange={(v) => onChange(v === '_all' ? '' : v)}
        >
            <SelectTrigger size="sm" className="min-w-[140px]">
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="_all">{emptyLabel}</SelectItem>
                {options.map((o) => (
                    <SelectItem key={o.value} value={o.value}>
                        {o.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function SortableHead({
    label,
    column,
    sort,
    dir,
    onToggle,
    align = 'left',
}: {
    label: string;
    column: string;
    sort: string;
    dir: string;
    onToggle: (column: string) => void;
    align?: 'left' | 'right';
}) {
    const active = sort === column;
    const Icon = !active ? ArrowUpDown : dir === 'asc' ? ArrowUp : ArrowDown;

    return (
        <TableHead
            className={cn(align === 'right' && 'text-right')}
        >
            <button
                type="button"
                onClick={() => onToggle(column)}
                className={cn(
                    'inline-flex items-center gap-1 hover:text-foreground',
                    align === 'right' && 'flex-row-reverse',
                )}
            >
                {label}
                <Icon className="size-3.5 opacity-70" />
            </button>
        </TableHead>
    );
}

function applyFilters(patch: Partial<ContactFilters>, current: ContactFilters) {
    const merged: Record<string, string | number> = { ...current, ...patch };
    const params = new URLSearchParams();

    for (const [k, v] of Object.entries(merged)) {
        if (v === '' || v === null || v === undefined) {
continue;
}

        params.set(k, String(v));
    }

    // Any filter change should land on page 1 — otherwise the user is stuck
    // on an empty page they can't see.
    if (!('page' in patch)) {
        params.delete('page');
    }

    const qs = params.toString();
    router.get(`/admin/contacts${qs ? `?${qs}` : ''}`, {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function Pagination({
    page,
}: {
    page: Paginated<ContactSummary>;
}) {
    if (page.last_page <= 1) {
        return (
            <div className="flex items-center justify-between pt-3 text-xs text-muted-foreground">
                <span>
                    {page.total} bản ghi
                </span>
            </div>
        );
    }

    // Build a compact page list: always show 1, last, current ± 1, with ellipses.
    const pages: (number | 'ellipsis')[] = [];
    const last = page.last_page;
    const cur = page.current_page;

    for (let p = 1; p <= last; p++) {
        if (p === 1 || p === last || Math.abs(p - cur) <= 1) {
            pages.push(p);
        } else if (pages[pages.length - 1] !== 'ellipsis') {
            pages.push('ellipsis');
        }
    }

    const params = new URLSearchParams(window.location.search);
    const linkFor = (p: number) => {
        const next = new URLSearchParams(params);
        next.set('page', String(p));

        return `/admin/contacts?${next.toString()}`;
    };

    return (
        <div className="flex flex-wrap items-center justify-between gap-2 pt-3 text-xs text-muted-foreground">
            <span>
                Trang {page.current_page} / {page.last_page} · {page.total} bản ghi
            </span>
            <div className="flex items-center gap-1">
                <Button
                    asChild={cur > 1}
                    variant="outline"
                    size="sm"
                    disabled={cur <= 1}
                >
                    {cur > 1 ? (
                        <Link href={linkFor(cur - 1)} preserveScroll preserveState>
                            <ChevronLeft data-icon="inline-start" />
                            Trước
                        </Link>
                    ) : (
                        <span>
                            <ChevronLeft data-icon="inline-start" />
                            Trước
                        </span>
                    )}
                </Button>
                {pages.map((p, i) =>
                    p === 'ellipsis' ? (
                        <span key={`e-${i}`} className="px-1.5">
                            …
                        </span>
                    ) : (
                        <Button
                            key={p}
                            asChild
                            variant={p === cur ? 'default' : 'outline'}
                            size="sm"
                        >
                            <Link href={linkFor(p)} preserveScroll preserveState>
                                {p}
                            </Link>
                        </Button>
                    ),
                )}
                <Button
                    asChild={cur < last}
                    variant="outline"
                    size="sm"
                    disabled={cur >= last}
                >
                    {cur < last ? (
                        <Link href={linkFor(cur + 1)} preserveScroll preserveState>
                            Sau
                            <ChevronRight data-icon="inline-end" />
                        </Link>
                    ) : (
                        <span>
                            Sau
                            <ChevronRight data-icon="inline-end" />
                        </span>
                    )}
                </Button>
            </div>
        </div>
    );
}

export default function Contacts({ contacts, filters, agents, leads }: ContactsProps) {
    const title = 'Contacts';

    function toggleSort(column: string) {
        const sameColumn = filters.sort === column;
        applyFilters(
            {
                sort: column,
                dir: sameColumn
                    ? filters.dir === 'asc'
                        ? 'desc'
                        : 'asc'
                    : 'asc',
            },
            filters,
        );
    }

    const hasFilters =
        filters.q || filters.status || filters.source || filters.owner_id || filters.tag;

    const items = contacts.data;

    // Distinct tags across the current page (for hint chips, not filter).
    // Full tag universe needs a dedicated endpoint — not in this cut.
    const tagUniverse = useMemo(() => {
        const set = new Set<string>();
        items.forEach((c) => (c.tags ?? []).forEach((t) => set.add(t)));

        return Array.from(set).slice(0, 12);
    }, [items]);

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
                            label={`${contacts.total} records`}
                        />
                    </CardHeader>
                    <CardContent>
                        <FiltersToolbar filters={filters} agents={agents} />

                        {items.length ? (
                            <>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <SortableHead
                                                label="Name"
                                                column={SORTABLE.name}
                                                sort={filters.sort}
                                                dir={filters.dir}
                                                onToggle={toggleSort}
                                            />
                                            <TableHead>Phone / email</TableHead>
                                            <TableHead>Owner</TableHead>
                                            <TableHead>Tags</TableHead>
                                            <TableHead>Source</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead className="text-right">Open leads</TableHead>
                                            <TableHead className="text-right">Identities</TableHead>
                                            <SortableHead
                                                label="Last inbound"
                                                column={SORTABLE.lastInboundAt}
                                                sort={filters.sort}
                                                dir={filters.dir}
                                                onToggle={toggleSort}
                                                align="right"
                                            />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {items.map((contact) => (
                                            <TableRow
                                                key={contact.id}
                                                className="cursor-pointer"
                                                onClick={() => {
                                                    window.location.href = `/admin/contacts/${contact.id}`;
                                                }}
                                            >
                                                <TableCell className="max-w-[240px]">
                                                    <span className="block truncate font-medium">
                                                        {contact.name}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="max-w-[240px]">
                                                    <div className="flex min-w-0 flex-col gap-1 text-sm">
                                                        <span className="truncate">
                                                            {contact.phone ?? 'No phone'}
                                                        </span>
                                                        <span className="truncate text-muted-foreground">
                                                            {contact.email ?? 'No email'}
                                                        </span>
                                                    </div>
                                                </TableCell>
                                                <TableCell className="max-w-[180px] truncate">
                                                    {contact.owner ?? 'Unassigned'}
                                                </TableCell>
                                                <TableCell className="max-w-[180px]">
                                                    <ContactTags tags={contact.tags ?? []} />
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge status={contact.source} />
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge status={contact.status} />
                                                </TableCell>
                                                <TableCell className="text-right [font-family:var(--font-mono)] tabular-nums">
                                                    {contact.openLeadsCount ? (
                                                        <span className="font-medium">
                                                            {contact.openLeadsCount}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">—</span>
                                                    )}
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
                                <Pagination page={contacts} />
                            </>
                        ) : hasFilters ? (
                            <Empty>
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        <Search />
                                    </EmptyMedia>
                                    <EmptyTitle>Không có khách khớp bộ lọc</EmptyTitle>
                                    <EmptyDescription>
                                        Thử bỏ bớt điều kiện hoặc xoá hết bộ lọc.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
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

                        {/* Tag hint chips — visible only when there are tags
                            in the current page so the user sees what's filterable. */}
                        {tagUniverse.length > 0 && items.length > 0 && (
                            <div className="mt-3 flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                                <span>Tags trên trang này:</span>
                                {tagUniverse.map((t) => (
                                    <button
                                        key={t}
                                        type="button"
                                        onClick={() => applyFilters({ tag: t }, filters)}
                                        className="rounded-full border px-2 py-0.5 hover:bg-muted"
                                    >
                                        {t}
                                    </button>
                                ))}
                            </div>
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
                                        <TableHead className="text-right">Value</TableHead>
                                        <TableHead className="text-right">Activity</TableHead>
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
                                                <StatusBadge status={lead.status} />
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

function ContactTags({ tags }: { tags: string[] }) {
    if (!tags.length) {
        return <span className="text-xs text-muted-foreground">—</span>;
    }

    const visible = tags.slice(0, 2);
    const overflow = tags.length - visible.length;

    return (
        <div className="flex flex-wrap gap-1">
            {visible.map((t) => (
                <span
                    key={t}
                    className="inline-flex items-center rounded-full border px-1.5 py-0.5 text-xs"
                >
                    {t}
                </span>
            ))}
            {overflow > 0 && (
                <span className="inline-flex items-center rounded-full border px-1.5 py-0.5 text-xs text-muted-foreground">
                    +{overflow}
                </span>
            )}
        </div>
    );
}