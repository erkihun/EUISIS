<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Go-live gates (ADR 0004, docs/go-live/go-live-gates.md)
|--------------------------------------------------------------------------
|
| Evidence that people produce outside the application, recorded here as
| references so `php artisan production:readiness --strict` can refuse a
| go-live until every mandatory gate is met. A reference is the identifier of
| the signed document or record (report number, memo number, ticket), never
| the document itself, and never a secret.
|
| Open P0/P1 counts must be set to 0 explicitly; an empty value is "unknown"
| and keeps the gate closed.
|
*/

return [

    'penetration_test' => [
        'report' => env('GOLIVE_PENTEST_REPORT'),
        'open_p0_p1' => env('GOLIVE_PENTEST_OPEN_P0_P1'),
    ],

    'uat' => [
        'signoff' => env('GOLIVE_UAT_SIGNOFF'),
        'open_p0_p1' => env('GOLIVE_UAT_OPEN_P0_P1'),
    ],

    // The restore drill is recorded as BACKUP_OBJECTIVES_VALIDATED_BY (config/backup.php).
    'failover_drill' => env('GOLIVE_FAILOVER_DRILL'),

    /*
    | Named governance roles. Management names a person for each; one person
    | may hold several roles, but "IT owns everything" is not an assignment.
    | The value is the person's name and title as approved (no contact data).
    */
    'governance_roles' => [
        'project_sponsor' => env('GOVERNANCE_PROJECT_SPONSOR'),
        'business_owner' => env('GOVERNANCE_BUSINESS_OWNER'),
        'system_owner' => env('GOVERNANCE_SYSTEM_OWNER'),
        'data_owner' => env('GOVERNANCE_DATA_OWNER'),
        'data_steward' => env('GOVERNANCE_DATA_STEWARD'),
        'technical_custodian' => env('GOVERNANCE_TECHNICAL_CUSTODIAN'),
        'information_security_lead' => env('GOVERNANCE_INFORMATION_SECURITY_LEAD'),
        'database_infrastructure_administrator' => env('GOVERNANCE_DATABASE_INFRASTRUCTURE_ADMINISTRATOR'),
        'dr_coordinator' => env('GOVERNANCE_DR_COORDINATOR'),
        'incident_manager' => env('GOVERNANCE_INCIDENT_MANAGER'),
        'integration_api_owner' => env('GOVERNANCE_INTEGRATION_API_OWNER'),
        'release_manager' => env('GOVERNANCE_RELEASE_MANAGER'),
        'audit_compliance_owner' => env('GOVERNANCE_AUDIT_COMPLIANCE_OWNER'),
        // Reference of the approved list of module owners (one per service module).
        'module_owners' => env('GOVERNANCE_MODULE_OWNERS'),
    ],

];
