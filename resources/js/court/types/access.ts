import type { LaravelPageMeta } from '@/Components/ui';

/** One team (Admin\TeamController::index). */
export interface TeamRow {
    id: number;
    name: string;
    parent_name: string | null;
    description: string | null;
    members_count: number;
    leader_name: string | null;
    leader_email: string | null;
}

export interface TeamsIndexProps {
    teams: TeamRow[];
}

/** A team to pick as parent (Admin\TeamController::create, edit). */
export interface TeamOption {
    id: number;
    name: string;
}

/** A person to lead or join a team, and a member row on the team page. */
export interface TeamUserOption {
    id: number;
    name: string;
    email: string;
}

export interface TeamsCreateProps {
    teams: TeamOption[];
    users: TeamUserOption[];
}

/** The team the edit form changes (Admin\TeamController::edit). */
export interface TeamFormTeam {
    id: number;
    name: string;
    parent_id: number | null;
    description: string | null;
    team_leader_id: number | null;
    user_ids: number[];
}

export interface TeamsEditProps {
    team: TeamFormTeam;
    /** Every other team. */
    teams: TeamOption[];
    /** People in no other team: the current members and those still free. */
    users: TeamUserOption[];
}

/** The team page (Admin\TeamController::show). */
export interface TeamDetail {
    id: number;
    name: string;
    parent_name: string | null;
    description: string | null;
    leader_name: string | null;
    members: TeamUserOption[];
}

export interface TeamsShowProps {
    team: TeamDetail;
}

/** One role (Admin\RolesController::index). */
export interface RoleRow {
    id: number;
    name: string;
    description: string | null;
    permissions: string[];
    users_count: number;
    /** Why Delete is disabled: the admin role, or a role still held by staff (the server refuses both). */
    delete_blocked: 'admin' | 'assigned' | null;
}

export interface RolesIndexProps {
    roles: LaravelPageMeta & { data: RoleRow[] };
}

/** One permission to tick on the role forms. */
export interface PermissionOption {
    id: number;
    name: string;
    label: string | null;
}

/** The permissions of one module (Admin\RolesController::groupPermissions). */
export interface PermissionGroup {
    key: string;
    label: string;
    permissions: PermissionOption[];
}

export interface RolesCreateProps {
    permissionsCount: number;
    permissionGroups: PermissionGroup[];
}

/** The role the edit form changes (Admin\RolesController::edit). */
export interface RoleFormRole {
    id: number;
    name: string;
    description: string | null;
    permission_ids: number[];
    users_count: number;
}

export interface RolesEditProps {
    role: RoleFormRole;
    permissionsCount: number;
    permissionGroups: PermissionGroup[];
}

/** One permission (Admin\PermissionController::index). */
export interface PermissionRow {
    id: number;
    name: string;
    label: string | null;
    description: string | null;
    roles: string[];
    roles_count: number;
    users_count: number;
}

export interface PermissionsIndexProps {
    permissions: LaravelPageMeta & { data: PermissionRow[] };
    filters: { q: string; perPage: number };
}

/** One text per locale (`{ en: '…', am: '…' }`). */
export type LocaleTexts = Record<string, string>;

/** The permission the edit form changes (Admin\PermissionController::edit). */
export interface PermissionFormPermission {
    id: number;
    name: string;
    label_translations: LocaleTexts;
    description_translations: LocaleTexts;
}

export interface PermissionsCreateProps {
    permission: null;
    locales: string[];
    primaryLocale: string;
}

export interface PermissionsEditProps {
    permission: PermissionFormPermission;
    locales: string[];
    primaryLocale: string;
}
