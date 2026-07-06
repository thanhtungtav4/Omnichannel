import type {
    ActiveConversation,
    ConversationSummary,
} from '@/types';

export type QueueTab = 'all' | 'mine' | 'waiting' | 'priority';
export type ThreadMessage = ActiveConversation['messages'][number];

export type InboxStats = {
    open: number;
    waitingAgent: number;
    unassigned: number;
    failedOutbox: number;
};

const PROVIDER_LABELS: Record<string, string> = {
    TELEGRAM: 'Telegram',
    ZALO_PERSONAL: 'Zalo personal',
    ZALO_OA: 'Zalo OA',
    FACEBOOK: 'Facebook',
};

export const MESSAGE_STATUS_VI: Record<string, string> = {
    RECEIVED: 'Đã nhận',
    QUEUED: 'Đang chờ gửi',
    SENDING: 'Đang gửi',
    SENT: 'Đã gửi',
    DELIVERED: 'Đã nhận',
    READ: 'Đã xem',
    FAILED: 'Gửi lỗi',
    RETRYING: 'Đang thử lại',
};

export function initials(name?: string | null) {
    return (name ?? 'KH')
        .split(' ')
        .filter(Boolean)
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

export function providerLabel(provider?: string | null) {
    return provider ? (PROVIDER_LABELS[provider] ?? provider) : 'Channel';
}

export function customerName(
    conversation?: ConversationSummary | ActiveConversation | null,
) {
    return (
        conversation?.contact?.name ?? conversation?.subject ?? 'Khách chưa rõ tên'
    );
}

export function providerClass(provider?: string | null) {
    if (provider === 'TELEGRAM') {
        return '[border-color:var(--channel-telegram)] [color:var(--channel-telegram)]';
    }
    if (provider === 'ZALO_PERSONAL' || provider === 'ZALO_OA') {
        return '[border-color:var(--channel-zalo)] [color:var(--channel-zalo)]';
    }
    return '';
}

export function queueTabValue(
    conversation: ConversationSummary,
    currentOwnerId?: number | null,
) {
    const mine =
        currentOwnerId && conversation.owner?.id === Number(currentOwnerId);
    const waiting = conversation.status === 'WAITING_AGENT';
    const priority = ['HIGH', 'URGENT'].includes(conversation.priority);
    return { mine, waiting, priority };
}

export function dayLabel(iso?: string | null): string {
    if (!iso) return '';
    const today = new Date();
    const d = new Date(iso + 'T00:00:00');
    const diffDays = Math.round(
        (today.setHours(0, 0, 0, 0) - d.getTime()) / 86_400_000,
    );
    if (diffDays === 0) return 'Hôm nay';
    if (diffDays === 1) return 'Hôm qua';
    return d.toLocaleDateString('vi-VN');
}

// Quick-reply templates. Type "/" in the composer to insert one.
// ponytail: static list for now; move to a DB table when agents need to edit them.
export const QUICK_TEMPLATES: { key: string; label: string; text: string }[] = [
    { key: 'chao', label: 'Chào hỏi', text: 'Dạ em chào anh/chị, em có thể hỗ trợ gì cho mình ạ?' },
    { key: 'cam-on', label: 'Cảm ơn', text: 'Dạ em cảm ơn anh/chị đã liên hệ ạ!' },
    { key: 'cho', label: 'Chờ chút', text: 'Dạ anh/chị chờ em một chút, em kiểm tra thông tin ngay ạ.' },
    { key: 'gia', label: 'Báo giá', text: 'Dạ về mức giá, em xin phép gửi anh/chị bảng giá chi tiết ạ.' },
    { key: 'hen', label: 'Hẹn lịch', text: 'Dạ mình muốn đặt lịch vào thời gian nào để em sắp xếp ạ?' },
    { key: 'ket', label: 'Kết thúc', text: 'Dạ em cảm ơn anh/chị, chúc anh/chị một ngày tốt lành ạ!' },
];

// ponytail: static common set; swap for a picker lib only if agents ask for search/skin-tones.
export const EMOJIS = [
    '😀', '😄', '😁', '😊', '🙂', '😍', '😘', '😆', '😅', '😂',
    '🤣', '😉', '😎', '🤗', '🤔', '😐', '😴', '😭', '😱', '😡',
    '👍', '👎', '👌', '🙏', '👏', '💪', '🤝', '✌️', '❤️', '💕',
    '🔥', '✨', '🎉', '💯', '✅', '❌', '⭐', '💰', '📞', '📅',
];

export function groupByDay(messages: ThreadMessage[]) {
    const groups: { date: string; label: string; messages: ThreadMessage[] }[] =
        [];
    for (const m of messages) {
        const date = m.dateIso ?? 'unknown';
        const last = groups[groups.length - 1];
        if (last && last.date === date) {
            last.messages.push(m);
        } else {
            groups.push({ date, label: dayLabel(m.dateIso), messages: [m] });
        }
    }
    return groups;
}
