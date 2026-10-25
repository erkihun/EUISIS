// Re-export from ConfirmProvider so all consumers share the same module
// (prevents Vite from creating separate chunks with duplicate createContext calls)
export { useConfirm, useConfirmContext, type ConfirmOptions, type ConfirmResult } from '@/Components/ConfirmProvider';
