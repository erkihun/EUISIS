/**
 * The display label of a permission catalogue group (permissions.groupLabels in
 * the translation files). Falls back to the group key, so a group added to the
 * catalogue before its label still shows something.
 */
export function permissionGroupLabel(t: (key: string) => string, group: string | null | undefined): string {
    if (!group) {
        return '';
    }
    const key = `permissions.groupLabels.${group}`;
    const label = t(key);

    return label && label !== key ? label : group;
}

/** A permission module (PermissionCatalog::modules()) as shown on the role and permission screens. */
export type PermissionModule = { key: string; name: string; groups: string[] };

/** The display label of a permission module (permissions.moduleLabels), falling back to its catalogue name. */
export function permissionModuleLabel(t: (key: string) => string, key: string, fallback?: string): string {
    const translationKey = `permissions.moduleLabels.${key}`;
    const label = t(translationKey);

    return label && label !== translationKey ? label : fallback ?? key;
}
