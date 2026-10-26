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
