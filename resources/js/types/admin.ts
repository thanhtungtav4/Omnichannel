export type CrmStat = {
    label: string;
    value: number;
    hint: string;
};

export type ChannelSummary = {
    id: string;
    provider: string;
    name: string;
    status: string;
    webhookUrl?: string | null;
    callbackUrl?: string | null;
    verifyToken?: string | null;
    hasReceivedWebhook?: boolean;
    lastWebhookAt?: string | null;
    lastHealthCheckAt?: string | null;
    lastError?: string | null;
    lastErrorCode?: string | null;
    lastErrorMessage?: string | null;
};

export type QueueSummary = {
    id: string;
    name: string;
    mode: string;
    status: string;
    members?: number;
    maxActive?: number;
    maxActivePerAgent?: number;
    requiresOnline: boolean;
    timeoutSeconds?: number;
};

export type QueueMemberSummary = {
    id: string;
    name?: string | null;
    status: string;
    lastAssignedAt?: string | null;
};

export type AgentSummary = {
    id: number;
    name?: string | null;
    status: string;
    active: number;
    lastSeenAt?: string | null;
};

export type ConversationSummary = {
    id: string;
    subject?: string | null;
    status: string;
    priority: string;
    channel?: string | null;
    channelName?: string | null;
    contact?: {
        id?: string | null;
        name?: string | null;
        avatarUrl?: string | null;
        phone?: string | null;
        email?: string | null;
    };
    owner?: {
        id: number;
        name: string;
    } | null;
    lastMessage?: string | null;
    lastDirection?: 'INBOUND' | 'OUTBOUND' | null;
    lastMessageStatus?: string | null;
    lastMessageAt?: string | null;
    isUnanswered?: boolean;
    unreadCount?: number;
    slaState: string;
};

export type ActiveConversation = {
    id: string;
    subject?: string | null;
    status: string;
    priority: string;
    channel?: string | null;
    contact?: {
        id: string;
        name: string;
        avatarUrl?: string | null;
        phone?: string | null;
        email?: string | null;
        source?: string | null;
        status?: string | null;
        tags?: string[];
        lastInboundAt?: string | null;
        identities?: {
            id: string;
            provider: string;
            displayName?: string | null;
            providerUserId: string;
        }[];
        leads?: { id: string; title: string; status: string }[];
        notes?: { id: string; body: string; pinned: boolean }[];
        otherConversations?: {
            id: string;
            channel?: string | null;
            status: string;
            lastMessageAt?: string | null;
        }[];
    } | null;
    owner?: {
        id: number;
        name: string;
        online?: boolean;
    } | null;
    isGroup?: boolean;
    hasMoreMessages?: boolean;
    messages: {
        id: string;
        direction: 'INBOUND' | 'OUTBOUND';
        senderType?: string;
        senderId?: string | null;
        body?: string | null;
        messageType?: string | null;
        attachmentUrl?: string | null;
        status?: string;
        outboxStatus?: string | null;
        outboxError?: string | null;
        timeLabel?: string | null;
        dateIso?: string | null;
    }[];
};

export type AgentOption = {
    id: number;
    name: string;
    display_name?: string | null;
    role: string;
};

export type ContactSummary = {
    id: string;
    name: string;
    phone?: string | null;
    email?: string | null;
    source: string;
    status: string;
    owner?: string | null;
    identities: number;
    lastInboundAt?: string | null;
};

export type LeadSummary = {
    id: string;
    title: string;
    status: string;
    source: string;
    valueAmount?: string | number | null;
    contact?: string | null;
    owner?: string | null;
    lastActivityAt?: string | null;
};
