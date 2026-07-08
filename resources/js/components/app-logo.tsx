import { usePage } from '@inertiajs/react';

type WorkspaceProps = {
    workspace?: { name: string; slug: string } | null;
    tenantDomain?: string;
};

function initials(name: string): string {
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((w) => w[0]?.toUpperCase() ?? '')
            .join('') || 'CRM'
    );
}

export default function AppLogo() {
    const { workspace, tenantDomain } = usePage<WorkspaceProps>().props;
    const name = workspace?.name ?? 'CRM Cockpit';
    const sub = workspace
        ? `${workspace.slug}.${tenantDomain ?? ''}`
        : 'Omnichannel Ops';

    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sm font-semibold text-sidebar-primary-foreground">
                {initials(name)}
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-tight font-semibold">
                    {name}
                </span>
                <span className="truncate text-xs text-muted-foreground">
                    {sub}
                </span>
            </div>
        </>
    );
}
