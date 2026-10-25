import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';
import { AuthProvider } from './auth';
import { LocaleProvider } from './i18n';
import { initTheme } from './lib/theme';
import './index.css';

// Theme before the first paint, as EUISIS app.tsx does.
initTheme('system');

createRoot(document.getElementById('app')!).render(
    <StrictMode>
        <LocaleProvider>
            <AuthProvider>
                <App />
            </AuthProvider>
        </LocaleProvider>
    </StrictMode>,
);
