import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ActionMenu, type ActionItem } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';

export interface AppActionItem {
    label: ReactNode;
    href?: string;
    /** Inertia method for an href; anything but GET renders the link as a button. */
    method?: 'get' | 'post' | 'put' | 'patch' | 'delete';
    onClick?: () => void;
    variant?: 'default' | 'warning' | 'danger';
    icon?: ReactNode;
    disabled?: boolean;
    /** Tooltip, e.g. why the action is disabled. */
    title?: string;
    /** If explicitly false, the item is hidden. Defaults to true. */
    show?: boolean;
    separatorBefore?: boolean;
}

interface AppActionMenuProps {
    items: AppActionItem[];
    /** Accessible label for the trigger button; defaults to "Actions". */
    label?: string;
}

/**
 * The ⋮ row menu: one trigger at the end of a table row (or card) that opens the record's actions.
 *
 * ```tsx
 * <AppActionMenu items={[
 *   { label: t('common.edit'), href: route('employees.edit', id), show: can.update },
 *   { label: t('common.delete'), variant: 'danger', onClick: () => destroy(row), show: can.delete },
 * ]} />
 * ```
 *
 * Links go through Inertia; hidden items (`show: false`) are dropped, and a menu with no visible
 * item renders nothing, so a row without permissions shows no trigger.
 */
export default function AppActionMenu({ items, label }: AppActionMenuProps) {
    const { t } = useLocale();

    const mapped: ActionItem[] = items.map((item) => ({
        label: item.label,
        icon: item.icon,
        href: item.href,
        method: item.method,
        onClick: item.onClick,
        tone: item.variant === 'danger' || item.variant === 'warning' ? item.variant : 'default',
        disabled: item.disabled,
        title: item.title,
        show: item.show,
        separatorBefore: item.separatorBefore,
    }));

    return (
        <ActionMenu
            items={mapped}
            label={label ?? t('common.actions')}
            renderLink={(item, className) => (
                <Link
                    href={item.href ?? '#'}
                    method={item.method ?? 'get'}
                    as={item.method && item.method !== 'get' ? 'button' : 'a'}
                    className={className}
                    title={item.title}
                    aria-disabled={item.disabled || undefined}
                >
                    {item.icon}
                    {item.label}
                </Link>
            )}
        />
    );
}
