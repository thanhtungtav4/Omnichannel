import { Link } from '@inertiajs/react';
import {
    Activity,
    ContactRound,
    KanbanSquare,
    LayoutDashboard,
    MessageSquareText,
    RadioTower,
    Route,
    Settings,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Overview',
        href: '/admin',
        icon: LayoutDashboard,
    },
    {
        title: 'Inbox',
        href: '/admin/inbox',
        icon: MessageSquareText,
    },
    {
        title: 'Contacts',
        href: '/admin/contacts',
        icon: ContactRound,
    },
    {
        title: 'Leads',
        href: '/admin/leads',
        icon: KanbanSquare,
    },
    {
        title: 'Channels',
        href: '/admin/channels',
        icon: RadioTower,
    },
    {
        title: 'Routing',
        href: '/admin/routing',
        icon: Route,
    },
    {
        title: 'Settings',
        href: '/settings/profile',
        icon: Settings,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/admin" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <div className="mx-2 rounded-md border border-sidebar-border bg-sidebar-accent/60 px-3 py-2 text-xs text-muted-foreground group-data-[collapsible=icon]:hidden">
                    <div className="flex items-center gap-2 font-medium text-sidebar-foreground">
                        <Activity className="size-3.5" />
                        <span>Ops mode</span>
                    </div>
                    <p className="mt-1 leading-snug">
                        Zalo, Telegram, assignment and CRM links stay modular.
                    </p>
                </div>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
