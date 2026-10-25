import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { api, getToken, setToken, setUnauthorizedHandler } from './api';

export type Role = 'core_viewer' | 'core_officer' | 'core_admin';

export type CoreUser = {
    id: number;
    name: string;
    email: string;
    role: Role;
    scope_organization_id: string | null;
    can_change_master_data: boolean;
};

type Auth = {
    user: CoreUser | null;
    ready: boolean;
    login: (email: string, password: string) => Promise<void>;
    logout: () => Promise<void>;
};

const AuthContext = createContext<Auth | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<CoreUser | null>(null);
    const [ready, setReady] = useState(false);

    useEffect(() => {
        setUnauthorizedHandler(() => {
            setToken(null);
            setUser(null);
        });
        if (!getToken()) {
            setReady(true);
            return;
        }
        api<{ user: CoreUser }>('/auth/me')
            .then((response) => setUser(response.user))
            .catch(() => setToken(null))
            .finally(() => setReady(true));
    }, []);

    const value = useMemo<Auth>(
        () => ({
            user,
            ready,
            login: async (email, password) => {
                const response = await api<{ token: string; user: CoreUser }>('/auth/login', { method: 'POST', body: { email, password } });
                setToken(response.token);
                setUser(response.user);
            },
            logout: async () => {
                await api('/auth/logout', { method: 'POST' }).catch(() => undefined);
                setToken(null);
                setUser(null);
            },
        }),
        [user, ready],
    );

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): Auth {
    const context = useContext(AuthContext);
    if (context === null) throw new Error('useAuth outside AuthProvider');
    return context;
}
