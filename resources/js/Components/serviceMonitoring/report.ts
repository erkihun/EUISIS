import type { Bilingual, FormSchema, ReportData } from './types';

export type ReportStatus = 'draft' | 'submitted' | 'returned' | 'accepted';

/** A report as ServiceMonitoringReportController::detail() sends it. */
export interface ReportDetail {
    id: string;
    status: ReportStatus;
    form_code: string;
    form: Bilingual & { id: string; version: number };
    period: Bilingual & { id: string; status: 'open' | 'closed' };
    organization: Bilingual & { id: string; type: Bilingual | null };
    submitted_at: string | null;
    updated_at: string | null;
    schema: FormSchema;
    description: string | null;
    data: ReportData;
    review_note: string | null;
    reviewed_at: string | null;
    submitter: string | null;
    reviewer: string | null;
    attachment: { name: string | null; url: string } | null;
    period_open: boolean;
}
