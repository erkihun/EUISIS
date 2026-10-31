import type { LaravelPageMeta } from '@/Components/ui';

/** A case to choose from (id, number, title). */
export interface BenchNoteCaseOption {
    id: number;
    case_number: string;
    title: string | null;
}

/** One seat of the panel: a judge with the signature on file, or an empty seat. */
export interface BenchNoteJudge {
    id: number;
    name: string;
    signature_url: string | null;
}

/** One bench note as the list, the show page and the form read it (Admin\BenchNoteController). */
export interface BenchNoteRow {
    id: number;
    case_id: number;
    case_number: string | null;
    case_title: string | null;
    title: string | null;
    /** ISO date. */
    note_date: string | null;
    /** Sanitized HTML. */
    note: string;
    author_name: string | null;
    /** The viewer wrote the note or is an administrator: only then may they edit or delete it. */
    mine: boolean;
    /** ISO 8601. */
    created_at: string | null;
    /** ISO 8601; null when the note was never changed after it was written. */
    updated_at: string | null;
    /** The three seats, in order. */
    judges: [BenchNoteJudge | null, BenchNoteJudge | null, BenchNoteJudge | null];
}

export interface BenchNotePermissions {
    view: boolean;
    create: boolean;
    update: boolean;
    delete: boolean;
}

export interface BenchNotesIndexProps {
    notes: LaravelPageMeta & { data: BenchNoteRow[] };
    cases: BenchNoteCaseOption[];
    filters: { case_id: string };
    can: BenchNotePermissions;
}

export interface BenchNoteFormProps {
    /** Null on the create page. */
    note: BenchNoteRow | null;
    cases: BenchNoteCaseOption[];
    /** The judges of the viewer's teams (plus the viewer). */
    judgeUsers: { id: number; name: string }[];
    /** The judges offered for the third seat: the viewer's own team when the viewer has one. */
    thirdJudgeUsers: { id: number; name: string }[];
    noTeammates: boolean;
    currentUser: { id: number; name: string } | null;
    prefill: { case_id: string };
}

export interface BenchNoteShowProps {
    note: BenchNoteRow;
    can: BenchNotePermissions;
}
