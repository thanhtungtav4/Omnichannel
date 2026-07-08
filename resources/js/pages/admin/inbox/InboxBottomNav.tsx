import {
    Inbox as InboxIcon,
    Search,
    UserRound,
    Users,
} from 'lucide-react';
import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';

/**
 * Mobile bottom navigation — only rendered on <768px viewports.
 * 4 tabs matching mockup §3.4:
 *   Hộp thư (queue)   — Inbox list (with unread badge)
 *   Khách     (customer)— Customer panel (bottom sheet on mobile)
 *   Tìm       (search)  — Cmd palette overlay
 *   Tôi       (profile) — /settings/profile
 *
 * Active state is derived from the inbox shell's `data-view` attribute set by
 * the parent. Tab taps dispatch one of:
 *   - setView('queue' | 'customer') — toggles the inboxShell data-view
 *   - onSearch()                      — parent opens the CmdPalette
 *   - router.visit('/settings/profile') — own profile page
 */
export function InboxBottomNav({
    unreadCount = 0,
    isOnline = false,
    onQueue,
    onCustomer,
    onSearch,
    activeView,
}: {
    unreadCount: number;
    isOnline: boolean;
    onQueue: () => void;
    onCustomer: () => void;
    onSearch: () => void;
    activeView: 'queue' | 'thread' | 'customer' | null;
}) {
    return (
        <nav
            aria-label="Điều hướng chính"
            className="fixed inset-x-0 bottom-0 z-50 flex justify-around border-t bg-card shadow-[0_-4px_12px_rgb(0_0_0/0.04)] pb-[calc(4px+env(safe-area-inset-bottom,0px))] pt-1 md:hidden"
        >
            <NavButton
                label="Hộp thư"
                icon={<InboxIcon className="size-[22px]" />}
                active={activeView === 'queue'}
                onClick={onQueue}
                badge={unreadCount > 0 ? (unreadCount > 99 ? '99+' : String(unreadCount)) : null}
            />
            <NavButton
                label="Khách"
                icon={<Users className="size-[22px]" />}
                active={activeView === 'customer'}
                onClick={onCustomer}
            />
            <NavButton
                label="Tìm"
                icon={<Search className="size-[22px]" />}
                active={false}
                onClick={onSearch}
            />
            <NavButton
                label="Tôi"
                icon={<UserRound className="size-[22px]" />}
                active={false}
                onClick={() => router.visit('/settings/profile')}
                badge={isOnline ? 'ok' : null}
            />
        </nav>
    );
}

function NavButton({
    label,
    icon,
    active,
    onClick,
    badge,
}: {
    label: string;
    icon: React.ReactNode;
    active: boolean;
    onClick: () => void;
    /** null = no badge, "ok" = presence dot (green), string = unread count. */
    badge?: string | null;
}) {
    return (
        <button
            type="button"
            data-active={active}
            onClick={onClick}
            aria-label={label}
            aria-current={active ? 'page' : undefined}
            className={cn(
                'flex min-h-12 flex-1 flex-col items-center justify-center gap-0.5 bg-transparent px-0 py-1 text-muted-foreground',
                '[-webkit-tap-highlight-color:transparent]',
                'transition-colors',
                active && 'text-primary',
            )}
        >
            <span className="relative inline-flex">
                {icon}
                {/* Unread count badge — sits top-right of icon. */}
                {badge && badge !== 'ok' && (
                    <span
                        className="absolute -right-2 -top-1 grid min-h-4 min-w-4 place-items-center rounded-full bg-destructive px-1 text-[9px] font-bold leading-none text-destructive-foreground"
                    >
                        {badge}
                    </span>
                )}
                {/* Presence dot — green = online. */}
                {badge === 'ok' && (
                    <span
                        aria-label="Đang online"
                        className="absolute -right-1.5 -top-0.5 size-2 rounded-full [background-color:var(--status-ok-fg)] ring-2 ring-card"
                    />
                )}
            </span>
            <span className="text-[10px] font-medium leading-none">{label}</span>
        </button>
    );
}