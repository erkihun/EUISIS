export type OrganizationUnitType =
    | 'department'
    | 'directorate'
    | 'team'
    | 'unit'
    | 'office'
    | 'section';

export type OrganizationUnitStatus = 'draft' | 'active' | 'inactive' | 'archived';

export interface OrganizationUnitTypeModel {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
}

export interface OrganizationUnit {
    id: string;
    organization_id: string;
    parent_unit_id: string | null;
    organization_unit_type_id: string | null;
    unit_type: OrganizationUnitType | string;
    code: string;
    name_en: string;
    name_am: string | null;
    description_en: string | null;
    description_am: string | null;
    status: OrganizationUnitStatus;
    effective_from: string | null;
    effective_to: string | null;
    sort_order: number;
    children_count?: number;
    can: {
        update: boolean;
        archive: boolean;
        restore: boolean;
        manageHierarchy: boolean;
    };
    organization?: {
        id: string;
        name_en: string;
        name_am: string | null;
        code: string;
    };
    parent?: OrganizationUnit | null;
    children?: OrganizationUnit[];
    unitType?: OrganizationUnitTypeModel | null;
    created_at?: string;
    updated_at?: string;
}

export interface OrganizationUnitOption {
    id: string;
    name_en: string;
    name_am: string | null;
    code: string;
    depth: number;
}

export interface OrganizationUnitTreeNode extends OrganizationUnit {
    depth: number;
    has_children: boolean;
    is_deleted?: boolean;
    unit_type_label?: string | null;
    unit_type_name_am?: string | null;
    children: OrganizationUnitTreeNode[];
}

export interface OrganizationSummary {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    status: string;
    logo_path: string | null;
    logo_url: string | null;
    has_logo: boolean;
    effective_from: string | null;
    organization_units_count?: number;
    type?: {
        id: string;
        code: string;
        name_en: string;
        name_am: string | null;
    } | null;
}

export interface OrganizationTreeNode {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    status: string;
    logo_url: string | null;
    has_logo: boolean;
    organization_units_count?: number;
    type?: {
        id: string;
        code: string;
        name_en: string;
        name_am: string | null;
    } | null;
    depth: number;
    children: OrganizationTreeNode[];
}
