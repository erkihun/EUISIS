# Governance roles register

Gate G5 ([../go-live/go-live-gates.md](../go-live/go-live-gates.md)): every
role below is named by management before go-live. One person may hold more
than one role at first, but each role has an explicit holder, and "IT owns
everything" is not an assignment: business, data and security roles sit with
the business and security functions, not only with ICT.

The names are recorded in the production environment as `GOVERNANCE_*`
(name and title only, no contact data) and checked by
`php artisan production:readiness --strict`. Nothing is named yet.

| Role | Responsible for | Decides | Key | Holder |
| --- | --- | --- | --- | --- |
| Project Sponsor | the programme; resolves conflicts between owners | go / no-go; budget; names the other roles | `GOVERNANCE_PROJECT_SPONSOR` | **NAME_REQUIRED** |
| Business Owner | the service the platform delivers to the city administration | acceptance (UAT sign-off), priorities, recovery targets, DR tiers | `GOVERNANCE_BUSINESS_OWNER` | **NAME_REQUIRED** |
| System Owner | the platform as a whole in production | release acceptance, risk acceptance, changes to scope | `GOVERNANCE_SYSTEM_OWNER` | **NAME_REQUIRED** |
| Data Owner | the employee, organization and card data as a government record | who may see and share data; retention approval (with Legal, Records, Audit, Data Protection) | `GOVERNANCE_DATA_OWNER` | **NAME_REQUIRED** |
| Data Steward | day-to-day data quality ([data-stewardship.md](data-stewardship.md)) | corrections, integrity findings, legacy retirement | `GOVERNANCE_DATA_STEWARD` | **NAME_REQUIRED** |
| Technical Custodian | running the application: deployment, configuration, monitoring | technical changes within approved scope | `GOVERNANCE_TECHNICAL_CUSTODIAN` | **NAME_REQUIRED** |
| Information Security Lead | security of the platform ([security-governance.md](security-governance.md)) | access model, penetration test acceptance, security incidents, partner access | `GOVERNANCE_INFORMATION_SECURITY_LEAD` | **NAME_REQUIRED** |
| Database/Infrastructure Administrator | servers, databases, backups, replicas | infrastructure changes; records gate evidence references | `GOVERNANCE_DATABASE_INFRASTRUCTURE_ADMINISTRATOR` | **NAME_REQUIRED** |
| DR Coordinator | disaster recovery: drills, runbooks, recovery decisions | when to fail over or restore | `GOVERNANCE_DR_COORDINATOR` | **NAME_REQUIRED** |
| Incident Manager | handling incidents end to end ([../incident-response-plan.md](../incident-response-plan.md)) | severity, communication, rollback calls during an incident | `GOVERNANCE_INCIDENT_MANAGER` | **NAME_REQUIRED** |
| Integration/API Owner | partner applications, scopes, events ([integration-onboarding.md](integration-onboarding.md)) | onboarding, API and event versions | `GOVERNANCE_INTEGRATION_API_OWNER` | **NAME_REQUIRED** |
| Release Manager | release contents and windows ([release-management.md](release-management.md)) | release go, rollback during a release | `GOVERNANCE_RELEASE_MANAGER` | **NAME_REQUIRED** |
| Audit/Compliance Owner | audit trail, compliance with law and INSA requirements | audit reviews; retention approval member | `GOVERNANCE_AUDIT_COMPLIANCE_OWNER` | **NAME_REQUIRED** |
| Module owners | one business owner per service module (cafeteria, transport, providers, recruitment and transfer, performance and assessment, feedback, grievance, discipline and appeal, court cases, daily and field work, organization design, communication, Situation Room, Intelligence) | their module's priorities, UAT and move onto Core contracts | `GOVERNANCE_MODULE_OWNERS` = reference of the approved list | **NAME_REQUIRED** |

## Names used in earlier documents

Earlier governance documents use shorter names: "business owner" = Business
Owner; "Core data steward" = Data Steward; "security owner" = Information
Security Lead; "integration owner" = Integration/API Owner; "operations lead"
= Technical Custodian and Database/Infrastructure Administrator.
