/** Shapes of App\Http\Resources\Transfers\TransferApplicationResource and its children. */

export type TransferApplicationStatus =
    | 'submitted'
    | 'under_review'
    | 'verified'
    | 'selected'
    | 'release_pending'
    | 'receiving_pending'
    | 'final_approval_pending'
    | 'approved'
    | 'implementation_failed'
    | 'transferred'
    | 'rejected'
    | 'withdrawn'
    | 'cancelled';

export type ApprovalType = 'release' | 'receiving' | 'final';
export type ApprovalStatus = 'pending' | 'approved' | 'rejected';

export interface BilingualName {
    name_en: string;
    name_am: string | null;
}

export interface TransferPosition {
    id: string;
    code: string | null;
    title_en: string;
    title_am: string | null;
}

export interface TransferApprovalRecord {
    id: string;
    approval_type: ApprovalType;
    status: ApprovalStatus;
    rejection_reason: string | null;
    decided_at: string | null;
    approver: { id: number; name: string } | null;
}

export interface TransferDocumentRecord {
    id: string;
    document_type: string;
    original_name: string;
    file_type: string | null;
    file_size: number | null;
    verification_status: string | null;
}

export interface TransferReviewRecord {
    id: string;
    action: string;
    notes: string | null;
    created_at: string | null;
    reviewer: { id: number; name: string } | null;
}

export interface TransferApplicationRecord {
    id: string;
    application_number: string | null;
    announcement: { id: string; closing_date: string | null } | null;
    position: TransferPosition | null;
    employee: { id: string; employee_number: string; full_name: string } | null;
    current_position?: { title_en: string; title_am: string | null } | null;
    releasing_organization: BilingualName | null;
    receiving_organization: BilingualName | null;
    status: TransferApplicationStatus;
    applicant_notes: string | null;
    rejected_reason: string | null;
    withdrawal_reason: string | null;
    implementation_failure: string | null;
    submitted_at: string | null;
    selected_at: string | null;
    effective_date: string | null;
    approved_at: string | null;
    implemented_at: string | null;
    documents?: TransferDocumentRecord[];
    approvals?: TransferApprovalRecord[];
    screening_reviews?: TransferReviewRecord[];
}

/** The order approval stages run in (SelectTransferCandidateAction). */
export const APPROVAL_ORDER: ApprovalType[] = ['release', 'receiving', 'final'];

/** The pending status that waits on each approval stage. */
export const APPROVAL_STATUS: Record<ApprovalType, TransferApplicationStatus> = {
    release: 'release_pending',
    receiving: 'receiving_pending',
    final: 'final_approval_pending',
};
