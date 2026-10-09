import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import type { ReactNode } from 'react';

type Bilingual = { en: string | null | undefined; am: string | null | undefined };
export type SheetOption = { id: string; label_en: string | null; label_am: string | null; description_en: string | null; description_am: string | null; score: string | number };
export type SheetCriterion = { id: string; code: string | null; title_en: string; title_am: string | null; max_score?: string | number | null; options: SheetOption[] };
export type SheetSection = { id: string; title_en: string; title_am: string | null; criteria: SheetCriterion[] };
/** One evaluator's scores by criterion id; null when the viewer may not see that column. */
export type SheetColumn = Record<string, string | number> | null;

type Props = {
    code: string;
    title: Bilingual;
    sections: SheetSection[];
    /** One entry per evaluator column, in a stable anonymous order. */
    columns: SheetColumn[];
    header?: { unit?: string | null; employee?: string | null; position?: string | null; grade?: string | null; periodStart?: string | null; periodEnd?: string | null };
    signatures?: { assessed?: { name?: string | null; date?: string | null }; supervisor?: { name?: string | null; date?: string | null } };
    footer?: ReactNode;
};

const num = (value: string | number | null | undefined) => (value === null || value === undefined || value === '' ? null : Number(value));
const fmt = (value: number | null) => (value === null ? '' : String(Math.round(value * 100) / 100));
const criterionMax = (criterion: SheetCriterion) => num(criterion.max_score) ?? Math.max(0, ...criterion.options.map((option) => Number(option.score)));
const cell = 'border border-black px-1.5 py-1 align-top';

/**
 * The official paper layout of a behavioural assessment form (ቅጽ. 01–03):
 * header lines, a numbered table with each criterion's weight and its rating
 * levels, one score column per evaluator, the total row and signature lines.
 * Shared by the form preview (blank) and the assessment record (filled).
 * Always printed black on white, as on paper.
 */
export default function OfficialAssessmentSheet({ code, title, sections, columns, header, signatures, footer }: Props) {
    const { t, locale } = useLocale();
    const pick = (en: string | null | undefined, am: string | null | undefined) => (locale === 'am' && am) || en || am || '';
    const s = (key: string) => t(`assessments.sheet.${key}`);
    const criteria = sections.flatMap((section) => section.criteria);
    const max = criteria.reduce((sum, criterion) => sum + criterionMax(criterion), 0);
    const width = Math.max(columns.length, 1);
    const totals = columns.map((column) => (column === null ? null : criteria.reduce((sum, criterion) => sum + (num(column[criterion.id]) ?? 0), 0)));
    const criteriaHeader = sections.length === 1 ? pick(sections[0].title_en, sections[0].title_am) : s('criteria');
    const blank = (value: ReactNode, wide = false) => (
        <span className={`inline-block border-b border-dotted border-black align-bottom ${wide ? 'min-w-[16rem]' : 'min-w-[9rem]'}`}>{value ?? ' '}</span>
    );
    const date = (value: string | null | undefined) => (value ? <LocalizedDateDisplay value={value} /> : null);

    return (
        <article className="official-assessment-sheet bg-white p-4 text-[12px] leading-snug text-black">
            <p className="text-right font-semibold">{code}</p>
            <h2 className="mt-1 text-center text-[14px] font-bold">{pick(title.en, title.am)}</h2>
            <div className="mt-3 space-y-1.5">
                <p>{s('unit')} {blank(header?.unit, true)}</p>
                <p>{s('employee')} {blank(header?.employee, true)}</p>
                <p>{s('position')} {blank(header?.position)} {s('grade')} {blank(header?.grade)}</p>
                <p>{s('periodFrom')} {blank(date(header?.periodStart))} {s('periodTo')} {blank(date(header?.periodEnd))}</p>
            </div>

            <table className="mt-3 w-full border-collapse">
                <thead>
                    <tr className="bg-[#d9e7f5] font-semibold">
                        <th rowSpan={2} className={`${cell} w-10 text-left`}>{s('number')}</th>
                        <th rowSpan={2} className={`${cell} text-center`}>{criteriaHeader}</th>
                        <th rowSpan={2} className={`${cell} w-20 text-left`}>{s('weight')} {fmt(max)}</th>
                        <th colSpan={width} className={`${cell} text-center`}>{s('scoreGiven')}</th>
                    </tr>
                    <tr className="bg-[#d9e7f5]">
                        {Array.from({ length: width }, (_, index) => <th key={index} className={`${cell} w-12 text-center font-normal`}>{index + 1}</th>)}
                    </tr>
                </thead>
                <tbody>
                    {criteria.map((criterion, ci) => (
                        <CriterionRows key={criterion.id} criterion={criterion} number={criterion.code || String(ci + 1)} columns={columns} width={width} pick={pick} />
                    ))}
                    <tr className="font-bold">
                        <td className={cell} />
                        <td className={cell}>{s('total')} {fmt(max)}</td>
                        <td className={cell}>{fmt(max)}</td>
                        {Array.from({ length: width }, (_, index) => <td key={index} className={`${cell} text-center tabular-nums`}>{fmt(totals[index] ?? null)}</td>)}
                    </tr>
                </tbody>
            </table>

            {footer && <div className="mt-2">{footer}</div>}

            <div className="mt-5 space-y-3">
                <p>{s('assessed')} {blank(signatures?.assessed?.name, true)} {s('signature')} {blank(null)} {s('date')} {blank(date(signatures?.assessed?.date))}</p>
                <p>{s('supervisor')} {blank(signatures?.supervisor?.name, true)} {s('signature')} {blank(null)} {s('date')} {blank(date(signatures?.supervisor?.date))}</p>
            </div>
        </article>
    );
}

function CriterionRows({ criterion, number, columns, width, pick }: {
    criterion: SheetCriterion; number: string; columns: SheetColumn[]; width: number;
    pick: (en: string | null | undefined, am: string | null | undefined) => string;
}) {
    return (
        <>
            <tr className="bg-[#eef3f8] font-semibold" style={{ breakInside: 'avoid' }}>
                <td className={cell}>{number}</td>
                <td className={cell}>{pick(criterion.title_en, criterion.title_am)}</td>
                <td className={`${cell} tabular-nums`}>{fmt(criterionMax(criterion))}</td>
                {Array.from({ length: width }, (_, index) => (
                    <td key={index} className={`${cell} text-center tabular-nums`}>{fmt(num(columns[index]?.[criterion.id]))}</td>
                ))}
            </tr>
            {criterion.options.map((option, oi) => (
                <tr key={option.id} style={{ breakInside: 'avoid' }}>
                    <td className={cell}>{pick(option.label_en, option.label_am) || `${number}.${oi + 1}`}</td>
                    <td className={cell}>{pick(option.description_en, option.description_am) || pick(option.label_en, option.label_am)}</td>
                    <td className={`${cell} tabular-nums`}>{fmt(Number(option.score))}</td>
                    {Array.from({ length: width }, (_, index) => <td key={index} className={cell} />)}
                </tr>
            ))}
        </>
    );
}
