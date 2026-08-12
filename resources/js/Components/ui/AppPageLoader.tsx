type AppPageLoaderProps = {
    fullScreen?: boolean;
};

export default function AppPageLoader({ fullScreen = false }: AppPageLoaderProps) {
    return (
        <div
            className={[
                'flex items-center justify-center bg-gray-50 dark:bg-slate-950',
                fullScreen ? 'fixed inset-0 z-[9998]' : 'min-h-[60vh]',
            ].join(' ')}
            role="status"
            aria-label="Loading"
        >
            <div className="flex flex-col items-center gap-3">
                <div className="relative h-11 w-11">
                    <div className="absolute inset-0 rounded-full border-[3px] border-blue-100 dark:border-slate-800" />
                    <div className="absolute inset-0 animate-spin rounded-full border-[3px] border-transparent border-t-blue-600 dark:border-t-blue-400" />
                </div>
            </div>
        </div>
    );
}
