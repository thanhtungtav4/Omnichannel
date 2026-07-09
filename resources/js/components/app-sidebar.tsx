import { Link } from '@inertiajs/react';
import {
    Activity,
    Briefcase,
    Inbox,
    LayoutDashboard,
    Plug,
    Route,
    Settings,
    Target,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain  } from '@/components/nav-main';
import type {NavSection} from '@/components/nav-main';
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

const navSections: NavSection[] = [
    {
        label: 'Tổng quan',
        items: [
            { title: 'Tổng quan', href: '/admin', icon: LayoutDashboard },
            {
                title: 'Hộp thư',
                href: '/admin/inbox',
                icon: Inbox,
                badge: { count: 0, tone: 'info' },
            },
        ],
    },
    {
        label: 'CRM',
        items: [
            { title: 'Khách hàng', href: '/admin/contacts', icon: Users },
            {
                title: 'Cơ hội',
                href: '/admin/leads',
                icon: Briefcase,
                badge: { count: 0, tone: 'info' },
            },
            { title: 'Deal', href: '/admin/leads', icon: Target },
        ],
    },
    {
        label: 'Vận hành',
        items: [
            { title: 'Phân bổ', href: '/admin/routing', icon: Route },
            {
                title: 'Kênh',
                href: '/admin/channels',
                icon: Plug,
                badge: { count: 0, tone: 'warn' },
            },
            { title: 'Tích hợp', href: '/admin/settings/integrations', icon: Plug },
        ],
    },
    {
        label: 'Hệ thống',
        items: [{ title: 'Cài đặt', href: '/settings/profile', icon: Settings }],
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
                <NavMain sections={navSections} />
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
