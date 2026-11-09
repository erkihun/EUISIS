import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import { cx, FormLabel, inputClassName } from '@/Components/ui';

export type JobMasterGroup = {
    kind: string;
    field: 'job_level_id' | 'profession_id' | 'employment_type_id' | 'position_category_id';
    options: Array<{ id: string; code: string; name_en: string; name_am: string | null }>;
};

export type JobMasterFields = Record<JobMasterGroup['field'], string>;

/** Core only (ADR 0010): the level, profession, employment type and category selects of the position form. */
export default function JobMasterSelects({ groups, values, onChange, errors }: {
    groups: JobMasterGroup[];
    values: JobMasterFields;
    onChange: (field: JobMasterGroup['field'], value: string) => void;
    errors: Partial<Record<string, string>>;
}) {
    const { t, locale } = useLocale();

    return (
        <>
            {groups.map((group) => (
                <div key={group.field} className="space-y-1.5">
                    <FormLabel htmlFor={group.field}>{t(`core.jobMasters.kinds.${group.kind}`)}</FormLabel>
                    <select id={group.field} className={cx(inputClassName, 'w-full')} value={values[group.field]} onChange={(event) => onChange(group.field, event.target.value)}>
                        <option value="">—</option>
                        {group.options.map((option) => (
                            <option key={option.id} value={option.id}>{option.code} · {localizedName(option.name_en, option.name_am, locale)}</option>
                        ))}
                    </select>
                    {errors[group.field] && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors[group.field]}</p>}
                </div>
            ))}
        </>
    );
}
