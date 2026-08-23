import AppLayout from '@/Layouts/AppLayout';
import { useLocale } from '@/hooks/useLocale';

type Integration = {
    base_url: string;
    token_configured: boolean;
    timeout: number;
    provider_code: string;
    required_scopes: string[];
};

export default function Settings({ integration }: { integration: Integration }) {
    const { t } = useLocale();
    return (
        <AppLayout title={t('settings.title')}>
            <section className="rounded-2xl border border-gray-200 bg-white p-5">
                <h2 className="mb-3 text-sm font-semibold text-slate-900">{t('extra.euisisIntegration')}</h2>

                <dl className="grid gap-3 sm:grid-cols-2">
                    <div>
                        <dt className="text-xs text-slate-500">{t('extra.baseUrl')}</dt>
                        <dd className="font-mono text-sm text-slate-900">{integration.base_url}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-slate-500">{t('extra.apiToken')}</dt>
                        <dd className="text-sm font-medium">
                            {/* The token value itself is never sent to the browser. */}
                            {integration.token_configured
                                ? <span className="text-emerald-700">{t('extra.configured')}</span>
                                : <span className="text-red-700">{t('extra.notConfigured')}</span>}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-slate-500">{t('extra.timeout')}</dt>
                        <dd className="text-sm text-slate-900">{integration.timeout}s</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-slate-500">{t('extra.providerCode')}</dt>
                        <dd className="font-mono text-sm text-slate-900">{integration.provider_code || '-'}</dd>
                    </div>
                </dl>

                <div className="mt-4">
                    <p className="mb-1 text-xs text-slate-500">{t('extra.requiredScopes')}</p>
                    <div className="flex flex-wrap gap-1.5">
                        {integration.required_scopes.map((scope) => (
                            <span key={scope} className="rounded-full bg-slate-100 px-2 py-0.5 font-mono text-[10px] text-slate-700">
                                {scope}
                            </span>
                        ))}
                    </div>
                </div>
            </section>
        </AppLayout>
    );
}
