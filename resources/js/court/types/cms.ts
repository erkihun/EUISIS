import type { LaravelPageMeta } from '@/Components/ui';

/** One announcement (Admin\AnnouncementController::index). */
export interface AnnouncementRow {
    id: number;
    title: string;
    status: string;
    created_at: string | null;
    updated_at: string | null;
}

export interface AnnouncementsIndexProps {
    announcements: LaravelPageMeta & { data: AnnouncementRow[] };
}

/** One terms-and-conditions text (Admin\TermsAndConditionsController::index). */
export interface TermRow {
    id: number;
    title: string;
    excerpt: string;
    is_published: boolean;
    published_at: string | null;
    updated_at: string | null;
}

export interface TermsIndexProps {
    terms: LaravelPageMeta & { data: TermRow[] };
    publicUrl: string | null;
}

/** One About page (Admin\AboutController::index). */
export interface AboutPageRow {
    id: number;
    title: string;
    slug: string;
    is_published: boolean;
    updated_at: string | null;
}

export interface AboutIndexProps {
    pages: LaravelPageMeta & { data: AboutPageRow[] };
}
