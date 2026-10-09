import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import CodeRuleForm, { type CodeRuleFormData } from '@/Components/code-rules/CodeRuleForm';
import type { TokenDefinition } from '@/Components/code-rules/FormatTokenGroup';

type CodeRuleView = Omit<CodeRuleFormData, 'scope_type' | 'name_am' | 'prefix' | 'suffix' | 'year_format' | 'description_en' | 'description_am' | 'sequence_scope_strategy' | 'sequence_scope_tokens'> & {
    id: string;
    scope_type: string | null;
    name_am: string | null;
    prefix: string | null;
    suffix: string | null;
    year_format: string | null;
    description_en: string | null;
    description_am: string | null;
    sequence_scope_strategy: string | null;
    sequence_scope_tokens: string[] | null;
    preview: string;
};

type Options = {
    entity_types: Array<{ value: string; label_key: string }>;
    scope_types: Array<{ value: string; label_key: string }>;
    reset_frequencies: Array<{ value: string; label_key: string }>;
    sequence_scope_strategies: Array<{ value: string; label_key: string }>;
    scope_options: Record<string, Array<{ id: string; label: string }>>;
    year_formats: Array<{ value: string; label: string }>;
};

export default function CodeRulesEdit({
    codeRule,
    options,
    can,
    available_tokens = [],
}: {
    codeRule: CodeRuleView;
    options: Options;
    can: { preview: boolean };
    available_tokens?: TokenDefinition[];
}) {
    const { t } = useLocale();
    const form = useForm<CodeRuleFormData>({
        entity_type: codeRule.entity_type,
        scope_type: codeRule.scope_type ?? '',
        scope_id: codeRule.scope_id,
        name_en: codeRule.name_en,
        name_am: codeRule.name_am ?? '',
        prefix: codeRule.prefix ?? '',
        suffix: codeRule.suffix ?? '',
        format: codeRule.format,
        separator: codeRule.separator,
        sequence_length: codeRule.sequence_length,
        next_number: codeRule.next_number,
        sequence_scope_strategy: codeRule.sequence_scope_strategy ?? 'auto',
        sequence_scope_tokens: codeRule.sequence_scope_tokens ?? [],
        reset_frequency: codeRule.reset_frequency,
        year_format: codeRule.year_format ?? 'Y',
        is_active: codeRule.is_active,
        allow_manual_override: codeRule.allow_manual_override,
        require_approval_for_override: codeRule.require_approval_for_override,
        description_en: codeRule.description_en ?? '',
        description_am: codeRule.description_am ?? '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.patch(route('code-rules.update', codeRule.id));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('codeRules.editTitle')} description={codeRule.name_en} />}>
            <Head title={t('codeRules.editTitle')} />
            <CodeRuleForm
                form={form}
                options={options}
                submitLabel={t('common.save')}
                cancelHref={route('code-rules.show', codeRule.id)}
                canPreview={can.preview}
                initialPreview={codeRule.preview}
                availableTokens={available_tokens}
                onSubmit={submit}
            />
        </AuthenticatedLayout>
    );
}
