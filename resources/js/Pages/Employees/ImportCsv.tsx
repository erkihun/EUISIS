import { FormEvent, ReactNode, useEffect, useRef, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import type { JSX } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { AlertTriangle, CheckReadIcon, DownloadIcon, FileSpreadsheetIcon, UploadIcon } from '@/Components/NavIcons';
import { useLocale } from '@/hooks/useLocale';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import {
    Alert,
    buttonClassName,
    cx,
    selectClassName,
    tableBodyClassName,
    tableCellClassName,
    tableClassName,
    tableHeadClassName,
    tableHeaderCellClassName,
    TableTools,
} from '@/Components/ui';

type Calendar = 'gregorian' | 'ethiopian';

type Batch = {
    id: string;
    file_name: string;
    total_rows: number;
    valid_rows: number;
    failed_rows: number;
    unfilled_rows: number;
    over_limit_rows: number;
    imported_rows: number;
    calendar: Calendar;
    status: string;
    importable: boolean;
    finished: boolean;
};

type Row = {
    row_number: number;
    name: string;
    employee_number: string | null;
    organization: string | null;
    organization_unit: string | null;
    position: string | null;
    start_date: string | null;
    status: 'valid' | 'invalid' | 'imported';
    errors: string[];
};

type Organization = { id: string; code: string; name_en: string; name_am: string | null };

type Props = {
    batch: Batch | null;
    rows: Row[];
    maxRows: number;
    organizations: Organization[];
    can: { upload: boolean; confirm: boolean; viewEmployees: boolean };
};

const MAX_FILE_BYTES = 5 * 1024 * 1024;

/** Fills `{name}` placeholders of a dictionary string. */
function fill(text: string, values: Record<string, string | number>): string {
    return Object.entries(values).reduce((result, [key, value]) => result.split(`{${key}}`).join(String(value)), text);
}

export default function ImportCsv({ batch, rows, maxRows, organizations, can }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';
    const displayCalendar = useCalendarSystem() as Calendar;
    const fileInput = useRef<HTMLInputElement>(null);
    const result = useRef<HTMLElement>(null);
    const [dragging, setDragging] = useState(false);
    const [importing, setImporting] = useState(false);
    const [organizationId, setOrganizationId] = useState(organizations.length === 1 ? organizations[0].id : '');
    const form = useForm<{ file: File | null; calendar: Calendar }>({
        file: null,
        calendar: batch?.calendar ?? displayCalendar,
    });

    /*
     * The result is the next thing to read once a file was checked or
     * imported. Only the window is scrolled: scrollIntoView() also moves the
     * app shell's clipped containers, which pushes the whole layout down.
     */
    useEffect(() => {
        if (!batch || !result.current) return;

        const top = result.current.getBoundingClientRect().top + window.scrollY - 88;
        window.scrollTo({ top: Math.max(top, 0), behavior: 'smooth' });
    }, [batch?.id, batch?.finished]);

    const organizationLabel = (organization: Organization): string =>
        `${am ? (organization.name_am || organization.name_en) : (organization.name_en || organization.name_am)} (${organization.code})`;

    const templateHref = (format: 'xlsx' | 'csv'): string =>
        route('employees.import.template', { organization_id: organizationId, locale, format });

    const chooseFile = (file: File | null) => {
        form.clearErrors('file');
        form.setData('file', file);

        if (file === null) return;

        // Said at once, before a large or unusable file is sent.
        if (!/\.(xlsx|csv)$/i.test(file.name)) {
            form.setError('file', t('employees.import.fileTypeError'));
        } else if (file.size > MAX_FILE_BYTES) {
            form.setError('file', t('employees.import.fileSizeError'));
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('employees.import.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset('file');
                if (fileInput.current) fileInput.current.value = '';
            },
        });
    };

    const confirmImport = () => {
        if (!batch) return;
        setImporting(true);
        router.post(route('employees.import.confirm', batch.id), {}, { preserveScroll: true, onFinish: () => setImporting(false) });
    };

    const startOver = () => router.post(route('employees.import.cancel'));

    const failedRows = rows.filter((row) => row.status === 'invalid');
    const readyRows = rows.filter((row) => row.status === 'valid');
    const fileBlocked = Boolean(form.errors.file) && form.data.file !== null && (!/\.(xlsx|csv)$/i.test(form.data.file.name) || form.data.file.size > MAX_FILE_BYTES);

    return (
        <AuthenticatedLayout header={<PageHeader
                    title={t('employees.import.title')}
                    description={t('employees.import.description')}
                    backHref={route('employees.index')}
                />}>
            <Head title={t('employees.import.title')} />

            <div className="space-y-5">
                <StepCard number={1} title={t('employees.import.step1Title')} description={t('employees.import.step1Description')}>
                    {organizations.length === 0 ? (
                        <Alert tone="warning">{t('employees.import.noOrganizations')}</Alert>
                    ) : (
                        <>
                            <label htmlFor="template-organization" className="mb-1.5 block text-sm font-medium text-(--app-foreground)">
                                {t('employees.import.organization')}
                            </label>
                            <select
                                id="template-organization"
                                value={organizationId}
                                onChange={(event) => setOrganizationId(event.target.value)}
                                className={cx(selectClassName, 'w-full sm:max-w-xl')}
                            >
                                <option value="">{t('employees.import.selectOrganization')}</option>
                                {organizations.map((organization) => (
                                    <option key={organization.id} value={organization.id}>{organizationLabel(organization)}</option>
                                ))}
                            </select>

                            <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2">
                                {organizationId ? (
                                    <a href={templateHref('xlsx')} className={buttonClassName({ variant: 'primary' })}>
                                        <DownloadIcon className="h-4 w-4" />
                                        {t('employees.import.downloadExcel')}
                                    </a>
                                ) : (
                                    <button type="button" disabled className={buttonClassName({ variant: 'primary' })}>
                                        <DownloadIcon className="h-4 w-4" />
                                        {t('employees.import.downloadExcel')}
                                    </button>
                                )}
                                {organizationId && (
                                    <a href={templateHref('csv')} className="text-sm font-medium text-primary underline-offset-2 hover:underline">
                                        {t('employees.import.downloadCsv')}
                                    </a>
                                )}
                            </div>

                            <ul className="mt-4 max-w-3xl list-disc space-y-1.5 pl-5 text-sm leading-6 text-(--app-muted-foreground)">
                                <li>{t('employees.import.tipRows')}</li>
                                <li>{t('employees.import.tipKeep')}</li>
                                <li>{t('employees.import.tipValues')}</li>
                                <li>{t('employees.import.tipNumbers')}</li>
                            </ul>
                        </>
                    )}
                </StepCard>

                {can.upload && (
                    <StepCard number={2} title={t('employees.import.step2Title')} description={t('employees.import.step2Description')}>
                        <form onSubmit={submit}>
                            <input
                                ref={fileInput}
                                id="file"
                                type="file"
                                accept=".xlsx,.csv"
                                onChange={(event) => chooseFile(event.target.files?.[0] ?? null)}
                                className="sr-only"
                                aria-describedby={form.errors.file ? 'file-error' : undefined}
                            />
                            <label
                                htmlFor="file"
                                onDragEnter={() => setDragging(true)}
                                onDragLeave={() => setDragging(false)}
                                onDragOver={(event) => event.preventDefault()}
                                onDrop={(event) => {
                                    event.preventDefault();
                                    setDragging(false);
                                    chooseFile(event.dataTransfer.files?.[0] ?? null);
                                }}
                                className={cx(
                                    'flex max-w-3xl cursor-pointer flex-col items-center justify-center rounded-panel border-2 border-dashed px-5 py-7 text-center transition focus-within:border-primary',
                                    dragging
                                        ? 'border-primary bg-primary-50 dark:bg-primary-950/20'
                                        : form.errors.file
                                            ? 'border-red-300 bg-red-50/60 dark:border-red-800 dark:bg-red-950/20'
                                            : form.data.file
                                                ? 'border-emerald-300 bg-emerald-50/60 dark:border-emerald-700 dark:bg-emerald-950/20'
                                                : 'border-(--app-border-strong) bg-(--app-surface-muted) hover:border-primary',
                                )}
                            >
                                {form.data.file ? <FileSpreadsheetIcon className="h-8 w-8 text-(--app-muted-foreground)" /> : <UploadIcon className="h-8 w-8 text-(--app-muted-foreground)" />}
                                <span className="mt-2 max-w-full truncate text-sm font-semibold text-(--app-foreground)">
                                    {form.data.file?.name ?? t('employees.import.dropTitle')}
                                </span>
                                <span className="mt-1 text-xs text-(--app-muted-foreground)">
                                    {form.data.file ? `${formatFileSize(form.data.file.size)} · ${t('employees.import.changeFile')}` : t('employees.import.dropHint')}
                                </span>
                            </label>

                            {form.errors.file && (
                                <p id="file-error" role="alert" className="mt-2 flex items-start gap-2 text-sm text-red-600 dark:text-red-400">
                                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                    <span>{form.errors.file}</span>
                                </p>
                            )}

                            <div className="mt-4 sm:max-w-xl">
                                <label htmlFor="calendar" className="mb-1.5 block text-sm font-medium text-(--app-foreground)">
                                    {t('employees.import.calendarLabel')}
                                </label>
                                <select
                                    id="calendar"
                                    value={form.data.calendar}
                                    onChange={(event) => form.setData('calendar', event.target.value as Calendar)}
                                    className={cx(selectClassName, 'w-full')}
                                >
                                    <option value="ethiopian">{t('employees.import.calendarEthiopian')}</option>
                                    <option value="gregorian">{t('employees.import.calendarGregorian')}</option>
                                </select>
                                <p className="mt-1.5 text-xs leading-5 text-(--app-muted-foreground)">{t('employees.import.calendarHelp')}</p>
                            </div>

                            <button type="submit" disabled={form.processing || !form.data.file || fileBlocked} className={buttonClassName({ variant: 'primary', className: 'mt-4' })}>
                                {form.processing ? <Spinner /> : <CheckReadIcon className="h-4 w-4" />}
                                {form.processing ? t('employees.import.checking') : t('employees.import.check')}
                            </button>
                            <p className="mt-2 text-xs text-(--app-muted-foreground)">{t('employees.import.checkHelp')}</p>
                        </form>
                    </StepCard>
                )}

                <StepCard
                    ref={result}
                    number={3}
                    title={batch?.finished ? t('employees.import.resultTitle') : t('employees.import.step3Title')}
                    description={batch ? batch.file_name : t('employees.import.step3Empty')}
                    muted={batch === null}
                >
                    {batch && (
                        <div className="space-y-5">
                            {batch.finished && (
                                <Alert tone={batch.imported_rows > 0 ? 'success' : 'warning'} title={fill(t('employees.import.importedTitle'), { count: batch.imported_rows })}>
                                    {batch.failed_rows > 0 ? fill(t('employees.import.importedWithLeftOut'), { count: batch.failed_rows }) : t('employees.import.importedAll')}
                                </Alert>
                            )}

                            <div className="grid gap-3 sm:grid-cols-3">
                                {batch.finished
                                    ? <Count label={t('employees.import.countImported')} value={batch.imported_rows} tone="success" />
                                    : <Count label={t('employees.import.countReady')} value={batch.valid_rows} tone="success" />}
                                <Count label={batch.finished ? t('employees.import.countNotImported') : t('employees.import.countToFix')} value={batch.failed_rows} tone={batch.failed_rows > 0 ? 'danger' : 'neutral'} />
                                <Count label={t('employees.import.countEmpty')} value={batch.unfilled_rows} tone="neutral" hint={t('employees.import.countEmptyHint')} />
                            </div>

                            <p className="text-xs text-(--app-muted-foreground)">
                                {fill(t('employees.import.calendarUsed'), {
                                    calendar: batch.calendar === 'ethiopian' ? t('employees.import.calendarEthiopian') : t('employees.import.calendarGregorian'),
                                })}
                            </p>

                            {batch.over_limit_rows > 0 && (
                                <Alert tone="warning" title={t('employees.import.overLimitTitle')}>
                                    {fill(t('employees.import.overLimitBody'), { max: maxRows, skipped: batch.over_limit_rows })}
                                </Alert>
                            )}

                            {!batch.finished && batch.valid_rows === 0 && batch.failed_rows === 0 && (
                                <Alert tone="warning" title={t('employees.import.nothingFilledTitle')}>{t('employees.import.nothingFilledBody')}</Alert>
                            )}

                            {failedRows.length > 0 && (
                                <section aria-labelledby="rows-to-fix">
                                    <div className="flex flex-wrap items-end justify-between gap-3">
                                        <div>
                                            <h3 id="rows-to-fix" className="text-sm font-semibold text-(--app-foreground)">
                                                {fill(t('employees.import.toFixTitle'), { count: failedRows.length })}
                                            </h3>
                                            <p className="mt-1 max-w-2xl text-sm leading-6 text-(--app-muted-foreground)">
                                                {batch.finished || batch.valid_rows === 0 ? t('employees.import.toFixHelpOnly') : t('employees.import.toFixHelp')}
                                            </p>
                                        </div>
                                        <a href={route('employees.import.errors', batch.id)} className={buttonClassName({ variant: 'outline' })}>
                                            <DownloadIcon className="h-4 w-4" />
                                            {t('employees.import.downloadToFix')}
                                        </a>
                                    </div>
                                    <div className="mt-3 overflow-x-auto rounded-panel border border-(--app-border)">
                                        <TableTools id="employees-import-to-fix" toolbar={false}>
                                            <table className={cx(tableClassName, 'min-w-full')}>
                                                <thead className={tableHeadClassName}>
                                                    <tr>
                                                        <HeadCell>{t('employees.import.columnRow')}</HeadCell>
                                                        <HeadCell>{t('employees.employee')}</HeadCell>
                                                        <HeadCell>{t('employees.import.columnProblem')}</HeadCell>
                                                    </tr>
                                                </thead>
                                                <tbody className={tableBodyClassName}>
                                                    {failedRows.map((row) => (
                                                        <tr key={row.row_number} className="align-top">
                                                            <td className={cx(tableCellClassName, 'whitespace-nowrap font-medium tabular-nums text-(--app-foreground)')}>{row.row_number}</td>
                                                            <td className={cx(tableCellClassName, 'min-w-40 text-(--app-foreground)')}>
                                                                <div>{row.name || '—'}</div>
                                                                {row.position && <div className="mt-0.5 text-xs text-(--app-muted-foreground)">{row.position}</div>}
                                                            </td>
                                                            <td className={tableCellClassName}>
                                                                <ul className="space-y-1.5 text-sm text-red-700 dark:text-red-300">
                                                                    {row.errors.map((error, index) => <li key={index}>{error}</li>)}
                                                                </ul>
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </TableTools>
                                    </div>
                                </section>
                            )}

                            {!batch.finished && readyRows.length > 0 && (
                                <details open={failedRows.length === 0} className="group">
                                    <summary className="cursor-pointer text-sm font-semibold text-(--app-foreground)">
                                        {fill(t('employees.import.readyTitle'), { count: readyRows.length })}
                                    </summary>
                                    <div className="mt-3 overflow-x-auto rounded-panel border border-(--app-border)">
                                        <TableTools id="employees-import-ready" toolbar={false}>
                                            <table className={cx(tableClassName, 'min-w-full')}>
                                                <thead className={tableHeadClassName}>
                                                    <tr>
                                                        <HeadCell>{t('employees.import.columnRow')}</HeadCell>
                                                        <HeadCell>{t('employees.employee')}</HeadCell>
                                                        <HeadCell>{t('employees.position')}</HeadCell>
                                                        <HeadCell className="hidden md:table-cell">{t('employees.organizationUnit')}</HeadCell>
                                                        <HeadCell>{t('employees.import.columnStartDate')}</HeadCell>
                                                        <HeadCell className="hidden md:table-cell">{t('employees.employeeNumber')}</HeadCell>
                                                    </tr>
                                                </thead>
                                                <tbody className={tableBodyClassName}>
                                                    {readyRows.map((row) => (
                                                        <tr key={row.row_number}>
                                                            <td className={cx(tableCellClassName, 'tabular-nums text-(--app-muted-foreground)')}>{row.row_number}</td>
                                                            <td className={cx(tableCellClassName, 'font-medium text-(--app-foreground)')}>{row.name}</td>
                                                            <td className={cx(tableCellClassName, 'text-(--app-foreground)')}>{row.position ?? '—'}</td>
                                                            <td className={cx(tableCellClassName, 'hidden text-(--app-muted-foreground) md:table-cell')}>{row.organization_unit ?? '—'}</td>
                                                            <td className={cx(tableCellClassName, 'whitespace-nowrap text-(--app-foreground)')}>
                                                                <LocalizedDateDisplay value={row.start_date} fallback={t('employees.import.startsToday')} />
                                                            </td>
                                                            <td className={cx(tableCellClassName, 'hidden whitespace-nowrap text-(--app-muted-foreground) md:table-cell')}>
                                                                {row.employee_number ?? t('employees.import.numberOnImport')}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </TableTools>
                                    </div>
                                </details>
                            )}

                            <div className="flex flex-col gap-3 border-t border-(--app-border) pt-4 sm:flex-row sm:items-center">
                                {batch.finished ? (
                                    <>
                                        {can.viewEmployees && (
                                            <Link href={route('employees.index')} className={buttonClassName({ variant: 'primary' })}>
                                                {t('employees.import.viewEmployees')}
                                            </Link>
                                        )}
                                        <button type="button" onClick={startOver} className={buttonClassName({ variant: 'outline' })}>
                                            {t('employees.import.importAnother')}
                                        </button>
                                    </>
                                ) : (
                                    <>
                                        {can.confirm && batch.importable && (
                                            <button type="button" onClick={confirmImport} disabled={importing} className={buttonClassName({ variant: 'primary' })}>
                                                {importing ? <Spinner /> : <CheckReadIcon className="h-4 w-4" />}
                                                {importing ? t('employees.import.importing') : fill(t('employees.import.importButton'), { count: batch.valid_rows })}
                                            </button>
                                        )}
                                        <button type="button" onClick={startOver} disabled={importing} className={buttonClassName({ variant: 'outline' })}>
                                            {t('employees.import.discard')}
                                        </button>
                                        {batch.importable && !can.confirm && (
                                            <p className="text-sm text-(--app-muted-foreground)">{t('employees.import.cannotConfirm')}</p>
                                        )}
                                    </>
                                )}
                            </div>
                        </div>
                    )}
                </StepCard>
            </div>
        </AuthenticatedLayout>
    );
}

type StepCardProps = {
    number: number;
    title: string;
    description: string;
    children?: ReactNode;
    muted?: boolean;
    ref?: React.Ref<HTMLElement>;
};

function StepCard({ number, title, description, children, muted = false, ref }: StepCardProps): JSX.Element {
    return (
        <section ref={ref} className="scroll-mt-24 rounded-panel border border-(--app-border) bg-(--app-surface) p-5 sm:p-6">
            <div className="flex items-start gap-3">
                <span className={cx(
                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold',
                    muted ? 'bg-(--app-surface-muted) text-(--app-muted-foreground)' : 'bg-primary text-white',
                )}>
                    {number}
                </span>
                <div className="min-w-0">
                    <h2 className="text-base font-semibold text-(--app-foreground)">{title}</h2>
                    <p className="mt-0.5 wrap-break-word text-sm text-(--app-muted-foreground)">{description}</p>
                </div>
            </div>
            {children && <div className="mt-5">{children}</div>}
        </section>
    );
}

function Count({ label, value, tone, hint }: { label: string; value: number; tone: 'neutral' | 'success' | 'danger'; hint?: string }): JSX.Element {
    const colors = {
        neutral: 'text-(--app-foreground)',
        success: 'text-emerald-600 dark:text-emerald-400',
        danger: 'text-red-600 dark:text-red-400',
    };

    return (
        <div className="rounded-control border border-(--app-border) bg-(--app-surface-muted) px-4 py-3">
            <p className={cx('text-2xl font-bold tabular-nums', colors[tone])}>{value}</p>
            <p className="mt-0.5 text-sm text-(--app-foreground)">{label}</p>
            {hint && <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{hint}</p>}
        </div>
    );
}

function HeadCell({ children, className }: { children: ReactNode; className?: string }): JSX.Element {
    return <th className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left', className)}>{children}</th>;
}

/** A busy mark drawn with borders, so it needs no icon. */
function Spinner(): JSX.Element {
    return <span aria-hidden="true" className="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />;
}

function formatFileSize(bytes: number): string {
    return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
