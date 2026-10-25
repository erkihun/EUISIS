import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';
import { AuthProvider } from './auth';
import { LocaleProvider } from './i18n';
import { SettingsProvider } from './settings';
import './index.css';

createRoot(document.getElementById('app')!).render(
    <StrictMode>
        <SettingsProvider>
            <LocaleProvider>
                <AuthProvider>
                    <App />
                </AuthProvider>
            </LocaleProvider>
        </SettingsProvider>
    </StrictMode>,
);
