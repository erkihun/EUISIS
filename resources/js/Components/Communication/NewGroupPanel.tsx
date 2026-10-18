import { useState, type FormEvent, type JSX } from 'react';
import { Button } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';
import { communicationApi, firstError, statusOf } from '@/lib/communication/api';
import { tr } from '@/lib/communication/format';
import PeoplePicker from '@/Components/Communication/PeoplePicker';
import { ArrowLeftIcon } from '@/Components/NavIcons';
import type { Person } from '@/types/communication';

const MAX_MEMBERS = 256;

/** Name a group and choose its people; shown in place of the conversation list. */
export default function NewGroupPanel({ onBack, onCreated }: { onBack: () => void; onCreated: (id: string) => void }): JSX.Element {
    const { t } = useLocale();
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [people, setPeople] = useState<Person[]>([]);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (!title.trim() || people.length === 0) return;
        setSaving(true);
        setError(null);
        try {
            onCreated(await communicationApi.createGroup({ title: title.trim(), description: description.trim() || null, member_ids: people.map((p) => p.id) }));
        } catch (reason) {
            setError(firstError(reason) ?? (statusOf(reason) === 403 ? t('communication.errors.forbidden') : t('communication.errors.generic')));
            setSaving(false);
        }
    };

    const field = 'w-full rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-background)] px-2.5 text-sm text-[color:var(--app-foreground)] placeholder:text-[color:var(--app-muted-foreground)] focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]/15';

    return (
        <form onSubmit={submit} className="flex h-full min-h-0 flex-col" onKeyDown={(event) => { if (event.key === 'Escape') onBack(); }}>
            <div className="flex items-center gap-2 px-3 pb-2 pt-3">
                <button
                    type="button"
                    onClick={onBack}
                    aria-label={t('communication.chat.back')}
                    className="flex h-8 w-8 items-center justify-center rounded-control text-[color:var(--app-muted-foreground)] hover:bg-[color:var(--app-surface-muted)] hover:text-[color:var(--app-foreground)]"
                >
                    <ArrowLeftIcon className="h-4 w-4" />
                </button>
                <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{t('communication.newGroup.title')}</h2>
            </div>

            <div className="comm-scroll min-h-0 flex-1 space-y-4 overflow-y-auto px-3 pb-3">
                <label className="block space-y-1">
                    <span className="text-xs font-medium text-[color:var(--app-foreground)]">{t('communication.newGroup.name')}</span>
                    <input value={title} onChange={(e) => setTitle(e.target.value)} maxLength={120} required autoFocus placeholder={t('communication.newGroup.namePlaceholder')} className={`${field} h-9`} />
                </label>
                <label className="block space-y-1">
                    <span className="text-xs font-medium text-[color:var(--app-foreground)]">{t('communication.newGroup.description')}</span>
                    <textarea value={description} onChange={(e) => setDescription(e.target.value)} maxLength={1000} rows={2} className={`${field} resize-none py-2`} />
                </label>
                <fieldset className="space-y-1.5">
                    <legend className="text-xs font-medium text-[color:var(--app-foreground)]">{t('communication.newGroup.people')}</legend>
                    <p className="text-xs text-[color:var(--app-muted-foreground)]">{t('communication.newGroup.peopleHelp')} {tr(t('communication.newGroup.max'), { max: MAX_MEMBERS })}</p>
                    <PeoplePicker selected={people} onChange={setPeople} max={MAX_MEMBERS - 1} />
                </fieldset>
                {error && <p role="alert" className="text-xs text-[color:var(--color-danger,#b42318)]">{error}</p>}
            </div>

            <div className="border-t border-[color:var(--app-border)] p-3">
                <Button type="submit" variant="primary" className="w-full" disabled={saving || !title.trim() || people.length === 0} loading={saving}>
                    {saving ? t('communication.newGroup.creating') : t('communication.newGroup.create')}
                </Button>
            </div>
        </form>
    );
}
