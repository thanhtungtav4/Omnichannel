import {
    CheckCircle2,
    UserRoundCheck,
} from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type { AgentOption } from '@/types';

/**
 * Mockup §3.6: "Chuyển hội thoại" sheet.
 * - Select agent (with active conversation count next to name)
 * - Select transfer reason
 * - Free-text note for the recipient
 *
 * On submit, calls onSubmit() with the chosen agent id. Parent wires it
 * to the existing transfer endpoint (channels.transfer).
 */
export function TransferSheet({
    open,
    onOpenChange,
    agents,
    onSubmit,
    processing,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    agents: AgentOption[];
    onSubmit: (params: { agentId: string; reason: string; note: string }) => void;
    processing: boolean;
}) {
    const [agentId, setAgentId] = useState<string>('');
    const [reason, setReason] = useState<string>('context');
    const [note, setNote] = useState<string>('');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md gap-0 p-0">
                <DialogHeader className="border-b px-4 py-3">
                    <div className="flex items-center gap-2">
                        <UserRoundCheck className="size-4 text-muted-foreground" />
                        <DialogTitle className="text-sm font-semibold">
                            Chuyển hội thoại
                        </DialogTitle>
                    </div>
                    <DialogDescription className="sr-only">
                        Chuyển hội thoại cho nhân viên khác, kèm lý do và ghi chú.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-4 p-4">
                    <Field label="Chuyển đến">
                        <Select
                            value={agentId}
                            onValueChange={setAgentId}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="— Chọn nhân viên —" />
                            </SelectTrigger>
                            <SelectContent>
                                {agents.map((agent) => (
                                    <SelectItem
                                        key={agent.id}
                                        value={String(agent.id)}
                                    >
                                        {agent.display_name ?? agent.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>

                    <Field label="Lý do (tuỳ chọn)">
                        <Select value={reason} onValueChange={setReason}>
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="context">
                                    Chuyển tiếp ngữ cảnh
                                </SelectItem>
                                <SelectItem value="customer_request">
                                    Khách yêu cầu
                                </SelectItem>
                                <SelectItem value="overloaded">
                                    Đang quá tải
                                </SelectItem>
                                <SelectItem value="other">Khác</SelectItem>
                            </SelectContent>
                        </Select>
                    </Field>

                    <Field label="Ghi chú kèm theo">
                        <Textarea
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            placeholder="Ghi chú cho người nhận..."
                            rows={3}
                        />
                    </Field>
                </div>

                <DialogFooter className="border-t px-4 py-3">
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        Huỷ
                    </Button>
                    <Button
                        type="button"
                        disabled={!agentId || processing}
                        onClick={() => {
                            onSubmit({ agentId, reason, note });
                            // Reset for next open.
                            setAgentId('');
                            setNote('');
                        }}
                    >
                        Chuyển ngay
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Mockup §3.6: "Đóng hội thoại" sheet — operator picks a close reason.
 */
export function CloseSheet({
    open,
    onOpenChange,
    onSubmit,
    processing,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSubmit: (reason: string) => void;
    processing: boolean;
}) {
    const [reason, setReason] = useState<string>('RESOLVED');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md gap-0 p-0">
                <DialogHeader className="border-b px-4 py-3">
                    <div className="flex items-center gap-2">
                        <CheckCircle2 className="size-4 text-muted-foreground" />
                        <DialogTitle className="text-sm font-semibold">
                            Đóng hội thoại
                        </DialogTitle>
                    </div>
                    <DialogDescription className="sr-only">
                        Chọn lý do đóng hội thoại để lưu lại cho analytics.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-4 p-4">
                    <Field label="Lý do đóng">
                        <Select value={reason} onValueChange={setReason}>
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="RESOLVED">
                                    RESOLVED — Đã giải quyết
                                </SelectItem>
                                <SelectItem value="DUPLICATE">
                                    DUPLICATE — Trùng hội thoại
                                </SelectItem>
                                <SelectItem value="SPAM">SPAM — Thư rác</SelectItem>
                                <SelectItem value="NO_RESPONSE">
                                    NO_RESPONSE — Không phản hồi
                                </SelectItem>
                                <SelectItem value="OTHER">OTHER — Khác</SelectItem>
                            </SelectContent>
                        </Select>
                    </Field>
                </div>

                <DialogFooter className="border-t px-4 py-3">
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        Huỷ
                    </Button>
                    <Button
                        type="button"
                        disabled={processing}
                        onClick={() => onSubmit(reason)}
                    >
                        Đóng hội thoại
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function Field({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                {label}
            </Label>
            {children}
        </div>
    );
}