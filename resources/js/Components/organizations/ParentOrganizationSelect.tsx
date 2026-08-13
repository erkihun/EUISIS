import { useEffect, useMemo, useState } from 'react';
import { ChevronDown, SearchIcon, X } from '@/Components/Icons';

export type ParentOrganizationOption = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    status: string;
    depth: number | null;
    parent_path: string | null;
    can_create_child: boolean;
    organization_type: {
        code: string;
        name_en: string;
        name_am: string | null;
    } | null;
};

type Props = {
    value: string;
    initialOptions: ParentOrganizationOption[];
    selectedOption: ParentOrganizationOption | null;
    hierarchyVersionId?: string;
    currentOrganizationId?: string;
    endpoint: string;
    disabled?: boolean;
    allowNoParent?: boolean;
    locale: string;
    onChange: (value: string, option: ParentOrganizationOption | null) => void;
    labels: {
        placeholder: string;
        searchPlaceholder: string;
        noParent: string;
        noResults: string;
        eligibleParents: string;
        permissionHint: string;
        level: string;
    };
};

type ParentOrganizationResponse = {
    options: ParentOrganizationOption[];
    selected: ParentOrganizationOption | null;
};

const inputCls =
    'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 disabled:cursor-not-allowed disabled:bg-gray-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500 dark:disabled:bg-slate-900';

export default function ParentOrganizationSelect({
    value,
    initialOptions,
    selectedOption,
    hierarchyVersionId,
    currentOrganizationId,
    endpoint,
    disabled = false,
    allowNoParent = true,
    locale,
    onChange,
    labels,
}: Props) {
    const [search, setSearch] = useState('');
    const [options, setOptions] = useState<ParentOrganizationOption[]>(initialOptions);
    const [selected, setSelected] = useState<ParentOrganizationOption | null>(selectedOption);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        setOptions(initialOptions);
    }, [initialOptions]);

    useEffect(() => {
        setSelected(selectedOption);
    }, [selectedOption]);

    useEffect(() => {
        if (value === '' && selected !== null) {
            setSelected(null);
        }
    }, [selected, value]);

    useEffect(() => {
        const controller = new AbortController();
        const delay = window.setTimeout(async () => {
            setLoading(true);

            try {
                const params = new URLSearchParams();

                if (search.trim() !== '') {
                    params.set('q', search.trim());
                }

                if (value !== '') {
                    params.set('selected_id', value);
                }

                if (hierarchyVersionId) {
                    params.set('hierarchy_version_id', hierarchyVersionId);
                }

                if (currentOrganizationId) {
                    params.set('current_organization_id', currentOrganizationId);
                }

                const response = await fetch(`${endpoint}?${params.toString()}`, {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: controller.signal,
                });

                if (!response.ok) {
                    return;
                }

                const payload = (await response.json()) as ParentOrganizationResponse;
                setOptions(payload.options);

                if (payload.selected) {
                    setSelected(payload.selected);
                }
            } catch (error) {
                if ((error as Error).name !== 'AbortError') {
                    console.error('Failed to load parent organizations', error);
                }
            } finally {
                setLoading(false);
            }
        }, search.trim() === '' ? 0 : 250);

        return () => {
            controller.abort();
            window.clearTimeout(delay);
        };
    }, [currentOrganizationId, endpoint, hierarchyVersionId, search, value]);

    const displayOptions = useMemo(() => {
        if (selected === null) {
            return options;
        }

        return [selected, ...options.filter((option) => option.id !== selected.id)];
    }, [options, selected]);

    return (
        <div className="space-y-3">
            <div className="relative">
                <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400 dark:text-slate-500" />
                <input
                    type="text"
                    value={search}
                    disabled={disabled}
                    onChange={(event) => setSearch(event.target.value)}
                    className={`${inputCls} pl-9 pr-10`}
                    placeholder={labels.searchPlaceholder}
                />
                {search !== '' && (
                    <button
                        type="button"
                        onClick={() => setSearch('')}
                        className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 transition hover:text-gray-600 dark:text-slate-500 dark:hover:text-slate-300"
                        aria-label={labels.placeholder}
                    >
                        <X className="h-4 w-4" />
                    </button>
                )}
            </div>

            <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-950">
                <div className="flex items-center justify-between border-b border-gray-100 px-3 py-2 text-xs font-medium text-gray-500 dark:border-slate-800 dark:text-slate-400">
                    <span>{labels.eligibleParents}</span>
                    <span>{loading ? '...' : displayOptions.length}</span>
                </div>

                {allowNoParent && (
                    <button
                        type="button"
                        disabled={disabled}
                        onClick={() => {
                            setSelected(null);
                            onChange('', null);
                        }}
                        className={`flex w-full items-center justify-between px-3 py-2 text-left text-sm transition ${
                            value === ''
                                ? 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300'
                                : 'text-gray-600 hover:bg-gray-50 dark:text-slate-300 dark:hover:bg-slate-900'
                        }`}
                    >
                        <span>{labels.noParent}</span>
                        <ChevronDown className="h-4 w-4 opacity-60" />
                    </button>
                )}

                {displayOptions.length === 0 ? (
                    <div className="px-3 py-4 text-sm text-gray-500 dark:text-slate-400">{labels.noResults}</div>
                ) : (
                    <div className="max-h-72 overflow-y-auto">
                        {displayOptions.map((option) => {
                            const isSelected = option.id === value;

                            return (
                                <button
                                    key={option.id}
                                    type="button"
                                    disabled={disabled}
                                    onClick={() => {
                                        setSelected(option);
                                        onChange(option.id, option);
                                    }}
                                    className={`w-full border-t border-gray-100 px-3 py-3 text-left transition first:border-t-0 dark:border-slate-800 ${
                                        isSelected
                                            ? 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300'
                                            : 'hover:bg-gray-50 dark:hover:bg-slate-900'
                                    }`}
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <div className="font-medium">
                                                {option.code} - {locale === 'am' ? (option.name_am || option.name_en) : option.name_en}
                                            </div>
                                            <div className="mt-1 text-xs text-gray-500 dark:text-slate-400">
                                                {option.organization_type
                                                    ? (locale === 'am'
                                                        ? (option.organization_type.name_am || option.organization_type.name_en)
                                                        : option.organization_type.name_en)
                                                    : option.status}
                                            </div>
                                            {option.parent_path && (
                                                <div className="mt-1 text-xs text-gray-400 dark:text-slate-500">
                                                    {option.parent_path}
                                                </div>
                                            )}
                                        </div>
                                        <div className="text-right text-xs text-gray-400 dark:text-slate-500">
                                            {option.depth !== null ? `${labels.level} ${option.depth}` : null}
                                        </div>
                                    </div>
                                    {!option.can_create_child && (
                                        <div className="mt-2 text-xs text-orange-600 dark:text-orange-400">
                                            {labels.permissionHint}
                                        </div>
                                    )}
                                </button>
                            );
                        })}
                    </div>
                )}
            </div>
        </div>
    );
}
