/*
 * Letters (ADR 0037) — the props the module's pages receive
 * (Modules/Letters/Http/Controllers and Application/Services/LetterPresenter).
 * Statuses are unions of the backend enums.
 */

export type LetterDirection = 'incoming' | 'outgoing' | 'internal';
export type LetterStatus = 'draft' | 'pending_approval' | 'approved' | 'dispatched' | 'received' | 'cancelled';
export type RegistrationStatus = 'pending_receipt' | 'registered' | 'routed' | 'in_progress' | 'closed';
export type RoutingStatus = 'pending' | 'acknowledged' | 'completed' | 'returned';
export type LetterPriority = 'normal' | 'urgent' | 'very_urgent';
export type TemplateStatus = 'draft' | 'published' | 'archived';
export type TemplateCategory = 'general' | 'notice' | 'request' | 'response' | 'decision' | 'transmittal' | 'invitation' | 'memo' | 'other';
export type ResetPolicy = 'ethiopian_year' | 'gregorian_year' | 'never';
export type AttachmentKind = 'scan' | 'annex' | 'rendered';
export type LetterLanguage = 'am' | 'en';
export type LetterClassification = 'public' | 'internal' | 'confidential' | 'secret';
export type DispatchMode = 'system' | 'hand' | 'post' | 'courier' | 'email' | 'fax';

export interface CopyRecipient {
    id?: string;
    organization_id: string | null;
    organization?: Named | null;
    external_name: string | null;
    external_address: string | null;
}

export interface Named {
    name_en: string | null;
    name_am: string | null;
    code?: string | null;
}

export interface NamedWithId extends Named {
    id: string;
}

export interface OrganizationOption {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
}

export interface UnitOption {
    id: string;
    code: string | null;
    name_en: string;
    name_am: string | null;
}

export interface PersonOption {
    id: number;
    name: string;
}

export interface Placeholder {
    key: string;
    label_en: string;
    label_am: string | null;
    required: boolean;
}

export interface TemplateOption {
    id: string;
    version_id: string;
    code: string;
    title_en: string;
    title_am: string | null;
    category: TemplateCategory;
    language: LetterLanguage;
    platform: boolean;
    subject: string;
    body: string;
    placeholders: Placeholder[];
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
}

export interface LetterRow {
    id: string;
    registration_id: string | null;
    direction: LetterDirection;
    status: LetterStatus;
    registration_status: RegistrationStatus | null;
    number: string | null;
    /** Null when the letter is confidential and the viewer may not read it. */
    subject: string | null;
    classification: LetterClassification;
    file_code: string | null;
    file_class: { code: string; title_am: string | null; title_en: string | null } | null;
    priority: LetterPriority;
    letter_date: string | null;
    received_on: string | null;
    due_on: string | null;
    organization: Named;
    counterpart: Named | null;
    open_routings: number;
    created_at: string | null;
}

export interface Routing {
    id: string;
    step: number;
    status: RoutingStatus;
    instruction: string;
    due_on: string | null;
    from: string | null;
    to_user: { id: number; name: string } | null;
    to_unit: NamedWithId | null;
    acknowledged_at: string | null;
    completed_at: string | null;
    outcome: string | null;
    /** The unit the step was sent from: the registry office for a first step. */
    from_unit: NamedWithId | null;
    /** The step this one was passed on from. */
    parent_step: number | null;
    can_act: boolean;
    /** The viewer is the person it was forwarded to and may pass it on. */
    can_forward: boolean;
}

export interface RegistryOfficeRow {
    id: string;
    status: 'active' | 'inactive';
    note: string | null;
    unit: { id: string; code: string | null; name_en: string | null; name_am: string | null } | null;
    staff: { id: number; name: string; position: { title_en: string | null; title_am: string | null } | null; can_register: boolean; can_route: boolean }[];
    problems: ('unit_inactive' | 'no_staff' | 'no_registrar' | 'no_router')[];
}

export interface Registration {
    id: string;
    organization: NamedWithId;
    direction: LetterDirection;
    number: string | null;
    status: RegistrationStatus;
    registered_at: string | null;
    received_on: string | null;
    due_on: string | null;
    physical_location: string | null;
    acknowledged_at: string | null;
    acknowledgement_note: string | null;
    escalated_at: string | null;
    closed_at: string | null;
    closing_note: string | null;
    routings: Routing[];
    can: { receive: boolean; route: boolean; close: boolean; acknowledge: boolean };
}

export interface Attachment {
    id: string;
    kind: AttachmentKind;
    name: string;
    mime: string;
    size: number;
    uploaded_by: string | null;
    created_at: string | null;
}

export interface LetterEvent {
    id: string;
    type: string;
    actor: string | null;
    payload: Record<string, unknown>;
    at: string | null;
}

export interface LetterFull {
    id: string;
    direction: LetterDirection;
    status: LetterStatus;
    classification: LetterClassification;
    /** True when the viewer may see the registry entry but not the text, files or parties. */
    contents_hidden: boolean;
    subject: string | null;
    raw_subject: string | null;
    summary: string | null;
    body: string | null;
    raw_body: string | null;
    language: LetterLanguage;
    priority: LetterPriority;
    file_code: string | null;
    file_class: { id: string; code: string; title_am: string | null; title_en: string | null } | null;
    letterhead: { id: string; name: string } | null;
    letter_date: string | null;
    external_party_name: string | null;
    external_party_address: string | null;
    external_party_email: string | null;
    external_reference: string | null;
    organization: NamedWithId;
    unit: NamedWithId | null;
    sender_organization: NamedWithId | null;
    recipient_organization: NamedWithId | null;
    counterpart: Named | null;
    copies: CopyRecipient[];
    number: string | null;
    signatory: { id: number; name: string } | null;
    approved_by: string | null;
    approved_at: string | null;
    dispatched_at: string | null;
    dispatch_mode: DispatchMode | null;
    dispatch_reference: string | null;
    dispatch_note: string | null;
    verification_code: string | null;
    verification_url: string | null;
    created_by: string | null;
    created_at: string | null;
    template: { code: string | null; title_en: string | null; title_am: string | null; version_no: number; placeholders: Placeholder[] } | null;
    template_context: Record<string, string>;
    regarding: { type: string; id: string | null } | null;
    in_reply_to: { id: string; subject: string | null } | null;
    supersedes: { id: string; subject: string | null } | null;
    replies: { id: string; subject: string; status: LetterStatus }[];
    registrations: Registration[];
    attachments: Attachment[];
    events: LetterEvent[];
    can: { update: boolean; submit: boolean; approve: boolean; return: boolean; dispatch: boolean; cancel: boolean; reply: boolean; add_attachment: boolean; pdf: boolean };
}

export interface TemplateRow {
    id: string;
    code: string;
    title_en: string;
    title_am: string | null;
    category: TemplateCategory;
    language: LetterLanguage;
    status: TemplateStatus;
    current_version_no: number;
    platform: boolean;
    owner: (OrganizationOption & { id: string }) | null;
    owner_organization_id: string | null;
}

export interface TemplateVersion {
    id: string;
    version_no: number;
    subject: string;
    body: string;
    placeholders: Placeholder[];
    change_note: string | null;
    published_by: string | null;
    published_at: string | null;
}

export interface TemplateFull extends TemplateRow {
    draft_subject: string | null;
    draft_body: string | null;
    draft_placeholders: Placeholder[];
    updated_by?: string | null;
    updated_at?: string | null;
    versions?: TemplateVersion[];
}

/** One line of a header or footer: text (with placeholders) or an image. */
export interface LayoutItem {
    type: 'text' | 'image';
    text?: string;
    language: 'both' | 'am' | 'en';
    font_family?: '' | string;
    font_size?: number;
    bold?: boolean;
    underline?: boolean;
    color?: string;
    image?: string;
    width_mm?: number;
    /** The picture prints across the whole width between the margins. */
    full?: boolean;
}

export interface LayoutCell {
    items: LayoutItem[];
    align: 'left' | 'center' | 'right';
    valign: 'top' | 'middle' | 'bottom';
    /** Percent of the row; 0 shares what is left. */
    width: number;
}

export interface LayoutRow {
    cells: LayoutCell[];
    border_position: 'none' | 'top' | 'bottom' | 'both';
    border_style: 'thin' | 'thick' | 'double';
    border_color: string;
    space_after: number;
}

export interface LayoutSection {
    rows: LayoutRow[];
}

export interface LayoutPage {
    paper: string;
    orientation: string;
    margin_top: number;
    margin_right: number;
    margin_bottom: number;
    margin_left: number;
    font_family: string;
    font_size: number;
    text_color: string;
    number_align: 'left' | 'center' | 'right' | 'none';
    date_align: 'left' | 'center' | 'right' | 'none';
}

export interface LetterLayoutData {
    id: string | null;
    organization_id: string;
    name: string;
    is_default: boolean;
    active: boolean;
    page: LayoutPage;
    header: LayoutSection;
    footer: LayoutSection;
    images: { key: string; name: string }[];
    has_stamp: boolean;
    letters_count: number;
    updated_by?: string | null;
    updated_at?: string | null;
}

export interface LetterLayoutRow {
    id: string;
    name: string;
    is_default: boolean;
    active: boolean;
    paper: string;
    orientation: string;
    header_rows: number;
    footer_rows: number;
    images: number;
    has_stamp: boolean;
    letters_count: number;
    updated_by: string | null;
    updated_at: string | null;
}

export interface LayoutOption {
    id: string;
    name: string;
    is_default: boolean;
    platform: boolean;
}

export interface LayoutEditorOptions {
    papers: string[];
    orientations: string[];
    fonts: { key: string; family: string; bold: boolean }[];
    placeholders: string[];
    max_rows: number;
    max_cells: number;
    max_items: number;
    max_images: number;
    org_logo: string;
}

export interface FileClassOption {
    id: string;
    code: string;
    title_am: string | null;
    title_en: string | null;
    parent_id: string | null;
}

export interface FileClassRow extends FileClassOption {
    active: boolean;
    position: number;
}

export interface ReplyStandard {
    reply_days_normal: number;
    reply_days_urgent: number;
    reply_days_very_urgent: number;
    interim_reply_days: number;
    escalation_after_days: number;
}

export interface NumberingRule {
    id: string;
    organization_id: string | null;
    direction: LetterDirection;
    language: LetterLanguage | null;
    pattern: string;
    reset_policy: ResetPolicy;
    padding: number;
    direction_code: string | null;
    language_code: string | null;
    organization: Named | null;
    updated_by: string | null;
    updated_at: string | null;
}
