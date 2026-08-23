import InputError from '@/Components/InputError';
import {
    ID_CARD_TEMPLATE_VALUES,
    resolveIdCardTemplate,
    type IdCardTemplate,
} from '@/Components/IdCards/idCardTemplates';
import { useLocale } from '@/hooks/useLocale';

type Props = {
    label: string;
    description?: string | null;
    value: string;
    error?: string;
    disabled?: boolean;
    onChange: (value: IdCardTemplate) => void;
};

export default function IdCardTemplateSettingField({
    label,
    description,
    value,
    error,
    disabled = false,
    onChange,
}: Props) {
    const { t } = useLocale();
    const selected = resolveIdCardTemplate(value);

    return (
        <div className="space-y-3 px-5 py-4">
            <div>
                <span className="text-sm font-medium text-gray-900 dark:text-slate-100">{label}</span>
                {description && (
                    <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{description}</p>
                )}
            </div>

            <div className="grid gap-3 sm:grid-cols-3">
                {ID_CARD_TEMPLATE_VALUES.map((template) => {
                    const isSelected = selected === template;

                    return (
                        <button
                            key={template}
                            type="button"
                            disabled={disabled}
                            aria-pressed={isSelected}
                            onClick={() => onChange(template)}
                            className={[
                                'rounded-xl border p-3 text-left transition',
                                isSelected
                                    ? 'border-blue-500 bg-blue-50 ring-2 ring-blue-500/20 dark:bg-blue-950/30'
                                    : 'border-gray-200 bg-white hover:border-blue-300 dark:border-slate-700 dark:bg-slate-950',
                                disabled ? 'cursor-not-allowed opacity-60' : '',
                            ].join(' ')}
                        >
                            <TemplateThumbnail template={template} />
                            <span className="mt-2 block text-sm font-semibold text-gray-900 dark:text-slate-100">
                                {t(`settings.idCardTemplates.${template}.name`)}
                            </span>
                            <span className="mt-0.5 block text-xs leading-5 text-gray-500 dark:text-slate-400">
                                {t(`settings.idCardTemplates.${template}.description`)}
                            </span>
                        </button>
                    );
                })}
            </div>

            <InputError message={error} />
        </div>
    );
}

function TemplateThumbnail({ template }: { template: IdCardTemplate }) {
    const background = template === 'modern'
        ? 'linear-gradient(110deg, #2563EB 0%, #2563EB 62%, #172554 62%, #172554 100%)'
        : template === 'minimal'
            ? 'linear-gradient(180deg, #1D4ED8 0%, #1D4ED8 88%, #F59E0B 88%, #F59E0B 100%)'
            : 'linear-gradient(135deg, #2563EB 0%, #1E3A8A 100%)';

    return (
        <span
            className={[
                'relative block aspect-[85.6/54] overflow-hidden shadow-sm',
                template === 'modern' ? 'rounded-xl' : template === 'minimal' ? 'rounded-md' : 'rounded-lg',
            ].join(' ')}
            style={{ background }}
            aria-hidden="true"
        >
            <span className="absolute inset-x-0 top-0 h-3 bg-white/15" />
            <span
                className={[
                    'absolute left-2 top-5 block bg-white/80',
                    template === 'modern' ? 'h-7 w-7 rounded-full' : 'h-8 w-6 rounded-sm',
                ].join(' ')}
            />
            <span className="absolute left-11 right-3 top-6 h-1.5 rounded bg-white/80" />
            <span className="absolute left-11 right-6 top-10 h-1 rounded bg-white/45" />
            {template === 'modern' && <span className="absolute -right-3 -top-3 h-14 w-14 rounded-full border-[8px] border-white/10" />}
        </span>
    );
}
