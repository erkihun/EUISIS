import { FormEvent, useEffect, useRef, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import type { JSX } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { AlertTriangle, Briefcase, CheckReadIcon, DownloadIcon, FileSpreadsheetIcon, FileTextIcon, RefreshIcon } from '@/Components/NavIcons';
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
import {
    Alert,
    AppMetricCard,
    badgeClassName,
    buttonClassName,
    cx,
    tableBodyClassName,
    tableCellClassName,
    tableClassName,
    tableHeadClassName,
    TableTools,
} from '@/Components/ui';

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
    /** Ready rows whose title a position in the same unit already carries. */
    existing_rows: number;
    status: string;
    importable: boolean;
    finished: boolean;
    /** An import started and rows still wait: the next pass is due, or it was interrupted. */
    in_progress: boolean;
};

type Row = {
    row_number: number;
    title: string | null;
    organization: string | null;
    organization_unit: string | null;
    occupation: string | null;
    grade_level: string | null;
    is_unit_head: boolean;
    position_code: string | null;
    status: 'valid' | 'invalid' | 'imported';
    already_exists: boolean;
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
};

type ListKey = 'fix' | 'ready' | 'imported';

export default function Import({ batch, rows, maxRows, maxFileBytes, organizations }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';
    // 5 MB reads "5", 1.5 MB reads "1.5".
    const maxFileMegabytes = String(Math.round((maxFileBytes / 1024 / 1024) * 10) / 10);
    const result = useRef<HTMLElement>(null);
    const [importing, setImporting] = useState(false);
    const [list, setList] = useState<ListKey | null>(null);
    const form = useForm<{ file: File | null }>({ file: null });

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
            form.setError('file', t('positions.import.fileTypeError'));
        } else if (file.size > maxFileBytes) {
            form.setError('file', fill(t('positions.import.fileSizeError'), { max: maxFileMegabytes }));
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('positions.import.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.reset('file'),
        });
    };

    /*
     * A position takes the server many steps to number and record, so a large
     * file is imported in passes of a few seconds each. Every pass returns the
     * page with the batch as it now stands; while rows still wait, the next
     * pass is sent. A pass that fails stops the loop and leaves the batch
     * ready to be continued.
     */
    const confirmImport = () => {
        if (!batch) return;
        setImporting(true);
        let waiting = Number.POSITIVE_INFINITY;

        const pass = () => {
            let again = false;

            router.post(route('positions.import.confirm', batch.id), {}, {
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

    const startOver = () => router.post(route('positions.import.cancel'));

    // While the passes run the server sends the counts only; the lists come back with the last pass.
    const failedRows = importing ? [] : rows.filter((row) => row.status === 'invalid');
    const readyRows = importing ? [] : rows.filter((row) => row.status === 'valid');
    const importedRows = importing ? [] : rows.filter((row) => row.status === 'imported');
    const fileBlocked = Boolean(form.errors.file) && form.data.file !== null && (!/\.(xlsx|csv)$/i.test(form.data.file.name) || form.data.file.size > maxFileBytes);
    // An import that has started: the counts speak of what is imported and what still waits.
    const started = Boolean(batch && (batch.finished || batch.in_progress || importing));
    const importTotal = batch ? batch.imported_rows + batch.remaining_rows : 0;

    const tabs: ResultTab<ListKey>[] = [
        { key: 'fix' as const, label: batch?.finished ? t('positions.import.countNotImported') : t('positions.import.countToFix'), count: failedRows.length, tone: 'danger' as const },
        { key: 'ready' as const, label: t('positions.import.countReady'), count: readyRows.length, tone: 'success' as const },
        { key: 'imported' as const, label: t('positions.import.countImported'), count: importedRows.length, tone: 'success' as const },
    ].filter((tab) => tab.count > 0);
    const activeList = tabs.find((tab) => tab.key === list)?.key ?? tabs[0]?.key ?? null;

    const stepStates: StepState[] = [
        batch || form.data.file ? 'done' : 'current',
        batch ? 'done' : form.data.file ? 'current' : 'upcoming',
        batch?.finished ? 'done' : batch ? 'current' : 'upcoming',
    ];

    return (
        <AuthenticatedLayout header={<PageHeader
                    title={t('positions.import.title')}
                    description={t('positions.import.description')}
                    backHref={route('positions.index')}
                />}>
            <Head title={t('positions.import.title')} />

            <div className="space-y-5">
                <ImportSteps
                    label={t('positions.import.title')}
                    steps={[
                        { title: t('positions.import.stepShort1'), state: stepStates[0] },
                        { title: t('positions.import.stepShort2'), state: stepStates[1] },
                        { title: t('positions.import.stepShort3'), state: stepStates[2] },
                    ]}
                />

                <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_21rem] lg:grid-rows-[auto_1fr] lg:items-start">
                    <StepSection number={1} state={stepStates[0]} title={t('positions.import.step1Title')} description={t('positions.import.step1Description')} className="lg:col-start-1">
                        {organizations.length === 0 ? (
                            <Alert tone="warning">{t('positions.import.noOrganizations')}</Alert>
                        ) : (
                            <TemplateChooser
                                organizations={organizations}
                                amharic={am}
                                labels={{
                                    organization: t('positions.import.organization'),
                                    selectOrganization: t('positions.import.selectOrganization'),
                                    unit: t('positions.import.unit'),
                                    allUnits: t('positions.import.allUnits'),
                                    unitHelp: t('positions.import.unitHelp'),
                                    unitsLoading: t('positions.import.unitsLoading'),
                                    unitsFailed: t('positions.import.unitsFailed'),
                                    downloadExcel: t('positions.import.downloadExcel'),
                                    downloadCsv: t('positions.import.downloadCsv'),
                                }}
                                hrefFor={(format, organizationId, unitId) => route('positions.import.template', {
                                    organization_id: organizationId,
                                    locale,
                                    format,
                                    ...(unitId ? { organization_unit_id: unitId } : {}),
                                })}
                            />
                        )}
                    </StepSection>

                    <GuidePanel
                        className="lg:col-start-2 lg:row-span-2 lg:row-start-1"
                        title={t('positions.import.guideTitle')}
                        tips={[
                            t('positions.import.tipRows'),
                            t('positions.import.tipKeep'),
                            t('positions.import.tipValues'),
                            t('positions.import.tipNumbers'),
                        ]}
                        footer={fill(t('positions.import.limitsNote'), { rows: maxRows.toLocaleString(), max: maxFileMegabytes })}
                    />

                    <StepSection number={2} state={stepStates[1]} title={t('positions.import.step2Title')} description={t('positions.import.step2Description')} className="lg:col-start-1">
                        <form onSubmit={submit}>
                            <FileDropZone
                                id="file"
                                file={form.data.file}
                                error={form.errors.file}
                                title={t('positions.import.dropTitle')}
                                hint={fill(t('positions.import.dropHint'), { max: maxFileMegabytes })}
                                changeLabel={t('positions.import.changeFile')}
                                removeLabel={t('positions.import.removeFile')}
                                onChoose={chooseFile}
                            />

                            <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2">
                                <button type="submit" disabled={form.processing || !form.data.file || fileBlocked} className={buttonClassName({ variant: 'primary' })}>
                                    {form.processing ? <Spinner /> : <CheckReadIcon className="h-4 w-4" />}
                                    {form.processing ? t('positions.import.checking') : t('positions.import.check')}
                                </button>
                                <p className="text-sm text-(--app-muted-foreground)">{t('positions.import.checkHelp')}</p>
                            </div>
                        </form>
                    </StepSection>
                </div>

                <StepSection
                    ref={result}
                    number={3}
                    state={stepStates[2]}
                    title={batch?.finished ? t('positions.import.resultTitle') : t('positions.import.step3Title')}
                    description={batch ? (
                        <span className="inline-flex max-w-full items-center gap-1.5">
                            <FileSpreadsheetIcon className="h-4 w-4 shrink-0" aria-hidden="true" />
                            <span className="truncate">{batch.file_name}</span>
                        </span>
                    ) : t('positions.import.step3Empty')}
                >
                    {batch && (
                        <div className="space-y-5">
                            {batch.finished && (
                                <ResultBanner tone={batch.imported_rows > 0 ? 'success' : 'warning'} title={fill(t('positions.import.importedTitle'), { count: batch.imported_rows })}>
                                    {batch.failed_rows > 0 ? fill(t('positions.import.importedWithLeftOut'), { count: batch.failed_rows }) : t('positions.import.importedAll')}
                                </ResultBanner>
                            )}

                            {importing && (
                                <ImportProgress
                                    label={fill(t('positions.import.progress'), { done: batch.imported_rows, total: importTotal })}
                                    note={t('positions.import.keepOpen')}
                                    barLabel={t('positions.import.importing')}
                                    done={batch.imported_rows}
                                    total={importTotal}
                                />
                            )}

                            {batch.in_progress && !importing && (
                                <Alert tone="warning" title={t('positions.import.interruptedTitle')}>
                                    {fill(t('positions.import.interruptedBody'), { imported: batch.imported_rows, remaining: batch.remaining_rows })}
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
                                        label={started ? t('positions.import.countImported') : t('positions.import.countReady')}
                                        value={started ? batch.imported_rows : batch.valid_rows}
                                        variant="success"
                                        icon={started ? <Briefcase className="h-5 w-5" /> : <CheckReadIcon className="h-5 w-5" />}
                                    />
                                    <AppMetricCard
                                        label={batch.finished ? t('positions.import.countNotImported') : t('positions.import.countToFix')}
                                        value={batch.failed_rows}
                                        variant={batch.failed_rows > 0 ? 'danger' : 'neutral'}
                                        icon={<AlertTriangle className="h-5 w-5" />}
                                    />
                                    <AppMetricCard
                                        label={t('positions.import.countEmpty')}
                                        value={batch.unfilled_rows}
                                        variant="neutral"
                                        icon={<FileTextIcon className="h-5 w-5" />}
                                        detail={<span className="text-xs text-(--app-muted-foreground)">{t('positions.import.countEmptyHint')}</span>}
                                    />
                                </div>
                            </div>

                            {!started && batch.existing_rows > 0 && (
                                <Alert tone="warning" title={t('positions.import.existingTitle')}>
                                    {fill(t('positions.import.existingBody'), { count: batch.existing_rows })}
                                </Alert>
                            )}

                            {batch.over_limit_rows > 0 && (
                                <Alert tone="warning" title={t('positions.import.overLimitTitle')}>
                                    {fill(t('positions.import.overLimitBody'), { max: maxRows, skipped: batch.over_limit_rows })}
                                </Alert>
                            )}

                            {!batch.finished && batch.valid_rows === 0 && batch.failed_rows === 0 && (
                                <Alert tone="warning" title={t('positions.import.nothingFilledTitle')}>{t('positions.import.nothingFilledBody')}</Alert>
                            )}

                            {activeList && (
                                <div>
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <ResultTabs id="positions-import-lists" label={t('positions.import.step3Title')} tabs={tabs} active={activeList} onChange={setList} />
                                        {activeList === 'fix' && (
                                            <a href={route('positions.import.errors', batch.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                                <DownloadIcon className="h-4 w-4" />
                                                {t('positions.import.downloadToFix')}
                                            </a>
                                        )}
                                    </div>

                                    <div id="positions-import-lists-panel" role="tabpanel" aria-labelledby={`positions-import-lists-tab-${activeList}`} className="mt-3">
                                        {activeList === 'fix' && (
                                            <>
                                                <p className="mb-3 max-w-3xl text-sm leading-6 text-(--app-muted-foreground)">
                                                    {batch.finished || batch.valid_rows === 0 ? t('positions.import.toFixHelpOnly') : t('positions.import.toFixHelp')}
                                                </p>
                                                <div className="overflow-x-auto rounded-card border border-(--app-border)">
                                                    <TableTools id="positions-import-to-fix" toolbar={false}>
                                                        <table className={cx(tableClassName, 'min-w-full')}>
                                                            <thead className={tableHeadClassName}>
                                                                <tr>
                                                                    <HeadCell>{t('positions.import.columnRow')}</HeadCell>
                                                                    <HeadCell>{t('positions.import.columnPosition')}</HeadCell>
                                                                    <HeadCell>{t('positions.import.columnProblem')}</HeadCell>
                                                                </tr>
                                                            </thead>
                                                            <tbody className={tableBodyClassName}>
                                                                {failedRows.map((row) => (
                                                                    <tr key={row.row_number} className="align-top">
                                                                        <td className={cx(tableCellClassName, 'whitespace-nowrap font-medium tabular-nums text-(--app-foreground)')}>{row.row_number}</td>
                                                                        <td className={cx(tableCellClassName, 'min-w-40 text-(--app-foreground)')}>
                                                                            <div>{row.title || '—'}</div>
                                                                            {row.organization_unit && <div className="mt-0.5 text-xs text-(--app-muted-foreground)">{row.organization_unit}</div>}
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
                                                <TableTools id="positions-import-ready" toolbar={false}>
                                                    <table className={cx(tableClassName, 'min-w-full')}>
                                                        <thead className={tableHeadClassName}>
                                                            <tr>
                                                                <HeadCell>{t('positions.import.columnRow')}</HeadCell>
                                                                <HeadCell>{t('positions.import.columnPosition')}</HeadCell>
                                                                <HeadCell>{t('positions.import.columnUnit')}</HeadCell>
                                                                <HeadCell className="hidden md:table-cell">{t('positions.import.columnOccupation')}</HeadCell>
                                                                <HeadCell className="hidden md:table-cell">{t('positions.import.columnGrade')}</HeadCell>
                                                            </tr>
                                                        </thead>
                                                        <tbody className={tableBodyClassName}>
                                                            {readyRows.map((row) => (
                                                                <tr key={row.row_number}>
                                                                    <td className={cx(tableCellClassName, 'tabular-nums text-(--app-muted-foreground)')}>{row.row_number}</td>
                                                                    <td className={cx(tableCellClassName, 'font-medium text-(--app-foreground)')}>
                                                                        <div>{row.title ?? '—'}</div>
                                                                        {row.is_unit_head && <div className="mt-0.5 text-xs font-normal text-(--app-muted-foreground)">{t('positions.import.unitHead')}</div>}
                                                                        {row.already_exists && <div className="mt-1"><span className={badgeClassName('warning')}>{t('positions.import.alreadyExists')}</span></div>}
                                                                    </td>
                                                                    <td className={cx(tableCellClassName, 'text-(--app-foreground)')}>{row.organization_unit ?? t('positions.import.organizationLevel')}</td>
                                                                    <td className={cx(tableCellClassName, 'hidden text-(--app-muted-foreground) md:table-cell')}>{row.occupation ?? '—'}</td>
                                                                    <td className={cx(tableCellClassName, 'hidden whitespace-nowrap text-(--app-muted-foreground) md:table-cell')}>{row.grade_level ?? '—'}</td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                </TableTools>
                                            </div>
                                        )}

                                        {activeList === 'imported' && (
                                            <div className="overflow-x-auto rounded-card border border-(--app-border)">
                                                <TableTools id="positions-import-imported" toolbar={false}>
                                                    <table className={cx(tableClassName, 'min-w-full')}>
                                                        <thead className={tableHeadClassName}>
                                                            <tr>
                                                                <HeadCell>{t('positions.import.columnRow')}</HeadCell>
                                                                <HeadCell>{t('positions.import.columnCode')}</HeadCell>
                                                                <HeadCell>{t('positions.import.columnPosition')}</HeadCell>
                                                                <HeadCell>{t('positions.import.columnUnit')}</HeadCell>
                                                            </tr>
                                                        </thead>
                                                        <tbody className={tableBodyClassName}>
                                                            {importedRows.map((row) => (
                                                                <tr key={row.row_number}>
                                                                    <td className={cx(tableCellClassName, 'tabular-nums text-(--app-muted-foreground)')}>{row.row_number}</td>
                                                                    <td className={cx(tableCellClassName, 'whitespace-nowrap font-medium tabular-nums text-(--app-foreground)')}>{row.position_code ?? '—'}</td>
                                                                    <td className={cx(tableCellClassName, 'text-(--app-foreground)')}>
                                                                        <div>{row.title ?? '—'}</div>
                                                                        {row.is_unit_head && <div className="mt-0.5 text-xs text-(--app-muted-foreground)">{t('positions.import.unitHead')}</div>}
                                                                    </td>
                                                                    <td className={cx(tableCellClassName, 'text-(--app-foreground)')}>{row.organization_unit ?? t('positions.import.organizationLevel')}</td>
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
                                        <Link href={route('positions.index')} className={buttonClassName({ variant: 'primary' })}>
                                            <Briefcase className="h-4 w-4" />
                                            {t('positions.import.viewPositions')}
                                        </Link>
                                        <button type="button" onClick={startOver} className={buttonClassName({ variant: 'outline' })}>
                                            <RefreshIcon className="h-4 w-4" />
                                            {t('positions.import.importAnother')}
                                        </button>
                                    </>
                                ) : (
                                    <>
                                        {batch.importable && (
                                            <button type="button" onClick={confirmImport} disabled={importing} className={buttonClassName({ variant: 'primary' })}>
                                                {importing ? <Spinner /> : <CheckReadIcon className="h-4 w-4" />}
                                                {importing
                                                    ? t('positions.import.importing')
                                                    : batch.in_progress
                                                        ? fill(t('positions.import.continueButton'), { count: batch.remaining_rows })
                                                        : fill(t('positions.import.importButton'), { count: batch.valid_rows })}
                                            </button>
                                        )}
                                        <button type="button" onClick={startOver} disabled={importing} className={buttonClassName({ variant: 'outline' })}>
                                            {t('positions.import.discard')}
                                        </button>
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
