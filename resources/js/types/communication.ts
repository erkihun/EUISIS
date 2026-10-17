/** Communication & Collaboration payloads (docs/communication-module.md). */

export type ConversationType = 'DIRECT' | 'GROUP' | 'CHANNEL';
export type MemberRole = 'owner' | 'admin' | 'member';

export type Presence = { online: boolean | null; last_seen_at: string | null };

export type ConversationSummary = {
    id: string;
    type: ConversationType;
    title: string;
    channel: { id: string; slug: string; type: string } | null;
    other_user: ({ id: number } & Presence) | null;
    has_avatar: boolean;
    role: MemberRole;
    unread_count: number;
    muted: boolean;
    last_message_at: string | null;
    last_message: {
        sender_name: string | null;
        deleted: boolean;
        excerpt: string | null;
        has_attachments: boolean;
        type: string;
    } | null;
};

export type Attachment = {
    id: string;
    name: string;
    mime_type: string;
    extension: string;
    size: number;
    is_image: boolean;
    width: number | null;
    height: number | null;
    downloadable: boolean;
    scan_status: string;
};

export type Reaction = { emoji: string; count: number; user_ids: number[] };

export type MessageItem = {
    id: string;
    conversation_id: string;
    type: 'TEXT' | 'IMAGE' | 'FILE' | 'SYSTEM' | 'TASK_REFERENCE';
    sender: { id: number; name: string | null } | null;
    body: string | null;
    deleted: boolean;
    edited_at: string | null;
    created_at: string | null;
    forwarded: boolean;
    metadata: Record<string, string | number | null> | null;
    parent: { id: string; deleted: boolean; excerpt: string | null; sender_name: string | null; has_attachments?: boolean } | null;
    attachments: Attachment[];
    reactions: Reaction[];
    mentions: { user_id: number; name: string | null }[];
};

/** A message the person sent that the server has not confirmed yet. */
export type PendingMessage = {
    clientId: string;
    body: string;
    attachments: Attachment[];
    parent: MessageItem | null;
    status: 'sending' | 'failed';
    createdAt: string;
};

export type Person = {
    id: number;
    name: string;
    employee_number: string | null;
    organization: string | null;
    unit: string | null;
    position: string | null;
    has_photo: boolean;
} & Presence;

export type ConversationMemberRow = Person & {
    role: MemberRole;
    source: 'AUTO' | 'MANUAL' | 'SYSTEM';
    last_read_message_id: string | null;
    last_delivered_message_id: string | null;
};

export type ConversationDetail = ConversationSummary & {
    description: string | null;
    member_count: number;
    members: ConversationMemberRow[];
    members_complete: boolean;
    my_last_read_message_id: string | null;
    can: {
        post: boolean;
        manageMembers: boolean;
        update: boolean;
        changeRoles: boolean;
        archive: boolean;
        leave: boolean;
        pin: boolean;
        mentionEveryone: boolean;
    };
};

export type Totals = { direct: number; group: number; channel: number; mentions: number; announcements: number; total: number };

export type ConversationPage = { data: ConversationSummary[]; next_cursor: string | null };
export type MessagePage = { data: MessageItem[]; next_before: string | null };

export type MessagesLimits = {
    maxAttachmentKb: number;
    allowedTypes: string[];
    maxBody: number;
    editWindowMinutes: number;
    deleteWindowMinutes: number;
};

export type MessagesAbilities = {
    message: boolean;
    upload: boolean;
    forward: boolean;
    createGroup: boolean;
    createTask: boolean;
    report: boolean;
};
