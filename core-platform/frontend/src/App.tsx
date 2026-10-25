import { Suspense, lazy, useEffect } from 'react';
import { useAuth } from './auth';
import { useLocale } from './i18n';
import { match, useRoute } from './router';

// Each screen is its own chunk, as Inertia pages are in EUISIS.
const LoginPage = lazy(() => import('./pages/Login'));
const DashboardPage = lazy(() => import('./pages/Dashboard'));
const CardsPage = lazy(() => import('./pages/Cards'));
const VerifyCardPage = lazy(() => import('./pages/VerifyCard'));
const EmployeeDetailPage = lazy(() => import('./pages/Employee').then((m) => ({ default: m.EmployeeDetailPage })));
const RegisterEmployeePage = lazy(() => import('./pages/Employee').then((m) => ({ default: m.RegisterEmployeePage })));
const OrganizationsPage = lazy(() => import('./pages/Lists').then((m) => ({ default: m.OrganizationsPage })));
const UnitsPage = lazy(() => import('./pages/Lists').then((m) => ({ default: m.UnitsPage })));
const PositionsPage = lazy(() => import('./pages/Lists').then((m) => ({ default: m.PositionsPage })));
const EmployeesPage = lazy(() => import('./pages/Lists').then((m) => ({ default: m.EmployeesPage })));
const AssignmentsPage = lazy(() => import('./pages/Lists').then((m) => ({ default: m.AssignmentsPage })));
const AuditPage = lazy(() => import('./pages/Lists').then((m) => ({ default: m.AuditPage })));
const SettingsPage = lazy(() => import('./pages/Settings'));

function Screen({ route }: { route: string }) {
    const { user } = useAuth();

    switch (route) {
        case '/':
            return <DashboardPage />;
        case '/organizations':
            return <OrganizationsPage />;
        case '/organization-units':
            return <UnitsPage />;
        case '/positions':
            return <PositionsPage />;
        case '/employees':
            return <EmployeesPage />;
        case '/employees/new':
            return user?.can_change_master_data ? <RegisterEmployeePage /> : <EmployeesPage />;
        case '/assignments':
            return <AssignmentsPage />;
        case '/id-cards':
            return <CardsPage />;
        case '/verify':
            return <VerifyCardPage />;
        case '/audit':
            return <AuditPage />;
        case '/settings':
            return user?.role === 'core_admin' ? <SettingsPage /> : <DashboardPage />;
    }

    const employee = match('/employees/:id', route);
    if (employee) return <EmployeeDetailPage key={employee.id} id={employee.id} />;

    return <DashboardPage />;
}

function Loading() {
    const { t } = useLocale();
    return <div className="flex min-h-screen items-center justify-center bg-(--app-background) text-sm text-(--app-muted-foreground)">{t('common.loading')}</div>;
}

export function App() {
    const { user, ready } = useAuth();
    const { t } = useLocale();
    const route = useRoute();

    // The tab title, as EUISIS's <Head> sets it.
    useEffect(() => {
        document.title = t('core.title');
    }, [t]);

    if (!ready) return <Loading />;

    return (
        <Suspense fallback={<Loading />}>
            {user ? <Screen route={route} /> : <LoginPage />}
        </Suspense>
    );
}
