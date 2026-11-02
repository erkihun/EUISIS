import type { CSSProperties } from 'react';
import enDict from '@/i18n/en';
import amDict from '@/i18n/am';
import enEmployees from '@/i18n/en/employees';
import amEmployees from '@/i18n/am/employees';

/** Shown when a card field has no value. */
const DASH = '-';

/**
 * Fields whose two language labels print on one line, above a single value:
 * the value reads the same in either language, so a second row would only
 * repeat it.
 * Mirrored in IdCardSvgRenderer::JOINED_LABEL_FIELDS.
 */
const JOINED_LABEL_FIELDS = ['idNumber', 'phone', 'email', 'address', 'emergency_contact_name', 'emergency_contact_phone'];

/**
 * Fields whose value shrinks to fit its line rather than wrapping or being
 * cut, down to FIT_MIN_SCALE of its size: a broken email address is useless.
 * Mirrored in IdCardSvgRenderer::FIT_VALUE_FIELDS.
 */
const FIT_VALUE_FIELDS = ['email', 'address'];
const FIT_MIN_SCALE = 0.7;

/**
 * An email or address as at most two lines of `budget` characters: an email
 * breaks just before its "@", an address between words; only what still does
 * not fit is cut. Mirrors IdCardSvgRenderer::fitLines.
 */
export function fitLines(value: string, budget: number, email: boolean): string[] {
    const text = value.trim();
    if (Array.from(text).length <= budget) return [text];
    const at = text.lastIndexOf('@');
    let lines: string[];
    if (email && at > 0) {
        lines = [text.slice(0, at), text.slice(at)];
    } else {
        lines = [''];
        for (const word of text.split(/\s+/u)) {
            const last = lines.length - 1;
            const candidate = lines[last] === '' ? word : `${lines[last]} ${word}`;
            if (Array.from(candidate).length <= budget || lines[last] === '') lines[last] = candidate;
            else lines.push(word);
        }
    }
    const cut = (line: string) => {
        const chars = Array.from(line);
        return chars.length > budget ? `${chars.slice(0, Math.max(1, budget - 1)).join('')}…` : line;
    };
    return [cut(lines[0]), cut(lines.slice(1).join(' '))];
}

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
    /**
     * The field's own layout box: its width in percent of the card and the
     * template's label/value sizes in px. Given this, the field takes the
     * printed card's shape (IdCardSvgRenderer::bilingualField), so a box sized
     * on screen holds the same number of lines in print.
     */
    fit?: { widthPct: number; labelPx: number; valuePx: number; canvasWidth?: number };
};

/** The SVG canvas width and font scale the printed card is drawn at. */
const CANVAS_WIDTH = 856;
const CANVAS_SCALE = 2;

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
    fit,
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
    const alwaysStacked = fieldKey !== undefined && STACKED_VALUE_FIELDS.includes(fieldKey);
    let stacked = longest > 12 || alwaysStacked;
    const fits = fieldKey !== undefined && FIT_VALUE_FIELDS.includes(fieldKey);
    let fittedValueStyle = valueStyle;
    let valueLines: string[] | null = null;
    let labelLines: string[] | null = null;
    if (fit) {
        // The printed card's rule, in its own canvas units: a label sits beside
        // its value when it takes at most 55% of the box (a joined label, when
        // the value still fits after it).
        const width = (fit.widthPct / 100) * (fit.canvasWidth ?? CANVAS_WIDTH);
        const labelSize = fit.labelPx * CANVAS_SCALE;
        const valueSize = fit.valuePx * CANVAS_SCALE;
        const labelWidth = longest * labelSize * 0.55 + 6;
        // A shared label wider than its box breaks after the " / ", as it
        // prints (IdCardSvgRenderer::bilingualField).
        const shared = rows[0][0];
        if (joins && labelWidth > width && shared.includes(' / ')) {
            const at = shared.indexOf(' / ');
            labelLines = [`${shared.slice(0, at)} /`, shared.slice(at + 3)];
        }
        stacked = alwaysStacked || (joins
            ? labelWidth + (valueAm ?? '').length * valueSize * 0.55 > width
            : labelWidth > width * 0.55);
        // A long email or address shrinks to its line, as it prints.
        const room = stacked ? width : Math.max(24, width - labelWidth);
        const needed = (valueAm ?? '').length * valueSize * 0.55;
        let fitSize = valueSize;
        if (fits && needed > room) {
            const scale = Math.max(FIT_MIN_SCALE, room / needed);
            fitSize = valueSize * scale;
            fittedValueStyle = {
                ...valueStyle,
                fontSize: valueStyle.fontSize ? `calc(${valueStyle.fontSize} * ${scale.toFixed(3)})` : `${scale.toFixed(3)}em`,
            };
        }
        if (fits && valueAm) {
            // The printed card's character budget (IdCardSvgRenderer::charBudget).
            const budget = Math.max(12, Math.floor(room / Math.max(1, fitSize * 0.55)));
            valueLines = fitLines(valueAm, budget, fieldKey === 'email');
        }
    }
    const rowClass = stacked
        ? 'leading-tight'
        : 'grid grid-cols-[minmax(0,auto)_minmax(0,1fr)] items-baseline gap-x-1 leading-tight';
    const labelClass = 'block min-w-0 wrap-break-word';
    // One line, as the printed card draws it: never broken mid-address.
    const valueClass = fits
        ? 'block min-w-0 overflow-hidden text-ellipsis whitespace-nowrap font-semibold'
        : 'block min-w-0 wrap-break-word font-semibold';

    return (
        <div className="min-w-0">
            {rows.map(([label, value], row) => (
                <div key={row} className={`${rowClass} min-w-0`}>
                    <span className={labelClass} style={labelStyle}>
                        {labelLines
                            ? labelLines.map((line, index) => <span key={index} className="block whitespace-nowrap">{line}</span>)
                            : label}
                    </span>
                    <span className={valueClass} style={fittedValueStyle} title={fits && value ? value : undefined}>
                        {valueLines
                            ? valueLines.map((line, index) => <span key={index} className="block">{line}</span>)
                            : value || DASH}
                    </span>
                </div>
            ))}
        </div>
    );
}

export type FieldSource = {
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
    email?: string | null;
    address?: string | null;
    emergencyContactName?: string | null;
    emergencyContactPhone?: string | null;
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
/**
 * Every field a card can print, on either face: the identity rows, email and
 * address, and the emergency contact. A face picks the ones placed on it.
 */
export function buildCardFields(source: FieldSource): BilingualField[] {
    const same = (key: string, labelAm: string, labelEn: string, value?: string | null): BilingualField =>
        ({ key, labelAm, labelEn, valueAm: value, valueEn: value });

    return [
        ...buildBilingualFields(source),
        same('email', amEmployees.email, enEmployees.email, source.email),
        same('address', amEmployees.address, enEmployees.address, source.address),
        same('emergency_contact_name', amDict.idCards.emergencyContactNameLabel, enDict.idCards.emergencyContactNameLabel, source.emergencyContactName),
        same('emergency_contact_phone', amDict.idCards.emergencyContactPhoneLabel, enDict.idCards.emergencyContactPhoneLabel, source.emergencyContactPhone),
    ];
}

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
