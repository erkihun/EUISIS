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
    archived: boolean;
    blocked_by_me: boolean;
    channel_info: ChannelInfo | null;
    can: {
        post: boolean;
        manageMembers: boolean;
        update: boolean;
        changeRoles: boolean;
        archive: boolean;
        leave: boolean;
        pin: boolean;
        mentionEveryone: boolean;
        addMembers: boolean;
    };
};

export type ChannelType = 'PUBLIC_ORGANIZATION' | 'PRIVATE' | 'SYSTEM_MANAGED' | 'PROJECT';
export type PostingPolicy = 'ALL_MEMBERS' | 'ADMINS_ONLY';

export type ChannelInfo = {
    type: ChannelType;
    name: string;
    name_am: string | null;
    posting_policy: PostingPolicy;
    auto_membership: boolean;
    include_descendants: boolean;
    organization: string | null;
    unit: string | null;
};

export type ChannelRow = {
    id: string;
    conversation_id: string;
    name: string;
    description: string | null;
    type: ChannelType;
    organization: string | null;
    unit: string | null;
    member_count: number;
    auto_membership: boolean;
    posting_policy: PostingPolicy;
    joined: boolean;
    can_join: boolean;
};

export type ChannelPage = { data: ChannelRow[]; next_cursor: string | null };
export type ScopeOption = { id: string; name: string; code: string | null };

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
    browseChannels: boolean;
    createTask: boolean;
    report: boolean;
};

export type AnnouncementPriority = 'NORMAL' | 'IMPORTANT' | 'URGENT' | 'EMERGENCY';
export type AnnouncementStatus = 'DRAFT' | 'SCHEDULED' | 'PUBLISHED' | 'EXPIRED' | 'CANCELLED';
export type AnnouncementTargetType = 'CITY' | 'ORGANIZATION' | 'ORGANIZATION_SUBTREE' | 'ORGANIZATION_UNIT' | 'EMPLOYEE' | 'ROLE' | 'GROUP';

export type AnnouncementSummary = {
    id: string;
    title: string;
    excerpt: string;
    priority: AnnouncementPriority;
    status: AnnouncementStatus;
    published_at: string | null;
    expires_at: string | null;
    acknowledgement_required: boolean;
    has_attachments: boolean;
    viewed: boolean;
    acknowledged: boolean;
    target_count?: number | null;
    acknowledged_count?: number | null;
    publish_at?: string | null;
    updated_at?: string | null;
};

export type AnnouncementPage = { data: AnnouncementSummary[]; next_cursor: string | null };
export type TargetLabel = { type: AnnouncementTargetType; id: string | null; label: string };
export type AnnouncementFile = { id: string; name: string; size: number; extension: string; downloadable: boolean };

export type AnnouncementDetail = AnnouncementSummary & {
    body: string;
    acknowledged_at: string | null;
    publisher: string | null;
    attachments: AnnouncementFile[];
    manage: null | {
        title: string;
        title_am: string | null;
        body: string;
        body_am: string | null;
        publish_at: string | null;
        cancelled_at: string | null;
        cancel_reason: string | null;
        author: string | null;
        targets: TargetLabel[];
        stats: { targeted: number | null; delivered: number; viewed: number; acknowledged: number };
    };
    can: { acknowledge: boolean; edit: boolean; publish: boolean; cancel: boolean; export: boolean };
};

export type Recipient = {
    id: number;
    name: string;
    employee_number: string | null;
    organization: string | null;
    delivered_at: string | null;
    viewed_at: string | null;
    acknowledged_at: string | null;
};

export type TargetOption = { id: string; name: string; hint: string | null };

export type TaskStatus = 'TODO' | 'IN_PROGRESS' | 'COMPLETED' | 'CANCELLED';
export type TaskPriority = 'LOW' | 'NORMAL' | 'HIGH' | 'URGENT';

export type CommunicationTaskItem = {
    id: string;
    title: string;
    description: string | null;
    priority: TaskPriority;
    status: TaskStatus;
    due_at: string | null;
    completed_at: string | null;
    created_at: string | null;
    assignee: { id: number; name: string } | null;
    creator: { id: number; name: string } | null;
    source: { conversation_id: string; message_id: string | null; title: string | null; type: ConversationType } | null;
    can: { updateStatus: boolean; manage: boolean };
};

export type ReportCategory = 'SPAM' | 'HARASSMENT' | 'INAPPROPRIATE_CONTENT' | 'SECURITY_CONCERN' | 'OTHER';
export type ReportStatus = 'OPEN' | 'UNDER_REVIEW' | 'ACTIONED' | 'DISMISSED';

export type ReportItem = {
    id: string;
    subject: 'MESSAGE' | 'CONVERSATION';
    category: ReportCategory;
    details: string | null;
    status: ReportStatus;
    reporter: string | null;
    reported_user: string | null;
    reviewer: string | null;
    resolution_note: string | null;
    created_at: string | null;
    reviewed_at: string | null;
};

export type ReportedMessage = {
    id: string;
    reported: boolean;
    sender: string | null;
    sender_id: number | null;
    type: string;
    body: string | null;
    deleted: boolean;
    attachments: { name: string; size: number }[];
    created_at: string | null;
};

export type ReportDetail = {
    report: ReportItem;
    conversation: { type: ConversationType; title: string | null } | null;
    messages: ReportedMessage[];
};
