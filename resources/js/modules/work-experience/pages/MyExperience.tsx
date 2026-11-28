import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import ConfirmationDialog from '@/Components/ConfirmationDialog';
import EmptyState from '@/Components/EmptyState';
import { EyeIcon, Plus, XCircle } from '@/Components/Icons';
import PageHeader from '@/Components/PageHeader';
import { AppActionMenu, Table, TableCard, TableTools, TBody, Td, Th, THead, Tr, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    ActionErrors, ExperiencePopup, LinesTable, Notice, StatusBadge, errorCls, inputCls, labelCls, mutedCls, primaryBtn, secondaryBtn, textareaCls, useExperience,
    type Duration, type EmployeeRef, type ExperienceLine, type RequestRow,
} from '@/modules/work-experience/lib';
import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

type MyRequest = RequestRow & { can_cancel: boolean };

interface Props {
    experienceEmployee: EmployeeRef;
    experience: { lines: ExperienceLine[]; total: Duration };
    experienceRequests: MyRequest[];
    experienceOpen: string | null;
}

/**
 * My Portal > My work experience: what the office recorded about my service,
 * and my requests for a written certificate. Asking is a short form, not a
 * letter; the signed and stamped paper is sent to me.
 */
export default function MyExperience({ experienceEmployee, experience, experienceRequests, experienceOpen }: Props): JSX.Element {
    const { wt, name, locale } = useExperience();
    const [asking, setAsking] = useState(false);
    const [opened, setOpened] = useState<MyRequest | null>(experienceRequests.find((row) => row.id === experienceOpen) ?? null);
    const [cancelling, setCancelling] = useState<MyRequest | null>(null);
    const form = useForm({ addressee: '', purpose: '', language: locale === 'en' ? 'en' : 'am' });
    const fieldError = (field: 'addressee' | 'purpose' | 'language') => (form.errors[field] ? <p className={errorCls}>{form.errors[field]}</p> : null);
    const close = () => {
        setAsking(false);
        form.clearErrors();
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('employee.work-experience.store'), { preserveScroll: true, onSuccess: () => { form.reset(); setAsking(false); } });
    };
    const ask = (
        <button type="button" className={cx(primaryBtn, 'gap-2')} onClick={() => setAsking(true)}>
            <Plus className="h-4 w-4" aria-hidden />{wt('my.ask')}
        </button>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={wt('my.title')} description={wt('my.description')} actions={ask} />}>
            <Head title={wt('my.title')} />
            <div className="space-y-4 pb-4">
                <ActionErrors except={['addressee', 'purpose', 'language']} />

                <TableCard>
                    <div className="border-b border-(--app-border) px-4 py-3">
                        <h2 className="text-sm font-semibold text-(--app-foreground)">{wt('my.requestsTitle')}</h2>
                        <p className={cx(mutedCls, 'mt-0.5 text-xs')}>{wt('my.requestsDescription')}</p>
                    </div>
                    {experienceRequests.length === 0 ? <EmptyState title={wt('my.noRequests')} description={wt('my.noRequestsDescription')} /> : (
                        <TableTools id="work-experience-my-requests"><Table>
                            <THead>
                                <Tr>
                                    <Th>{wt('requests.reference')}</Th><Th>{wt('requests.askedOn')}</Th><Th>{wt('form.addressee')}</Th><Th>{wt('form.purpose')}</Th>
                                    <Th>{wt('requests.status')}</Th><Th className="text-end">{wt('common.actions')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {experienceRequests.map((row) => (
                                    <Tr key={row.id}>
                                        <Td><button type="button" className="font-medium text-primary hover:underline" onClick={() => setOpened(row)}>{row.reference_number}</button></Td>
                                        <Td className="whitespace-nowrap">{row.submitted_at ? <LocalizedDateDisplay value={row.submitted_at} /> : '—'}</Td>
                                        <Td>{row.addressee ?? wt('form.toWhom')}</Td>
                                        <Td>{row.purpose}</Td>
                                        <Td>
                                            <StatusBadge status={row.status} />
                                            {row.status === 'returned' && row.decision_comment && <span className={cx(mutedCls, 'mt-1 block text-xs')}>{row.decision_comment}</span>}
                                        </Td>
                                        <Td className="whitespace-nowrap text-end">
                                            <div className="flex justify-end">
                                                <AppActionMenu items={[
                                                    { label: wt('common.open'), onClick: () => setOpened(row), icon: <EyeIcon className="h-4 w-4" /> },
                                                    { label: wt('my.withdraw'), onClick: () => setCancelling(row), show: row.can_cancel, variant: 'danger', icon: <XCircle className="h-4 w-4" /> },
                                                ]} />
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table></TableTools>
                    )}
                </TableCard>

                <TableCard>
                    <div className="border-b border-(--app-border) px-4 py-3">
                        <h2 className="text-sm font-semibold text-(--app-foreground)">{wt('my.recordTitle')}</h2>
                        <p className={cx(mutedCls, 'mt-0.5 text-xs')}>{wt('my.recordDescription')}</p>
                    </div>
                    <LinesTable lines={experience.lines} total={experience.total} toolsId="work-experience-my-record" />
                </TableCard>
            </div>

            <ExperiencePopup show={asking} onClose={close} title={wt('my.askTitle')} subtitle={`${name(experienceEmployee)} · ${experienceEmployee.employee_number ?? ''}`}>
                <form onSubmit={submit} className="space-y-4">
                    <Notice tone="info">{wt('my.askHelp')}</Notice>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label htmlFor="wx-addressee" className={labelCls}>{wt('form.addressee')}</label>
                            <input id="wx-addressee" type="text" maxLength={255} className={inputCls} placeholder={wt('form.toWhom')} value={form.data.addressee} onChange={(e) => form.setData('addressee', e.target.value)} />
                            <p className={cx(mutedCls, 'mt-1 text-xs')}>{wt('form.addresseeHelp')}</p>
                            {fieldError('addressee')}
                        </div>
                        <div>
                            <label htmlFor="wx-language" className={labelCls}>{wt('form.language')}<span className="text-red-600"> *</span></label>
                            <select id="wx-language" className={inputCls} value={form.data.language} onChange={(e) => form.setData('language', e.target.value)}>
                                <option value="am">{wt('form.amharic')}</option>
                                <option value="en">{wt('form.english')}</option>
                            </select>
                            {fieldError('language')}
                        </div>
                    </div>
                    <div>
                        <label htmlFor="wx-purpose" className={labelCls}>{wt('form.purpose')}<span className="text-red-600"> *</span></label>
                        <textarea id="wx-purpose" rows={3} maxLength={500} className={textareaCls} value={form.data.purpose} onChange={(e) => form.setData('purpose', e.target.value)} />
                        <p className={cx(mutedCls, 'mt-1 text-xs')}>{wt('form.purposeHelp')}</p>
                        {fieldError('purpose')}
                    </div>
                    <div className="flex flex-wrap justify-end gap-3">
                        <button type="button" className={secondaryBtn} onClick={close}>{wt('common.close')}</button>
                        <button type="submit" className={primaryBtn} disabled={form.processing || form.data.purpose.trim() === ''}>{wt('my.send')}</button>
                    </div>
                </form>
            </ExperiencePopup>

            <ExperiencePopup show={opened !== null} onClose={() => setOpened(null)} title={wt('requests.detailTitle', { reference: opened?.reference_number ?? '' })} subtitle={opened ? wt(`status.${opened.status}`) : null}>
                {opened && (
                    <>
                        <Notice tone={opened.status === 'returned' ? 'danger' : 'info'}>{wt(`my.step.${opened.status}`)}</Notice>
                        <dl className="grid gap-4 text-sm sm:grid-cols-2">
                            <div><dt className={labelCls}>{wt('form.addressee')}</dt><dd>{opened.addressee ?? wt('form.toWhom')}</dd></div>
                            <div><dt className={labelCls}>{wt('form.language')}</dt><dd>{wt(opened.language === 'en' ? 'form.english' : 'form.amharic')}</dd></div>
                            <div className="sm:col-span-2"><dt className={labelCls}>{wt('form.purpose')}</dt><dd>{opened.purpose}</dd></div>
                            <div><dt className={labelCls}>{wt('requests.askedOn')}</dt><dd>{opened.submitted_at ? <LocalizedDateDisplay value={opened.submitted_at} /> : '—'}</dd></div>
                            {opened.decided_at && <div><dt className={labelCls}>{wt('requests.decidedOn')}</dt><dd><LocalizedDateDisplay value={opened.decided_at} /></dd></div>}
                            {opened.decision_comment && <div className="sm:col-span-2"><dt className={labelCls}>{wt('requests.reason')}</dt><dd>{opened.decision_comment}</dd></div>}
                            {opened.certificate_number && <div><dt className={labelCls}>{wt('requests.certificateNumber')}</dt><dd>{opened.certificate_number}</dd></div>}
                            {opened.sent_at && <div><dt className={labelCls}>{wt('requests.sentOn')}</dt><dd><LocalizedDateDisplay value={opened.sent_at} /></dd></div>}
                            {opened.delivery_note && <div className="sm:col-span-2"><dt className={labelCls}>{wt('requests.deliveryNote')}</dt><dd>{opened.delivery_note}</dd></div>}
                        </dl>
                    </>
                )}
            </ExperiencePopup>

            <ConfirmationDialog open={cancelling !== null} onClose={() => setCancelling(null)} variant="danger"
                title={wt('my.withdrawTitle')} description={wt('my.withdrawDescription')} confirmLabel={wt('my.withdraw')} cancelLabel={wt('common.keep')}
                onConfirm={() => cancelling && router.post(route('employee.work-experience.cancel', cancelling.id), {}, { preserveScroll: true, onFinish: () => setCancelling(null) })} />
        </AuthenticatedLayout>
    );
}
