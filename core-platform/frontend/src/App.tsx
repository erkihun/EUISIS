import { useAuth } from './auth';
import { Layout } from './components/Layout';
import { useI18n } from './i18n';
import { Cards } from './pages/Cards';
import { Dashboard } from './pages/Dashboard';
import { EmployeeDetailPage, RegisterEmployee } from './pages/Employee';
import { Assignments, Audit, Employees, Organizations, Positions, Units } from './pages/Lists';
import { Login } from './pages/Login';
import { VerifyCard } from './pages/VerifyCard';
import { match, useRoute } from './router';

function Screen({ route }: { route: string }) {
    const { user } = useAuth();
    const path = route.split('?')[0];

    switch (path) {
        case '/':
            return <Dashboard />;
        case '/organizations':
            return <Organizations />;
        case '/units':
            return <Units />;
        case '/positions':
            return <Positions />;
        case '/employees':
            return <Employees />;
        case '/employees/new':
            return user?.can_change_master_data ? <RegisterEmployee /> : <Employees />;
        case '/assignments':
            return <Assignments />;
        case '/cards':
            return <Cards />;
        case '/verify':
            return <VerifyCard />;
        case '/audit':
            return <Audit />;
    }

    const employee = match('/employees/:id', path);
    if (employee) return <EmployeeDetailPage key={employee.id} id={employee.id} />;

    return <Dashboard />;
}

export function App() {
    const { user, ready } = useAuth();
    const { t } = useI18n();
    const route = useRoute();

    if (!ready) return <div className="flex min-h-screen items-center justify-center text-muted">{t('common.loading')}</div>;
    if (!user) return <Login />;

    return (
        <Layout route={route}>
            <Screen route={route} />
        </Layout>
    );
}
