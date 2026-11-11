import { FormEvent, useEffect, useRef, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import type { JSX } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { AlertTriangle, CalendarIcon, CheckReadIcon, DownloadIcon, FileSpreadsheetIcon, FileTextIcon, RefreshIcon, Users } from '@/Components/NavIcons';
import {
    fill,
    FileDropZone,
    GuidePanel,
    HeadCell,
    ImportProgress,
    ImportSteps,
    ResultBanner,
    ResultTabs,
    Spinner,
    SplitBar,
    StepSection,
    TemplateChooser,
} from '@/Components/ImportFlow';
import type { ResultTab, StepState, TemplateOrganization } from '@/Components/ImportFlow';
import { useLocale } from '@/hooks/useLocale';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import {
    Alert,
    AppMetricCard,
    buttonClassName,
    cx,
    selectClassName,
    tableBodyClassName,
    tableCellClassName,
    tableClassName,
    tableHeadClassName,
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
    /** Checked rows still waiting to be imported. */
    remaining_rows: number;
    calendar: Calendar;
    status: string;
    importable: boolean;
    finished: boolean;
    /** An import started and rows still wait: the next pass is due, or it was interrupted. */
    in_progress: boolean;
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

type Organization = TemplateOrganization;

type Props = {
    batch: Batch | null;
    rows: Row[];
    maxRows: number;
    /** The largest file the server takes: the importer's cap or the server's upload limit, whichever is smaller. */
    maxFileBytes: number;
    organizations: Organization[];
    can: { upload: boolean; confirm: boolean; viewEmployees: boolean };
};

type ListKey = 'fix' | 'ready' | 'imported';

export default function ImportCsv({ batch, rows, maxRows, maxFileBytes, organizations, can }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';
    // 5 MB reads "5", 1.5 MB reads "1.5".
    const maxFileMegabytes = String(Math.round((maxFileBytes / 1024 / 1024) * 10) / 10);
    const displayCalendar = useCalendarSystem() as Calendar;
    const result = useRef<HTMLElement>(null);
    const [importing, setImporting] = useState(false);
    const [list, setList] = useState<ListKey | null>(null);
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

    // A new file, or the end of an import, starts on the list that matters most again.
    useEffect(() => setList(null), [batch?.id, batch?.finished]);

    const chooseFile = (file: File | null) => {
        form.clearErrors('file');
        form.setData('file', file);

        if (file === null) return;

        // Said at once, before a large or unusable file is sent.
        if (!/\.(xlsx|csv)$/i.test(file.name)) {
            form.setError('file', t('employees.import.fileTypeError'));
        } else if (file.size > maxFileBytes) {
            form.setError('file', fill(t('employees.import.fileSizeError'), { max: maxFileMegabytes }));
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('employees.import.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.reset('file'),
        });
    };

    /*
     * Registering an employee takes the server many steps, so a large file is
     * imported in passes of a few seconds each. Every pass returns the page
     * with the batch as it now stands; while rows still wait, the next pass
     * is sent. A pass that fails stops the loop and leaves the batch ready to
     * be continued.
     */
    const confirmImport = () => {
        if (!batch) return;
        setImporting(true);
        let waiting = Number.POSITIVE_INFINITY;

        const pass = () => {
            let again = false;

            router.post(route('employees.import.confirm', batch.id), {}, {
                preserveScroll: true,
                onSuccess: (page) => {
                    const next = (page.props as unknown as Props).batch;
                    const flash = (page.props as { flash?: { type?: string } }).flash;
                    // Carry on only while each pass leaves fewer rows waiting than the one before.
                    again = Boolean(next && next.id === batch.id && next.in_progress && next.remaining_rows > 0 && next.remaining_rows < waiting && flash?.type !== 'error');
                    waiting = next?.remaining_rows ?? 0;
                },
                onFinish: () => {
                    if (again) pass();
                    else setImporting(false);
                },
            });
        };

        pass();
    };

    const startOver = () => router.post(route('employees.import.cancel'));

    // While the passes run the server sends the counts only; the lists come back with the last pass.
    const failedRows = importing ? [] : rows.filter((row) => row.status === 'invalid');
    const readyRows = importing ? [] : rows.filter((row) => row.status === 'valid');
    const importedRows = importing ? [] : rows.filter((row) => row.status === 'imported');
    const fileBlocked = Boolean(form.errors.file) && form.data.file !== null && (!/\.(xlsx|csv)$/i.test(form.data.file.name) || form.data.file.size > maxFileBytes);
    // An import that has started: the counts speak of what is imported and what still waits.
    const started = Boolean(batch && (batch.finished || batch.in_progress || importing));
    const importTotal = batch ? batch.imported_rows + batch.remaining_rows : 0;

    const tabs: ResultTab<ListKey>[] = [
        { key: 'fix' as const, label: batch?.finished ? t('employees.import.countNotImported') : t('employees.import.countToFix'), count: failedRows.length, tone: 'danger' as const },
        { key: 'ready' as const, label: t('employees.import.countReady'), count: readyRows.length, tone: 'success' as const },
        { key: 'imported' as const, label: t('employees.import.countImported'), count: importedRows.length, tone: 'success' as const },
    ].filter((tab) => tab.count > 0);
    const activeList = tabs.find((tab) => tab.key === list)?.key ?? tabs[0]?.key ?? null;

    const stepStates: StepState[] = [
        batch || form.data.file ? 'done' : 'current',
        batch ? 'done' : form.data.file ? 'current' : 'upcoming',
        batch?.finished ? 'done' : batch ? 'current' : 'upcoming',
    ];

    return (
        <AuthenticatedLayout header={<PageHeader
                    title={t('employees.import.title')}
                    description={t('employees.import.description')}
                    backHref={route('employees.index')}
                />}>
            <Head title={t('employees.import.title')} />

            <div className="space-y-5">
                <ImportSteps
                    label={t('employees.import.title')}
                    steps={[
                        { title: t('employees.import.stepShort1'), state: stepStates[0] },
                        { title: t('employees.import.stepShort2'), state: stepStates[1] },
                        { title: t('employees.import.stepShort3'), state: stepStates[2] },
                    ]}
                />

                <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_21rem] lg:grid-rows-[auto_1fr] lg:items-start">
                    <StepSection number={1} state={stepStates[0]} title={t('employees.import.step1Title')} description={t('employees.import.step1Description')} className="lg:col-start-1">
                        {organizations.length === 0 ? (
                            <Alert tone="warning">{t('employees.import.noOrganizations')}</Alert>
                        ) : (
                            <TemplateChooser
                                organizations={organizations}
                                amharic={am}
                                labels={{
                                    organization: t('employees.import.organization'),
                                    selectOrganization: t('employees.import.selectOrganization'),
                                    unit: t('employees.import.unit'),
                                    allUnits: t('employees.import.allUnits'),
                                    unitHelp: t('employees.import.unitHelp'),
                                    unitsLoading: t('employees.import.unitsLoading'),
                                    unitsFailed: t('employees.import.unitsFailed'),
                                    downloadExcel: t('employees.import.downloadExcel'),
                                    downloadCsv: t('employees.import.downloadCsv'),
                                }}
                                hrefFor={(format, organizationId, unitId) => route('employees.import.template', {
                                    organization_id: organizationId,
                                    locale,
                                    format,
                                    ...(unitId ? { organization_unit_id: unitId } : {}),
                                })}
                            />
                        )}
                    </StepSection>

                    <GuidePanel
                        className={cx('lg:col-start-2 lg:row-start-1', can.upload && 'lg:row-span-2')}
                        title={t('employees.import.guideTitle')}
                        tips={[
                            t('employees.import.tipRows'),
                            t('employees.import.tipKeep'),
                            t('employees.import.tipValues'),
                            t('employees.import.tipDetails'),
                            t('employees.import.tipNumbers'),
                        ]}
                        footer={fill(t('employees.import.limitsNote'), { rows: maxRows.toLocaleString(), max: maxFileMegabytes })}
                    />

                    {can.upload && (
                        <StepSection number={2} state={stepStates[1]} title={t('employees.import.step2Title')} description={t('employees.import.step2Description')} className="lg:col-start-1">
                            <form onSubmit={submit}>
                                <FileDropZone
                                    id="file"
                                    file={form.data.file}
                                    error={form.errors.file}
                                    title={t('employees.import.dropTitle')}
                                    hint={fill(t('employees.import.dropHint'), { max: maxFileMegabytes })}
                                    changeLabel={t('employees.import.changeFile')}
                                    removeLabel={t('employees.import.removeFile')}
                                    onChoose={chooseFile}
                                />

                                <div className="mt-5 rounded-card border border-(--app-border) bg-(--app-surface-muted) p-4">
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start">
                                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-control bg-(--app-surface) text-primary ring-1 ring-(--app-border)">
                                            <CalendarIcon className="h-5 w-5" aria-hidden="true" />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <label htmlFor="calendar" className="block text-sm font-medium text-(--app-foreground)">
                                                {t('employees.import.calendarLabel')}
                                            </label>
                                            <p className="mt-0.5 text-xs leading-5 text-(--app-muted-foreground)">{t('employees.import.calendarHelp')}</p>
                                            <select
                                                id="calendar"
                                                value={form.data.calendar}
                                                onChange={(event) => form.setData('calendar', event.target.value as Calendar)}
                                                className={cx(selectClassName, 'mt-2.5 w-full sm:max-w-xs')}
                                            >
                                                <option value="ethiopian">{t('employees.import.calendarEthiopian')}</option>
                                                <option value="gregorian">{t('employees.import.calendarGregorian')}</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2">
                                    <button type="submit" disabled={form.processing || !form.data.file || fileBlocked} className={buttonClassName({ variant: 'primary' })}>
                                        {form.processing ? <Spinner /> : <CheckReadIcon className="h-4 w-4" />}
                                        {form.processing ? t('employees.import.checking') : t('employees.import.check')}
                                    </button>
                                    <p className="text-sm text-(--app-muted-foreground)">{t('employees.import.checkHelp')}</p>
                                </div>
                            </form>
                        </StepSection>
                    )}
                </div>

                <StepSection
                    ref={result}
                    number={3}
                    state={stepStates[2]}
                    title={batch?.finished ? t('employees.import.resultTitle') : t('employees.import.step3Title')}
                    description={batch ? (
                        <span className="inline-flex max-w-full flex-wrap items-center gap-x-3 gap-y-1">
                            <span className="inline-flex min-w-0 items-center gap-1.5">
                                <FileSpreadsheetIcon className="h-4 w-4 shrink-0" aria-hidden="true" />
                                <span className="truncate">{batch.file_name}</span>
                            </span>
                            <span className="inline-flex items-center gap-1.5">
                                <CalendarIcon className="h-4 w-4 shrink-0" aria-hidden="true" />
                                {fill(t('employees.import.calendarUsed'), {
                                    calendar: batch.calendar === 'ethiopian' ? t('employees.import.calendarEthiopian') : t('employees.import.calendarGregorian'),
                                })}
                            </span>
                        </span>
                    ) : t('employees.import.step3Empty')}
                >
                    {batch && (
                        <div className="space-y-5">
                            {batch.finished && (
                                <ResultBanner tone={batch.imported_rows > 0 ? 'success' : 'warning'} title={fill(t('employees.import.importedTitle'), { count: batch.imported_rows })}>
                                    {batch.failed_rows > 0 ? fill(t('employees.import.importedWithLeftOut'), { count: batch.failed_rows }) : t('employees.import.importedAll')}
                                </ResultBanner>
                            )}

                            {importing && (
                                <ImportProgress
                                    label={fill(t('employees.import.progress'), { done: batch.imported_rows, total: importTotal })}
                                    note={t('employees.import.keepOpen')}
                                    barLabel={t('employees.import.importing')}
                                    done={batch.imported_rows}
                                    total={importTotal}
                                />
                            )}

                            {batch.in_progress && !importing && (
                                <Alert tone="warning" title={t('employees.import.interruptedTitle')}>
                                    {fill(t('employees.import.interruptedBody'), { imported: batch.imported_rows, remaining: batch.remaining_rows })}
                                </Alert>
                            )}

                            <div className="space-y-3">
                                <SplitBar
                                    parts={[
                                        { key: 'done', value: started ? batch.imported_rows : batch.valid_rows, className: 'bg-emerald-500' },
                                        { key: 'waiting', value: started ? batch.remaining_rows : 0, className: 'bg-primary' },
                                        { key: 'failed', value: batch.failed_rows, className: 'bg-red-500' },
                                        { key: 'empty', value: batch.unfilled_rows, className: 'bg-(--app-border-strong)' },
                                    ]}
                                />
                                <div className="grid gap-3 sm:grid-cols-3">
                                    <AppMetricCard
                                        label={started ? t('employees.import.countImported') : t('employees.import.countReady')}
                                        value={started ? batch.imported_rows : batch.valid_rows}
                                        variant="success"
                                        icon={started ? <Users className="h-5 w-5" /> : <CheckReadIcon className="h-5 w-5" />}
                                    />
                                    <AppMetricCard
                                        label={batch.finished ? t('employees.import.countNotImported') : t('employees.import.countToFix')}
                                        value={batch.failed_rows}
                                        variant={batch.failed_rows > 0 ? 'danger' : 'neutral'}
                                        icon={<AlertTriangle className="h-5 w-5" />}
                                    />
                                    <AppMetricCard
                                        label={t('employees.import.countEmpty')}
                                        value={batch.unfilled_rows}
                                        variant="neutral"
                                        icon={<FileTextIcon className="h-5 w-5" />}
                                        detail={<span className="text-xs text-(--app-muted-foreground)">{t('employees.import.countEmptyHint')}</span>}
                                    />
                                </div>
                            </div>

                            {batch.over_limit_rows > 0 && (
                                <Alert tone="warning" title={t('employees.import.overLimitTitle')}>
                                    {fill(t('employees.import.overLimitBody'), { max: maxRows, skipped: batch.over_limit_rows })}
                                </Alert>
                            )}

                            {!batch.finished && batch.valid_rows === 0 && batch.failed_rows === 0 && (
                                <Alert tone="warning" title={t('employees.import.nothingFilledTitle')}>{t('employees.import.nothingFilledBody')}</Alert>
                            )}

                            {activeList && (
                                <div>
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <ResultTabs id="employees-import-lists" label={t('employees.import.step3Title')} tabs={tabs} active={activeList} onChange={setList} />
                                        {activeList === 'fix' && (
                                            <a href={route('employees.import.errors', batch.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                                <DownloadIcon className="h-4 w-4" />
                                                {t('employees.import.downloadToFix')}
                                            </a>
                                        )}
                                    </div>

                                    <div id="employees-import-lists-panel" role="tabpanel" aria-labelledby={`employees-import-lists-tab-${activeList}`} className="mt-3">
                                        {activeList === 'fix' && (
                                            <>
                                                <p className="mb-3 max-w-3xl text-sm leading-6 text-(--app-muted-foreground)">
                                                    {batch.finished || batch.valid_rows === 0 ? t('employees.import.toFixHelpOnly') : t('employees.import.toFixHelp')}
                                                </p>
                                                <div className="overflow-x-auto rounded-card border border-(--app-border)">
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
                                                                                {row.errors.map((error, index) => (
                                                                                    <li key={index} className="flex items-start gap-2">
                                                                                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                                                                                        <span>{error}</span>
                                                                                    </li>
                                                                                ))}
                                                                            </ul>
                                                                        </td>
                                                                    </tr>
                                                                ))}
                                                            </tbody>
                                                        </table>
                                                    </TableTools>
                                                </div>
                                            </>
                                        )}

                                        {activeList === 'ready' && (
                                            <div className="overflow-x-auto rounded-card border border-(--app-border)">
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
                                        )}

                                        {activeList === 'imported' && (
                                            <div className="overflow-x-auto rounded-card border border-(--app-border)">
                                                <TableTools id="employees-import-imported" toolbar={false}>
                                                    <table className={cx(tableClassName, 'min-w-full')}>
                                                        <thead className={tableHeadClassName}>
                                                            <tr>
                                                                <HeadCell>{t('employees.import.columnRow')}</HeadCell>
                                                                <HeadCell>{t('employees.employeeNumber')}</HeadCell>
                                                                <HeadCell>{t('employees.employee')}</HeadCell>
                                                                <HeadCell>{t('employees.position')}</HeadCell>
                                                                <HeadCell className="hidden md:table-cell">{t('employees.organizationUnit')}</HeadCell>
                                                            </tr>
                                                        </thead>
                                                        <tbody className={tableBodyClassName}>
                                                            {importedRows.map((row) => (
                                                                <tr key={row.row_number}>
                                                                    <td className={cx(tableCellClassName, 'tabular-nums text-(--app-muted-foreground)')}>{row.row_number}</td>
                                                                    <td className={cx(tableCellClassName, 'whitespace-nowrap font-medium tabular-nums text-(--app-foreground)')}>{row.employee_number ?? '—'}</td>
                                                                    <td className={cx(tableCellClassName, 'text-(--app-foreground)')}>{row.name}</td>
                                                                    <td className={cx(tableCellClassName, 'text-(--app-foreground)')}>{row.position ?? '—'}</td>
                                                                    <td className={cx(tableCellClassName, 'hidden text-(--app-muted-foreground) md:table-cell')}>{row.organization_unit ?? '—'}</td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                </TableTools>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}

                            <div className="flex flex-col gap-3 border-t border-(--app-border) pt-4 sm:flex-row sm:items-center">
                                {batch.finished ? (
                                    <>
                                        {can.viewEmployees && (
                                            <Link href={route('employees.index')} className={buttonClassName({ variant: 'primary' })}>
                                                <Users className="h-4 w-4" />
                                                {t('employees.import.viewEmployees')}
                                            </Link>
                                        )}
                                        <button type="button" onClick={startOver} className={buttonClassName({ variant: 'outline' })}>
                                            <RefreshIcon className="h-4 w-4" />
                                            {t('employees.import.importAnother')}
                                        </button>
                                    </>
                                ) : (
                                    <>
                                        {can.confirm && batch.importable && (
                                            <button type="button" onClick={confirmImport} disabled={importing} className={buttonClassName({ variant: 'primary' })}>
                                                {importing ? <Spinner /> : <CheckReadIcon className="h-4 w-4" />}
                                                {importing
                                                    ? t('employees.import.importing')
                                                    : batch.in_progress
                                                        ? fill(t('employees.import.continueButton'), { count: batch.remaining_rows })
                                                        : fill(t('employees.import.importButton'), { count: batch.valid_rows })}
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
                </StepSection>
            </div>
        </AuthenticatedLayout>
    );
}
