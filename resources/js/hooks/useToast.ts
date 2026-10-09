import { useEffect, useState } from 'react';
import { subscribeToToasts, toast, type ToastItem } from '@/lib/toast';

export function useToast() {
    const [toasts, setToasts] = useState<ToastItem[]>([]);

    useEffect(() => {
        const unsubscribe = subscribeToToasts(setToasts);
        return () => unsubscribe();
    }, []);

    return { toasts, dismiss: toast.dismiss };
}
