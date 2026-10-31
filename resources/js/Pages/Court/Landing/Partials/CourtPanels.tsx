import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { ChevronDown, ChevronUp } from '@/Components/Icons';
import { Button, Card, FormField, Input, Textarea, cx } from '@/Components/ui';
import { usePublicText } from '@/court/i18n/usePublicText';
import type { LandingAbout, LandingFact, LandingMember } from '@/court/types/public';
import {
    AddButton, AmharicFields, EditorCard, EmptyBox, HiddenChip, OptionalHint, PanelHeading, RowActions, blankAmharic, useInlineEditor,
} from './LandingParts';

const visit = { preserveScroll: true } as const;
const MAX_FACTS = 8;
const fileInputClass = 'cursor-pointer py-1.5 file:mr-3 file:rounded-md file:border-0 file:bg-primary/10 file:px-3 file:py-1 file:text-xs file:font-semibold file:text-primary';
const blankFact = (): LandingFact => ({ label: '', value: '', label_am: '', value_am: '' });

/* ── About the court ──────────────────────────────────────────────────── */

/** The court's mandate and its key facts (established, seat, jurisdiction…), in both languages. */
export function AboutPanel({ about }: { about: LandingAbout }) {
    const { t } = usePublicText();
    const form = useForm<LandingAbout>({ body: about.body, body_am: about.body_am, facts: about.facts.length > 0 ? about.facts : [blankFact()] });
    const a = (key: string) => t(`cm.admin_landing.about.${key}`);
    const c = (key: string) => t(`cm.admin_landing.common.${key}`);
    const errors = form.errors as Record<string, string | undefined>;

    const setFact = (index: number, field: keyof LandingFact, value: string) =>
        form.setData('facts', form.data.facts.map((fact, i) => (i === index ? { ...fact, [field]: value } : fact)));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(route('court.admin.landing.about.update'), visit);
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <PanelHeading title={a('title')} description={a('description')} />

            <Card className="space-y-4">
                <div className="grid gap-4 lg:grid-cols-2">
                    <FormField label={`${c('about_body')} · ${c('english')}`} description={a('body_help')} error={errors.body}>
                        {({ id, describedBy }) => <Textarea id={id} aria-describedby={describedBy} rows={8} maxLength={5000} value={form.data.body} onChange={(event) => form.setData('body', event.target.value)} />}
                    </FormField>
                    <FormField label={`${c('about_body')} · ${c('amharic')}`} error={errors.body_am}>
                        {({ id }) => <Textarea id={id} lang="am" rows={8} maxLength={5000} value={form.data.body_am} onChange={(event) => form.setData('body_am', event.target.value)} />}
                    </FormField>
                </div>
            </Card>

            <Card className="space-y-4">
                <div>
                    <h3 className="text-sm font-semibold text-(--app-foreground)">{a('facts')}</h3>
                    <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{a('facts_help')}</p>
                </div>
                <ol className="space-y-3">
                    {form.data.facts.map((fact, index) => (
                        <li key={index} className="rounded-card border border-(--app-border) p-4">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <FormField label={`${a('fact_label')} · ${c('english')}`} error={errors[`facts.${index}.label`]}>
                                    {({ id }) => <Input id={id} maxLength={120} value={fact.label} onChange={(event) => setFact(index, 'label', event.target.value)} />}
                                </FormField>
                                <FormField label={`${a('fact_value')} · ${c('english')}`} error={errors[`facts.${index}.value`]}>
                                    {({ id }) => <Input id={id} maxLength={255} value={fact.value} onChange={(event) => setFact(index, 'value', event.target.value)} />}
                                </FormField>
                                <FormField label={`${a('fact_label')} · ${c('amharic')}`} error={errors[`facts.${index}.label_am`]}>
                                    {({ id }) => <Input id={id} lang="am" maxLength={120} value={fact.label_am} onChange={(event) => setFact(index, 'label_am', event.target.value)} />}
                                </FormField>
                                <FormField label={`${a('fact_value')} · ${c('amharic')}`} error={errors[`facts.${index}.value_am`]}>
                                    {({ id }) => <Input id={id} lang="am" maxLength={255} value={fact.value_am} onChange={(event) => setFact(index, 'value_am', event.target.value)} />}
                                </FormField>
                            </div>
                            <div className="mt-3 flex justify-end">
                                <Button type="button" variant="ghost" size="sm" onClick={() => form.setData('facts', form.data.facts.filter((_, i) => i !== index))}>{a('remove_fact')}</Button>
                            </div>
                        </li>
                    ))}
                </ol>
                <Button type="button" variant="outline" size="sm" disabled={form.data.facts.length >= MAX_FACTS} onClick={() => form.setData('facts', [...form.data.facts, blankFact()])}>
                    {a('add_fact')}
                </Button>
            </Card>

            <div className="flex justify-end">
                <Button type="submit" disabled={form.processing}>{a('save')}</Button>
            </div>
        </form>
    );
}

/* ── Members of the court ─────────────────────────────────────────────── */

type MemberForm = { name: string; role: string; bio: string; photo: File | null; remove_photo: boolean; am: Record<string, string> };
const MEMBER_AM = ['name', 'role', 'bio'] as const;
const EMPTY_MEMBER: MemberForm = { name: '', role: '', bio: '', photo: null, remove_photo: false, am: blankAmharic(MEMBER_AM) };

/** The president, judges and registrar the home page lists under the composition of the court. */
export function MembersPanel({ members }: { members: LandingMember[] }) {
    const { t } = usePublicText();
    const editor = useInlineEditor();
    const form = useForm<MemberForm>(EMPTY_MEMBER);
    const current = editor.editing !== null ? members.find((member) => member.id === editor.editing) ?? null : null;
    const m = (key: string) => t(`cm.admin_landing.members.${key}`);
    const c = (key: string) => t(`cm.admin_landing.common.${key}`);
    const errors = form.errors as Record<string, string | undefined>;

    const create = () => { form.reset(); form.clearErrors(); editor.toggleCreate(); };
    const cancel = () => { editor.close(); form.reset(); form.clearErrors(); };
    const edit = (member: LandingMember) => {
        form.setData({ name: member.name, role: member.role ?? '', bio: member.bio ?? '', photo: null, remove_photo: false, am: { ...blankAmharic(MEMBER_AM), ...member.am } });
        form.clearErrors();
        editor.startEdit(member.id);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { ...visit, forceFormData: true, onSuccess: cancel };
        if (editor.editing !== null) {
            // Multipart cannot travel as PUT; Laravel reads the method from `_method`.
            form.transform((data) => ({ ...data, _method: 'put' }));
            form.post(route('court.admin.landing.members.update', editor.editing), options);
        } else {
            form.transform((data) => data);
            form.post(route('court.admin.landing.members.store'), options);
        }
    };

    const move = (index: number, step: -1 | 1) => {
        const order = members.map((member) => member.id);
        const target = index + step;
        if (target < 0 || target >= order.length) return;
        [order[index], order[target]] = [order[target], order[index]];
        router.post(route('court.admin.landing.members.reorder'), { order }, visit);
    };

    return (
        <div className="space-y-4">
            <PanelHeading title={m('title')} description={m('description')} action={<AddButton label={m('add')} onClick={create} expanded={editor.open && editor.editing === null} />} />

            {editor.open && (
                <EditorCard
                    key={editor.editing ?? 'new'}
                    editorRef={editor.ref}
                    title={editor.editing !== null ? m('edit') : m('new')}
                    onSubmit={submit}
                    saveLabel={m('save')}
                    cancelLabel={c('cancel')}
                    processing={form.processing}
                    onCancel={cancel}
                >
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField label={c('member_name')} required error={errors.name}>
                            {({ id }) => <Input id={id} required maxLength={255} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />}
                        </FormField>
                        <FormField label={<>{c('member_role')} <OptionalHint /></>} error={errors.role}>
                            {({ id }) => <Input id={id} maxLength={255} value={form.data.role} onChange={(event) => form.setData('role', event.target.value)} />}
                        </FormField>
                    </div>
                    <FormField label={<>{c('member_bio')} <OptionalHint /></>} error={errors.bio}>
                        {({ id }) => <Textarea id={id} rows={3} maxLength={2000} value={form.data.bio} onChange={(event) => form.setData('bio', event.target.value)} />}
                    </FormField>
                    <FormField label={<>{c('member_photo')} <OptionalHint extra="2 MB" /></>} description={m('photo_help')} error={errors.photo}>
                        {({ id, describedBy }) => (
                            <div className="space-y-2">
                                <Input id={id} type="file" accept="image/*" aria-describedby={describedBy} className={fileInputClass} onChange={(event) => form.setData('photo', event.target.files?.[0] ?? null)} />
                                {current?.photo_url && (
                                    <div className="flex items-center gap-3">
                                        <img src={current.photo_url} className="h-12 w-12 rounded-full object-cover" alt="" />
                                        <label className="flex cursor-pointer items-center gap-1.5 text-xs text-red-600">
                                            <input type="checkbox" checked={form.data.remove_photo} onChange={(event) => form.setData('remove_photo', event.target.checked)} className="rounded border-(--app-border-strong) text-red-600" />
                                            {m('remove_photo')}
                                        </label>
                                    </div>
                                )}
                            </div>
                        )}
                    </FormField>
                    <AmharicFields
                        values={form.data.am}
                        errors={errors}
                        onChange={(key, value) => form.setData('am', { ...form.data.am, [key]: value })}
                        fields={[
                            { key: 'name', label: c('member_name'), maxLength: 255 },
                            { key: 'role', label: c('member_role'), maxLength: 255 },
                            { key: 'bio', label: c('member_bio'), multiline: true, maxLength: 2000 },
                        ]}
                    />
                </EditorCard>
            )}

            {members.length === 0 ? (
                <EmptyBox>{m('empty')}</EmptyBox>
            ) : (
                <ol className="space-y-2">
                    {members.map((member, index) => (
                        <li key={member.id} className="flex items-center gap-4 rounded-card border border-(--app-border) bg-(--app-surface) px-4 py-3">
                            <div className="flex shrink-0 flex-col">
                                <button type="button" disabled={index === 0} onClick={() => move(index, -1)} className="text-(--app-muted-foreground) hover:text-(--app-foreground) disabled:opacity-30" aria-label={`↑ ${member.name}`}>
                                    <ChevronUp className="h-4 w-4" aria-hidden="true" />
                                </button>
                                <button type="button" disabled={index === members.length - 1} onClick={() => move(index, 1)} className="text-(--app-muted-foreground) hover:text-(--app-foreground) disabled:opacity-30" aria-label={`↓ ${member.name}`}>
                                    <ChevronDown className="h-4 w-4" aria-hidden="true" />
                                </button>
                            </div>
                            {member.photo_url
                                ? <img src={member.photo_url} className="h-10 w-10 shrink-0 rounded-full object-cover" alt="" />
                                : <span aria-hidden="true" className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-(--app-surface-muted) text-xs font-semibold text-(--app-muted-foreground)">{member.name.charAt(0)}</span>}
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <p className="truncate text-sm font-semibold text-(--app-foreground)">{member.name}</p>
                                    {!member.is_active && <HiddenChip />}
                                </div>
                                <p className={cx('truncate text-xs text-(--app-muted-foreground)')}>{[member.role, member.am.name].filter(Boolean).join(' · ')}</p>
                            </div>
                            <RowActions
                                active={member.is_active}
                                toggleUrl={route('court.admin.landing.members.toggle', member.id)}
                                destroyUrl={route('court.admin.landing.members.destroy', member.id)}
                                onEdit={() => edit(member)}
                            />
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}
