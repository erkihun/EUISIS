import { KeyboardEvent, ReactNode, Ref, useEffect, useRef, useState } from 'react';
import type { JSX } from 'react';
import { AlertTriangle, CheckReadIcon, DownloadIcon, FileSpreadsheetIcon, LightbulbIcon, UploadIcon, XMarkIcon } from '@/Components/NavIcons';
import { buttonClassName, cx, selectClassName, tableHeaderCellClassName } from '@/Components/ui';

/*
 * The parts the file-import screens share (employees, positions): the step
 * rail, a step's card, the drop zone, the guide beside the steps, and the
 * tabs over the result lists. They carry no wording of their own; each page
 * passes its dictionary strings in.
 */

export type StepState = 'done' | 'current' | 'upcoming';

/** Fills `{name}` placeholders of a dictionary string. */
export function fill(text: string, values: Record<string, string | number>): string {
    return Object.entries(values).reduce((result, [key, value]) => result.split(`{${key}}`).join(String(value)), text);
}

export function formatFileSize(bytes: number): string {
    return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/** A busy mark drawn with borders, so it needs no icon. */
export function Spinner(): JSX.Element {
    return <span aria-hidden="true" className="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent motion-reduce:animate-none" />;
}

export function HeadCell({ children, className }: { children: ReactNode; className?: string }): JSX.Element {
    return <th className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left', className)}>{children}</th>;
}

/** The numbered mark of a step: a check once passed, filled while current, hollow before. */
function StepMark({ number, state }: { number: number; state: StepState }): JSX.Element {
    return (
        <span
            className={cx(
                'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold transition-colors',
                state === 'done' && 'bg-emerald-600 text-white dark:bg-emerald-500',
                state === 'current' && 'bg-primary text-white ring-4 ring-primary/15',
                state === 'upcoming' && 'border border-(--app-border-strong) bg-(--app-surface) text-(--app-muted-foreground)',
            )}
        >
            {state === 'done' ? <CheckReadIcon className="h-4 w-4" aria-hidden="true" /> : number}
        </span>
    );
}

/** Where the reader is in the flow: one mark per step, joined by a line that fills as steps are passed. */
export function ImportSteps({ label, steps }: { label: string; steps: { title: string; state: StepState }[] }): JSX.Element {
    return (
        <nav aria-label={label}>
            <ol className="flex items-center rounded-card border border-(--app-border) bg-(--app-surface) px-4 py-3 sm:px-6">
                {steps.map((step, index) => (
                    <li
                        key={step.title}
                        aria-current={step.state === 'current' ? 'step' : undefined}
                        className={cx('flex min-w-0 items-center', index < steps.length - 1 && 'flex-1')}
                    >
                        <StepMark number={index + 1} state={step.state} />
                        <span
                            className={cx(
                                'ml-2.5 truncate text-sm',
                                // On a phone only the current step is named; the others keep their number.
                                step.state === 'current' ? 'font-semibold text-(--app-foreground)' : 'sr-only sm:not-sr-only',
                                step.state === 'done' && 'font-medium text-(--app-foreground)',
                                step.state === 'upcoming' && 'text-(--app-muted-foreground)',
                            )}
                        >
                            {step.title}
                        </span>
                        {index < steps.length - 1 && (
                            <span
                                aria-hidden="true"
                                className={cx('mx-3 h-0.5 min-w-4 flex-1 rounded-full sm:mx-5', step.state === 'done' ? 'bg-emerald-600 dark:bg-emerald-500' : 'bg-(--app-border)')}
                            />
                        )}
                    </li>
                ))}
            </ol>
        </nav>
    );
}

type StepSectionProps = {
    number: number;
    state: StepState;
    title: string;
    description: ReactNode;
    children?: ReactNode;
    className?: string;
    ref?: Ref<HTMLElement>;
};

/** One step: its mark, what it is for, and its controls below a hairline. */
export function StepSection({ number, state, title, description, children, className, ref }: StepSectionProps): JSX.Element {
    return (
        <section ref={ref} className={cx('min-w-0 scroll-mt-24 rounded-card border border-(--app-border) bg-(--app-surface)', className)}>
            <header className={cx('flex items-start gap-3 px-5 py-4 sm:px-6', Boolean(children) && 'border-b border-(--app-border)')}>
                <StepMark number={number} state={state} />
                <div className="min-w-0 flex-1">
                    <h2 className="text-base font-semibold text-(--app-foreground)">{title}</h2>
                    <p className="mt-0.5 wrap-break-word text-sm leading-6 text-(--app-muted-foreground)">{description}</p>
                </div>
            </header>
            {children && <div className="px-5 py-5 sm:px-6">{children}</div>}
        </section>
    );
}

/** The guide beside the steps: what to type where, as a short list, and the file limits under it. */
export function GuidePanel({ title, tips, footer, className }: { title: string; tips: string[]; footer?: ReactNode; className?: string }): JSX.Element {
    return (
        <aside className={cx('min-w-0 rounded-card border border-(--app-border) bg-(--app-surface-muted) p-5', className)}>
            <h2 className="flex items-center gap-2 text-sm font-semibold text-(--app-foreground)">
                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
                    <LightbulbIcon className="h-4 w-4" aria-hidden="true" />
                </span>
                {title}
            </h2>
            <ul className="mt-4 space-y-3">
                {tips.map((tip) => (
                    <li key={tip} className="flex items-start gap-2.5 text-sm leading-6 text-(--app-muted-foreground)">
                        <span aria-hidden="true" className="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary" />
                        <span className="min-w-0 wrap-break-word">{tip}</span>
                    </li>
                ))}
            </ul>
            {footer && <p className="mt-4 border-t border-(--app-border) pt-3 text-xs leading-5 text-(--app-muted-foreground)">{footer}</p>}
        </aside>
    );
}

export type TemplateOrganization = { id: string; code: string; name_en: string; name_am: string | null };

type TemplateUnit = { id: string; code: string; name_en: string; name_am: string | null; depth: number };

type TemplateChooserProps = {
    organizations: TemplateOrganization[];
    amharic: boolean;
    labels: {
        organization: string;
        selectOrganization: string;
        unit: string;
        allUnits: string;
        unitHelp: string;
        unitsLoading: string;
        unitsFailed: string;
        downloadExcel: string;
        downloadCsv: string;
    };
    /** The download address for the chosen organization and unit; an empty unit means the whole organization. */
    hrefFor: (format: 'xlsx' | 'csv', organizationId: string, unitId: string) => string;
};

/**
 * What the template is made for: the organization, then, if wanted, one of
 * its units. With a unit the template covers that unit and the units below
 * it; without one, the whole organization. The units are fetched when the
 * organization is chosen, and listed indented by their place in the tree.
 */
export function TemplateChooser({ organizations, amharic, labels, hrefFor }: TemplateChooserProps): JSX.Element {
    const [organizationId, setOrganizationId] = useState(organizations.length === 1 ? organizations[0].id : '');
    const [unitId, setUnitId] = useState('');
    const [units, setUnits] = useState<TemplateUnit[]>([]);
    const [unitsState, setUnitsState] = useState<'idle' | 'loading' | 'failed'>('idle');

    useEffect(() => {
        setUnitId('');
        setUnits([]);

        if (organizationId === '') {
            setUnitsState('idle');

            return;
        }

        const request = new AbortController();
        setUnitsState('loading');

        fetch(route('organizations.units.options', organizationId), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: request.signal,
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
            .then((list: TemplateUnit[]) => {
                setUnits(Array.isArray(list) ? list : []);
                setUnitsState('idle');
            })
            .catch(() => {
                // A newer choice cancelled this request: that one reports for itself.
                if (!request.signal.aborted) setUnitsState('failed');
            });

        return () => request.abort();
    }, [organizationId]);

    const name = (item: { name_en: string; name_am: string | null }): string =>
        (amharic ? (item.name_am || item.name_en) : (item.name_en || item.name_am)) ?? '';

    return (
        <div>
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="min-w-0">
                    <label htmlFor="template-organization" className="mb-1.5 block text-sm font-medium text-(--app-foreground)">
                        {labels.organization}
                    </label>
                    <select
                        id="template-organization"
                        value={organizationId}
                        onChange={(event) => setOrganizationId(event.target.value)}
                        className={cx(selectClassName, 'w-full')}
                    >
                        <option value="">{labels.selectOrganization}</option>
                        {organizations.map((organization) => (
                            <option key={organization.id} value={organization.id}>{`${name(organization)} (${organization.code})`}</option>
                        ))}
                    </select>
                </div>

                <div className="min-w-0">
                    <label htmlFor="template-unit" className="mb-1.5 block text-sm font-medium text-(--app-foreground)">
                        {labels.unit}
                    </label>
                    <select
                        id="template-unit"
                        value={unitId}
                        onChange={(event) => setUnitId(event.target.value)}
                        disabled={organizationId === '' || unitsState === 'loading'}
                        aria-describedby="template-unit-help"
                        className={cx(selectClassName, 'w-full')}
                    >
                        <option value="">{unitsState === 'loading' ? labels.unitsLoading : labels.allUnits}</option>
                        {units.map((unit) => (
                            // Two non-breaking spaces per level: an option cannot be indented any other way.
                            <option key={unit.id} value={unit.id}>{`${'  '.repeat(unit.depth)}${name(unit)} (${unit.code})`}</option>
                        ))}
                    </select>
                </div>
            </div>

            <p id="template-unit-help" className={cx('mt-2 text-xs leading-5', unitsState === 'failed' ? 'text-red-600 dark:text-red-400' : 'text-(--app-muted-foreground)')} role={unitsState === 'failed' ? 'alert' : undefined}>
                {unitsState === 'failed' ? labels.unitsFailed : labels.unitHelp}
            </p>

            <div className="mt-4 flex flex-wrap items-center gap-2">
                {organizationId ? (
                    <>
                        <a href={hrefFor('xlsx', organizationId, unitId)} className={buttonClassName({ variant: 'primary' })}>
                            <DownloadIcon className="h-4 w-4" aria-hidden="true" />
                            {labels.downloadExcel}
                        </a>
                        <a href={hrefFor('csv', organizationId, unitId)} className={buttonClassName({ variant: 'outline' })}>
                            {labels.downloadCsv}
                        </a>
                    </>
                ) : (
                    <>
                        <button type="button" disabled className={buttonClassName({ variant: 'primary' })}>
                            <DownloadIcon className="h-4 w-4" aria-hidden="true" />
                            {labels.downloadExcel}
                        </button>
                        <button type="button" disabled className={buttonClassName({ variant: 'outline' })}>
                            {labels.downloadCsv}
                        </button>
                    </>
                )}
            </div>
        </div>
    );
}

type FileDropZoneProps = {
    id: string;
    file: File | null;
    error?: string;
    /** Shown while no file is chosen. */
    title: string;
    hint: string;
    changeLabel: string;
    removeLabel: string;
    onChoose: (file: File | null) => void;
};

/**
 * The file field as a drop target. Empty, it invites a drop or a click; with
 * a file it shows the file as a card that can be swapped or removed. The real
 * input sits before the zone and stays reachable by keyboard: its focus is
 * drawn on the zone through `peer`.
 */
export function FileDropZone({ id, file, error, title, hint, changeLabel, removeLabel, onChoose }: FileDropZoneProps): JSX.Element {
    const input = useRef<HTMLInputElement>(null);
    // Entering a child fires a leave on the parent; counting keeps the highlight steady.
    const depth = useRef(0);
    const [dragging, setDragging] = useState(false);

    // The page clears the file after a successful check: the input must forget it too.
    useEffect(() => {
        if (file === null && input.current) input.current.value = '';
    }, [file]);

    const dropHandlers = {
        onDragEnter: () => { depth.current += 1; setDragging(true); },
        onDragLeave: () => { depth.current = Math.max(0, depth.current - 1); if (depth.current === 0) setDragging(false); },
        onDragOver: (event: React.DragEvent) => event.preventDefault(),
        onDrop: (event: React.DragEvent) => {
            event.preventDefault();
            depth.current = 0;
            setDragging(false);
            onChoose(event.dataTransfer.files?.[0] ?? null);
        },
    };

    const focusRing = 'peer-focus-visible:border-primary peer-focus-visible:ring-2 peer-focus-visible:ring-primary/40';

    return (
        <div>
            <input
                ref={input}
                id={id}
                type="file"
                accept=".xlsx,.csv"
                // Emptied first, so choosing the same file again after correcting it is still noticed.
                onClick={(event) => { event.currentTarget.value = ''; }}
                onChange={(event) => onChoose(event.target.files?.[0] ?? null)}
                className="peer sr-only"
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? `${id}-error` : undefined}
            />

            {file === null ? (
                <label
                    htmlFor={id}
                    {...dropHandlers}
                    className={cx(
                        'flex cursor-pointer flex-col items-center justify-center rounded-card border-2 border-dashed px-5 py-9 text-center transition-colors',
                        focusRing,
                        dragging
                            ? 'border-primary bg-primary-50 dark:bg-primary-950/20'
                            : error
                                ? 'border-red-300 bg-red-50/60 dark:border-red-800 dark:bg-red-950/20'
                                : 'border-(--app-border-strong) bg-(--app-surface-muted) hover:border-primary hover:bg-primary-50/50 dark:hover:bg-primary-950/10',
                    )}
                >
                    <span className={cx(
                        'flex h-12 w-12 items-center justify-center rounded-full transition-colors',
                        dragging ? 'bg-primary text-white' : 'bg-(--app-surface) text-primary ring-1 ring-(--app-border)',
                    )}>
                        <UploadIcon className="h-6 w-6" aria-hidden="true" />
                    </span>
                    <span className="mt-3 text-sm font-semibold text-(--app-foreground)">{title}</span>
                    <span className="mt-1 text-xs text-(--app-muted-foreground)">{hint}</span>
                    <span className="mt-3 flex items-center gap-1.5" aria-hidden="true">
                        {['XLSX', 'CSV'].map((format) => (
                            <span key={format} className="rounded-full border border-(--app-border) bg-(--app-surface) px-2 py-0.5 text-[11px] font-semibold tracking-wide text-(--app-muted-foreground)">
                                {format}
                            </span>
                        ))}
                    </span>
                </label>
            ) : (
                <div
                    {...dropHandlers}
                    className={cx(
                        'flex items-center gap-3 rounded-card border px-4 py-3 transition-colors',
                        focusRing,
                        dragging
                            ? 'border-primary bg-primary-50 dark:bg-primary-950/20'
                            : error
                                ? 'border-red-300 bg-red-50/60 dark:border-red-800 dark:bg-red-950/20'
                                : 'border-emerald-300 bg-emerald-50/60 dark:border-emerald-800 dark:bg-emerald-950/20',
                    )}
                >
                    <span className={cx(
                        'flex h-10 w-10 shrink-0 items-center justify-center rounded-control',
                        error ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
                    )}>
                        <FileSpreadsheetIcon className="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold text-(--app-foreground)">{file.name}</p>
                        <p className="text-xs tabular-nums text-(--app-muted-foreground)">{formatFileSize(file.size)}</p>
                    </div>
                    <label htmlFor={id} className={buttonClassName({ variant: 'outline', size: 'sm', className: 'cursor-pointer' })}>
                        {changeLabel}
                    </label>
                    <button
                        type="button"
                        onClick={() => onChoose(null)}
                        aria-label={removeLabel}
                        title={removeLabel}
                        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-control text-(--app-muted-foreground) transition-colors hover:bg-(--app-surface) hover:text-(--app-foreground) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                    >
                        <XMarkIcon className="h-4 w-4" aria-hidden="true" />
                    </button>
                </div>
            )}

            {error && (
                <p id={`${id}-error`} role="alert" className="mt-2 flex items-start gap-2 text-sm text-red-600 dark:text-red-400">
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                    <span>{error}</span>
                </p>
            )}
        </div>
    );
}

/** The outcome in one line: a round mark, what happened, and what to do about the rest. */
export function ResultBanner({ tone, title, children }: { tone: 'success' | 'warning'; title: string; children?: ReactNode }): JSX.Element {
    const success = tone === 'success';

    return (
        <div
            role="status"
            className={cx(
                'flex items-start gap-4 rounded-card p-4 ring-1 ring-inset sm:p-5',
                success
                    ? 'bg-emerald-50 ring-emerald-200 dark:bg-emerald-950/30 dark:ring-emerald-800'
                    : 'bg-amber-50 ring-amber-200 dark:bg-amber-950/30 dark:ring-amber-800',
            )}
        >
            <span className={cx(
                'flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-white',
                success ? 'bg-emerald-600 dark:bg-emerald-500' : 'bg-amber-500',
            )}>
                {success ? <CheckReadIcon className="h-5 w-5" aria-hidden="true" /> : <AlertTriangle className="h-5 w-5" aria-hidden="true" />}
            </span>
            <div className="min-w-0">
                <p className={cx('text-base font-semibold', success ? 'text-emerald-900 dark:text-emerald-100' : 'text-amber-900 dark:text-amber-100')}>{title}</p>
                {children && <p className={cx('mt-0.5 text-sm leading-6', success ? 'text-emerald-800 dark:text-emerald-200' : 'text-amber-800 dark:text-amber-200')}>{children}</p>}
            </div>
        </div>
    );
}

/** How the rows of the file divide, as one thin bar. The figures themselves are in the tiles below it. */
export function SplitBar({ parts }: { parts: { key: string; value: number; className: string }[] }): JSX.Element | null {
    const shown = parts.filter((part) => part.value > 0);

    if (shown.length === 0) return null;

    return (
        <div aria-hidden="true" className="flex h-1.5 gap-0.5 overflow-hidden rounded-full">
            {shown.map((part) => (
                <span key={part.key} className={cx('min-w-1.5 rounded-full transition-[flex-grow] duration-500 motion-reduce:transition-none', part.className)} style={{ flexGrow: part.value }} />
            ))}
        </div>
    );
}

/** How far an import in passes has come. */
export function ImportProgress({ label, note, barLabel, done, total }: { label: string; note: string; barLabel: string; done: number; total: number }): JSX.Element {
    const percent = total > 0 ? Math.round((done / total) * 100) : 0;

    return (
        <div className="rounded-card border border-(--app-border) bg-(--app-surface-muted) p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <p className="flex items-center gap-2 text-sm font-semibold text-(--app-foreground)" aria-live="polite">
                    <span className="text-primary"><Spinner /></span>
                    {label}
                </p>
                <p className="text-sm font-semibold tabular-nums text-(--app-foreground)">{percent}%</p>
            </div>
            <div
                role="progressbar"
                aria-label={barLabel}
                aria-valuemin={0}
                aria-valuemax={total}
                aria-valuenow={done}
                className="mt-3 h-2 overflow-hidden rounded-full bg-(--app-border)"
            >
                <div className="h-full rounded-full bg-primary transition-[width] duration-500 motion-reduce:transition-none" style={{ width: `${percent}%` }} />
            </div>
            <p className="mt-2 text-xs text-(--app-muted-foreground)">{note}</p>
        </div>
    );
}

export type ResultTab<Key extends string> = { key: Key; label: string; count: number; tone: 'danger' | 'success' | 'neutral' };

const countTones = {
    danger: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    success: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    neutral: 'bg-(--app-surface-muted) text-(--app-muted-foreground)',
};

/**
 * The result lists behind one row of tabs, so a long file does not become a
 * long page. Left and Right move between tabs, as the tab pattern expects.
 */
export function ResultTabs<Key extends string>({ id, label, tabs, active, onChange }: {
    id: string;
    label: string;
    tabs: ResultTab<Key>[];
    active: Key;
    onChange: (key: Key) => void;
}): JSX.Element {
    const move = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;

        event.preventDefault();
        const index = tabs.findIndex((tab) => tab.key === active);
        const next = tabs[(index + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
        onChange(next.key);
        document.getElementById(`${id}-tab-${next.key}`)?.focus();
    };

    return (
        <div
            role="tablist"
            aria-label={label}
            onKeyDown={move}
            className="inline-flex max-w-full gap-1 overflow-x-auto rounded-control border border-(--app-border) bg-(--app-surface-muted) p-1"
        >
            {tabs.map((tab) => {
                const selected = tab.key === active;

                return (
                    <button
                        key={tab.key}
                        id={`${id}-tab-${tab.key}`}
                        type="button"
                        role="tab"
                        aria-selected={selected}
                        aria-controls={`${id}-panel`}
                        tabIndex={selected ? 0 : -1}
                        onClick={() => onChange(tab.key)}
                        className={cx(
                            'inline-flex shrink-0 items-center gap-2 rounded-control px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary',
                            selected
                                ? 'bg-(--app-surface) text-(--app-foreground) shadow-sm'
                                : 'text-(--app-muted-foreground) hover:text-(--app-foreground)',
                        )}
                    >
                        {tab.label}
                        <span className={cx('rounded-full px-1.5 py-0.5 text-xs font-semibold tabular-nums leading-none', countTones[tab.tone])}>{tab.count}</span>
                    </button>
                );
            })}
        </div>
    );
}
