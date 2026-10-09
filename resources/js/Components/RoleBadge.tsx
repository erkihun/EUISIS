interface Props {
    role: string;
    className?: string;
}

const roleStyles: Record<string, string> = {
    'Super Admin': 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300',
    'City Admin': 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
    'HR Officer': 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
    'Auditor': 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300',
};

const defaultStyle = 'bg-gray-100 text-gray-700 dark:bg-slate-700 dark:text-slate-300';

export default function RoleBadge({ role, className = '' }: Props) {
    const style = roleStyles[role] ?? defaultStyle;
    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${style} ${className}`}>
            {role}
        </span>
    );
}
