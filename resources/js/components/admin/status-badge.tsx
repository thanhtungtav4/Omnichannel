import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

type StatusTone = 'ok' | 'warn' | 'danger' | 'info' | 'idle';

const statusToneMap: Record<string, StatusTone> = {
    ACTIVE: 'ok',
    ONLINE: 'ok',
    ASSIGNED: 'ok',
    SENT: 'ok',
    DELIVERED: 'ok',
    OK: 'ok',
    DEGRADED: 'warn',
    DUE_SOON: 'warn',
    RETRYING: 'warn',
    AWAY: 'warn',
    HIGH: 'warn',
    FAILED: 'danger',
    BREACHED: 'danger',
    OFFLINE: 'danger',
    DISABLED: 'danger',
    WAITING_AGENT: 'info',
    WAITING_CUSTOMER: 'info',
    PROCESSING: 'info',
    QUEUED: 'info',
    SENDING: 'info',
    NEW: 'info',
    OPEN: 'info',
    CLOSED: 'idle',
    DRAFT: 'idle',
    ARCHIVED: 'idle',
    NORMAL: 'idle',
};

const toneClassName: Record<StatusTone, string> = {
    ok: '[background-color:var(--status-ok-bg)] [border-color:var(--status-ok-border)] [color:var(--status-ok-fg)]',
    warn: '[background-color:var(--status-warn-bg)] [border-color:var(--status-warn-border)] [color:var(--status-warn-fg)]',
    danger: '[background-color:var(--status-danger-bg)] [border-color:var(--status-danger-border)] [color:var(--status-danger-fg)]',
    info: '[background-color:var(--status-info-bg)] [border-color:var(--status-info-border)] [color:var(--status-info-fg)]',
    idle: '[background-color:var(--status-idle-bg)] [border-color:var(--status-idle-border)] [color:var(--status-idle-fg)]',
};

// Vietnamese labels for enum statuses shown in badges. Missing keys fall back
// to the raw enum. ponytail: one map covers every StatusBadge/Metric.
const statusLabelVi: Record<string, string> = {
    // Conversation status
    OPEN: 'Đang mở',
    WAITING_AGENT: 'Chờ nhân viên',
    WAITING_CUSTOMER: 'Chờ khách',
    ASSIGNED: 'Đã gán',
    CLOSED: 'Đã đóng',
    ARCHIVED: 'Lưu trữ',
    // Priority
    URGENT: 'Khẩn cấp',
    HIGH: 'Cao',
    NORMAL: 'Bình thường',
    LOW: 'Thấp',
    // Message / outbox status
    RECEIVED: 'Đã nhận',
    QUEUED: 'Đang chờ gửi',
    SENDING: 'Đang gửi',
    SENT: 'Đã gửi',
    DELIVERED: 'Đã nhận',
    READ: 'Đã xem',
    FAILED: 'Gửi lỗi',
    RETRYING: 'Đang thử lại',
    PROCESSING: 'Đang xử lý',
    // SLA / channel health
    OK: 'Bình thường',
    DUE_SOON: 'Sắp đến hạn',
    BREACHED: 'Trễ hạn',
    ACTIVE: 'Hoạt động',
    DEGRADED: 'Suy giảm',
    DISABLED: 'Tắt',
    DRAFT: 'Nháp',
    // Presence
    ONLINE: 'Online',
    AWAY: 'Vắng',
    OFFLINE: 'Offline',
    NEW: 'Mới',
};

export function statusLabel(status?: string | null): string {
    if (!status) return 'Nhàn';
    return statusLabelVi[status] ?? status;
}

export function toneForStatus(status?: string | null): StatusTone {
    return status ? (statusToneMap[status] ?? 'idle') : 'idle';
}

export function StatusBadge({
    status,
    label,
    className,
}: {
    status?: string | null;
    label?: string;
    className?: string;
}) {
    const tone = toneForStatus(status);

    return (
        <Badge
            variant="outline"
            className={cn(
                'max-w-full truncate',
                toneClassName[tone],
                className,
            )}
        >
            {label ?? statusLabel(status)}
        </Badge>
    );
}

export function StatusDot({
    status,
    className,
}: {
    status?: string | null;
    className?: string;
}) {
    const tone = toneForStatus(status);

    return (
        <span
            aria-hidden="true"
            className={cn(
                'inline-block size-[var(--status-dot)] shrink-0 rounded-full border',
                toneClassName[tone],
                className,
            )}
        />
    );
}
