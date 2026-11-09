import type { LaravelPageMeta } from '@/Components/ui';

/** One row of the letters list (Admin\LetterController::index). */
export interface LetterRow {
    id: number;
    reference_number: string | null;
    subject: string | null;
    case_number: string | null;
    send_to_applicant: boolean;
    send_to_respondent: boolean;
    applicant_name: string | null;
    /** The lawyer who filed for the applicant, when one did. */
    applicant_lawyer_name: string | null;
    respondent_name: string | null;
    author_name: string | null;
    author_position: string | null;
    category: string | null;
    approval_status: 'pending' | 'approved' | 'returned' | 'rejected' | null;
    approved_by_name: string | null;
    /** ISO 8601. */
    created_at: string | null;
}

export interface LettersIndexProps {
    letters: LaravelPageMeta & { data: LetterRow[] };
    stats: { total: number; approved: number; rejected: number; categories: number; top_category: string | null; top_category_count: number };
    /** `q` searches reference, subject, case number and party names. */
    filters: { status: '' | 'pending' | 'approved' | 'rejected'; q: string };
    can: { create: boolean; update: boolean; delete: boolean; approve: boolean };
}

/** A template the composer can load (Admin\LetterComposerController::create). */
export interface LetterTemplateChoice {
    id: number;
    title: string;
    body: string;
    category: string | null;
    placeholders: string[];
}

/** The letter fields both the compose and the edit page carry. */
export interface LetterFormValues {
    recipient_name: string;
    recipient_title: string;
    recipient_company: string;
    case_number: string;
    cc: string;
    send_to_applicant: boolean;
    send_to_respondent: boolean;
    subject: string;
    /** HTML. */
    body: string;
}

export interface LetterComposeProps {
    templates: LetterTemplateChoice[];
    /** From `?template_id=`: the template loaded on arrival, when it exists. */
    selectedTemplateId: number | null;
    /** From `?case_id=`: the case the letter is written for. */
    caseNumber: string | null;
    /** "<case number>/<nn>": what the reference will be, when a case is known. */
    nextReference: string | null;
    /** The fields as last submitted (old input after a failed save), else the defaults. */
    initial: LetterFormValues;
}

export interface LetterEditProps {
    letter: LetterFormValues & {
        id: number;
        reference_number: string | null;
        approval_status: 'pending' | 'approved' | 'returned' | 'rejected' | null;
    };
    template: {
        id: number;
        title: string;
        subject_prefix: string | null;
        /** "<prefix>/<nnnn>": the reference the template would give its next letter. */
        next_reference: string;
    };
}

/** One row of the letter templates list (Admin\LetterTemplateController::index). */
export interface LetterTemplateRow {
    id: number;
    title: string;
    category: string | null;
    placeholders: string[];
    /** ISO 8601. */
    updated_at: string | null;
}

export interface LetterTemplatesIndexProps {
    templates: LaravelPageMeta & { data: LetterTemplateRow[] };
    can: { create: boolean; update: boolean; delete: boolean; compose: boolean; categories: boolean };
}

export interface LetterTemplateFormProps {
    /** Null on the create page. */
    template: {
        id: number;
        title: string;
        category: string | null;
        /** Comma-separated, as the form edits it. */
        placeholders: string;
        body: string;
        header_image_url: string | null;
        header_image_name: string | null;
        footer_image_url: string | null;
        footer_image_name: string | null;
    } | null;
    /** The category names to pick from. */
    categories: string[];
}

/** One row of the letter categories list (Admin\LetterCategoryController::index). */
export interface LetterCategoryRow {
    id: number;
    name: string;
    description: string | null;
}

export interface LetterCategoriesIndexProps {
    categories: LaravelPageMeta & { data: LetterCategoryRow[] };
    can: { create: boolean; update: boolean; delete: boolean };
}

export interface LetterCategoryFormProps {
    /** Null on the create page. */
    category: LetterCategoryRow | null;
}
