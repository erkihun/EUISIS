import { Fragment, ReactNode } from 'react';
import { Menu, Transition, MenuButton, MenuItems, MenuItem } from '@headlessui/react';
import { Link } from '@inertiajs/react';
import { MoreHorizontal } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';

export interface AppActionItem {
    label: string;
    href?: string;
    method?: 'get' | 'post' | 'put' | 'patch' | 'delete';
    onClick?: () => void;
    variant?: 'default' | 'danger';
    icon?: ReactNode;
    disabled?: boolean;
    /** If explicitly false, the item is hidden. Defaults to true. */
    show?: boolean;
}

interface AppActionMenuProps {
    items: AppActionItem[];
    /** Accessible label for the trigger button. */
    label?: string;
    align?: 'left' | 'right';
}

const itemCls = {
    default: 'text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-800',
    danger:  'text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20',
};

/**
 * Three-dot (⋯) action dropdown for table rows and cards.
 *
 * ```tsx
 * <AppActionMenu items={[
 *   { label: t('common.edit'), href: route('employees.edit', id) },
 *   { label: t('common.delete'), variant: 'danger', onClick: () => setConfirm(true) },
 * ]} />
 * ```
 */
export default function AppActionMenu({ items, label, align = 'right' }: AppActionMenuProps) {
    const { t } = useLocale();
    const visible = items.filter((item) => item.show !== false);

    if (visible.length === 0) return null;

    return (
        <Menu as="div" className="relative inline-block text-left">
            <MenuButton
                className="inline-flex items-center justify-center rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)] dark:text-slate-500 dark:hover:bg-slate-800 dark:hover:text-slate-300"
                aria-label={label ?? t('common.actions')}
            >
                <MoreHorizontal className="h-4 w-4" aria-hidden="true" />
            </MenuButton>

            <Transition
                as={Fragment}
                enter="transition ease-out duration-100"
                enterFrom="transform opacity-0 scale-95"
                enterTo="transform opacity-100 scale-100"
                leave="transition ease-in duration-75"
                leaveFrom="transform opacity-100 scale-100"
                leaveTo="transform opacity-0 scale-95"
            >
                <MenuItems
                    className={[
                        'absolute z-20 mt-1 w-40 rounded-card border border-gray-100 bg-white py-1 shadow-lg',
                        'ring-1 ring-black/5 focus:outline-none dark:border-slate-700 dark:bg-slate-900',
                        align === 'right' ? 'right-0' : 'left-0',
                    ].join(' ')}
                >
                    {visible.map((item, idx) => (
                        <MenuItem key={idx} disabled={item.disabled}>
                            {({ focus }) =>
                                item.href ? (
                                    <Link
                                        href={item.href}
                                        method={item.method ?? 'get'}
                                        as={item.method && item.method !== 'get' ? 'button' : 'a'}
                                        className={[
                                            'flex w-full items-center gap-2 px-3 py-2 text-sm',
                                            itemCls[item.variant ?? 'default'],
                                            focus ? 'bg-gray-50 dark:bg-slate-800' : '',
                                            item.disabled ? 'pointer-events-none opacity-50' : '',
                                        ].join(' ')}
                                    >
                                        {item.icon && <span className="h-4 w-4 shrink-0" aria-hidden="true">{item.icon}</span>}
                                        {item.label}
                                    </Link>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={item.onClick}
                                        disabled={item.disabled}
                                        className={[
                                            'flex w-full items-center gap-2 px-3 py-2 text-sm',
                                            itemCls[item.variant ?? 'default'],
                                            focus ? 'bg-gray-50 dark:bg-slate-800' : '',
                                            item.disabled ? 'pointer-events-none opacity-50' : '',
                                        ].join(' ')}
                                    >
                                        {item.icon && <span className="h-4 w-4 shrink-0" aria-hidden="true">{item.icon}</span>}
                                        {item.label}
                                    </button>
                                )
                            }
                        </MenuItem>
                    ))}
                </MenuItems>
            </Transition>
        </Menu>
    );
}
