import { useLocale } from '@/hooks/useLocale';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import type { JSX } from 'react';
import type { FormOptions } from './types';

type Payload = Record<string, unknown>;

type Props = {
    requestType: string;
    organizationId: string;
    payload: Payload;
    onChange: (key: string, value: unknown) => void;
    options: FormOptions;
    errors: Record<string, string>;
};

const inputCls =
    'w-full min-w-0 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';
const labelCls = 'block space-y-1.5 text-xs font-medium text-gray-600 dark:text-slate-300';

/**
 * The type-specific half of the request form.
 *
 * One lifecycle, many payload shapes: this component decides which fields a
 * given request type needs. Every option list is already narrowed to the
 * actor's scope by the server, and the server re-validates whatever is sent.
 */
export default function RequestTypeFields({
    requestType,
    organizationId,
    payload,
    onChange,
    options,
    errors,
}: Props): JSX.Element | null {
    const { t, locale } = useLocale();
    const am = locale === 'am';

    const units = options.units.filter(unit => !organizationId || unit.organization_id === organizationId);
    const positions = options.positions.filter(position => !organizationId || position.organization_id === organizationId);

    const str = (key: string): string => (payload[key] === undefined || payload[key] === null ? '' : String(payload[key]));

    function Field({ name, children }: { name: string; children: JSX.Element }): JSX.Element {
        return (
            <div>
                {children}
                {errors[`payload.${name}`] && (
                    <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors[`payload.${name}`]}</p>
                )}
                {errors[name] && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors[name]}</p>}
            </div>
        );
    }

    function unitSelect(name: string, labelKey: string, allowNone = true): JSX.Element {
        return (
            <Field name={name}>
                <label className={labelCls}>
                    <span>{t(`organizationalChangeRequests.form.${labelKey}`)}</span>
                    <select className={inputCls} value={str(name)} onChange={event => onChange(name, event.target.value)}>
                        <option value="">{allowNone ? t('organizationalChangeRequests.form.none') : t('common.select')}</option>
                        {units.map(unit => (
                            <option key={unit.id} value={unit.id}>
                                {am ? unit.name_am || unit.name_en : unit.name_en}
                            </option>
                        ))}
                    </select>
                </label>
            </Field>
        );
    }

    function positionSelect(name: string, labelKey: string): JSX.Element {
        return (
            <Field name={name}>
                <label className={labelCls}>
                    <span>{t(`organizationalChangeRequests.form.${labelKey}`)}</span>
                    <select className={inputCls} value={str(name)} onChange={event => onChange(name, event.target.value)}>
                        <option value="">{t('common.select')}</option>
                        {positions.map(position => (
                            <option key={position.id} value={position.id}>
                                {position.job_position_code ? `${position.job_position_code} — ` : ''}
                                {am ? position.title_am || position.title_en : position.title_en}
                            </option>
                        ))}
                    </select>
                </label>
            </Field>
        );
    }

    function textField(name: string, labelKey: string, type: 'text' | 'number' = 'text'): JSX.Element {
        return (
            <Field name={name}>
                <label className={labelCls}>
                    <span>{t(`organizationalChangeRequests.form.${labelKey}`)}</span>
                    <input
                        type={type}
                        className={inputCls}
                        value={str(name)}
                        min={type === 'number' ? 1 : undefined}
                        onChange={event => onChange(name, type === 'number' ? Number(event.target.value) : event.target.value)}
                    />
                </label>
            </Field>
        );
    }

    function dateField(name: string, labelKey: string): JSX.Element {
        return (
            <Field name={name}>
                <div className={labelCls}>
                    <label htmlFor={`ocr-${name}`}>{t(`organizationalChangeRequests.form.${labelKey}`)}</label>
                    <LocalizedDatePicker
                        id={`ocr-${name}`}
                        className={inputCls}
                        value={str(name)}
                        onChange={value => onChange(name, value)}
                    />
                </div>
            </Field>
        );
    }

    const grid = 'grid gap-4 md:grid-cols-2';

    switch (requestType) {
        case 'add_organization_unit':
            return (
                <div className={grid}>
                    {unitSelect('parent_unit_id', 'parentUnit')}
                    <Field name="organization_unit_type_id">
                        <label className={labelCls}>
                            <span>{t('organizationalChangeRequests.form.proposedUnitType')}</span>
                            <select
                                className={inputCls}
                                value={str('organization_unit_type_id')}
                                onChange={event => onChange('organization_unit_type_id', event.target.value)}
                            >
                                <option value="">{t('common.select')}</option>
                                {options.unitTypes.map(type => (
                                    <option key={type.id} value={type.id}>
                                        {am ? type.name_am || type.name_en : type.name_en}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </Field>
                    {textField('name_en', 'nameEn')}
                    {textField('name_am', 'nameAm')}
                    {unitSelect('functional_parent_unit_id', 'functionalParent')}
                    {dateField('effective_from', 'effectiveDate' as string)}
                </div>
            );

        case 'update_organization_unit':
            return (
                <div className={grid}>
                    {unitSelect('entity_id', 'targetUnit', false)}
                    {textField('name_en', 'nameEn')}
                    {textField('name_am', 'nameAm')}
                    {textField('description_en', 'descriptionEn')}
                    {dateField('effective_from', 'effectiveDate' as string)}
                </div>
            );

        case 'move_organization_unit':
        case 'change_structural_parent':
            return (
                <div className={grid}>
                    {unitSelect('entity_id', 'targetUnit', false)}
                    {unitSelect('parent_unit_id', 'structuralParent')}
                    {dateField('effective_from', 'effectiveDate' as string)}
                </div>
            );

        case 'deactivate_organization_unit':
            return (
                <div className={grid}>
                    {unitSelect('entity_id', 'targetUnit', false)}
                    {dateField('effective_to', 'effectiveDate' as string)}
                </div>
            );

        case 'change_functional_relationship':
            return (
                <div className={grid}>
                    {unitSelect('entity_id', 'targetUnit', false)}
                    {unitSelect('functional_parent_unit_id', 'functionalParent')}
                    {textField('relationship_note', 'relationshipNote')}
                    {dateField('effective_from', 'effectiveDate' as string)}
                </div>
            );

        case 'add_position':
            return (
                <div className={grid}>
                    {unitSelect('organization_unit_id', 'targetUnit', false)}
                    {textField('title_en', 'positionTitleEn')}
                    {textField('title_am', 'positionTitleAm')}
                    {textField('quantity', 'quantity', 'number')}
                    {textField('grade_level', 'grade')}
                    <Field name="occupation_id">
                        <label className={labelCls}>
                            <span>{t('organizationalChangeRequests.form.occupation')}</span>
                            <select
                                className={inputCls}
                                value={str('occupation_id')}
                                onChange={event => onChange('occupation_id', event.target.value)}
                            >
                                <option value="">{t('common.select')}</option>
                                {options.occupations.map(occupation => (
                                    <option key={occupation.id} value={occupation.id}>
                                        {am ? occupation.name_am || occupation.name_en : occupation.name_en}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </Field>
                    {dateField('effective_from', 'effectiveDate' as string)}
                </div>
            );

        case 'update_position':
            return (
                <div className={grid}>
                    {positionSelect('entity_id', 'targetPosition')}
                    {textField('title_en', 'positionTitleEn')}
                    {textField('title_am', 'positionTitleAm')}
                    {textField('grade_level', 'grade')}
                </div>
            );

        case 'move_position':
            return (
                <div className={grid}>
                    {positionSelect('entity_id', 'targetPosition')}
                    {unitSelect('organization_unit_id', 'targetUnit', false)}
                    {dateField('effective_from', 'effectiveDate' as string)}
                </div>
            );

        case 'increase_positions':
            return (
                <div className={grid}>
                    {positionSelect('entity_id', 'targetPosition')}
                    {textField('additional_quantity', 'additionalQuantity', 'number')}
                    {dateField('effective_from', 'effectiveDate' as string)}
                </div>
            );

        case 'abolish_position':
            return (
                <div className={grid}>
                    {positionSelect('entity_id', 'targetPosition')}
                    {dateField('effective_to', 'effectiveDate' as string)}
                </div>
            );

        case 'change_position_status':
            return (
                <div className={grid}>
                    {positionSelect('entity_id', 'targetPosition')}
                    <Field name="is_active">
                        <label className={labelCls}>
                            <span>{t('organizationalChangeRequests.form.positionStatus')}</span>
                            <select
                                className={inputCls}
                                value={payload.is_active === undefined ? '' : String(payload.is_active)}
                                onChange={event => onChange('is_active', event.target.value === 'true')}
                            >
                                <option value="">{t('common.select')}</option>
                                <option value="true">{t('organizationalChangeRequests.form.active')}</option>
                                <option value="false">{t('organizationalChangeRequests.form.inactive')}</option>
                            </select>
                        </label>
                    </Field>
                    {dateField('effective_from', 'effectiveDate' as string)}
                </div>
            );

        case 'change_grade':
            return (
                <div className={grid}>
                    {positionSelect('entity_id', 'targetPosition')}
                    {textField('grade_level', 'grade')}
                    {dateField('effective_from', 'effectiveDate' as string)}
                </div>
            );

        case 'structural_change':
        case 'other_structure_request':
            return (
                <div className="grid gap-4">
                    {textField('summary', 'summary')}
                    <Field name="details">
                        <label className={labelCls}>
                            <span>{t('organizationalChangeRequests.form.details')}</span>
                            <textarea
                                rows={4}
                                className={inputCls}
                                value={str('details')}
                                onChange={event => onChange('details', event.target.value)}
                            />
                        </label>
                    </Field>
                    {dateField('effective_from', 'effectiveDate' as string)}
                </div>
            );

        default:
            return null;
    }
}
