type Activity = { id: string; isic_code: string; name_en: string | null };

type Props = {
    value: string | null | undefined;
    onChange: (v: string) => void;
    activities: Activity[];
    placeholder?: string;
    id?: string;
    className?: string;
};

export default function IsicActivitySelect({ value, onChange, activities, placeholder, id, className }: Props) {
    const cls =
        className ??
        'w-full rounded-lg border border-(--app-border-strong) bg-(--app-surface) px-3 py-2 text-sm text-(--app-foreground) focus:border-primary focus:outline-hidden focus:ring-1 focus:ring-primary';
    return (
        <select id={id} className={cls} value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
            <option value="">{placeholder ?? '—'}</option>
            {activities.map((a) => (
                <option key={a.id} value={a.id}>
                    {a.isic_code} · {a.name_en ?? ''}
                </option>
            ))}
        </select>
    );
}
