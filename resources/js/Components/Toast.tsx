import { useCallback, useEffect, useRef, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { AlertTriangle, CheckCircle, InfoIcon, X, XCircle } from '@/Components/Icons';
import type { ToastItem, ToastType } from '@/lib/toast';

const DURATION_MS = 5000;

interface StyleDef {
    container: string;
    border: string;
    icon: string;
    bar: string;
    ariaLive: 'polite' | 'assertive';
}

const styles: Record<ToastType, StyleDef> = {
    success: {
        container: 'bg-green-50 dark:bg-green-900 text-green-800 dark:text-green-200',
        border:    'border-green-200 dark:border-green-700',
        icon:      'text-green-500 dark:text-green-400',
        bar:       'bg-green-500',
        ariaLive:  'polite',
    },
    error: {
        container: 'bg-red-50 dark:bg-red-900 text-red-800 dark:text-red-200',
        border:    'border-red-200 dark:border-red-700',
        icon:      'text-red-500 dark:text-red-400',
        bar:       'bg-red-500',
        ariaLive:  'assertive',
    },
    warning: {
        container: 'bg-amber-50 dark:bg-amber-900 text-amber-800 dark:text-amber-200',
        border:    'border-amber-200 dark:border-amber-700',
        icon:      'text-amber-500 dark:text-amber-400',
        bar:       'bg-amber-500',
        ariaLive:  'assertive',
    },
    info: {
        container: 'bg-blue-50 dark:bg-blue-900 text-blue-800 dark:text-blue-200',
        border:    'border-blue-200 dark:border-blue-700',
        icon:      'text-blue-500 dark:text-[color:var(--color-primary)]',
        bar:       'bg-blue-500',
        ariaLive:  'polite',
    },
};

const TypeIcon = ({ type, className }: { type: ToastType; className?: string }) => {
    const props = { className: `h-5 w-5 shrink-0 ${className ?? ''}` };
    switch (type) {
        case 'success': return <CheckCircle {...props} />;
        case 'error':   return <XCircle {...props} />;
        case 'warning': return <AlertTriangle {...props} />;
        case 'info':    return <InfoIcon {...props} />;
        default:        return <InfoIcon {...props} />;
    }
};

interface ToastProps {
    toast: ToastItem;
    onDismiss: (id: string) => void;
}

export default function Toast({ toast: item, onDismiss }: ToastProps) {
    const { t } = useLocale();
    const style = styles[item.type];
    const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const [exiting, setExiting] = useState(false);

    const startTimer = useCallback(() => {
        timerRef.current = setTimeout(() => {
            setExiting(true);
            setTimeout(() => onDismiss(item.id), 180);
        }, DURATION_MS);
    }, [item.id, onDismiss]);

    const clearTimer = useCallback(() => {
        if (timerRef.current) {
            clearTimeout(timerRef.current);
            timerRef.current = null;
        }
    }, []);

    useEffect(() => {
        startTimer();
        return () => clearTimer();
    }, [startTimer, clearTimer]);

    const handleDismiss = useCallback(() => {
        clearTimer();
        setExiting(true);
        setTimeout(() => onDismiss(item.id), 180);
    }, [clearTimer, item.id, onDismiss]);

    return (
        <div
            role="alert"
            aria-live={style.ariaLive}
            aria-atomic="true"
            className={[
                'relative flex w-full items-start gap-3 overflow-hidden',
                'rounded-card border shadow-lg px-4 py-3',
                style.container,
                style.border,
                exiting ? 'toast-exit' : 'toast-enter',
            ].join(' ')}
            onMouseEnter={clearTimer}
            onMouseLeave={startTimer}
            onFocus={clearTimer}
            onBlur={startTimer}
        >
            <span className={`mt-0.5 ${style.icon}`} aria-hidden="true">
                <TypeIcon type={item.type} />
            </span>

            <p className="flex-1 text-sm font-medium leading-snug break-words">
                {item.message}
            </p>

            <button
                type="button"
                onClick={handleDismiss}
                aria-label={t('common.dismiss')}
                className="shrink-0 rounded p-0.5 opacity-60 hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-current transition-opacity"
            >
                <X className="h-4 w-4" aria-hidden="true" />
            </button>

            {/* Auto-dismiss progress bar */}
            <span
                aria-hidden="true"
                className={`pointer-events-none absolute bottom-0 left-0 h-0.5 ${style.bar}`}
                style={{ animation: `toast-shrink ${DURATION_MS}ms linear forwards` }}
            />
        </div>
    );
}
