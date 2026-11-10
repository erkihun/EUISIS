import { useMemo, useState } from 'react';
import { SearchInput, badgeClassName, cx } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';

export type PickableRole = { id: number; name: string; scope: 'organization' | 'global' };

/** The groups roles are shown in, by name; the first match wins and "other" takes the rest. */
const ROLE_CATEGORIES = [
    { key: 'administration', names: /^(Super Admin|System Admin|City Admin|Public Service Bureau Admin|Organizational Admin)$/ },
    { key: 'hr', names: /^(HR Officer|Employee|Supervisor|Functional Supervisor|Daily Activity .*|Field Work .*)$/ },
    { key: 'transfers', names: /^(Transfer |Vacancy )/ },
    { key: 'structure', names: /^(Structure |Organization Registrar$|Code Rule )/ },
    { key: 'identity', names: /^(ID Card |NFC )/ },
    { key: 'security', names: /^(Security Settings Manager|System Settings Admin|API Manager|Public Site Manager|Workflow Policy Administrator|Recycle Bin Manager)$/ },
    { key: 'platform', names: /^(Platform Operations Engineer|Help Desk Agent|Backup Operator|Recovery |Intelligence Governance Officer)/ },
    { key: 'executive', names: /^(Situation Room |Intelligence Analyst$)/ },
    { key: 'reporting', names: /^(Auditor|Report Viewer)$/ },
    { key: 'performance', names: /^(Performance |Assessment )/ },
    { key: 'feedback', names: /^Public Feedback / },
    { key: 'grievances', names: /^(Grievance |Administrative Tribunal |Discipline |Disciplinary )/ },
    { key: 'letters', names: /^(Letter |Correspondence Officer$)/ },
    { key: 'court', names: /^Court / },
    { key: 'services', names: /^(Cafeteria |Transport |Service Provider |Settlement |Service Entitlement |Reception |Information Officer$)/ },
    { key: 'other', names: /.*/ },
] as const;

/**
 * The roles to give a user: a search box, a "selected only" switch and the
 * roles in collapsible groups, each group showing how many of its roles are
 * picked. With 100-odd roles, the groups start closed unless they hold a
 * selected role or match the search.
 */
export default function RolePicker({ roles, selected, onToggle, onClear, error }: {
    roles: PickableRole[];
    selected: number[];
    onToggle: (id: number) => void;
    onClear: () => void;
    error?: string;
}) {
    const { t } = useLocale();
    const [query, setQuery] = useState('');
    const [selectedOnly, setSelectedOnly] = useState(false);
    const [open, setOpen] = useState<Record<string, boolean>>({});
    const needle = query.trim().toLowerCase();

    const groups = useMemo(() => ROLE_CATEGORIES
        .map((category) => {
            const all = roles.filter((role) => ROLE_CATEGORIES.find((entry) => entry.names.test(role.name)) === category);
            const shown = all.filter((role) => (!needle || role.name.toLowerCase().includes(needle)) && (!selectedOnly || selected.includes(role.id)));
            return { key: category.key, all, shown, picked: all.filter((role) => selected.includes(role.id)).length };
        })
        .filter((group) => group.shown.length > 0), [roles, needle, selectedOnly, selected]);

    // A group is open when the person opened it, or when searching/filtering, or when it holds a pick.
    const isOpen = (key: string, picked: number) => open[key] ?? (needle !== '' || selectedOnly || picked > 0);

    if (roles.length === 0) {
        return <p className="rounded-control border border-dashed border-(--app-border) px-3 py-4 text-center text-sm text-(--app-muted-foreground)">{t('users.noRoles')}</p>;
    }

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
                <SearchInput value={query} onChange={setQuery} onClear={() => setQuery('')} placeholder={t('users.createForm.searchRoles')} label={t('users.createForm.searchRoles')} className="min-w-56 flex-1" />
                <label className="inline-flex cursor-pointer items-center gap-2 rounded-control border border-(--app-border) px-3 py-2 text-sm text-(--app-foreground) hover:bg-(--app-surface-muted)">
                    <input type="checkbox" className="h-4 w-4 rounded-sm border-(--app-border-strong) text-primary focus:ring-primary" checked={selectedOnly} onChange={(event) => setSelectedOnly(event.target.checked)} />
                    {t('users.createForm.selectedOnly')}
                </label>
            </div>

            <div className="flex items-center justify-between text-xs text-(--app-muted-foreground)" aria-live="polite">
                <span>{t('users.createForm.selectedCount').replace(':count', String(selected.length))}</span>
                {selected.length > 0 && (
                    <button type="button" onClick={onClear} className="font-medium text-primary hover:underline">{t('users.createForm.clearSelection')}</button>
                )}
            </div>

            {groups.length === 0 ? (
                <p className="rounded-control border border-dashed border-(--app-border) px-3 py-4 text-center text-sm text-(--app-muted-foreground)">{t('users.createForm.noRolesMatch')}</p>
            ) : (
                <div className="divide-y divide-(--app-border) rounded-control border border-(--app-border)">
                    {groups.map((group) => {
                        const expanded = isOpen(group.key, group.picked);
                        const panelId = `role-group-${group.key}`;
                        return (
                            <div key={group.key}>
                                <button type="button" aria-expanded={expanded} aria-controls={panelId}
                                    onClick={() => setOpen((current) => ({ ...current, [group.key]: !expanded }))}
                                    className="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-(--app-surface-muted)">
                                    <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" className={cx('h-4 w-4 shrink-0 text-(--app-muted-foreground) transition-transform', expanded && 'rotate-90')}>
                                        <path fillRule="evenodd" d="M7.2 14.8a.75.75 0 0 1 0-1.06L10.94 10 7.2 6.26a.75.75 0 1 1 1.06-1.06l4.27 4.27a.75.75 0 0 1 0 1.06L8.26 14.8a.75.75 0 0 1-1.06 0Z" clipRule="evenodd" />
                                    </svg>
                                    <span className="flex-1 text-sm font-semibold text-(--app-foreground)">{t(`users.roleCategories.${group.key}`)}</span>
                                    {group.picked > 0 && <span className={badgeClassName('success')}>{group.picked}</span>}
                                    <span className="text-xs tabular-nums text-(--app-muted-foreground)">{group.all.length}</span>
                                </button>
                                {expanded && (
                                    <div id={panelId} className="grid grid-cols-1 gap-2 px-4 pb-4 sm:grid-cols-2">
                                        {group.shown.map((role) => {
                                            const checked = selected.includes(role.id);
                                            return (
                                                <label key={role.id} className={cx(
                                                    'flex cursor-pointer items-start gap-2.5 rounded-control border px-3 py-2.5 transition-colors',
                                                    checked ? 'border-primary bg-primary/5' : 'border-(--app-border) hover:bg-(--app-surface-muted)',
                                                )}>
                                                    <input type="checkbox" className="mt-0.5 h-4 w-4 shrink-0 rounded-sm border-(--app-border-strong) text-primary focus:ring-primary" checked={checked} onChange={() => onToggle(role.id)} />
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block text-sm font-medium text-(--app-foreground)">{role.name}</span>
                                                        <span className="mt-0.5 block text-xs text-(--app-muted-foreground)">{role.scope === 'organization' ? t('users.scopedRole') : t('users.globalRole')}</span>
                                                    </span>
                                                </label>
                                            );
                                        })}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}
            {error && <p className="text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}
