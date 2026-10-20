import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import CatalogEntryForm, { type CatalogEntry, type CatalogOption } from '@/Components/organizationDesign/CatalogEntryForm';
import { StatusPill, nameOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Alert, Button, Card, CardHeader, CardTitle, actionLinkClassName, badgeClassName } from '@/Components/ui';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

type Entry = CatalogEntry & { id: string; code: string; name_en: string; is_active: boolean };
type TypeRow = Entry & { competencies_count: number };
type LevelRow = Entry & { rank: number };
type CompetencyRow = Entry & { competency_type_id: string | null };
type Framework = { id: string; code: string; name_en: string; name_am: string | null; is_active: boolean; competencies: CompetencyRow[] };

type Props = {
    types: TypeRow[];
    levels: LevelRow[];
    educationLevels: LevelRow[];
    frameworks: Framework[];
    can: { manage: boolean };
};

type Editing =
    | { kind: 'type'; record: TypeRow | null }
    | { kind: 'level'; record: LevelRow | null }
    | { kind: 'education'; record: LevelRow | null }
    | { kind: 'competency'; record: CompetencyRow | null; frameworkId: string }
    | null;

export default function CompetencyCatalogIndex({ types, levels, educationLevels, frameworks, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const [editing, setEditing] = useState<Editing>(null);
    const typeOptions: CatalogOption[] = types.filter((x) => x.is_active).map((x) => ({ id: x.id, code: x.code, name_en: x.name_en, name_am: x.name_am ?? null }));
    const typeName = (id: string | null) => { const type = types.find((x) => x.id === id); return type ? nameOf(type, locale) : tt('competencyCatalog.untyped'); };

    const list = (rows: Entry[], empty: string, onEdit: (row: Entry) => void, extra?: (row: Entry) => React.ReactNode) => rows.length === 0 ? (
        <p className="px-5 py-4 text-sm text-(--app-muted-foreground)">{empty}</p>
    ) : (
        <ul className="divide-y divide-(--app-border)">
            {rows.map((row) => (
                <li key={row.id} className="flex flex-wrap items-center gap-3 px-5 py-3 text-sm">
                    <span className="font-mono text-xs text-(--app-muted-foreground)">{row.code}</span>
                    <span className="min-w-0 flex-1 font-medium text-(--app-foreground)">{nameOf(row, locale)}</span>
                    {extra?.(row)}
                    <StatusPill status={row.is_active ? 'active' : 'inactive'} />
                    {can.manage && <button type="button" className={actionLinkClassName()} onClick={() => onEdit(row)}>{t('common.edit')}</button>}
                </li>
            ))}
        </ul>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={tt('competencyCatalog.title')} description={tt('competencyCatalog.description')} />}>
            <Head title={tt('competencyCatalog.title')} />

            <div className="space-y-4">
                {!can.manage && <Alert tone="info">{tt('competencyCatalog.readOnly')}</Alert>}
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="flex items-center justify-between gap-3">
                            <CardTitle>{tt('competencyCatalog.types')}</CardTitle>
                            {can.manage && <Button size="sm" variant="outline" onClick={() => setEditing({ kind: 'type', record: null })}>{tt('competencyCatalog.addType')}</Button>}
                        </CardHeader>
                        {list(types, tt('competencyCatalog.noTypes'), (row) => setEditing({ kind: 'type', record: row as TypeRow }), (row) => <span className="text-xs text-(--app-muted-foreground)">{tt('competencyCatalog.competenciesCount').replace(':count', String((row as TypeRow).competencies_count))}</span>)}
                    </Card>
                    <Card>
                        <CardHeader className="flex items-center justify-between gap-3">
                            <div><CardTitle>{tt('competencyCatalog.levels')}</CardTitle><p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('competencyCatalog.levelsHelp')}</p></div>
                            {can.manage && <Button size="sm" variant="outline" onClick={() => setEditing({ kind: 'level', record: null })}>{tt('competencyCatalog.addLevel')}</Button>}
                        </CardHeader>
                        {list(levels, tt('competencyCatalog.noLevels'), (row) => setEditing({ kind: 'level', record: row as LevelRow }), (row) => <span className={badgeClassName('info')}>{tt('fields.rank')} {(row as LevelRow).rank}</span>)}
                    </Card>
                    <Card>
                        <CardHeader className="flex items-center justify-between gap-3">
                            <div><CardTitle>{tt('competencyCatalog.educationLevels')}</CardTitle><p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('competencyCatalog.educationHelp')}</p></div>
                            {can.manage && <Button size="sm" variant="outline" onClick={() => setEditing({ kind: 'education', record: null })}>{tt('competencyCatalog.addEducationLevel')}</Button>}
                        </CardHeader>
                        {list(educationLevels, tt('competencyCatalog.noEducationLevels'), (row) => setEditing({ kind: 'education', record: row as LevelRow }), (row) => <span className={badgeClassName('info')}>{tt('fields.rank')} {(row as LevelRow).rank}</span>)}
                    </Card>
                </div>

                {frameworks.map((f) => (
                    <Card key={f.id}>
                        <CardHeader className="flex items-center justify-between gap-3">
                            <CardTitle><span className="font-mono text-xs text-(--app-muted-foreground)">{f.code}</span> {nameOf(f, locale)}</CardTitle>
                            {can.manage && <Button size="sm" variant="outline" onClick={() => setEditing({ kind: 'competency', record: null, frameworkId: f.id })}>{tt('competencyCatalog.addCompetency')}</Button>}
                        </CardHeader>
                        {list(f.competencies, tt('competencyCatalog.noCompetencies'), (row) => setEditing({ kind: 'competency', record: row as CompetencyRow, frameworkId: f.id }), (row) => <span className={badgeClassName('neutral')}>{typeName((row as CompetencyRow).competency_type_id)}</span>)}
                    </Card>
                ))}
            </div>

            {editing?.kind === 'type' && <CatalogEntryForm title={editing.record ? tt('competencyCatalog.editType') : tt('competencyCatalog.addType')} record={editing.record} url={editing.record ? route('organization-design.competency-types.update', editing.record.id) : route('organization-design.competency-types.store')} method={editing.record ? 'put' : 'post'} fields={{ description: true, sortOrder: true }} onClose={() => setEditing(null)} />}
            {editing?.kind === 'level' && <CatalogEntryForm title={editing.record ? tt('competencyCatalog.editLevel') : tt('competencyCatalog.addLevel')} record={editing.record} url={editing.record ? route('organization-design.competency-levels.update', editing.record.id) : route('organization-design.competency-levels.store')} method={editing.record ? 'put' : 'post'} fields={{ description: true, rank: true, rankMax: 10 }} onClose={() => setEditing(null)} />}
            {editing?.kind === 'education' && <CatalogEntryForm title={editing.record ? tt('competencyCatalog.editEducationLevel') : tt('competencyCatalog.addEducationLevel')} record={editing.record} url={editing.record ? route('organization-design.education-levels.update', editing.record.id) : route('organization-design.education-levels.store')} method={editing.record ? 'put' : 'post'} fields={{ rank: true, rankMax: 20 }} onClose={() => setEditing(null)} />}
            {editing?.kind === 'competency' && <CatalogEntryForm title={editing.record ? tt('competencyCatalog.editCompetency') : tt('competencyCatalog.addCompetency')} record={editing.record} url={editing.record ? route('organization-design.competencies.update', editing.record.id) : route('organization-design.competencies.store', editing.frameworkId)} method={editing.record ? 'put' : 'post'} fields={{ description: true, sortOrder: true, competencyType: true }} options={{ competencyTypes: typeOptions }} onClose={() => setEditing(null)} />}
        </AuthenticatedLayout>
    );
}
