import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
    /** Optional badge count shown right-aligned. Tone controls the
     *  status color used; absent / `count === 0` hides the badge. */
    badge?: {
        count: number;
        tone?: 'ok' | 'info' | 'warn' | 'danger' | 'idle';
    };
};
