/**
 * Thin wrapper around Sonner so the rest of the app uses a stable
 * `toast.success / .error / .warning / .info` API regardless of the
 * underlying library.
 */
import { toast as sonner, type ExternalToast } from 'sonner';

/** Optional per-toast settings: a second line (`description`), `duration`, or an `id` to replace a toast. */
export type ToastOptions = Pick<ExternalToast, 'description' | 'duration' | 'id'>;

export const toast = {
    success: (message: string, options?: ToastOptions) => sonner.success(message, options),
    error:   (message: string, options?: ToastOptions) => sonner.error(message, options),
    warning: (message: string, options?: ToastOptions) => sonner.warning(message, options),
    info:    (message: string, options?: ToastOptions) => sonner.info(message, options),
    dismiss: (id?: string | number) => sonner.dismiss(id),
};

export type ToastType = 'success' | 'error' | 'warning' | 'info';
