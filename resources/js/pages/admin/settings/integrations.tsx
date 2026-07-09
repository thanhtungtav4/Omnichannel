import { Head, router, useForm } from '@inertiajs/react';
import { Copy, KeyRound, RotateCw, ShieldAlert, Trash2 } from 'lucide-react';
import {  useState } from 'react';
import type {FormEvent} from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Specs/15_CONTACTS_INGESTION.md § C3 — settings/integrations page.
 *
 * Lists workspace_ingest_tokens, lets owner/admin mint new tokens,
 * rotate (re-mint + revoke old), and revoke. The plaintext token is
 * shown EXACTLY ONCE on mint/rotate (via the alert below) and is gone
 * on the next page load.
 */

type IngestToken = {
    id: string;
    name: string;
    token_prefix: string;
    allowed_sources: string[];
    requires_hmac: boolean;
    rate_limit_per_minute: number;
    default_source_detail: string | null;
    domain_whitelist: string | null;
    last_used_at: string | null;
    expires_at: string | null;
    revoked_at: string | null;
    is_active: boolean;
    created_at: string | null;
};

type NewlyMinted = {
    token: IngestToken;
    plaintext: string;
    hmac_secret: string | null;
};

type Props = {
    tokens: IngestToken[];
    publicEndpoint: string;
};

function csrfToken(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

function MintDialog() {
    const [open, setOpen] = useState(false);
    const form = useForm<{
        name: string;
        allowed_sources: string[];
        with_hmac: boolean;
        rate_limit_per_minute: number;
        default_source_detail: string;
        domain_whitelist: string;
    }>({
        name: '',
        allowed_sources: ['WEBSITE_FORM'],
        with_hmac: false,
        rate_limit_per_minute: 60,
        default_source_detail: '',
        domain_whitelist: '',
    });
    const [minted, setMinted] = useState<NewlyMinted | null>(null);

    function toggleSource(src: string) {
        const next = form.data.allowed_sources.includes(src)
            ? form.data.allowed_sources.filter((s) => s !== src)
            : [...form.data.allowed_sources, src];
        form.setData('allowed_sources', next);
    }

    async function submit(e: FormEvent) {
        e.preventDefault();
        form.clearErrors();

        try {
            const res = await fetch('/api/admin/ingest-tokens', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(form.data),
            });

            if (res.status === 422) {
                const body = (await res.json()) as {
                    errors: Record<string, string[]>;
                };
                form.setError(
                    Object.fromEntries(
                        Object.entries(body.errors ?? {}).map(([k, v]) => [
                            k,
                            v.join(' '),
                        ]),
                    ),
                );

                return;
            }

            if (!res.ok) {
                form.setError('name', `Mint failed (${res.status})`);

                return;
            }

            const data = (await res.json()) as NewlyMinted;
            setMinted(data);
            form.reset();
            // Refresh the Inertia page so the new token shows in the list
            // (the fetch above bypasses Inertia's data pipeline).
            router.reload({ only: ['tokens'] });
        } catch (err) {
            form.setError(
                'name',
                err instanceof Error ? err.message : 'Network error',
            );
        }
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(v) => {
                setOpen(v);

                if (!v) {
                    setMinted(null);
                    form.reset();
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button>
                    <KeyRound data-icon="inline-start" />
                    Mint new token
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Mint ingest token</DialogTitle>
                    <DialogDescription>
                        Returns the plaintext EXACTLY ONCE. Copy it now — you
                        won't be able to read it again from the UI.
                    </DialogDescription>
                </DialogHeader>

                {minted ? (
                    <NewlyMintedAlert minted={minted} />
                ) : (
                    <form onSubmit={submit} className="space-y-4">
                        <FieldGroup>
                            <Field>
                                <FieldLabel htmlFor="token-name">Name</FieldLabel>
                                <Input
                                    id="token-name"
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                    placeholder="Landing page mùa hè"
                                    required
                                />
                                <FieldError>
                                    {form.errors.name}
                                </FieldError>
                            </Field>

                            <Field>
                                <FieldLabel>Allowed sources</FieldLabel>
                                <div className="flex flex-col gap-2">
                                    {[
                                        {
                                            code: 'WEBSITE_FORM',
                                            label: 'Website form',
                                            desc: 'Public web form ingest',
                                        },
                                        {
                                            code: 'ZALO_MINIAPP',
                                            label: 'Zalo Mini App',
                                            desc: 'Requires HMAC signature',
                                        },
                                    ].map((src) => (
                                        <label
                                            key={src.code}
                                            className="flex items-start gap-2 cursor-pointer rounded-md border border-input p-3 hover:bg-muted/50"
                                        >
                                            <Checkbox
                                                checked={form.data.allowed_sources.includes(
                                                    src.code,
                                                )}
                                                onCheckedChange={() =>
                                                    toggleSource(src.code)
                                                }
                                            />
                                            <div className="space-y-0.5">
                                                <div className="text-sm font-medium">
                                                    {src.label}
                                                    <span className="ml-2 text-xs text-muted-foreground">
                                                        {src.code}
                                                    </span>
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {src.desc}
                                                </div>
                                            </div>
                                        </label>
                                    ))}
                                </div>
                                <FieldError>
                                    {form.errors['allowed_sources.0'] ??
                                        form.errors.allowed_sources}
                                </FieldError>
                            </Field>

                            <Field>
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="with_hmac"
                                        checked={form.data.with_hmac}
                                        onCheckedChange={(v) =>
                                            form.setData('with_hmac', Boolean(v))
                                        }
                                    />
                                    <Label htmlFor="with_hmac">
                                        Requires HMAC signature
                                    </Label>
                                </div>
                                <FieldDescription>
                                    Required for ZALO_MINIAPP. Encrypts an
                                    HMAC secret at rest, used to verify the
                                    X-Signature header.
                                </FieldDescription>
                            </Field>

                            <Field>
                                <FieldLabel htmlFor="rate-limit">
                                    Rate limit (per minute)
                                </FieldLabel>
                                <Input
                                    id="rate-limit"
                                    type="number"
                                    min={1}
                                    max={10000}
                                    value={form.data.rate_limit_per_minute}
                                    onChange={(e) =>
                                        form.setData(
                                            'rate_limit_per_minute',
                                            Number(e.target.value),
                                        )
                                    }
                                />
                            </Field>

                            <Field>
                                <FieldLabel htmlFor="src-detail">
                                    Default source detail
                                </FieldLabel>
                                <Input
                                    id="src-detail"
                                    value={form.data.default_source_detail}
                                    onChange={(e) =>
                                        form.setData(
                                            'default_source_detail',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="summer-sale-2026"
                                />
                                <FieldDescription>
                                    Applied when the form omits X-Source-Detail.
                                </FieldDescription>
                            </Field>

                            <Field>
                                <FieldLabel htmlFor="whitelist">
                                    Domain whitelist
                                </FieldLabel>
                                <Input
                                    id="whitelist"
                                    value={form.data.domain_whitelist}
                                    onChange={(e) =>
                                        form.setData(
                                            'domain_whitelist',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="example.com, https://www.example.com"
                                />
                                <FieldDescription>
                                    Comma-separated origins. Empty = no check.
                                </FieldDescription>
                            </Field>
                        </FieldGroup>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                Mint
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}

function NewlyMintedAlert({ minted }: { minted: NewlyMinted }) {
    return (
        <Alert className="border-amber-500/40 bg-amber-50/40 dark:bg-amber-950/20">
            <ShieldAlert className="h-4 w-4 text-amber-600" />
            <AlertTitle>Copy now — shown only once</AlertTitle>
            <AlertDescription className="space-y-3">
                <p className="text-sm">
                    Paste these values into the website form / Zalo OA
                    dashboard. We will NOT show them again.
                </p>
                <CopyableRow label="Plaintext token" value={minted.plaintext} />
                {minted.hmac_secret && (
                    <CopyableRow
                        label="HMAC secret"
                        value={minted.hmac_secret}
                    />
                )}
                <p className="text-xs text-muted-foreground">
                    Prefix shown in the list:{' '}
                    <code className="font-mono">
                        {minted.token.token_prefix}
                    </code>
                </p>
            </AlertDescription>
        </Alert>
    );
}

function CopyableRow({ label, value }: { label: string; value: string }) {
    const [copied, setCopied] = useState(false);
    function copy() {
        void navigator.clipboard.writeText(value);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 1500);
    }

    return (
        <div className="space-y-1">
            <div className="text-xs font-medium text-muted-foreground">
                {label}
            </div>
            <div className="flex items-stretch gap-1">
                <Input
                    readOnly
                    value={value}
                    className="font-mono text-xs"
                />
                <Button
                    type="button"
                    size="icon"
                    variant="outline"
                    onClick={copy}
                    aria-label="Copy"
                >
                    <Copy className="h-4 w-4" />
                </Button>
                {copied && (
                    <span className="self-center text-xs text-emerald-600">
                        Copied
                    </span>
                )}
            </div>
        </div>
    );
}

function RotateTokenButton({ token }: { token: IngestToken }) {
    const [open, setOpen] = useState(false);
    const [minted, setMinted] = useState<NewlyMinted | null>(null);
    const [processing, setProcessing] = useState(false);

    async function rotate() {
        setProcessing(true);

        try {
            const res = await fetch(
                `/api/admin/ingest-tokens/${token.id}/rotate`,
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            );

            if (!res.ok) {
                setProcessing(false);

                return;
            }

            const data = (await res.json()) as NewlyMinted;
            setMinted(data);
            setProcessing(false);
            router.reload({ only: ['tokens'] });
        } catch {
            setProcessing(false);
        }
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(v) => {
                setOpen(v);

                if (!v) {
                    setMinted(null);
                }
            }}
        >
            <DialogTrigger asChild>
                <Button variant="outline" size="sm" className="min-h-9 sm:min-h-8">
                    <RotateCw data-icon="inline-start" />
                    Rotate
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Rotate token</DialogTitle>
                    <DialogDescription>
                        Mint a fresh token and revoke the current one. The
                        current token's plaintext isn't shown here — but the
                        new plaintext is.
                    </DialogDescription>
                </DialogHeader>

                {minted ? (
                    <NewlyMintedAlert minted={minted} />
                ) : (
                    <div className="space-y-4">
                        <div className="text-sm">
                            <div>
                                <span className="text-muted-foreground">
                                    Rotating:
                                </span>{' '}
                                <span className="font-medium">{token.name}</span>{' '}
                                <code className="font-mono text-xs">
                                    ({token.token_prefix})
                                </code>
                            </div>
                        </div>
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => setOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button onClick={rotate} disabled={processing}>
                                <RotateCw data-icon="inline-start" />
                                Rotate
                            </Button>
                        </DialogFooter>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

function RevokeTokenButton({ token }: { token: IngestToken }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    function confirm() {
        setProcessing(true);
        router.delete(`/api/admin/ingest-tokens/${token.id}`, {
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
                    className="min-h-9 sm:min-h-8 [color:var(--status-danger-fg)]"
                    disabled={!token.is_active}
                >
                    <Trash2 data-icon="inline-start" />
                    Revoke
                </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Revoke “{token.name}”?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        Active integrations using this token will receive 401
                        on the next call. The row is preserved (revoked_at set)
                        so the audit log stays complete.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={processing}>
                        Cancel
                    </AlertDialogCancel>
                    <AlertDialogAction
                        onClick={(e) => {
                            e.preventDefault();
                            confirm();
                        }}
                        disabled={processing}
                        className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                    >
                        Revoke
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

export default function IntegrationsPage({ tokens, publicEndpoint }: Props) {
    return (
        <>
            <Head title="Integrations" />
            <div className="space-y-6 p-6">
                <div className="flex items-end justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Integrations
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Public API tokens for website forms and the Zalo
                            Mini App. Each token is scoped per source so a
                            leaked web-form token can't be used to forge a
                            Mini App event.
                        </p>
                    </div>
                    <MintDialog />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Public endpoint</CardTitle>
                        <CardDescription>
                            POST a JSON body with{' '}
                            <code className="font-mono text-xs">
                                X-Workspace-Key
                            </code>{' '}
                            and{' '}
                            <code className="font-mono text-xs">X-Source</code>.
                            Zalo Mini App requests must also include{' '}
                            <code className="font-mono text-xs">
                                X-Signature: t=&lt;unix&gt;,s=&lt;hex&gt;
                            </code>
                            .
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <code className="block rounded-md bg-muted px-3 py-2 font-mono text-xs">
                            POST {publicEndpoint}
                        </code>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Active tokens</CardTitle>
                        <CardDescription>
                            Each row is one token. The plaintext is shown
                            only at mint/rotate — copy it then.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {tokens.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No tokens yet. Mint one to wire up a website
                                form or Zalo Mini App backend.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="text-left text-xs uppercase tracking-wide text-muted-foreground">
                                        <tr>
                                            <th className="py-2 pr-3">Name</th>
                                            <th className="py-2 pr-3">Prefix</th>
                                            <th className="py-2 pr-3">
                                                Sources
                                            </th>
                                            <th className="py-2 pr-3">
                                                Rate / min
                                            </th>
                                            <th className="py-2 pr-3">
                                                HMAC
                                            </th>
                                            <th className="py-2 pr-3">
                                                Last used
                                            </th>
                                            <th className="py-2 pr-3">
                                                Status
                                            </th>
                                            <th className="py-2 pr-3"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {tokens.map((t) => (
                                            <tr
                                                key={t.id}
                                                className="border-t border-border"
                                            >
                                                <td className="py-3 pr-3 font-medium">
                                                    {t.name}
                                                </td>
                                                <td className="py-3 pr-3">
                                                    <code className="font-mono text-xs">
                                                        {t.token_prefix}
                                                    </code>
                                                </td>
                                                <td className="py-3 pr-3">
                                                    {t.allowed_sources.join(
                                                        ', ',
                                                    )}
                                                </td>
                                                <td className="py-3 pr-3">
                                                    {t.rate_limit_per_minute}
                                                </td>
                                                <td className="py-3 pr-3">
                                                    {t.requires_hmac
                                                        ? 'required'
                                                        : '—'}
                                                </td>
                                                <td className="py-3 pr-3 text-muted-foreground">
                                                    {t.last_used_at
                                                        ? new Date(
                                                              t.last_used_at,
                                                          ).toLocaleString()
                                                        : 'never'}
                                                </td>
                                                <td className="py-3 pr-3">
                                                    {t.revoked_at ? (
                                                        <span className="text-destructive">
                                                            revoked
                                                        </span>
                                                    ) : t.expires_at &&
                                                      new Date(t.expires_at) <
                                                        new Date() ? (
                                                        <span className="text-amber-600">
                                                            expired
                                                        </span>
                                                    ) : (
                                                        <span className="text-emerald-600">
                                                            active
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="py-3 pr-3">
                                                    <div className="flex gap-1">
                                                        <RotateTokenButton
                                                            token={t}
                                                        />
                                                        <RevokeTokenButton
                                                            token={t}
                                                        />
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}