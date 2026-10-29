import BirthDatePicker from '@/Components/Calendar/BirthDatePicker';
import { Field, FormCard, SystemIcon, inputCls } from '@/Components/employees/EmployeeFormLayout';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';

/**
 * The statistical part of an employee record, shared by Create and Edit:
 * marital status, education, a declared disability and the date the person
 * entered public service. Every field is optional; the disability fields are
 * hidden from users who may not see health data (they keep their value).
 */

export const MARITAL_STATUSES = ['single', 'married', 'divorced', 'widowed'] as const;
export const DISABILITY_TYPES = ['physical', 'visual', 'hearing', 'intellectual', 'psychosocial', 'multiple', 'other'] as const;

export type EducationLevelOption = { id: string; name_en: string; name_am: string | null };

export type EmployeeStatistics = {
    marital_status: string;
    education_level_id: string;
    field_of_study: string;
    /** '' not recorded, '1' yes, '0' no. */
    has_disability: string;
    disability_type: string;
    service_start_date: string;
};

type Stored = {
    marital_status?: string | null;
    education_level_id?: string | null;
    field_of_study?: string | null;
    has_disability?: boolean | null;
    disability_type?: string | null;
    service_start_date?: string | null;
};

export function employeeStatistics(employee?: Stored | null): EmployeeStatistics {
    return {
        marital_status: employee?.marital_status ?? '',
        education_level_id: employee?.education_level_id ?? '',
        field_of_study: employee?.field_of_study ?? '',
        has_disability: employee?.has_disability === true ? '1' : employee?.has_disability === false ? '0' : '',
        disability_type: employee?.disability_type ?? '',
        service_start_date: employee?.service_start_date ?? '',
    };
}

/** Empty values go as null so clearing a field clears it; without the right to see health data the disability fields are not sent. */
export function employeeStatisticsPayload(data: EmployeeStatistics, canViewSensitive: boolean): Record<string, string | boolean | null> {
    const payload: Record<string, string | boolean | null> = {
        marital_status: data.marital_status || null,
        education_level_id: data.education_level_id || null,
        field_of_study: data.field_of_study.trim() || null,
        service_start_date: data.service_start_date || null,
    };
    if (canViewSensitive) {
        payload.has_disability = data.has_disability === '' ? null : data.has_disability === '1';
        payload.disability_type = data.has_disability === '1' ? (data.disability_type || null) : null;
    }

    return payload;
}

export default function EmployeeStatisticsSection({
    data,
    errors,
    set,
    educationLevels,
    canViewSensitive,
}: {
    data: EmployeeStatistics;
    errors: Partial<Record<string, string>>;
    set: (values: Partial<EmployeeStatistics>) => void;
    educationLevels: EducationLevelOption[];
    canViewSensitive: boolean;
}) {
    const { t, locale } = useLocale();

    return (
        <FormCard wide icon={<SystemIcon />} title={t('employees.sectionStatistics')} description={t('employees.sectionStatisticsHelp')}>
            <Field label={t('employees.maritalStatus')} error={errors.marital_status} htmlFor="marital_status">
                <select id="marital_status" className={inputCls} value={data.marital_status} onChange={(e) => set({ marital_status: e.target.value })}>
                    <option value="">{t('employees.notRecorded')}</option>
                    {MARITAL_STATUSES.map((status) => <option key={status} value={status}>{t(`employees.maritalStatus_${status}`)}</option>)}
                </select>
            </Field>

            <Field label={t('employees.educationLevel')} error={errors.education_level_id} htmlFor="education_level_id">
                <select id="education_level_id" className={inputCls} value={data.education_level_id} onChange={(e) => set({ education_level_id: e.target.value })}>
                    <option value="">{t('employees.notRecorded')}</option>
                    {educationLevels.map((level) => <option key={level.id} value={level.id}>{localizedName(level.name_en, level.name_am, locale)}</option>)}
                </select>
            </Field>

            <Field label={t('employees.fieldOfStudy')} error={errors.field_of_study} htmlFor="field_of_study">
                <input id="field_of_study" className={inputCls} maxLength={150} placeholder={t('employees.fieldOfStudyPlaceholder')} value={data.field_of_study} onChange={(e) => set({ field_of_study: e.target.value })} />
            </Field>

            <Field label={t('employees.serviceStartDate')} error={errors.service_start_date} help={t('employees.serviceStartDateHelp')} htmlFor="service_start_date">
                <BirthDatePicker id="service_start_date" showAge={false} value={data.service_start_date} onChange={(iso) => set({ service_start_date: iso })} invalid={Boolean(errors.service_start_date)} />
            </Field>

            {canViewSensitive && (
                <>
                    <Field label={t('employees.hasDisability')} error={errors.has_disability} help={t('employees.hasDisabilityHelp')} htmlFor="has_disability">
                        <select
                            id="has_disability"
                            className={inputCls}
                            value={data.has_disability}
                            onChange={(e) => set({ has_disability: e.target.value, ...(e.target.value !== '1' ? { disability_type: '' } : {}) })}
                        >
                            <option value="">{t('employees.notRecorded')}</option>
                            <option value="0">{t('common.no')}</option>
                            <option value="1">{t('common.yes')}</option>
                        </select>
                    </Field>

                    {data.has_disability === '1' && (
                        <Field label={t('employees.disabilityType')} error={errors.disability_type} required htmlFor="disability_type">
                            <select id="disability_type" className={inputCls} value={data.disability_type} onChange={(e) => set({ disability_type: e.target.value })}>
                                <option value="">{t('employees.selectDisabilityType')}</option>
                                {DISABILITY_TYPES.map((type) => <option key={type} value={type}>{t(`employees.disabilityType_${type}`)}</option>)}
                            </select>
                        </Field>
                    )}
                </>
            )}
        </FormCard>
    );
}
