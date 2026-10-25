export type HierarchyVersion = {
    id: string;
    version_name: string;
    status: string;
    effective_from: string;
    effective_to: string | null;
    legal_basis: string | null;
    source_document: string | null;
    created_by: number | null;
};

export type HierarchyTransition = 'review' | 'approve' | 'publish';

/** Availability mirrors server transitions; the API remains authoritative. */
export function hierarchyTransition(version: HierarchyVersion, userId: number): HierarchyTransition | null {
    if (version.status === 'draft') return 'review';
    if (version.status === 'review' && version.created_by !== userId) return 'approve';
    if (version.status === 'approved') return 'publish';
    return null;
}
