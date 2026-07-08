import { usePage } from '@inertiajs/react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import {
    Avatar,
    AvatarFallback,
    AvatarImage,
} from '@/components/ui/avatar';
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

            {workspace && (
                <div className="flex min-w-0 items-center gap-2">
                    <span className="truncate text-sm font-medium">
                        {workspace.name}
                    </span>
                </div>
            )}

            <Breadcrumbs breadcrumbs={breadcrumbs} />

            <div className="flex-1" />

            {/* Presence: agent is online and auto-receiving conversations. */}
            <span className="hidden items-center gap-1.5 rounded-full [background-color:var(--status-ok-bg)] px-2.5 py-1 text-xs [color:var(--status-ok-fg)] sm:inline-flex">
                <span className="size-1.5 rounded-full [background-color:var(--status-ok-fg)]" />
                Online · Tự nhận
            </span>

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
