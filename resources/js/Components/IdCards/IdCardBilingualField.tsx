import type { CSSProperties } from 'react';
import enDict from '@/i18n/en';
import amDict from '@/i18n/am';
import enEmployees from '@/i18n/en/employees';
import amEmployees from '@/i18n/am/employees';

/** Shown when a card field has no value. */
const DASH = '-';

/**
 * Fields whose two language labels print on one line, above a single value.
 * Mirrored in IdCardSvgRenderer::JOINED_LABEL_FIELDS.
 */
const JOINED_LABEL_FIELDS = ['idNumber', 'phone'];

/**
 * Fields that always print their value on the line below the label, however
 * much room the column has.
 * Mirrored in IdCardSvgRenderer::STACKED_VALUE_FIELDS.
 */
const STACKED_VALUE_FIELDS = ['idNumber'];

type Props = {
    labelAm: string;
    valueAm?: string | null;
    labelEn: string;
    valueEn?: string | null;
    /** Identifies the field; decides whether the two labels share a line. */
    fieldKey?: string;
    /** Template typography for the label row. */
    labelStyle: CSSProperties;
    /** Template typography for the value; the name field passes its own style. */
    valueStyle: CSSProperties;
};

/**
 * One front-face field as two stacked rows — the Amharic label and value, then
 * the English label and value beneath. Labels share a fixed column so values
 * line up, and neither row is ever collapsed into "amharic | english".
 */
export default function IdCardBilingualField({
    labelAm,
    valueAm,
    labelEn,
    valueEn,
    fieldKey,
    labelStyle,
    valueStyle,
}: Props) {
    // The ID and phone labels are abbreviations of a value that reads the same
    // in either language, so they share one line rather than printing an
    // identical value twice. Keyed, not inferred from the values, so a name
    // that happens to match in both languages still gets two rows.
    const joins = fieldKey !== undefined && JOINED_LABEL_FIELDS.includes(fieldKey);
    const rows: ReadonlyArray<readonly [string, string | null | undefined]> = joins
        ? [[`${labelAm} / ${labelEn}`, valueAm]]
        : [
              [labelAm, valueAm],
              [labelEn, valueEn],
          ];

    // Label and value share a two-track grid so every value in a column starts
    // at the same x. Without a reserved label track the rows drift apart and
    // long labels push their value off the card.
    //
    // A long label cannot sit beside its value in a narrow column without
    // squeezing the value to nothing, so those wrap onto their own line. The
    // threshold is in characters because the em-based font size means the label
    // always occupies the same fraction of the column whatever the card width.
    // A joined label is longer than either half, so it stacks on narrow cards.
    // The ID number stacks unconditionally, so the emphasised band reads as a
    // label with the number beneath it.
    const longest = Math.max(...rows.map(([label]) => label.length));
    const stacked =
        longest > 12 || (fieldKey !== undefined && STACKED_VALUE_FIELDS.includes(fieldKey));
    const rowClass = stacked
        ? 'leading-tight'
        : 'grid grid-cols-[minmax(0,auto)_minmax(0,1fr)] items-baseline gap-x-1 leading-tight';
    const labelClass = 'block min-w-0 break-words';
    const valueClass = 'block min-w-0 break-words font-semibold';

    return (
        <div className="min-w-0">
            {rows.map(([label, value], row) => (
                <div key={row} className={`${rowClass} min-w-0`}>
                    <span className={labelClass} style={labelStyle}>
                        {label}
                    </span>
                    <span className={valueClass} style={valueStyle}>
                        {value || DASH}
                    </span>
                </div>
            ))}
        </div>
    );
}

type FieldSource = {
    fullName?: string | null;
    fullNameAm?: string | null;
    gender?: string | null;
    dateOfBirth?: string | null;
    dateOfBirthAm?: string | null;
    nationality?: string | null;
    nationalityAm?: string | null;
    employmentStatus?: string | null;
    phoneNumber?: string | null;
    cardNumber: string;
    /** Shown as the ID Number on the card face. */
    employeeNumber?: string | null;
};

/** A label/value pair resolved in both languages. */
export type BilingualField = {
    key: string;
    labelAm: string;
    valueAm?: string | null;
    labelEn: string;
    valueEn?: string | null;
};

// Only employment types belong here. Lifecycle values such as "active" must
// never be substituted when an older employee record has no employment type.
const statusOf = (employees: Record<string, unknown>, status?: string | null) =>
    status ? (employees[`employmentType_${status}`] as string | undefined) ?? null : null;

/**
 * The seven front-face fields, each resolved in both languages. A missing
 * translation falls back to the other language so a row never renders blank.
 */
export function buildBilingualFields(source: FieldSource): BilingualField[] {
    const { gender } = source;
    const genderAm = gender === 'male' ? amDict.idCards.maleLabel : gender === 'female' ? amDict.idCards.femaleLabel : gender;
    const genderEn = gender === 'male' ? enDict.idCards.maleLabel : gender === 'female' ? enDict.idCards.femaleLabel : gender;

    return [
        {
            key: 'name',
            labelAm: amDict.idCards.nameLabel, valueAm: source.fullNameAm ?? source.fullName,
            labelEn: enDict.idCards.nameLabel, valueEn: source.fullName ?? source.fullNameAm,
        },
        {
            key: 'sex',
            labelAm: amDict.idCards.sexLabel, valueAm: genderAm,
            labelEn: enDict.idCards.sexLabel, valueEn: genderEn,
        },
        {
            key: 'dob',
            labelAm: amDict.idCards.dobLabel, valueAm: source.dateOfBirthAm ?? source.dateOfBirth,
            labelEn: enDict.idCards.dobLabel, valueEn: source.dateOfBirth ?? source.dateOfBirthAm,
        },
        {
            key: 'nationality',
            labelAm: amDict.idCards.nationalityLabel, valueAm: source.nationalityAm ?? (source.nationality?.toLowerCase() === 'ethiopian' ? amDict.idCards.ethiopianNationality : source.nationality),
            labelEn: enDict.idCards.nationalityLabel, valueEn: source.nationality?.toLowerCase() === 'ethiopian' ? enDict.idCards.ethiopianNationality : source.nationality ?? source.nationalityAm,
        },
        {
            key: 'employment',
            labelAm: amDict.idCards.employmentStatusLabel, valueAm: statusOf(amEmployees, source.employmentStatus),
            labelEn: enDict.idCards.employmentStatusLabel, valueEn: statusOf(enEmployees, source.employmentStatus),
        },
        {
            key: 'phone',
            labelAm: amDict.idCards.phoneLabel, valueAm: source.phoneNumber,
            labelEn: enDict.idCards.phoneLabel, valueEn: source.phoneNumber,
        },
        {
            key: 'idNumber',
            labelAm: amDict.idCards.idNumberLabel, valueAm: source.employeeNumber,
            labelEn: enDict.idCards.idNumberLabel, valueEn: source.employeeNumber,
        },
    ];
}
