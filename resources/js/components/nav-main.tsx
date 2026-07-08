import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import type { NavItem } from '@/types';

export type NavSection = { label: string; items: NavItem[] };

export function NavMain({ sections = [] }: { sections: NavSection[] }) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <>
            {sections.map((section) => (
                <SidebarGroup key={section.label} className="px-2 py-0">
                    <SidebarGroupLabel>{section.label}</SidebarGroupLabel>
                    <SidebarMenu>
                        {section.items.map((item) => (
                            <SidebarMenuItem key={item.title}>
                                <SidebarMenuButton
                                    asChild
                                    isActive={isCurrentUrl(item.href)}
                                    tooltip={{ children: item.title }}
                                >
                                    <Link href={item.href} prefetch>
                                        {item.icon && <item.icon />}
                                        <span>{item.title}</span>
                                    </Link>
                                </SidebarMenuButton>
                                {item.badge && item.badge.count > 0 && (
                                    <span
                                        className={cn(
                                            'ml-auto inline-flex h-5 min-w-5 items-center justify-center rounded-md px-1.5 text-[10px] font-semibold tabular-nums',
                                            item.badge.tone === 'danger'
                                                ? '[background-color:var(--status-danger-bg)] [color:var(--status-danger-fg)] [border:1px_solid_var(--status-danger-border)]'
                                                : item.badge.tone === 'warn'
                                                  ? '[background-color:var(--status-warn-bg)] [color:var(--status-warn-fg)] [border:1px_solid_var(--status-warn-border)]'
                                                  : item.badge.tone === 'info'
                                                    ? '[background-color:var(--status-info-bg)] [color:var(--status-info-fg)] [border:1px_solid_var(--status-info-border)]'
                                                    : '[background-color:var(--muted)] text-muted-foreground',
                                        )}
                                        aria-label={`${item.badge.count} mục`}
                                    >
                                        {item.badge.count > 99
                                            ? '99+'
                                            : item.badge.count}
                                    </span>
                                )}
                            </SidebarMenuItem>
                        ))}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}
