import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import CodeRuleForm, { type CodeRuleFormData } from '@/Components/code-rules/CodeRuleForm';
import type { TokenDefinition } from '@/Components/code-rules/FormatTokenGroup';

type Options = {
    entity_types: Array<{ value: string; label_key: string }>;
    scope_types: Array<{ value: string; label_key: string }>;
    reset_frequencies: Array<{ value: string; label_key: string }>;
    sequence_scope_strategies: Array<{ value: string; label_key: string }>;
    scope_options: Record<string, Array<{ id: string; label: string }>>;
    year_formats: Array<{ value: string; label: string }>;
};

export default function CodeRulesCreate({
    options,
    can,
    available_tokens = [],
}: {
    options: Options;
    can: { preview: boolean };
    available_tokens?: TokenDefinition[];
}) {
    const { t } = useLocale();
    const form = useForm<CodeRuleFormData>({
        entity_type: 'organization',
        scope_type: '',
        scope_id: '',
        name_en: '',
        name_am: '',
        prefix: 'ORG',
        suffix: '',
        format: '{PREFIX}-{YEAR}-{SEQUENCE_PADDED}',
        separator: '-',
        sequence_length: 4,
        next_number: 1,
        sequence_scope_strategy: 'auto',
        sequence_scope_tokens: [],
        reset_frequency: 'never',
        year_format: 'Y',
        is_active: true,
        allow_manual_override: false,
        require_approval_for_override: true,
        description_en: '',
        description_am: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(route('code-rules.store'));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('codeRules.createTitle')} description={t('codeRules.description')} />}>
            <Head title={t('codeRules.createTitle')} />
            <CodeRuleForm
                form={form}
                options={options}
                submitLabel={t('common.save')}
                cancelHref={route('code-rules.index')}
                canPreview={can.preview}
                availableTokens={available_tokens}
                onSubmit={submit}
            />
        </AuthenticatedLayout>
    );
}
