import { useState, type JSX } from 'react';
import type { OfficialForm } from '@/types/serviceMonitoring';
import OfficialTables from './OfficialTables';
import { useSm } from './helpers';

const SHEETS = ['section_1', 'summary', 'section_2', 'section_3', 'section_4'] as const;

/** The consolidated official forms, one tab per section (I.1, I.2, II, III, IV), Form A then Form B for the city. */
export default function ConsolidationForms({ forms }: { forms: OfficialForm[] }): JSX.Element {
    const { sm } = useSm();
    const [sheet, setSheet] = useState<(typeof SHEETS)[number]>('section_1');
    const label = (key: (typeof SHEETS)[number]) => ({ section_1: 'I.1', summary: 'I.2', section_2: 'II', section_3: 'III', section_4: 'IV' })[key];

    return (
        <div className="space-y-3">
            <div role="tablist" aria-label={sm('edit.sectionsNav')} className="flex flex-wrap gap-1 border-b border-(--app-border)">
                {SHEETS.map((key) => (
                    <button key={key} type="button" role="tab" aria-selected={sheet === key} onClick={() => setSheet(key)}
                        className={`px-3 py-2 text-sm ${sheet === key ? 'border-b-2 border-(--app-foreground) font-semibold' : 'text-(--app-muted-foreground)'}`}>
                        {sm('edit.section', { n: label(key) })}
                    </button>
                ))}
            </div>
            {forms.map((form) => (
                <section key={form.form_type} aria-label={sm(`formType.${form.form_type}`)} className="space-y-2">
                    {forms.length > 1 && <h2 className="text-sm font-semibold">{sm(`formType.${form.form_type}`)}{form.title ? ` — ${form.title}` : ''}</h2>}
                    <OfficialTables idPrefix={`${form.form_type}-${sheet}`} tables={form.sections[sheet]} />
                </section>
            ))}
            {forms[0]?.general_notes && (
                <details className="text-sm">
                    <summary className="cursor-pointer font-semibold">{sm('common.notes')}</summary>
                    <ol className="mt-2 list-decimal space-y-1 ps-5 text-(--app-muted-foreground)">{forms[0].general_notes.map((n, i) => <li key={i}>{n}</li>)}</ol>
                </details>
            )}
        </div>
    );
}
