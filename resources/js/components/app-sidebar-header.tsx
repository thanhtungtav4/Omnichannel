import { Link, usePage } from '@inertiajs/react';
import {
    Bell,
    ChevronsUpDown,
    HelpCircle,
    Search as SearchIcon,
} from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import {
    Avatar,
    AvatarFallback,
    AvatarImage,
} from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

type HeaderProps = {
    auth?: { user?: { name?: string; avatar?: string; [k: string]: unknown } };
    workspace?: { name: string; slug: string } | null;
};

function initials(name: string): string {
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((w) => w[0]?.toUpperCase() ?? '')
            .join('') || '?'
    );
}

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { auth, workspace } = usePage<HeaderProps>().props;
    const user = auth?.user;
    const displayName =
        (user?.display_name as string | undefined) ?? user?.name ?? 'Người dùng';
    const role = (user?.role as string | undefined) ?? '';

    return (
        <header className="flex h-14 shrink-0 items-center gap-3 border-b border-sidebar-border/50 px-4 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12">
            <SidebarTrigger className="-ml-1" />

            {/* Tenant switcher (mockup line 1659-1663): avatar +
                workspace name + chevron. */}
            {workspace && (
                <button
                    type="button"
                    className="flex min-w-0 items-center gap-2 rounded-md px-2 py-1 hover:bg-muted/60"
                    aria-label="Đổi workspace"
                >
                    <Avatar className="size-6 shrink-0">
                        <AvatarFallback className="text-[10px]">
                            {initials(workspace.name)}
                        </AvatarFallback>
                    </Avatar>
                    <span className="truncate text-sm font-medium">
                        {workspace.name}
                    </span>
                    <ChevronsUpDown
                        className="size-3.5 shrink-0 text-muted-foreground"
                        aria-hidden
                    />
                </button>
            )}

            <Breadcrumbs breadcrumbs={breadcrumbs} />

            {/* Search button — mockup line 1665-1669: full-width
                'Tìm khách, SĐT, mã hội thoại…' button with ⌘K.
                Click navigates to /admin/inbox?openPalette=1; the inbox
                page reads the param, opens the Cmd palette, and strips
                the param so reload doesn't re-open. ⌘K still works
                anywhere on the page. */}
            <Link
                href="/admin/inbox?openPalette=1"
                prefetch
                className="ml-2 flex h-8 min-w-0 flex-1 max-w-md items-center gap-2 rounded-md border bg-card px-3 text-sm text-muted-foreground transition-colors hover:bg-muted/40"
                title="Tìm nhanh (⌘K)"
                aria-label="Tìm khách, SĐT, mã hội thoại"
            >
                <SearchIcon className="size-3.5" aria-hidden />
                <span className="truncate">Tìm khách, SĐT, mã hội thoại…</span>
                <span className="ml-auto inline-flex items-center gap-0.5 [font-family:var(--font-mono)] text-[10px]">
                    <kbd className="rounded border bg-background px-1 py-0.5 text-[10px]">
                        ⌘
                    </kbd>
                    <kbd className="rounded border bg-background px-1 py-0.5 text-[10px]">
                        K
                    </kbd>
                </span>
            </Link>

            <div className="flex-1" />

            {/* Presence: agent is online and auto-receiving conversations. */}
            <span className="hidden items-center gap-1.5 rounded-full [background-color:var(--status-ok-bg)] px-2.5 py-1 text-xs [color:var(--status-ok-fg)] sm:inline-flex">
                <span className="size-1.5 rounded-full [background-color:var(--status-ok-fg)]" />
                Online · Tự nhận
            </span>

            {/* Bell — mockup line 1678-1682: icon-btn with red dot. */}
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="relative size-8 text-muted-foreground"
                aria-label="Thông báo"
            >
                <Bell className="size-4" aria-hidden />
                <span
                    className="absolute right-1.5 top-1.5 size-1.5 rounded-full bg-[var(--status-danger-fg)] ring-2 ring-card"
                    aria-hidden
                />
            </Button>

            {/* Help — mockup line 1683-1685: icon-btn. */}
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-8 text-muted-foreground"
                aria-label="Trợ giúp"
            >
                <HelpCircle className="size-4" aria-hidden />
            </Button>

            <div className="flex items-center gap-2">
                <Avatar className="size-7">
                    {user?.avatar && (
                        <AvatarImage src={user.avatar} alt={displayName} />
                    )}
                    <AvatarFallback className="text-[11px]">
                        {initials(displayName)}
                    </AvatarFallback>
                </Avatar>
                <div className="hidden flex-col leading-tight sm:flex">
                    <span className="text-xs font-medium">{displayName}</span>
                    {role && (
                        <span className="text-[11px] text-muted-foreground">
                            {role}
                        </span>
                    )}
                </div>
            </div>
        </header>
    );
}
