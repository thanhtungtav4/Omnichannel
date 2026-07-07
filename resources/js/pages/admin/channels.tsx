import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Bot,
    CheckCircle2,
    Circle,
    Copy,
    MessageCirclePlus,
    Pencil,
    Trash2,
    Wand2,
} from 'lucide-react';
import { type FormEvent, useState } from 'react';
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
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
    InputGroupTextarea,
} from '@/components/ui/input-group';
import {
    Select,
    SelectContent,
    SelectGroup,
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
import type { ChannelSummary } from '@/types';

type ChannelsProps = {
    channels: ChannelSummary[];
    canManage: boolean;
    canDelete: boolean;
    webhookBase: string;
};

function DeleteChannelButton({
    id,
    name,
}: {
    id: string;
    name: string;
}) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    function confirmDelete() {
        setProcessing(true);
        router.delete(`/api/admin/channels/${id}`, {
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                setOpen(false);
            },
        });
    }

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    className="[color:var(--status-danger-fg)] min-h-9 sm:min-h-8"
                >
                    <Trash2 data-icon="inline-start" />
                    Delete
                </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Delete “{name}”?</AlertDialogTitle>
                    <AlertDialogDescription>
                        This permanently removes the channel account and all of
                        its synced conversations, messages and webhook events.
                        This cannot be undone. To keep history, disable the
                        account instead.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={processing}>
                        Cancel
                    </AlertDialogCancel>
                    <AlertDialogAction
                        onClick={(e) => {
                            e.preventDefault(); // keep dialog until request finishes
                            confirmDelete();
                        }}
                        disabled={processing}
                        className="bg-destructive text-white hover:bg-destructive/90"
                    >
                        <Trash2 data-icon="inline-start" />
                        Delete permanently
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

const PROVIDERS = [
    { value: 'TELEGRAM', label: 'Telegram Bot' },
    { value: 'ZALO_OA', label: 'Zalo OA' },
    { value: 'ZALO_PERSONAL', label: 'Zalo Personal (QR)' },
    { value: 'FACEBOOK', label: 'Facebook Messenger' },
] as const;

// Credential fields shown per provider (matches ChannelAccountController).
const CREDENTIAL_FIELDS: Record<string, { key: string; label: string }[]> = {
    TELEGRAM: [{ key: 'bot_token', label: 'Bot token' }],
    ZALO_OA: [
        { key: 'app_id', label: 'App ID' },
        { key: 'app_secret', label: 'App secret' },
        { key: 'access_token', label: 'Access token' },
        { key: 'refresh_token', label: 'Refresh token' },
    ],
    ZALO_PERSONAL: [],
    FACEBOOK: [
        { key: 'app_secret', label: 'App secret' },
        { key: 'page_access_token', label: 'Page access token' },
    ],
};

function providerLabel(provider: string) {
    return PROVIDERS.find((p) => p.value === provider)?.label ?? provider;
}

function webhookHint(provider: string, base: string, id?: string) {
    const path = provider === 'FACEBOOK' ? 'facebook' : provider === 'TELEGRAM' ? 'telegram' : 'zalo';
    return `${base}/${path}/${id ?? '{account-id}'}`;
}

const STATUSES = ['DRAFT', 'ACTIVE', 'DEGRADED', 'DISABLED'] as const;

function EditChannelDialog({ channel }: { channel: ChannelSummary }) {
    const [open, setOpen] = useState(false);
    const form = useForm<{
        name: string;
        status: string;
        credentials: Record<string, string>;
    }>({
        name: channel.name,
        status: channel.status,
        credentials: {},
    });

    const fields = CREDENTIAL_FIELDS[channel.provider] ?? [];

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(`/api/admin/channels/${channel.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('credentials');
                setOpen(false);
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm" className="min-h-9 sm:min-h-8">
                    <Pencil data-icon="inline-start" />
                    Edit
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Edit {providerLabel(channel.provider)}</DialogTitle>
                        <DialogDescription>
                            Update the name, status or credentials. Leave a
                            credential blank to keep the existing value.
                        </DialogDescription>
                    </DialogHeader>

                    <FieldGroup className="py-4">
                        <Field data-invalid={!!form.errors.name}>
                            <FieldLabel htmlFor="edit_name">Account name</FieldLabel>
                            <InputGroup>
                                <InputGroupInput
                                    id="edit_name"
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    aria-invalid={!!form.errors.name}
                                />
                            </InputGroup>
                            <FieldError errors={[{ message: form.errors.name }]} />
                        </Field>

                        <Field>
                            <FieldLabel>Status</FieldLabel>
                            <Select
                                value={form.data.status}
                                onValueChange={(value) => form.setData('status', value)}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        {STATUSES.map((s) => (
                                            <SelectItem key={s} value={s}>
                                                {s}
                                            </SelectItem>
                                        ))}
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                        </Field>

                        {fields.map((f) => (
                            <Field key={f.key}>
                                <FieldLabel htmlFor={`edit_${f.key}`}>
                                    {f.label}
                                </FieldLabel>
                                <InputGroup>
                                    <InputGroupInput
                                        id={`edit_${f.key}`}
                                        type="password"
                                        autoComplete="off"
                                        placeholder="Unchanged"
                                        value={form.data.credentials[f.key] ?? ''}
                                        onChange={(e) =>
                                            form.setData('credentials', {
                                                ...form.data.credentials,
                                                [f.key]: e.target.value,
                                            })
                                        }
                                    />
                                </InputGroup>
                            </Field>
                        ))}
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
                            Save changes
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

// Setup steps shown per provider. `done` is evaluated against live channel data.
function setupSteps(channel: ChannelSummary) {
    const connected = channel.status === 'ACTIVE';
    const gotMessage = !!channel.hasReceivedWebhook;

    switch (channel.provider) {
        case 'TELEGRAM':
            return [
                { label: 'Bot token saved', done: true },
                { label: 'Register webhook (needs a public HTTPS URL)', done: !!channel.webhookUrl },
                { label: 'Send your bot a message to test', done: gotMessage },
            ];
        case 'FACEBOOK':
            return [
                { label: 'App secret + page token saved', done: true },
                { label: 'Paste callback URL + verify token into the Facebook app', done: !!channel.webhookUrl },
                { label: 'Subscribe the page and send a Messenger message', done: gotMessage },
            ];
        case 'ZALO_OA':
            return [
                { label: 'OA credentials + access token saved', done: true },
                { label: 'Paste the webhook URL into the Zalo OA dashboard', done: !!channel.webhookUrl },
                { label: 'Send the OA a message to test', done: gotMessage },
            ];
        case 'ZALO_PERSONAL':
            return [
                { label: 'Account created', done: true },
                { label: 'Start the Node sidecar and log in by QR', done: connected },
                { label: 'Send the nick a message to test', done: gotMessage },
            ];
        default:
            return [];
    }
}

function CopyRow({ label, value }: { label: string; value?: string | null }) {
    if (!value) return null;
    return (
        <div className="flex flex-col gap-1">
            <span className="text-xs font-medium text-muted-foreground">{label}</span>
            <div className="flex items-center gap-2">
                <code className="flex-1 truncate rounded bg-muted px-2 py-1 font-mono text-xs">
                    {value}
                </code>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => navigator.clipboard?.writeText(value)}
                >
                    <Copy data-icon="inline-start" />
                    Copy
                </Button>
            </div>
        </div>
    );
}

function ZaloQrLogin({ channelId }: { channelId: string }) {
    const [qr, setQr] = useState<string | null>(null);
    const [status, setStatus] = useState<string>('idle');
    const [loading, setLoading] = useState(false);

    async function startLogin() {
        setLoading(true);
        setStatus('starting');
        try {
            const res = await fetch(
                `/api/admin/channels/${channelId}/zalo-login-qr`,
                {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN':
                            document
                                .querySelector('meta[name="csrf-token"]')
                                ?.getAttribute('content') ?? '',
                    },
                },
            );
            const data = await res.json();
            if (data.qr) {
                setQr(data.qr);
                setStatus(data.status ?? 'QR_PENDING');
                poll();
            } else {
                setStatus(data.error ?? 'error');
            }
        } catch {
            setStatus('error');
        } finally {
            setLoading(false);
        }
    }

    function poll() {
        const timer = setInterval(async () => {
            const res = await fetch(
                `/api/admin/channels/${channelId}/zalo-status`,
            );
            const data = await res.json();
            setStatus(data.status);
            if (data.qr && data.status === 'QR_PENDING') setQr(data.qr);
            if (data.status === 'CONNECTED' || data.status === 'QR_EXPIRED') {
                clearInterval(timer);
                if (data.status === 'CONNECTED') {
                    setQr(null);
                    setTimeout(() => router.reload({ only: ['channels'] }), 800);
                }
            }
        }, 2500);
        setTimeout(() => clearInterval(timer), 180000); // stop after 3 min
    }

    return (
        <div className="flex flex-col items-center gap-3 rounded-md border p-4">
            {status === 'CONNECTED' ? (
                <div className="flex flex-col items-center gap-2">
                    <span className="[color:var(--status-ok-fg)]">
                        ✓ Nick connected
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => {
                            fetch(`/api/admin/channels/${channelId}/zalo-sync`, {
                                method: 'POST',
                                headers: {
                                    'X-CSRF-TOKEN':
                                        document
                                            .querySelector('meta[name="csrf-token"]')
                                            ?.getAttribute('content') ?? '',
                                },
                            });
                        }}
                    >
                        <Wand2 data-icon="inline-start" />
                        Sync lịch sử tin nhắn
                    </Button>
                    <span className="text-xs text-muted-foreground">
                        Kéo lại tin gần đây (tin trùng tự bỏ qua).
                    </span>
                </div>
            ) : qr ? (
                <>
                    <img
                        src={qr}
                        alt="Zalo QR code"
                        className="size-52 rounded bg-white p-2"
                    />
                    <p className="text-center text-xs text-muted-foreground">
                        Mở app Zalo → Quét mã QR này.
                        {status === 'QR_SCANNED' && ' Đã quét, đang đăng nhập…'}
                        {status === 'QR_EXPIRED' && ' Mã hết hạn, bấm lại.'}
                    </p>
                    {status === 'QR_EXPIRED' && (
                        <Button size="sm" onClick={startLogin} disabled={loading}>
                            Tạo mã mới
                        </Button>
                    )}
                </>
            ) : (
                <>
                    <p className="text-center text-xs text-muted-foreground">
                        Bấm để tạo mã QR, rồi quét bằng app Zalo trên điện thoại.
                    </p>
                    <Button onClick={startLogin} disabled={loading}>
                        <Wand2 data-icon="inline-start" />
                        {loading ? 'Đang tạo QR…' : 'Đăng nhập bằng QR'}
                    </Button>
                    {status !== 'idle' && status !== 'starting' && (
                        <span className="text-xs [color:var(--status-danger-fg)]">
                            {status}
                        </span>
                    )}
                </>
            )}
        </div>
    );
}

function SetupChannelDialog({ channel }: { channel: ChannelSummary }) {
    const [open, setOpen] = useState(false);
    const [registering, setRegistering] = useState(false);
    const steps = setupSteps(channel);
    const isTelegram = channel.provider === 'TELEGRAM';
    const isPersonal = channel.provider === 'ZALO_PERSONAL';

    function register() {
        setRegistering(true);
        router.post(
            `/api/admin/channels/${channel.id}/register-webhook`,
            {},
            { preserveScroll: true, onFinish: () => setRegistering(false) },
        );
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm" className="min-h-9 sm:min-h-8">
                    <Wand2 data-icon="inline-start" />
                    Setup
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Set up {providerLabel(channel.provider)} — {channel.name}
                    </DialogTitle>
                    <DialogDescription>
                        Follow the steps. A green check means that step is done.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-4 py-2">
                    <ol className="flex flex-col gap-2">
                        {steps.map((step, i) => (
                            <li key={i} className="flex items-start gap-2 text-sm">
                                {step.done ? (
                                    <CheckCircle2 className="mt-0.5 size-4 [color:var(--status-ok-fg)]" />
                                ) : (
                                    <Circle className="mt-0.5 size-4 text-muted-foreground" />
                                )}
                                <span className={step.done ? 'text-muted-foreground line-through' : ''}>
                                    {i + 1}. {step.label}
                                </span>
                            </li>
                        ))}
                    </ol>

                    {!isPersonal && (
                        <div className="flex flex-col gap-3 rounded-md border p-3">
                            <CopyRow label="Webhook / callback URL" value={channel.callbackUrl} />
                            {channel.provider === 'FACEBOOK' && (
                                <CopyRow label="Verify token" value={channel.verifyToken} />
                            )}
                            {isTelegram ? (
                                <p className="text-xs text-muted-foreground">
                                    Telegram can register automatically, but the URL
                                    must be public HTTPS (use a tunnel like ngrok in
                                    local dev). Then click “Register webhook”.
                                </p>
                            ) : (
                                <p className="text-xs text-muted-foreground">
                                    Paste the URL above into the provider’s dashboard,
                                    then click “Mark ready”.
                                </p>
                            )}
                        </div>
                    )}

                    {isPersonal && <ZaloQrLogin channelId={channel.id} />}

                    <div className="flex items-center gap-2 text-sm">
                        {channel.hasReceivedWebhook ? (
                            <span className="[color:var(--status-ok-fg)]">
                                ✓ Receiving messages ({channel.lastWebhookAt})
                            </span>
                        ) : (
                            <span className="text-muted-foreground">
                                No message received yet.
                            </span>
                        )}
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={() => setOpen(false)}>
                        Close
                    </Button>
                    {!isPersonal && (
                        <Button onClick={register} disabled={registering}>
                            <Wand2 data-icon="inline-start" />
                            {isTelegram ? 'Register webhook' : 'Mark ready'}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function AddChannelForm({ webhookBase }: { webhookBase: string }) {
    const form = useForm<{
        provider: string;
        name: string;
        webhook_secret: string;
        credentials: Record<string, string>;
    }>({
        provider: 'TELEGRAM',
        name: '',
        webhook_secret: '',
        credentials: {},
    });

    const fields = CREDENTIAL_FIELDS[form.data.provider] ?? [];

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/api/admin/channels', {
            preserveScroll: true,
            onSuccess: () => form.reset('name', 'credentials', 'webhook_secret'),
        });
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <MessageCirclePlus className="size-4 text-muted-foreground" />
                    Add channel account
                </CardTitle>
                <CardDescription>
                    Create a Telegram, Zalo or Facebook connection, then register
                    its webhook.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit}>
                    <FieldGroup>
                        <Field data-invalid={!!form.errors.provider}>
                            <FieldLabel>Provider</FieldLabel>
                            <Select
                                value={form.data.provider}
                                onValueChange={(value) => {
                                    form.setData('provider', value);
                                    form.setData('credentials', {});
                                }}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Choose provider" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        {PROVIDERS.map((p) => (
                                            <SelectItem key={p.value} value={p.value}>
                                                {p.label}
                                            </SelectItem>
                                        ))}
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                            <FieldError errors={[{ message: form.errors.provider }]} />
                        </Field>

                        <Field data-invalid={!!form.errors.name}>
                            <FieldLabel htmlFor="channel_name">Account name</FieldLabel>
                            <InputGroup>
                                <InputGroupInput
                                    id="channel_name"
                                    placeholder="e.g. Support Telegram Bot"
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    aria-invalid={!!form.errors.name}
                                />
                            </InputGroup>
                            <FieldError errors={[{ message: form.errors.name }]} />
                        </Field>

                        {fields.map((f) => (
                            <Field key={f.key}>
                                <FieldLabel htmlFor={`cred_${f.key}`}>{f.label}</FieldLabel>
                                <InputGroup>
                                    <InputGroupInput
                                        id={`cred_${f.key}`}
                                        type="password"
                                        autoComplete="off"
                                        value={form.data.credentials[f.key] ?? ''}
                                        onChange={(e) =>
                                            form.setData('credentials', {
                                                ...form.data.credentials,
                                                [f.key]: e.target.value,
                                            })
                                        }
                                    />
                                </InputGroup>
                            </Field>
                        ))}

                        {form.data.provider === 'ZALO_PERSONAL' && (
                            <FieldDescription>
                                No credentials needed here — log in by QR through the
                                sidecar after creating the account.
                            </FieldDescription>
                        )}

                        <Field>
                            <FieldLabel htmlFor="webhook_secret">
                                {form.data.provider === 'FACEBOOK'
                                    ? 'Webhook verify token'
                                    : 'Webhook secret'}{' '}
                                <span className="text-muted-foreground">(optional)</span>
                            </FieldLabel>
                            <InputGroup>
                                <InputGroupInput
                                    id="webhook_secret"
                                    autoComplete="off"
                                    placeholder="Auto-generated if left blank"
                                    value={form.data.webhook_secret}
                                    onChange={(e) => form.setData('webhook_secret', e.target.value)}
                                />
                            </InputGroup>
                            <FieldDescription className="break-all font-mono text-xs">
                                {webhookHint(form.data.provider, webhookBase)}
                            </FieldDescription>
                        </Field>

                        <Button type="submit" disabled={form.processing}>
                            <MessageCirclePlus data-icon="inline-start" />
                            Create account
                        </Button>
                    </FieldGroup>
                </form>
            </CardContent>
        </Card>
    );
}

export default function Channels({ channels, canManage, canDelete, webhookBase }: ChannelsProps) {
    const mockForm = useForm({
        provider: 'TELEGRAM',
        sender_name: 'Khách demo',
        text: 'Mình muốn được tư vấn CRM cho team support.',
    });

    function submitMock(event: FormEvent) {
        event.preventDefault();

        mockForm.post('/api/admin/mock/inbound', {
            preserveScroll: true,
            onSuccess: () => {
                mockForm.setData(
                    'text',
                    'Mình muốn được tư vấn CRM cho team support.',
                );
            },
        });
    }

    return (
        <>
            <Head title="Channels" />

            <main className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div className="min-w-0">
                        <p className="text-sm text-muted-foreground">
                            Channel Connectors / Telegram and Zalo
                        </p>
                        <h1 className="truncate text-2xl font-semibold tracking-tight">
                            Channel health
                        </h1>
                    </div>
                    <Button asChild variant="outline">
                        <Link href="/admin/inbox">
                            <MessageCirclePlus data-icon="inline-start" />
                            Open synced inbox
                        </Link>
                    </Button>
                </div>

                {channels.some((channel) => channel.lastErrorMessage) && (
                    <Alert className="[border-color:var(--status-warn-border)] [background-color:var(--status-warn-bg)]">
                        <AlertTriangle />
                        <AlertTitle>Provider attention</AlertTitle>
                        <AlertDescription>
                            One or more channel accounts report token or webhook
                            warnings.
                        </AlertDescription>
                    </Alert>
                )}

                <section className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_380px]">
                    <Card className="min-w-0">
                        <CardHeader>
                            <CardTitle>Provider accounts</CardTitle>
                            <CardDescription>
                                Webhook freshness, credentials state and last
                                visible error.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Provider</TableHead>
                                        <TableHead>Account</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Webhook</TableHead>
                                        <TableHead>Health</TableHead>
                                        <TableHead>Error</TableHead>
                                        <TableHead className="text-right">
                                            Action
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {channels.map((channel) => (
                                        <TableRow key={channel.id}>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <StatusDot
                                                        status={channel.status}
                                                    />
                                                    <span>
                                                        {providerLabel(
                                                            channel.provider,
                                                        )}
                                                    </span>
                                                </div>
                                            </TableCell>
                                            <TableCell className="max-w-[220px] truncate">
                                                {channel.name}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    status={channel.status}
                                                />
                                            </TableCell>
                                            <TableCell className="text-xs">
                                                {channel.hasReceivedWebhook ? (
                                                    <span className="[color:var(--status-ok-fg)]">
                                                        ✓ receiving
                                                        <span className="block text-muted-foreground">
                                                            {channel.lastWebhookAt}
                                                        </span>
                                                    </span>
                                                ) : channel.webhookUrl ? (
                                                    <span className="[color:var(--status-warn-fg)]">
                                                        registered, no message yet
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        not set up —{' '}
                                                        <span className="font-medium">
                                                            open Setup
                                                        </span>
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-xs text-muted-foreground">
                                                {channel.lastHealthCheckAt ??
                                                    'never'}
                                            </TableCell>
                                            <TableCell className="max-w-[260px]">
                                                {channel.lastErrorMessage ? (
                                                    <div className="flex flex-col gap-1">
                                                        <StatusBadge
                                                            status="DUE_SOON"
                                                            label={
                                                                channel.lastErrorCode ??
                                                                'WARN'
                                                            }
                                                        />
                                                        <span className="line-clamp-2 text-xs text-muted-foreground">
                                                            {
                                                                channel.lastErrorMessage
                                                            }
                                                        </span>
                                                    </div>
                                                ) : (
                                                    <span className="text-xs text-muted-foreground">
                                                        No visible error
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex justify-end gap-2">
                                                    {canManage && (
                                                        <SetupChannelDialog
                                                            channel={channel}
                                                        />
                                                    )}
                                                    {canManage && (
                                                        <EditChannelDialog
                                                            channel={channel}
                                                        />
                                                    )}
                                                    {canDelete && (
                                                        <DeleteChannelButton
                                                            id={channel.id}
                                                            name={channel.name}
                                                        />
                                                    )}
                                                    {!canManage && (
                                                        <span className="text-xs text-muted-foreground">
                                                            —
                                                        </span>
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <div className="flex flex-col gap-4">
                    {canManage && <AddChannelForm webhookBase={webhookBase} />}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Bot className="size-4 text-muted-foreground" />
                                Mock inbound
                            </CardTitle>
                            <CardDescription>
                                Create a provider-shaped webhook event for local
                                testing.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submitMock}>
                                <FieldGroup>
                                    <Field
                                        data-invalid={
                                            !!mockForm.errors.provider
                                        }
                                    >
                                        <FieldLabel>Provider</FieldLabel>
                                        <Select
                                            value={mockForm.data.provider}
                                            onValueChange={(value) =>
                                                mockForm.setData(
                                                    'provider',
                                                    value,
                                                )
                                            }
                                        >
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Choose provider" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectGroup>
                                                    <SelectItem value="TELEGRAM">
                                                        Telegram
                                                    </SelectItem>
                                                    <SelectItem value="ZALO_OA">
                                                        Zalo OA
                                                    </SelectItem>
                                                    <SelectItem value="FACEBOOK">
                                                        Facebook
                                                    </SelectItem>
                                                </SelectGroup>
                                            </SelectContent>
                                        </Select>
                                        <FieldError
                                            errors={[
                                                {
                                                    message:
                                                        mockForm.errors
                                                            .provider,
                                                },
                                            ]}
                                        />
                                    </Field>

                                    <Field
                                        data-invalid={
                                            !!mockForm.errors.sender_name
                                        }
                                    >
                                        <FieldLabel htmlFor="sender_name">
                                            Customer name
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupInput
                                                id="sender_name"
                                                value={
                                                    mockForm.data.sender_name
                                                }
                                                onChange={(event) =>
                                                    mockForm.setData(
                                                        'sender_name',
                                                        event.target.value,
                                                    )
                                                }
                                                aria-invalid={
                                                    !!mockForm.errors
                                                        .sender_name
                                                }
                                            />
                                        </InputGroup>
                                        <FieldError
                                            errors={[
                                                {
                                                    message:
                                                        mockForm.errors
                                                            .sender_name,
                                                },
                                            ]}
                                        />
                                    </Field>

                                    <Field
                                        data-invalid={!!mockForm.errors.text}
                                    >
                                        <FieldLabel htmlFor="mock_text">
                                            Message
                                        </FieldLabel>
                                        <InputGroup className="items-stretch">
                                            <InputGroupTextarea
                                                id="mock_text"
                                                rows={5}
                                                value={mockForm.data.text}
                                                onChange={(event) =>
                                                    mockForm.setData(
                                                        'text',
                                                        event.target.value,
                                                    )
                                                }
                                                aria-invalid={
                                                    !!mockForm.errors.text
                                                }
                                            />
                                            <InputGroupAddon align="block-end">
                                                <InputGroupButton
                                                    type="submit"
                                                    disabled={
                                                        mockForm.processing
                                                    }
                                                >
                                                    <MessageCirclePlus data-icon="inline-start" />
                                                    Create inbound
                                                </InputGroupButton>
                                            </InputGroupAddon>
                                        </InputGroup>
                                        <FieldDescription>
                                            The ingestor will create/match
                                            contact, lead, conversation, message
                                            and assignment.
                                        </FieldDescription>
                                        <FieldError
                                            errors={[
                                                {
                                                    message:
                                                        mockForm.errors.text,
                                                },
                                            ]}
                                        />
                                    </Field>
                                </FieldGroup>
                            </form>
                        </CardContent>
                    </Card>
                    </div>
                </section>
            </main>
        </>
    );
}
