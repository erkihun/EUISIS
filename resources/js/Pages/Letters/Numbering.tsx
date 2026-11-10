import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import ActionErrors from '@/Components/letters/ActionErrors';
import { StatusBadge } from '@/Components/letters/LetterBadges';
import { errorCls, inputCls, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, sectionTitleCls, useLetters } from '@/Components/letters/helpers';
import { TBody, Table, Td, Th, THead, Tr, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { NumberingRule, OrganizationOption } from '@/types/letters';
import { Head, useForm } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState, type FormEvent, type JSX } from 'react';

interface Props {
    rules: NumberingRule[];
    organizations: OrganizationOption[];
    platform: boolean;
    directions: string[];
    reset_policies: string[];
    tokens: string[];
    default_pattern: string;
    default_pattern_en: string;
}

/**
 * Numbering rules per institution, direction and language, with a live preview
 * of the next number the rule would give. Amharic and English letters of one
 * institution always count separately; a rule may name one language or both.
 */
export default function Numbering({ rules, organizations, platform, directions, reset_policies, tokens, default_pattern, default_pattern_en }: Props): JSX.Element {
    const { lt, pick, name } = useLetters();
    const [preview, setPreview] = useState('');
    const form = useForm({
        organization_id: platform ? '' : organizations[0]?.id ?? '',
        direction: 'outgoing',
        language: '',
        pattern: default_pattern,
        reset_policy: 'ethiopian_year',
        padding: 4,
        direction_code: '',
        language_code: '',
    });

    useEffect(() => {
        const handle = window.setTimeout(() => {
            axios.post(route('letters.numbering.preview'), {
                ...form.data, organization_id: form.data.organization_id || null, language: form.data.language || null,
                direction_code: form.data.direction_code || null, language_code: form.data.language_code || null,
            })
                .then((r) => setPreview(String(r.data.preview ?? '')))
                .catch(() => setPreview(''));
        }, 300);

        return () => window.clearTimeout(handle);
    }, [form.data.organization_id, form.data.direction, form.data.language, form.data.pattern, form.data.reset_policy, form.data.padding, form.data.direction_code, form.data.language_code]);

    function edit(rule: NumberingRule) {
        form.setData({
            organization_id: rule.organization_id ?? '', direction: rule.direction, language: rule.language ?? '', pattern: rule.pattern, reset_policy: rule.reset_policy,
            padding: rule.padding, direction_code: rule.direction_code ?? '', language_code: rule.language_code ?? '',
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function save(event: FormEvent) {
        event.preventDefault();
        form.transform((d) => ({ ...d, organization_id: d.organization_id || null, language: d.language || null, direction_code: d.direction_code || null, language_code: d.language_code || null }));
        form.put(route('letters.numbering.save'), { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={lt('numbering.title')} description={lt('numbering.description')} backHref={route('letters.dashboard')} />}>
            <Head title={lt('numbering.title')} />
            <ActionErrors />
            <div className="space-y-5">
                <form onSubmit={save} className={cx(panelCls, 'grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3')} aria-label={lt('numbering.form')}>
                    <h2 className={cx(sectionTitleCls, 'sm:col-span-2 lg:col-span-3')}>{lt('numbering.form')}</h2>
                    <div>
                        <label htmlFor="num-org" className={labelCls}>{lt('numbering.institution')}</label>
                        <select id="num-org" className={inputCls} value={form.data.organization_id} onChange={(e) => form.setData('organization_id', e.target.value)}>
                            {platform && <option value="">{lt('numbering.platformDefault')}</option>}
                            {organizations.map((o) => <option key={o.id} value={o.id}>{pick(o)}</option>)}
                        </select>
                        {form.errors.organization_id && <p className={errorCls}>{form.errors.organization_id}</p>}
                    </div>
                    <div>
                        <label htmlFor="num-dir" className={labelCls}>{lt('common.direction')}</label>
                        <select id="num-dir" className={inputCls} value={form.data.direction} onChange={(e) => form.setData('direction', e.target.value)}>
                            {directions.map((d) => <option key={d} value={d}>{lt(`direction.${d}`)}</option>)}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="num-lang" className={labelCls}>{lt('numbering.language')}</label>
                        <select id="num-lang" className={inputCls} value={form.data.language} onChange={(e) => form.setData('language', e.target.value)}>
                            <option value="">{lt('numbering.anyLanguage')}</option>
                            <option value="am">{lt('language.am')}</option>
                            <option value="en">{lt('language.en')}</option>
                        </select>
                        <p className={cx(mutedCls, 'mt-1')}>{lt('numbering.languageHelp')}</p>
                    </div>
                    <div className="sm:col-span-2">
                        <label htmlFor="num-pattern" className={labelCls}>{lt('numbering.pattern')}</label>
                        <input id="num-pattern" className={cx(inputCls, 'font-mono')} value={form.data.pattern} onChange={(e) => form.setData('pattern', e.target.value)} required maxLength={120} />
                        <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                            <span className={mutedCls}>{lt('numbering.patternHelp')}</span>
                            {tokens.map((token) => (
                                <button key={token} type="button" title={token}
                                    className="inline-flex min-h-8 items-center rounded-full border border-(--app-border) px-2.5 font-mono text-xs transition-colors duration-150 hover:border-[#122170] hover:text-[#122170] focus-visible:outline-2 focus-visible:outline-[#122170]"
                                    onClick={() => {
                                        const el = document.getElementById('num-pattern') as HTMLInputElement | null;
                                        const at = el?.selectionStart ?? form.data.pattern.length;
                                        form.setData('pattern', (form.data.pattern.slice(0, at) + token + form.data.pattern.slice(at)).slice(0, 120));
                                        el?.focus();
                                    }}>
                                    {token}
                                </button>
                            ))}
                        </div>
                        {form.errors.pattern && <p className={errorCls}>{form.errors.pattern}</p>}
                    </div>
                    <div>
                        <label htmlFor="num-reset" className={labelCls}>{lt('numbering.resetPolicy')}</label>
                        <select id="num-reset" className={inputCls} value={form.data.reset_policy} onChange={(e) => form.setData('reset_policy', e.target.value)}>
                            {reset_policies.map((p) => <option key={p} value={p}>{lt(`resetPolicy.${p}`)}</option>)}
                        </select>
                    </div>
                    <div className="grid grid-cols-3 gap-3 sm:col-span-2 lg:col-span-3">
                        <div>
                            <label htmlFor="num-pad" className={labelCls}>{lt('numbering.padding')}</label>
                            <input id="num-pad" type="number" min={1} max={8} className={inputCls} value={form.data.padding} onChange={(e) => form.setData('padding', Number(e.target.value))} />
                        </div>
                        <div>
                            <label htmlFor="num-code" className={labelCls}>{lt('numbering.directionCode')}</label>
                            <input id="num-code" className={inputCls} value={form.data.direction_code} onChange={(e) => form.setData('direction_code', e.target.value)} maxLength={12} placeholder="ወ / ገ" />
                        </div>
                        <div>
                            <label htmlFor="num-lang-code" className={labelCls}>{lt('numbering.languageCode')}</label>
                            <input id="num-lang-code" className={inputCls} value={form.data.language_code} onChange={(e) => form.setData('language_code', e.target.value)} maxLength={12} placeholder="አማ / EN" />
                        </div>
                    </div>
                    <div className="sm:col-span-2 lg:col-span-3 flex flex-wrap items-center justify-between gap-3 border-t border-(--app-border) pt-3">
                        <p className="text-sm">
                            <span className={labelCls}>{lt('numbering.preview')}</span>
                            <span className="font-mono text-base font-semibold">{preview || '—'}</span>
                            <span className={mutedCls}> {lt('numbering.previewHelp', { language: lt(`language.${form.data.language || 'am'}`) })}</span>
                        </p>
                        <button type="submit" className={primaryBtn} disabled={form.processing}>{lt('numbering.save')}</button>
                    </div>
                </form>

                <section className={cx(panelCls, 'overflow-x-auto')} aria-labelledby="num-rules">
                    <h2 id="num-rules" className={cx(sectionTitleCls, 'p-4 pb-2')}>{lt('numbering.rules')}</h2>
                    {rules.length === 0 ? <p className={cx(mutedCls, 'px-4 pb-4')}>{lt('numbering.noRules', { pattern: default_pattern, pattern_en: default_pattern_en })}</p> : (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{lt('numbering.institution')}</Th>
                                    <Th>{lt('common.direction')}</Th>
                                    <Th>{lt('numbering.language')}</Th>
                                    <Th>{lt('numbering.pattern')}</Th>
                                    <Th>{lt('numbering.resetPolicy')}</Th>
                                    <Th>{lt('numbering.padding')}</Th>
                                    <Th>{lt('numbering.updatedBy')}</Th>
                                    <Th>{lt('common.actions')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {rules.map((r) => (
                                    <Tr key={r.id}>
                                        <Td>{r.organization ? name(r.organization) : lt('numbering.platformDefault')}</Td>
                                        <Td><StatusBadge kind="direction" value={r.direction} /></Td>
                                        <Td>{r.language ? lt(`language.${r.language}`) : lt('numbering.anyLanguage')}</Td>
                                        <Td className="font-mono">{r.pattern}{(r.direction_code || r.language_code) && <span className={mutedCls}> ({[r.direction_code, r.language_code].filter(Boolean).join(' · ')})</span>}</Td>
                                        <Td>{lt(`resetPolicy.${r.reset_policy}`)}</Td>
                                        <Td>{r.padding}</Td>
                                        <Td className="text-xs">{r.updated_by ?? '—'} · <LocalizedDateDisplay value={r.updated_at} /></Td>
                                        <Td><button type="button" className={secondaryBtn} onClick={() => edit(r)}>{lt('common.edit')}</button></Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                    <p className={cx(mutedCls, 'px-4 pb-4')}>{lt('numbering.defaultNote', { pattern: default_pattern, pattern_en: default_pattern_en })}</p>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
