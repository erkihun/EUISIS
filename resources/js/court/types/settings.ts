/** SystemSettingController::maintenance (Court/Settings/Maintenance). The settings themselves are the EUISIS SystemSettings/Index page. */
export interface SettingsMaintenanceProps {
    databaseMetrics: { driver: string; database: string; connection: string; table_count: number; size: string | null; migration_batch: number | null; backup_supported: boolean };
    securityMetrics: {
        https_enforced: boolean; debug_disabled: boolean; secure_cookies: boolean; strong_password: boolean; strict_lockout: boolean; mfa_configured: boolean;
        score: number; environment: string; session_driver: string;
    };
    systemInfo: Record<string, string | boolean>;
}
