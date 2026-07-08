import { router } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';

/**
 * Agent-controlled tags on a customer. Mockup §3.5 has the profile tab
 * show tag chips + an inline "Thêm" affordance; this component is that
 * affordance plus the kebab-menu "Gắn nhãn" launcher.
 *
 * Workspace-scope vocabulary: tags added here automatically extend the
 * workspace's tag vocabulary (workspace_settings.tags.vocabulary) so other
 * agents see them as suggestions next time they tag a contact. Vocabulary
 * is consulted in autocomplete via the `suggestions` prop.
 */
export function TagEditor({
    contactId,
    tags,
    suggestions = [],
}: {
    contactId: string;
    tags: string[];
    /** Workspace vocabulary — tags not yet on this contact but available to add. */
    suggestions?: string[];
}) {
    const [draft, setDraft] = useState('');

    // Suggestions = vocab - tags. Capped at 8 chips.
    const remaining = useMemo(
        () =>
            suggestions
                .filter((s) => !tags.some((t) => t.toLowerCase() === s.toLowerCase()))
                .slice(0, 8),
        [suggestions, tags],
    );

    function save(next: string[]) {
        router.put(
            `/api/admin/contacts/${contactId}/tags`,
            { tags: next },
            {
                preserveScroll: true,
                onError: () => toast.error('Lưu tag lỗi'),
            },
        );
    }

    function add(tag: string) {
        const t = tag.trim();
        if (!t) return;
        if (tags.some((x) => x.toLowerCase() === t.toLowerCase())) {
            setDraft('');
            return;
        }
        save([...tags, t]);
        setDraft('');
    }

    return (
        <div className="flex flex-col gap-1.5">
            <div className="flex flex-wrap items-center gap-1.5">
                {tags.map((tag) => (
                    <Badge key={tag} variant="secondary" className="gap-1">
                        {tag}
                        <button
                            type="button"
                            onClick={() => save(tags.filter((x) => x !== tag))}
                            aria-label={`Xoá tag ${tag}`}
                            className="opacity-60 hover:opacity-100"
                        >
                            <X className="size-3" />
                        </button>
                    </Badge>
                ))}
                <div className="flex items-center gap-1">
                    <Input
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                add(draft);
                            }
                        }}
                        placeholder="Thêm tag…"
                        className="h-9 w-28 text-xs sm:h-7"
                    />
                    <button
                        type="button"
                        onClick={() => add(draft)}
                        aria-label="Thêm tag"
                        className="flex size-9 shrink-0 items-center justify-center rounded-md border text-muted-foreground hover:bg-accent sm:size-7"
                    >
                        <Plus className="size-3.5" />
                    </button>
                </div>
            </div>
            {remaining.length > 0 && (
                <div className="flex flex-wrap items-center gap-1 text-[11px] text-muted-foreground">
                    <span className="shrink-0">Gợi ý:</span>
                    {remaining.map((s) => (
                        <button
                            key={s}
                            type="button"
                            onClick={() => add(s)}
                            title={`Thêm tag "${s}"`}
                            className="rounded-full border border-dashed px-2 py-0.5 hover:border-solid hover:bg-accent"
                        >
                            + {s}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}