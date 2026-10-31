import { useState, type InputHTMLAttributes } from 'react';
import { EyeIcon, EyeOffIcon } from '@/Components/Icons';
import { Input, cx } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';

/** A password field with the show/hide switch the Blade applicant pages had. */
export default function PasswordInput({ className, ...props }: Omit<InputHTMLAttributes<HTMLInputElement>, 'type'>) {
    const { t } = useLocale();
    const [visible, setVisible] = useState(false);

    return (
        <div className="relative">
            <Input {...props} type={visible ? 'text' : 'password'} className={cx('w-full pr-11', className)} />
            <button
                type="button"
                onClick={() => setVisible((shown) => !shown)}
                aria-label={visible ? t('cm.auth.hide_password') : t('cm.auth.show_password')}
                aria-pressed={visible}
                className="absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-r-control text-(--app-muted-foreground) hover:text-(--app-foreground) focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary"
            >
                {visible ? <EyeOffIcon className="h-4.5 w-4.5" /> : <EyeIcon className="h-4.5 w-4.5" />}
            </button>
        </div>
    );
}
