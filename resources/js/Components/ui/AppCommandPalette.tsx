import { useEffect, useRef, useState } from 'react';
import { Command } from 'cmdk';
import { Dialog, Transition, TransitionChild, DialogPanel } from '@headlessui/react';
import { Fragment } from 'react';
import { router } from '@inertiajs/react';
/*
 * These are the same nav icons the sidebar renders, so they come from the same
 * set. They were previously pulled from `lucide-react` while the sidebar drew
 * them from `Components/Icons` — two icon libraries side by side, with the
 * palette and the sidebar showing subtly different glyphs for the same
 * destination. The aliases keep the call sites below unchanged.
 */
import {
    LayoutDashboard, Building2, Users, CreditCard, Layers, ScrollText,
    SettingsIcon as Settings, ShieldCheck, Briefcase, TrashIcon as Trash2,
    TagsIcon as Tag, GitBranchIcon as GitBranch, HashIcon as Hash,
    GitForkIcon as GitFork, ArrowLeftRightIcon as ArrowLeftRight,
    ClipboardCheckIcon as ClipboardCheck, ClipboardListIcon as ClipboardList,
    MegaphoneIcon as Megaphone, Inbox, BadgeCheckIcon as BadgeCheck,
    ReceiptTextIcon as ReceiptText, HandshakeIcon as Handshake, KeyIcon as Key,
    UserCogIcon as UserCog, TrendingUpIcon as TrendingUp, HardHatIcon as HardHat,
    ActivityIcon as Activity, BoxesIcon as Boxes, QrCodeIcon as QrCode,
    UserIcon as User, SearchIcon as Search, NetworkIcon as Network,
} from '@/Components/Icons';
import { useCan } from '@/hooks/useCan';
import { useLocale } from '@/hooks/useLocale';

interface NavEntry {
    routeName: string;
    labelKey: string;
    groupKey: string;
    permission?: string;
    icon: React.ElementType;
}

const allEntries: NavEntry[] = [
    // Overview
    { routeName: 'dashboard', labelKey: 'nav.dashboard', groupKey: 'nav.groupOverview',       icon: LayoutDashboard },
    // Organization
    { routeName: 'organizations.index', labelKey: 'nav.organizations', groupKey: 'nav.groupOrganization', icon: Building2,        permission: 'organizations.view' },
    { routeName: 'organization-types.index', labelKey: 'nav.organizationTypes', groupKey: 'nav.groupOrganization', icon: Tag,              permission: 'organization-types.viewAny' },
    { routeName: 'organization-units.index', labelKey: 'nav.organizationUnits', groupKey: 'nav.groupOrganization', icon: GitBranch,        permission: 'organization-units.viewAny' },
    { routeName: 'institution-offices.index', labelKey: 'nav.institutionOffices', groupKey: 'nav.groupOrganization', icon: Network,          permission: 'institution-offices.viewAny' },
    { routeName: 'organization-unit-types.index', labelKey: 'nav.organizationUnitTypes', groupKey: 'nav.groupOrganization', icon: Boxes,            permission: 'organization-unit-types.viewAny' },
    { routeName: 'hierarchy-versions.index', labelKey: 'nav.hierarchyVersions', groupKey: 'nav.groupOrganization', icon: GitFork,          permission: 'hierarchy-versions.viewAny' },
    // Workforce
    { routeName: 'employees.index', labelKey: 'nav.employees', groupKey: 'nav.groupWorkforce', icon: Users,            permission: 'employees.view' },
    { routeName: 'positions.index', labelKey: 'nav.positions', groupKey: 'nav.groupWorkforce', icon: Briefcase,        permission: 'positions.viewAny' },
    { routeName: 'grade-levels.index', labelKey: 'nav.gradeLevels', groupKey: 'nav.groupWorkforce', icon: TrendingUp,       permission: 'grade-levels.viewAny' },
    { routeName: 'occupations.index', labelKey: 'nav.occupations', groupKey: 'nav.groupWorkforce', icon: HardHat,          permission: 'occupations.viewAny' },
    { routeName: 'vacancy-announcements.index', labelKey: 'nav.vacancyAnnouncements', groupKey: 'nav.groupWorkforce', icon: Megaphone,        permission: 'vacancy-announcements.viewAny' },
    { routeName: 'position-establishments.index', labelKey: 'nav.positionEstablishments', groupKey: 'nav.groupWorkforce', icon: ClipboardList,    permission: 'position-establishments.viewAny' },
    // Transfers
    { routeName: 'transfer-announcements.index', labelKey: 'nav.transferAnnouncements', groupKey: 'nav.transferManagement', icon: Megaphone,        permission: 'transfers.announcements.view' },
    { routeName: 'transfer-applications.index', labelKey: 'nav.transferApplications', groupKey: 'nav.transferManagement', icon: Inbox,            permission: 'transfers.applications.view' },
    // Identity
    { routeName: 'id-cards.index', labelKey: 'nav.idCards', groupKey: 'nav.groupIdentity', icon: CreditCard,       permission: 'cards.view' },
    { routeName: 'card-requests.index', labelKey: 'nav.cardRequests', groupKey: 'nav.groupIdentity', icon: ClipboardCheck,   permission: 'id-cards.viewAny' },
    // Services
    { routeName: 'service-types.index', labelKey: 'nav.serviceTypes', groupKey: 'nav.groupServices', icon: Layers,           permission: 'service-types.viewAny' },
    { routeName: 'entitlement-rules.index', labelKey: 'nav.entitlementRules', groupKey: 'nav.groupServices', icon: ReceiptText,      permission: 'entitlement-rules.viewAny' },
    // Cafeteria
    { routeName: 'cafeteria.dashboard', labelKey: 'nav.cafeteriaDashboard', groupKey: 'nav.groupCafeteria', icon: LayoutDashboard,  permission: 'cafeteria_transactions.view' },
    { routeName: 'cafeteria.transactions.index', labelKey: 'nav.cafeteriaTransactions', groupKey: 'nav.groupCafeteria', icon: ReceiptText,      permission: 'cafeteria_transactions.view' },
    { routeName: 'cafeteria.reports.index', labelKey: 'nav.cafeteriaReports', groupKey: 'nav.groupCafeteria', icon: Activity,         permission: 'cafeteria_reports.view' },
    { routeName: 'cafeteria.settings.index', labelKey: 'nav.cafeteriaSettings', groupKey: 'nav.groupCafeteria', icon: Settings,         permission: 'cafeteria_settings.view' },
    // Transport
    { routeName: 'transport.providers.index', labelKey: 'nav.transportProviders', groupKey: 'nav.groupTransport', icon: Handshake,        permission: 'transport-providers.viewAny' },
    { routeName: 'transport.routes.index', labelKey: 'nav.transportRoutes', groupKey: 'nav.groupTransport', icon: ScrollText,       permission: 'transport-routes.viewAny' },
    { routeName: 'transport.vehicles.index', labelKey: 'nav.transportVehicles', groupKey: 'nav.groupTransport', icon: Activity,         permission: 'transport-vehicles.viewAny' },
    { routeName: 'transport.drivers.index', labelKey: 'nav.transportDrivers', groupKey: 'nav.groupTransport', icon: User,             permission: 'transport-drivers.viewAny' },
    { routeName: 'transport.passes.index', labelKey: 'nav.transportPasses', groupKey: 'nav.groupTransport', icon: BadgeCheck,       permission: 'transport-passes.viewAny' },
    // Configuration
    { routeName: 'code-rules.index', labelKey: 'nav.codeRules', groupKey: 'nav.groupConfiguration', icon: Hash,             permission: 'code-rules.viewAny' },
    { routeName: 'audit-logs.index', labelKey: 'nav.auditLogs', groupKey: 'nav.groupConfiguration', icon: ScrollText,       permission: 'audit.view' },
    { routeName: 'users.index', labelKey: 'nav.users', groupKey: 'nav.groupConfiguration', icon: UserCog,          permission: 'users.viewAny' },
    { routeName: 'roles.index', labelKey: 'nav.roles', groupKey: 'nav.groupConfiguration', icon: ShieldCheck,      permission: 'roles.viewAny' },
    { routeName: 'permissions.index', labelKey: 'nav.permissions', groupKey: 'nav.groupConfiguration', icon: Key,              permission: 'permissions.viewAny' },
    { routeName: 'system-settings.index', labelKey: 'nav.systemSettings', groupKey: 'nav.groupConfiguration', icon: Settings,         permission: 'system-settings.view' },
    { routeName: 'recycle-bin.index', labelKey: 'nav.recycleBin', groupKey: 'nav.groupConfiguration', icon: Trash2,           permission: 'recycle-bin.view' },
];

interface Props {
    open: boolean;
    onClose: () => void;
}

export default function AppCommandPalette({ open, onClose }: Props) {
    const { t } = useLocale();
    const { can } = useCan();
    const [search, setSearch] = useState('');
    const inputRef = useRef<HTMLInputElement>(null);

    // Reset search when opened
    useEffect(() => {
        if (open) setSearch('');
    }, [open]);

    // Focus input after transition
    useEffect(() => {
        if (open) {
            const id = setTimeout(() => inputRef.current?.focus(), 50);
            return () => clearTimeout(id);
        }
    }, [open]);

    const visible = allEntries.filter(
        (e) => !e.permission || can(e.permission),
    );

    // Group visible entries
    const grouped = visible.reduce<Record<string, NavEntry[]>>((acc, entry) => {
        if (!acc[entry.groupKey]) acc[entry.groupKey] = [];
        acc[entry.groupKey].push(entry);
        return acc;
    }, {});

    function go(routeName: string) {
        try {
            router.visit(route(routeName));
        } catch {
            // Route might not exist in current context — silently ignore
        }
        onClose();
    }

    return (
        <Transition show={open} as={Fragment}>
            <Dialog
                as="div"
                className="fixed inset-0 z-50"
                onClose={onClose}
                aria-label={t('nav.commandMenu')}
            >
                {/* Backdrop */}
                <TransitionChild
                    as={Fragment}
                    enter="ease-out duration-150"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="ease-in duration-100"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" aria-hidden="true" />
                </TransitionChild>

                {/* Panel */}
                <TransitionChild
                    as={Fragment}
                    enter="ease-out duration-150"
                    enterFrom="opacity-0 scale-95 translate-y-2"
                    enterTo="opacity-100 scale-100 translate-y-0"
                    leave="ease-in duration-100"
                    leaveFrom="opacity-100 scale-100 translate-y-0"
                    leaveTo="opacity-0 scale-95 translate-y-2"
                >
                    <DialogPanel className="relative mx-auto mt-20 w-full max-w-lg overflow-hidden rounded-panel bg-white shadow-2xl ring-1 ring-gray-200 dark:bg-slate-900 dark:ring-slate-700">
                        <Command
                            className="flex flex-col"
                            shouldFilter={true}
                            onKeyDown={(e) => { if (e.key === 'Escape') onClose(); }}
                        >
                            {/* Search input */}
                            <div className="flex items-center gap-2 border-b border-gray-100 px-4 py-3 dark:border-slate-800">
                                <Search className="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
                                <Command.Input
                                    ref={inputRef}
                                    value={search}
                                    onValueChange={setSearch}
                                    placeholder={t('nav.typeCommandOrSearch')}
                                    className="flex-1 bg-transparent text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none dark:text-slate-100 dark:placeholder:text-slate-500"
                                />
                                <kbd className="hidden rounded border border-gray-200 px-1.5 py-0.5 text-[10px] font-medium text-gray-400 sm:block dark:border-slate-700 dark:text-slate-500">
                                    ESC
                                </kbd>
                            </div>

                            {/* Results */}
                            <Command.List className="max-h-80 overflow-y-auto py-2">
                                <Command.Empty className="px-4 py-8 text-center text-sm text-gray-500 dark:text-slate-400">
                                    {t('nav.noResults')}
                                </Command.Empty>

                                {Object.entries(grouped).map(([groupKey, entries]) => (
                                    <Command.Group
                                        key={groupKey}
                                        heading={t(groupKey as any)}
                                        className="mb-1"
                                    >
                                        <div className="mb-1 px-3 pt-2 text-[10px] font-semibold text-gray-400 dark:text-slate-500">
                                            {t(groupKey as any)}
                                        </div>
                                        {entries.map((entry) => {
                                            const Icon = entry.icon;
                                            return (
                                                <Command.Item
                                                    key={entry.routeName}
                                                    value={`${t(entry.labelKey as any)} ${t(groupKey as any)}`}
                                                    onSelect={() => go(entry.routeName)}
                                                    className="flex cursor-pointer items-center gap-3 px-4 py-2 text-sm text-gray-700 data-[selected=true]:bg-blue-50 data-[selected=true]:text-blue-700 dark:text-slate-300 dark:data-[selected=true]:bg-blue-900/30 dark:data-[selected=true]:text-blue-300"
                                                >
                                                    <Icon className="h-4 w-4 shrink-0 text-gray-400 dark:text-slate-500" aria-hidden="true" />
                                                    {t(entry.labelKey as any)}
                                                </Command.Item>
                                            );
                                        })}
                                    </Command.Group>
                                ))}
                            </Command.List>

                            {/* Footer hint */}
                            <div className="flex items-center justify-end gap-3 border-t border-gray-100 px-4 py-2 dark:border-slate-800">
                                <span className="text-[11px] text-gray-400 dark:text-slate-500">
                                    <kbd className="rounded border border-gray-200 px-1 dark:border-slate-700">↑</kbd>
                                    <kbd className="ml-0.5 rounded border border-gray-200 px-1 dark:border-slate-700">↓</kbd>
                                    {' '}navigate
                                </span>
                                <span className="text-[11px] text-gray-400 dark:text-slate-500">
                                    <kbd className="rounded border border-gray-200 px-1 dark:border-slate-700">↵</kbd>
                                    {' '}open
                                </span>
                            </div>
                        </Command>
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </Transition>
    );
}
