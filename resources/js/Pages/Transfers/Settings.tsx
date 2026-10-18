import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';

import { buttonClassName, cx, inputClassName } from '@/Components/ui';
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

/* Named to avoid shadowing the globally shared `settings` branding prop. */
type Props = { transferSettings: TransferSettings };

type Tab = 'rules' | 'approval' | 'documents' | 'override' | 'post_transfer';

export default function TransferSettings({ transferSettings }: Props) {
    const { t } = useLocale();
    const [tab, setTab]   = useState<Tab>('rules');
    const [form, setForm] = useState<TransferSettings>({ ...transferSettings });
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

    /*
     * Control styling matches the shared admin form styling used by every
     * other settings screen. This page had rolled its own, which left its
     * fields with the browser's default focus outline instead of the
     * institutional primary, no placeholder colour, and a plain opacity
     * fade for disabled state — so it visibly did not match the rest of the
     * admin area.
     */
    const sectionCls = 'rounded-card border border-(--app-border) bg-(--app-surface) p-6 space-y-5';
    const inputCls = cx(inputClassName, 'w-full');
    const labelCls = 'mb-1 block text-xs font-medium text-(--app-muted-foreground)';

    const tabBarCls = (active: boolean) =>
        `whitespace-nowrap px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
            active
                ? 'border-primary text-primary dark:text-primary'
                : 'border-transparent text-(--app-muted-foreground) hover:text-(--app-foreground)'
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

            {/*
              * min-w-0 matches every other admin page and matters more here:
              * the tab strip below is `min-w-max`, so without it a long tab
              * row can push the content column wider than its track instead
              * of scrolling inside its own container.
              */}
            <div className="min-w-0 space-y-6">
                {/* Tab bar */}
                <div className="min-w-0 overflow-x-auto">
                    <div className="flex min-w-max border-b border-(--app-border)">
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
                            <h3 className="text-base font-semibold text-(--app-foreground)">{t('transfers.tabRules')}</h3>
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
                            <h3 className="text-base font-semibold text-(--app-foreground)">{t('transfers.approvalChain')}</h3>
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
                                <h3 className="text-base font-semibold text-(--app-foreground)">{t('transfers.requiredDocuments')}</h3>
                                <button
                                    type="button"
                                    onClick={() => set('required_documents', [...(form.required_documents ?? []), ''])}
                                    className="text-sm font-medium text-primary hover:underline dark:text-primary"
                                >
                                    + {t('transfers.addDocument')}
                                </button>
                            </div>
                            {(form.required_documents ?? []).length === 0 && (
                                <p className="text-sm text-(--app-muted-foreground)">—</p>
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
                                            className={buttonClassName({ variant: 'outline' })}
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
                            <h3 className="text-base font-semibold text-(--app-foreground)">{t('transfers.tabOverride')}</h3>
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
                                        <label className="text-sm font-medium text-(--app-foreground)">{t('transfers.overrideApproverRoles')}</label>
                                        <button
                                            type="button"
                                            onClick={() => set('override_approver_roles', [...(form.override_approver_roles ?? []), ''])}
                                            className="text-sm font-medium text-primary hover:underline dark:text-primary"
                                        >
                                            + {t('transfers.addRole')}
                                        </button>
                                    </div>
                                    <div className="mt-2 space-y-2">
                                        {(form.override_approver_roles ?? []).length === 0 && (
                                            <p className="text-sm text-(--app-muted-foreground)">—</p>
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
                                                    className={buttonClassName({ variant: 'outline' })}
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
                            <h3 className="text-base font-semibold text-(--app-foreground)">{t('transfers.tabPostTransfer')}</h3>
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
                            className={buttonClassName({ variant: 'primary' })}
                        >
                            {saving ? t('common.saving') : t('common.save')}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
