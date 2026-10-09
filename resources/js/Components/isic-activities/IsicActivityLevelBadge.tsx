type Level = 'section' | 'division' | 'group' | 'class' | string;

const colorMap: Record<string, string> = {
    section: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300',
    division: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
    group: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300',
    class: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
};

export default function IsicActivityLevelBadge({ level }: { level: Level }) {
    const cls = colorMap[level] ?? 'bg-gray-100 text-gray-800 dark:bg-slate-800 dark:text-slate-300';
    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${cls}`}>{level}</span>
    );
}
