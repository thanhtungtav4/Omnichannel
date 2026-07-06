import { router } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';

// Agent-controlled free-form tags on a customer (VIP, Nợ tiền, ...). Chips +
// an add box; each change PUTs the whole list. ponytail: JSON array on the
// contact — no tag table until tags need colours or cross-entity reuse.
export function TagEditor({
    contactId,
    tags,
}: {
    contactId: string;
    tags: string[];
}) {
    const [draft, setDraft] = useState('');

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

    function add() {
        const t = draft.trim();
        if (!t) return;
        if (tags.some((x) => x.toLowerCase() === t.toLowerCase())) {
            setDraft('');
            return;
        }
        save([...tags, t]);
        setDraft('');
    }

    return (
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
                            add();
                        }
                    }}
                    placeholder="Thêm tag…"
                    className="h-7 w-24 text-xs"
                />
                <button
                    type="button"
                    onClick={add}
                    aria-label="Thêm tag"
                    className="rounded-md border p-1 text-muted-foreground hover:bg-accent"
                >
                    <Plus className="size-3.5" />
                </button>
            </div>
        </div>
    );
}
