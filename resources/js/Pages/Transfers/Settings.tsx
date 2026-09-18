import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';

type TransferSettings = {
    require_same_position: boolean;
    require_same_grade: boolean;
    require_same_salary: boolean;
    allow_cross_institution: boolean;
    allow_exceptional_override: boolean;
    override_approver_roles: string[];
    required_documents: string[];
    minimum_service_months: number;
    releasing_consent_required: boolean;
    receiving_consent_required: boolean;
    final_approval_required: boolean;
    card_reprint_policy: string;
    service_recalculation_policy: string;
};

type Props = { settings: TransferSettings };

type Tab = 'rules' | 'approval' | 'documents' | 'override' | 'post_transfer';

export default function TransferSettings({ settings }: Props) {
    const { t } = useLocale();
    const [tab, setTab]   = useState<Tab>('rules');
    const [form, setForm] = useState<TransferSettings>({ ...settings });
    const [saving, setSaving] = useState(false);

    const cardReprintOptions = [
        { value: 'no_reprint', label: t('transfers.cardReprintNoReprint') },
        { value: 'request_reprint', label: t('transfers.cardReprintRequestReprint') },
        { value: 'auto_reprint', label: t('transfers.cardReprintAutoReprint') },
    ];

    const serviceRecalcOptions = [
        { value: 'no_recalculation', label: t('transfers.serviceRecalcNone') },
        { value: 'recalculate_from_transfer', label: t('transfers.serviceRecalcFromTransfer') },
        { value: 'recalculate_from_effective_date', label: t('transfers.serviceRecalcFromEffective') },
    ];

    const sectionCls = 'rounded-card border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900 space-y-5';
    const inputCls   = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 disabled:opacity-60';
    const labelCls   = 'block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1';

    const tabBarCls = (active: boolean) =>
        `whitespace-nowrap px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
            active
                ? 'border-[color:var(--color-primary)] text-[color:var(--color-primary)] dark:text-[color:var(--color-primary)]'
                : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200'
        }`;

    function set<K extends keyof TransferSettings>(key: K, value: TransferSettings[K]) {
        setForm((f) => ({ ...f, [key]: value }));
    }

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setSaving(true);
        router.patch(route('transfer-settings.update'), form as never, {
            onFinish: () => setSaving(false),
            preserveScroll: true,
        });
    }

    const tabDefs: { key: Tab; label: string }[] = [
        { key: 'rules', label: t('transfers.tabRules') },
        { key: 'approval', label: t('transfers.tabApproval') },
        { key: 'documents', label: t('transfers.tabDocuments') },
        { key: 'override', label: t('transfers.tabOverride') },
        { key: 'post_transfer', label: t('transfers.tabPostTransfer') },
    ];

    return (
        <AuthenticatedLayout header={<PageHeader title={t('transfers.settings')} />}>
            <Head title={t('transfers.settings')} />

            <div className="space-y-6">
                {/* Tab bar */}
                <div className="overflow-x-auto">
                    <div className="flex min-w-max border-b border-gray-200 dark:border-slate-700">
                        {tabDefs.map((tb) => (
                            <button
                                key={tb.key}
                                type="button"
                                onClick={() => setTab(tb.key)}
                                className={tabBarCls(tab === tb.key)}
                            >
                                {tb.label}
                            </button>
                        ))}
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">

                    {/* ── Transfer Rules ── */}
                    {tab === 'rules' && (
                        <div className={sectionCls}>
                            <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">{t('transfers.tabRules')}</h3>
                            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div>
                                    <label className={labelCls}>{t('transfers.requireSamePosition')}</label>
                                    <select className={inputCls} value={form.require_same_position ? '1' : '0'} onChange={(e) => set('require_same_position', e.target.value === '1')}>
                                        <option value="1">{t('common.yes')}</option>
                                        <option value="0">{t('common.no')}</option>
                                    </select>
                                </div>
                                <div>
                                    <label className={labelCls}>{t('transfers.requireSameGrade')}</label>
                                    <select className={inputCls} value={form.require_same_grade ? '1' : '0'} onChange={(e) => set('require_same_grade', e.target.value === '1')}>
                                        <option value="1">{t('common.yes')}</option>
                                        <option value="0">{t('common.no')}</option>
                                    </select>
                                </div>
                                <div>
                                    <label className={labelCls}>{t('transfers.requireSameSalary')}</label>
                                    <select className={inputCls} value={form.require_same_salary ? '1' : '0'} onChange={(e) => set('require_same_salary', e.target.value === '1')}>
                                        <option value="1">{t('common.yes')}</option>
                                        <option value="0">{t('common.no')}</option>
                                    </select>
                                </div>
                                <div>
                                    <label className={labelCls}>{t('transfers.allowCrossInstitution')}</label>
                                    <select className={inputCls} value={form.allow_cross_institution ? '1' : '0'} onChange={(e) => set('allow_cross_institution', e.target.value === '1')}>
                                        <option value="1">{t('common.yes')}</option>
                                        <option value="0">{t('common.no')}</option>
                                    </select>
                                </div>
                                <div>
                                    <label className={labelCls}>{t('transfers.minimumServiceMonths')}</label>
                                    <input
                                        type="number"
                                        min={0}
                                        max={600}
                                        className={inputCls}
                                        value={form.minimum_service_months}
                                        onChange={(e) => set('minimum_service_months', Number(e.target.value))}
                                    />
                                </div>
                            </div>
                        </div>
                    )}

                    {/* ── Approval Chain ── */}
                    {tab === 'approval' && (
                        <div className={sectionCls}>
                            <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">{t('transfers.approvalChain')}</h3>
                            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div>
                                    <label className={labelCls}>{t('transfers.releasingConsentRequired')}</label>
                                    <select className={inputCls} value={form.releasing_consent_required ? '1' : '0'} onChange={(e) => set('releasing_consent_required', e.target.value === '1')}>
                                        <option value="1">{t('common.yes')}</option>
                                        <option value="0">{t('common.no')}</option>
                                    </select>
                                </div>
                                <div>
                                    <label className={labelCls}>{t('transfers.receivingConsentRequired')}</label>
                                    <select className={inputCls} value={form.receiving_consent_required ? '1' : '0'} onChange={(e) => set('receiving_consent_required', e.target.value === '1')}>
                                        <option value="1">{t('common.yes')}</option>
                                        <option value="0">{t('common.no')}</option>
                                    </select>
                                </div>
                                <div>
                                    <label className={labelCls}>{t('transfers.finalApprovalRequired')}</label>
                                    <select className={inputCls} value={form.final_approval_required ? '1' : '0'} onChange={(e) => set('final_approval_required', e.target.value === '1')}>
                                        <option value="1">{t('common.yes')}</option>
                                        <option value="0">{t('common.no')}</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* ── Required Documents ── */}
                    {tab === 'documents' && (
                        <div className={sectionCls}>
                            <div className="flex items-center justify-between">
                                <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">{t('transfers.requiredDocuments')}</h3>
                                <button
                                    type="button"
                                    onClick={() => set('required_documents', [...(form.required_documents ?? []), ''])}
                                    className="text-sm font-medium text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]"
                                >
                                    + {t('transfers.addDocument')}
                                </button>
                            </div>
                            {(form.required_documents ?? []).length === 0 && (
                                <p className="text-sm text-gray-400 dark:text-slate-500">—</p>
                            )}
                            <div className="space-y-2">
                                {(form.required_documents ?? []).map((doc, i) => (
                                    <div key={i} className="flex gap-2">
                                        <input
                                            type="text"
                                            className={`${inputCls} flex-1`}
                                            value={doc}
                                            onChange={(e) => {
                                                const next = [...(form.required_documents ?? [])];
                                                next[i] = e.target.value;
                                                set('required_documents', next);
                                            }}
                                        />
                                        <button
                                            type="button"
                                            onClick={() => set('required_documents', (form.required_documents ?? []).filter((_, j) => j !== i))}
                                            className="rounded-lg border border-red-200 px-3 text-sm text-red-600 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-950"
                                        >
                                            {t('common.remove')}
                                        </button>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* ── Override Policy ── */}
                    {tab === 'override' && (
                        <div className={sectionCls}>
                            <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">{t('transfers.tabOverride')}</h3>
                            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div>
                                    <label className={labelCls}>{t('transfers.allowExceptionalOverride')}</label>
                                    <select className={inputCls} value={form.allow_exceptional_override ? '1' : '0'} onChange={(e) => set('allow_exceptional_override', e.target.value === '1')}>
                                        <option value="1">{t('common.yes')}</option>
                                        <option value="0">{t('common.no')}</option>
                                    </select>
                                </div>
                            </div>
                            {form.allow_exceptional_override && (
                                <div className="mt-4">
                                    <div className="flex items-center justify-between">
                                        <label className="text-sm font-medium text-gray-700 dark:text-slate-300">{t('transfers.overrideApproverRoles')}</label>
                                        <button
                                            type="button"
                                            onClick={() => set('override_approver_roles', [...(form.override_approver_roles ?? []), ''])}
                                            className="text-sm font-medium text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]"
                                        >
                                            + {t('transfers.addRole')}
                                        </button>
                                    </div>
                                    <div className="mt-2 space-y-2">
                                        {(form.override_approver_roles ?? []).length === 0 && (
                                            <p className="text-sm text-gray-400 dark:text-slate-500">—</p>
                                        )}
                                        {(form.override_approver_roles ?? []).map((role, i) => (
                                            <div key={i} className="flex gap-2">
                                                <input
                                                    type="text"
                                                    className={`${inputCls} flex-1`}
                                                    value={role}
                                                    onChange={(e) => {
                                                        const next = [...(form.override_approver_roles ?? [])];
                                                        next[i] = e.target.value;
                                                        set('override_approver_roles', next);
                                                    }}
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() => set('override_approver_roles', (form.override_approver_roles ?? []).filter((_, j) => j !== i))}
                                                    className="rounded-lg border border-red-200 px-3 text-sm text-red-600 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-950"
                                                >
                                                    {t('common.remove')}
                                                </button>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {/* ── Post-Transfer ── */}
                    {tab === 'post_transfer' && (
                        <div className={sectionCls}>
                            <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">{t('transfers.tabPostTransfer')}</h3>
                            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div>
                                    <label className={labelCls}>{t('transfers.cardReprintPolicy')}</label>
                                    <select className={inputCls} value={form.card_reprint_policy} onChange={(e) => set('card_reprint_policy', e.target.value)}>
                                        {cardReprintOptions.map((o) => (
                                            <option key={o.value} value={o.value}>{o.label}</option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className={labelCls}>{t('transfers.serviceRecalculationPolicy')}</label>
                                    <select className={inputCls} value={form.service_recalculation_policy} onChange={(e) => set('service_recalculation_policy', e.target.value)}>
                                        {serviceRecalcOptions.map((o) => (
                                            <option key={o.value} value={o.value}>{o.label}</option>
                                        ))}
                                    </select>
                                </div>
                            </div>
                        </div>
                    )}

                    <div className="flex justify-end">
                        <button
                            type="submit"
                            disabled={saving}
                            className="rounded-lg bg-[color:var(--color-primary)] px-5 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] disabled:opacity-60"
                        >
                            {saving ? t('common.saving') : t('common.save')}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
