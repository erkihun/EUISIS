/**
 * Thin wrapper around Sonner so the rest of the app uses a stable
 * `toast.success / .error / .warning / .info` API regardless of the
 * underlying library.
 */
import { toast as sonner } from 'sonner';

export const toast = {
    success: (message: string) => sonner.success(message),
    error:   (message: string) => sonner.error(message),
    warning: (message: string) => sonner.warning(message),
    info:    (message: string) => sonner.info(message),
    dismiss: (id?: string | number) => sonner.dismiss(id),
};

export type ToastType = 'success' | 'error' | 'warning' | 'info';
