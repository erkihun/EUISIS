import type { SrLabel } from '@/types/situationRoom';

export interface SituationActionRow {
    id: string;
    reference_number: string;
    title: string;
    category: string;
    organization_id: string;
    organization: SrLabel;
    priority: 'low' | 'normal' | 'high' | 'urgent';
    status: 'open' | 'in_progress' | 'done' | 'cancelled';
    responsible_office: string | null;
    due_date: string | null;
    completed_at: string | null;
    overdue: boolean;
}

export interface SituationActionDetail extends SituationActionRow {
    description: string | null;
    outcome_note: string | null;
    panel_key: string | null;
    created_by: string | null;
    updated_by: string | null;
}

export interface SituationActionOptions {
    organizations: Array<{ id: string; label: SrLabel }>;
    categories: string[];
    priorities: string[];
    statuses: string[];
}
