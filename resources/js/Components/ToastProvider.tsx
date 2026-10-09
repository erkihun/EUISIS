import { useEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import { useToast } from '@/hooks/useToast';
import { toast as showToast } from '@/lib/toast';
import ToastItem from '@/Components/Toast';

/**
 * Shape of the flash prop shared by HandleInertiaRequests.
 *
 * Supports both a single-message style   { message, type }
 * and individual-key style               { success, error, warning, info }
 */
interface FlashProps {
    // Individual-key form (preferred, set via session('success'), etc.)
    success?: string | null;
    error?: string | null;
    warning?: string | null;
    info?: string | null;
    // Single-message form (legacy / fallback)
    message?: string | null;
    type?: string | null;
}

export default function ToastProvider() {
    const { toasts, dismiss } = useToast();
    const page = usePage();

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const flash = (page.props as any).flash as FlashProps | undefined;

    /**
     * Deduplication key — track what we've already dispatched for this
     * specific page URL + flash payload combination.
     */
    const shownRef = useRef<string>('');

    useEffect(() => {
        if (!flash) return;

        // Build a stable fingerprint of the entire flash payload
        const fingerprint = [
            page.url,
            flash.success ?? '',
            flash.error ?? '',
            flash.warning ?? '',
            flash.info ?? '',
            flash.message ?? '',
            flash.type ?? '',
        ].join('||');

        if (shownRef.current === fingerprint) return;
        shownRef.current = fingerprint;

        // --- Individual-key form (takes priority) ---
        if (flash.success)  showToast.success(flash.success);
        if (flash.error)    showToast.error(flash.error);
        if (flash.warning)  showToast.warning(flash.warning);
        if (flash.info)     showToast.info(flash.info);

        // --- Single-message fallback (only fires if no individual keys matched) ---
        const hadIndividualKeys =
            Boolean(flash.success) ||
            Boolean(flash.error) ||
            Boolean(flash.warning) ||
            Boolean(flash.info);

        if (!hadIndividualKeys && flash.message) {
            const type = flash.type as 'success' | 'error' | 'warning' | 'info' | undefined;
            if (type === 'success')      showToast.success(flash.message);
            else if (type === 'error')   showToast.error(flash.message);
            else if (type === 'warning') showToast.warning(flash.message);
            else                         showToast.info(flash.message);
        }
    }, [flash, page.url]);

    if (toasts.length === 0) return null;

    return (
        <div
            aria-label="Notifications"
            aria-live="polite"
            className="pointer-events-none fixed inset-x-0 top-4 z-[9999] flex flex-col items-center gap-2 px-4"
        >
            {toasts.map((item) => (
                <div key={item.id} className="pointer-events-auto w-full max-w-md">
                    <ToastItem toast={item} onDismiss={dismiss} />
                </div>
            ))}
        </div>
    );
}
