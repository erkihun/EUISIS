Addis Ababa City Administration Employee Unified ID and Service Integration System
Business Requirements, Organizational Structure Design, and Technical Architecture Document
Document Position
This document aligns the proposed system with the current Addis Ababa City Administration structure while ensuring future structure changes can be handled without rewriting the application. Bureaus, authorities, commissions, agencies, sub-cities, woredas, branches, pools, and service providers are treated as configurable, versioned master data with effective dates and approval workflows.
Item
Detail
Project name
AA Employee Unified ID & Service Platform
Document type
Business Requirements + Solution Architecture
Version
v1.0 Draft - English Version
Prepared date
May 9, 2026
Primary business owner
Public Service and Human Resource Development Bureau and city administration institutions
System type
Modular enterprise platform: organization registry, employee registry, ID card, service entitlement, verification, reporting, and settlement
Note: Public sources are used only for initial alignment. During implementation, the final master list of bureaus, authorities, commissions, agencies, sub-cities, woredas, branches, pools, and service providers must be officially validated before production import.
Table of Contents
1. Executive Summary
2. Current Addis Ababa City Administration Structure and System Compatibility
3. System Objectives, Scope, and Main Users
4. Design Principles
5. Flexible Organization Structure Model
6. Core Data Model and Database Approach
7. Main Functional Modules
8. User Roles, Permissions, and Data Access
9. Core Business Workflows
10. ID Card Requirements
11. Service Entitlement and Verification Architecture
12. Security, Privacy, and Audit Requirements
13. Reporting, Dashboard, and Analytics Requirements
14. Implementation Roadmap and MVP
15. Laravel 12 Technical Approach
16. Success Metrics, Risks, and Controls
Appendices A-E
1. Executive Summary
The proposed platform is a city-level employee identity and service integration system for institutions under the Addis Ababa City Administration. It registers government institutions and their internal or geographic structures, maintains a clean employee registry, issues a unified employee ID card, and connects that ID card to services such as public transport, cafeteria and meal coupons, consumer association benefits, employee transfer tracking, and future service modules.
The core design decision is that the system must not assume a fixed government hierarchy. Addis Ababa has city-level executive organs, bureaus, authorities, commissions, agencies, sub-cities, woredas, branches, pools, and service delivery points. These structures can be reorganized through legal, cabinet, or administrative decisions. Therefore, the platform must treat organization structure as configurable master data with version history, effective dates, and auditable approval workflows.
Core Operating Principle
An employee is registered once with a stable identity. If the institution structure changes or the employee transfers, the employee identity remains stable while assignments, organization relationships, card display data, service entitlements, and reports are updated through controlled history records.
1.1 Problem to Be Solved
A fixed hierarchy such as Bureau -> Branch -> Sub-city -> Woreda will fail when new administrative levels are introduced, offices are renamed, bureaus are merged or split, or service units are reorganized.
Employee records can be duplicated across institutions, making it difficult to prevent duplicate ID cards, duplicate benefits, and wrong service access.
Lost, damaged, replaced, revoked, and expired cards require a controlled lifecycle and audit trail.
Transport, cafeteria, coupon, consumer association, and similar services require real-time entitlement checks to distinguish eligible and ineligible employees.
Leadership needs reliable reports by city, bureau, authority, commission, agency, sub-city, woreda, service provider, card status, and employee status.
1.2 Definition of Success
Institutional structure can be changed fully through master data without code changes.
Each employee has one stable employee identity and one current assignment, with full assignment history retained.
Card status, employee status, and service entitlement can be verified in real time.
Every sensitive operation creates an immutable audit record.
The platform exposes secure APIs for transport, cafeteria, POS, mobile scanner, finance, and future integrations.
2. Current Addis Ababa City Administration Structure and System Compatibility
The referenced public sources show that Addis Ababa is a city administration led by the Mayor and composed of bureaus, authorities, commissions, agencies, sub-cities, and woredas. The AMALI GovHub city profile states that the city has 11 sub-cities and 120 woredas [S4].
The Mayor Office Cabinet Members page reviewed for the earlier draft indicates that the Public Service and Human Resource Development Bureau is represented at city leadership level and that several city bureaus and authorities operate under the administration [S1]. The Bureau website also describes services related to human resource deployment, job evaluation and grading, allowance and benefits studies, training, and competency certification [S2].
2.1 City-Level Structural Components
Mayor Office: city leadership, cabinet coordination, and strategic decisions.
Bureaus: examples include Public Service and Human Resource Development, Finance, Transport, Health, Education, Trade, Plan and Development, Revenue, Land Development, Housing, Communication, and Urban Beautification.
Authorities, commissions, and agencies: executive or service organs that may be created, re-established, renamed, merged, or reorganized by law or cabinet decision.
Sub-cities: 11 geographic administrations with lower-level woreda structures and local offices.
Woredas: local administrative and service delivery level, often closest to citizens and employees.
Branches, pools, and service points: operational extensions that may report functionally to a bureau while geographically operating within a sub-city or woreda context.
2.2 Initial Master List of Sub-Cities
The Addis Ababa City Administration Landholding Registration and Information Agency website lists the following sub-cities [S5]. This list can be used only as an initial seed. Final official names, Amharic spellings, codes, and woreda mappings must be validated before import.
#
Sub-city
Type
Implementation note
1
Lemi Kura
Sub-city
Contains woreda-level structures.
2
Bole
Sub-city
Contains woreda-level structures.
3
Yeka
Sub-city
Contains woreda-level structures.
4
Nifas Silk Lafto
Sub-city
Contains woreda-level structures.
5
Lideta
Sub-city
Contains woreda-level structures.
6
Arada
Sub-city
Contains woreda-level structures.
7
Akaki Kality
Sub-city
Contains woreda-level structures.
8
Kirkos
Sub-city
Contains woreda-level structures.
9
Addis Ketema
Sub-city
Contains woreda-level structures.
10
Gulele
Sub-city
Contains woreda-level structures.
11
Kolfe Keraniyo
Sub-city
Contains woreda-level structures.
2.3 Restructuring Context and System Implication
The Ethiopian News Agency reported that the Addis Ababa City Council approved a proclamation on Hidar 15, 2016 E.C. to re-establish executive organs of the city. The report mentioned bodies such as the Industry Development Bureau, Labor and Skills Bureau, Plan and Development Bureau, Public Enterprises Administration Authority, Cooperative Commission, Food and Medicine Authority, Traffic Management Authority, and Workplace Development and Administration Agency [S3].
System Compatibility Rule
When a structure change is introduced by law, cabinet decision, or internal institutional decision, the platform must support it through master data, effective dates, hierarchy versions, and approvals rather than source code changes.
3. System Objectives, Scope, and Main Users
3.1 Main Objectives
Register all government institutions and structural units under the city administration with accurate hierarchy data.
Manage employees by institution, assignment, position, employment status, service entitlement, and ID card status.
Manage the complete lifecycle of unified employee ID cards: request, verification, approval, printing, issuance, activation, replacement, suspension, revocation, expiry, and renewal.
Create a service entitlement layer so transport, cafeteria, coupon, consumer association, and future services can be connected consistently.
Establish role management, organization-scoped permissions, approval workflows, audit logs, dashboards, and reports under one governance model.
3.2 MVP Scope
Organization structure and hierarchy registration.
User, role, permission, and organization scope management.
Employee registry with document attachments.
ID card request, data verification, approval, printing, issuance, activation, and verification.
QR code or barcode verification endpoint.
Basic public transport and cafeteria entitlement pilot.
Audit logs, dashboards, and core reports.
3.3 Roadmap Scope Beyond MVP
NFC smart card and optional biometric verification.
Third-party POS integration with consumer associations.
Full finance settlement and accounting integration.
Mobile application for field verification.
Advanced data warehouse and analytics.
Physical access control gate integration.
3.4 Main Users
Role
Main responsibility
Super Admin
Controls global system configuration and platform-level administration.
Public Service Bureau Admin
Controls city-level structure, approvals, role delegation, and oversight.
Institution Admin
Manages the institution and lower units within the assigned subtree.
Sub-city / Woreda Admin
Registers and manages employees within the assigned geographic scope.
HR Officer
Creates employee records, submits updates, attaches documents, and initiates transfer requests.
ID Card Officer
Handles card requests, print batches, card issuance, replacements, and card stock controls.
Service Provider User
Verifies card/service entitlement and records service transactions.
Auditor / Report Viewer
Reviews reports, audit trails, service usage, and sensitive activity.
4. Design Principles
Configuration over code: organization types, hierarchy levels, approval steps, service entitlement rules, and card display rules must be configurable.
Versioned hierarchy: structure change history must be preserved. A report for one period may use a different structure version than a report for another period.
Stable employee identity: an employee UUID and employee number must not change when the employee transfers. Only assignment records change.
Least privilege: users may view or change only the data permitted by their role, organization scope, and service context.
Audit first: every sensitive change must record actor, time, IP/device, reason, and old/new values.
API ready: transport, cafeteria, POS, mobile scanner, and future systems must integrate through secure APIs.
Data minimization: verification responses must return only the data required by the service context.
Offline tolerance: controlled offline cache or deferred synchronization can be supported for selected service providers under clear risk rules.
5. Flexible Organization Structure Model
The organization model is the heart of the system. It must support the current structure while allowing future levels to be added, removed, split, merged, renamed, or archived without breaking employee history, card validity, service entitlement, access scope, or reports.
5.1 Structure Lines
Line
Example
Meaning for the system
Functional / Executive line
Bureau -> Sector -> Directorate -> Team
Controls work responsibility, approval paths, HR ownership, and functional reporting.
Administrative / Geographic line
Sub-city -> Woreda -> Branch/Pool
Controls geographic scope, service delivery point, and local HR scope.
Service Provider line
Transport provider -> terminal/route/operator
Controls service eligibility, usage transaction capture, and settlement.
5.2 Node + Edge + Version Model
Institutions should be represented as nodes, relationships as edges, and structure validity through hierarchy versions and effective dates. This is more flexible than a hard-coded parent_id-only tree because it supports history and multiple relationship types.
Organization Node- id: UUIDv7- code- name_en / name_am- organization_type_id- legal_basis_ref- status: draft, active, inactive, merged, dissolved, archived- effective_from, effective_toOrganization Edge- parent_organization_id- child_organization_id- relationship_type: reports_to, geographically_under, service_scope, oversight- hierarchy_version_id- effective_from, effective_toHierarchy Version- version_name- approved_by- approval_date- source_document- status: draft, published, archived
5.3 Rules When Structure Changes
Existing organization nodes are not physically deleted; they are marked inactive, merged, dissolved, or archived.
New bureaus, woredas, branches, pools, and service providers create new organization nodes.
A relationship change creates a new organization edge under a new hierarchy version.
The employee assignment to the old organization is closed with valid_to; a new assignment is created with valid_from.
Historical cards, transactions, and reports continue to use the structure valid at the time of the event.
5.4 Organization Types and Levels
organization_type
Scope level
Example
Identifier behavior
city_government
city
Addis Ababa City Administration
Root node.
mayor_office
city
Mayor Office
Strategic/cabinet control.
bureau
city
Public Service and HR Development Bureau
Executive organ.
authority / commission / agency
city
Traffic Management Authority
Executive or service organ.
sector
institution
Human Resource Development Sector
Internal division.
directorate
institution
IT Directorate, HR Directorate
Operational unit.
sub_city
geographic
Bole, Yeka, Arada
Geographic administration.
woreda
geographic
Woreda 01, Woreda 02
Local administration.
branch / pool
service point
Branch office or pool
Service delivery point.
service_provider
external/internal
Transport, cafeteria, consumer association
Linked service provider.
5.5 Structure Change Scenarios
Scenario
System behavior
Reporting impact
A bureau is split
Old bureau is closed or continued as needed; new nodes are created; employees move to new assignments.
Old-period reports remain under the old bureau; new-period reports use the new bureaus.
Two institutions are merged
merged_into_id is recorded and a new hierarchy version is published.
Historical transactions remain under the previous institutions.
A woreda is added
New woreda node is created and connected to the parent sub-city.
The new woreda appears in dashboards from the effective date.
Institution name changes
Name history is recorded while UUID remains unchanged.
Historical reports can show old name; current reports can show current name.
Service provider changes
Provider status, contract, and effective dates are updated.
Settlement is separated by provider and period.
6. Core Data Model and Database Approach
The database must distinguish identity from assignment. Identity defines who the employee is; assignment defines where the employee works at a specific time, under which institution, and in which role or position.
6.1 Entity Groups
Group
Tables
Purpose
Organization Master Data
organizations, organization_types, organization_edges, hierarchy_versions, organization_name_histories
Institutions, relationships, history, codes, and legal basis.
Employee Registry
employees, employee_assignments, positions, employment_status_histories, employee_documents
Employee profile, assignments, positions, and documents.
ID Card
id_cards, card_requests, card_print_batches, card_issuances, card_replacements, card_verifications
Card request, print, issue, verify, suspend, replace, and renew.
Service Entitlement
service_types, service_providers, entitlements, entitlement_rules, service_transactions
Benefit rules, entitlement grants, authorization, and usage.
Transport
transport_plans, transport_routes, transport_usages, transport_settlements
Transport entitlement and usage tracking.
Cafeteria / Coupon
coupon_programs, coupon_balances, cafeteria_transactions, meal_plans
Meal/coupon programs, balances, redemption, and provider reporting.
Consumer Association
consumer_memberships, consumer_limits, consumer_transactions
Membership, purchase eligibility, limits, and POS transactions.
Workflow
approval_workflows, approval_steps, approvals, task_assignments
Verification, approval, escalation, and tasks.
Security / Audit
users, roles, permissions, user_organization_scopes, audit_logs, api_clients
Access control, sessions, API clients, and audit history.
Support / Notification
notifications, support_tickets, ticket_comments
Notifications, complaints, corrections, and support.
6.2 Organization Table Key Fields
Field
Type
Rule / Description
id
UUIDv7
Primary key; safe for public URLs and distributed systems.
code
unique string
Institution code; should not automatically change when hierarchy changes.
name_am / name_en
string
Official name in Amharic and English.
organization_type_id
foreign key
Bureau, authority, sub-city, woreda, branch, pool, service provider, etc.
legal_basis_ref
nullable string
Proclamation, regulation, decision, or official reference number.
status
enum
draft, active, inactive, merged, dissolved, archived.
effective_from / effective_to
date nullable
Validity period for the organization record.
metadata
JSON
Type-specific details such as address, phone, geo code, contact person, or service attributes.
6.3 Employee Table Key Fields
Field
Rule / Description
id / employee_uuid
UUIDv7 used as stable global identity.
employee_number
City-level unique employee identifier.
full_name_am / full_name_en
Full official name.
gender, date_of_birth, phone, email
Basic personal information, subject to privacy rules.
photo_path / signature_path
Images used for ID card rendering.
employment_status
active, suspended, transferred, retired, terminated, deceased, etc.
current_assignment_id
Pointer to the current active assignment.
data_quality_score
Completeness and accuracy indicator for data cleansing and validation.
6.4 Status Enum Examples
Context
Enums
organization_status
draft, active, inactive, merged, dissolved, archived
employee_status
draft, active, suspended, transferred, retired, terminated, deceased
card_request_status
draft, submitted, verified, approved, rejected, cancelled
card_status
pending_print, printed, issued, active, lost, damaged, suspended, revoked, expired, replaced
entitlement_status
pending, active, paused, exhausted, expired, revoked
transaction_status
authorized, denied, pending_sync, reversed, settled
workflow_status
draft, in_review, approved, rejected, escalated, cancelled
7. Main Functional Modules
7.1 Organization Structure Management
Create, update, archive, and classify organization types without hard-coding the hierarchy.
Register institutions, sub-cities, woredas, branches, pools, and service providers.
Create parent-child, oversight, geographic, and service-scope relationships with effective dates.
Display hierarchy trees and searchable lists by type, code, status, location, and effective period.
Create draft structure versions, review affected employees and users, approve, and publish.
7.2 User, Role, and Permission Management
Implement role-based access control combined with organization-scoped permissions.
Allow a user to have scope over one or more organizations and their subtrees.
Support delegated administration for bureaus, sub-cities, woredas, and selected institutions.
Require MFA for administrators, approvers, ID card officers, and settlement officers.
Track sessions, devices, failed login attempts, and account lockouts.
7.3 Employee Registry Module
Support individual registration and bulk import from Excel or CSV templates.
Detect duplicate employees using employee number, national identifier where available, name, date of birth, phone, and other configurable rules.
Attach documents such as employment letters, transfer letters, photos, and signatures.
Maintain employee assignment history using valid_from and valid_to dates.
Provide data completeness dashboards and correction workflows.
7.4 ID Card Management Module
Start card requests from verified employee records.
Use a verification checklist covering photo, name, institution, position, and employment status.
Generate card numbers, QR/barcode tokens, and print batch records.
Support lost, damaged, replacement, revoked, expired, renewed, and suspended lifecycle paths.
Manage card design templates by version so card layout can change without losing old records.
7.5 Verification and Service Entitlement Module
Verify employee status, card status, and service eligibility using QR, barcode, NFC, or secure lookup.
Return only the minimum data required for the service context.
Define entitlement rules by employee status, organization, job category, service program, provider, time period, and quota.
Record denial reasons such as inactive employee, expired card, no entitlement, quota exhausted, or provider not allowed.
7.6 Public Transport Module
Configure transport benefits such as monthly pass, route-based eligibility, amount-based benefit, or ride quota.
Register transport providers, routes, terminals, vehicles, operators, and supported scanning devices.
Record usage transactions at scan time or through deferred synchronization.
Detect duplicate ride attempts and suspicious usage patterns within configured time windows.
Generate monthly usage, provider, and settlement reports.
7.7 Cafeteria and Meal Coupon Module
Register meal plans, coupon programs, cafeteria providers, and redemption locations.
Define daily, weekly, or monthly quotas and reset rules.
Track coupon balance, redemption history, reversals, and expired quotas.
Provide cafeteria operator portal and scanner-based verification.
Support cafeteria cost reports and invoice attachments.
7.8 Consumer Association Module
Register membership eligibility and employee membership records.
Define purchase limits, discount rules, credit or cash policies, and product categories.
Integrate with POS systems or provide a web portal for controlled transactions.
Generate monthly usage statements for employees, institutions, and providers.
Monitor abuse or fraud using quota, frequency, unusual pattern, and provider anomalies.
7.9 Employee Transfer Module
Create transfer requests from current organization to receiving organization.
Require current institution confirmation, receiving institution confirmation, and city-level approval where applicable.
Close old assignments and create new assignments without changing employee identity.
Refresh card display data or trigger reprint based on configured policy.
Recalculate service entitlements according to the new assignment and program rules.
7.10 Service Provider Portal
Allow approved providers to verify card and entitlement status for their service type only.
Record transactions, cancellations, reversals, and synchronization status.
Provide daily and monthly usage reports to provider users.
Manage API client credentials, device binding, and rate limiting.
Generate provider-specific settlement reports.
7.11 Approval Workflow Module
Support workflow templates by process type: employee registration, card request, card replacement, transfer, entitlement override, and organization change.
Support conditional approvals based on organization type, service type, risk level, value, or data quality.
Support delegation, escalation, timeout, rejection reason, and task assignment.
Maintain approval history and e-signature markers.
7.12 Document Management Module
Manage document categories, required/optional flags, expiry dates, and verification status.
Encrypt files at rest and restrict document access by role and purpose.
Maintain document versions and access logs.
Support document verification status in workflows.
7.13 Notification Module
Support in-app notifications, email, and SMS gateway integration.
Notify users when cards are ready, requests are approved/rejected, transfers are approved, entitlements are activated, or cards are near expiry.
Manage notification templates in Amharic and English.
7.14 Support / Complaint Module
Support lost card reports, wrong data correction requests, entitlement issues, and service denial complaints.
Manage ticket categories, SLA, assignment, escalation, and status tracking.
Provide support knowledge base and frequently asked questions.
8. User Roles, Permissions, and Data Access
The platform must combine role-based access control with organization-scoped access control. For example, an HR Officer in one bureau may register employees in that bureau, but cannot view or edit employees of another bureau. A Public Service Bureau Admin may have full city-level oversight scope.
Area
Read
Create/Update
Approve
Special control
Organization
Admin/Viewer
Bureau Admin
City Admin
Publish hierarchy version
Employee
Scoped users
HR Officer
Institution/City Admin
Sensitive field masking
ID Card
ID Officer/HR
ID Officer
Approver
Print batch control
Entitlement
Service Admin
Service Admin
Program Owner
Quota/rule override
Transaction
Provider/User
Provider/User
Settlement Officer
Reverse/void transaction
Reports
Report Viewer
-
-
Export permission
Audit Logs
Auditor
-
-
Immutable logs
8.1 Data Access Rules
Subtree access: a user can view only the assigned organization and permitted lower structures.
Service scope: a service provider receives verification results only for its authorized service type.
Sensitive field masking: date of birth, phone, documents, and personal data are visible only to roles with a clear purpose.
Export control: Excel/PDF export is allowed only for permitted roles and must create an audit record.
Break-glass access: emergency temporary access requires approval, a reason, a time limit, and audit trail.
9. Core Business Workflows
Workflow
High-level flow
Employee Registration
HR Officer registers -> Institution Admin verifies -> Public Service Bureau approves -> Employee becomes active -> Card request can be submitted.
Card Issuance
Card request -> Data verification -> Approval -> Print batch -> Quality check -> Issue to employee -> Activate card.
Service Usage
Employee presents card -> Provider scans -> System verifies identity/card/entitlement -> Transaction logged -> Report/settlement generated.
Employee Transfer
Transfer request -> Current organization confirms -> Receiving organization confirms -> City/Bureau approval -> Assignment updated -> Card/service rules refreshed.
Organization Change
Draft hierarchy -> Review affected units/users/employees -> Approve legal basis -> Publish version -> Notify scoped admins -> Reports use new hierarchy from effective date.
Lost/Damaged Card
Report -> Suspend old card -> Verify request -> Approve replacement -> Print replacement -> Issue and activate -> Link replacement history.
9.1 Card Lifecycle
Normal path:Draft -> Submitted -> Verified -> Approved -> Printed -> Issued -> ActiveAlternative paths:Rejected -> ClosedLost -> Suspended -> ReplacedDamaged -> Reprint Approved -> ReplacedExpired -> RenewedRevoked -> Closed
9.2 Employee Assignment Lifecycle
Registered -> Active Assignment -> Transfer Requested -> Transfer Approved-> Old Assignment valid_to set-> New Assignment valid_from set-> Entitlements recalculated-> Card display data refreshed or reprint requested
10. ID Card Requirements
The ID card must be designed in two layers: physical card and digital credential. The physical card displays information needed for visual identification. The digital credential is tokenized and verified through the backend verification endpoint.
10.1 Information Displayed on the Card
City emblem/logo and issuing bureau name.
Employee full name in Amharic and, where required, English.
Employee photo, employee number, and card number.
Institution, sub-city, woreda, branch, or pool name based on configured display rules.
Position or service grade.
Issue date and expiry date.
QR code, barcode, and optional NFC serial.
10.2 QR Code Content Rule
The QR code must not contain personal information in plain text. It should contain a signed verification token or a card UUID with a secure token. The scanner app or API calls the server to verify card status and service entitlement.
Data
Inside QR?
Reason
card_uuid / token
Yes
Used to call the verification endpoint.
employee full name
No
Privacy risk; server returns only when allowed.
phone / date of birth
No
Sensitive personal data.
signature / hash
Yes
Tamper detection.
expiry indicator
Optional
Only when offline validation is required.
10.3 Print Batch Control
Each print batch must have a registered batch number.
The system must record whose card was printed, by which officer, on which printer, and at what time.
Spoiled card count and reprint reason must be captured.
Card stock should be treated as a security-sensitive inventory item and may be integrated with an inventory module.
11. Service Entitlement and Verification Architecture
Service entitlement is the rule system that determines which employee is allowed to use which service, during which period, at what quota, and under what conditions. It allows one ID card to support multiple services while keeping eligibility auditable and configurable.
11.1 Entitlement Rule Model
Rule input
Example
Determines
Employee status
active only
Suspended, terminated, retired, or inactive employees cannot use the service.
Organization scope
Transport benefit for selected bureaus
Benefit based on institution or structure.
Job category / grade
field workers, officers, drivers
Eligibility based on job class or responsibility.
Time period
monthly, daily, campaign period
Quota expiry and reset.
Service provider
cafeteria A, transport operator B
Provider-specific rules.
Quota
20 meals/month, 2 rides/day
Usage limit.
11.2 Verification Response Levels
Level
Target user
Visible result
Basic validity
Public or low-risk scanner
Card active/invalid, employee active/inactive, optional minimal name/photo.
Service entitlement
Transport, cafeteria, provider
Allowed/denied, service type, quota remaining, denial reason.
HR verification
HR/Admin
Full employee profile, assignment history, and documents according to role.
Audit review
Auditor
Verification logs, transaction logs, user actions.
11.3 Example API Endpoints
POST /api/v1/cards/verifyPOST /api/v1/services/{serviceType}/authorizePOST /api/v1/services/{serviceType}/transactionsGET  /api/v1/employees/{employee}/entitlementsGET  /api/v1/providers/{provider}/settlements/{period}POST /api/v1/offline-sync/transactions
All APIs must be protected with client credentials, IP/device binding, rate limits, request signatures, scoped authorization, and audit logging.
12. Security, Privacy, and Audit Requirements
12.1 Security Requirements
HTTPS/TLS for all communication channels.
MFA for admin, approver, ID officer, and settlement officer roles.
Password policy, account lockout, device tracking, and session management.
Sensitive file encryption at rest.
Encrypted database backups and tested restore drills.
API token rotation and scoped tokens.
Queue jobs must preserve authorization context and create audit records for sensitive operations.
12.2 Events That Must Be Audited
Event
Audit fields
Employee created/updated
actor, old values, new values, reason, IP, device, timestamp.
Organization hierarchy changed
version, legal basis, approver, affected units.
Card printed/issued/replaced/revoked
card id, batch, officer, reason, timestamp.
Verification performed
card id, provider, result, reason, location/device.
Entitlement overridden
program, old rule, new rule, approver, reason.
Export/download
user, report name, filters, record count, purpose.
Login/security event
success/failure, IP, device, MFA result.
12.3 Privacy Principles
Verification responses must be limited by service context.
Viewing uploaded documents requires a specific permission and clear purpose.
Exported reports may include watermark/user trace to discourage uncontrolled sharing.
Data retention policy must define how long card transactions, audit logs, documents, and inactive employee records are retained.
When a user role changes, existing sessions should be invalidated.
13. Reporting, Dashboard, and Analytics Requirements
Reporting must support current hierarchy view and historical hierarchy view. Leadership may want reporting based on today’s structure, while audit users may need the structure that was valid on the transaction date.
Report
Description
Organization report
Employee count by institution, sub-city, woreda, active/inactive status, gender, and employment type.
ID card report
Requested, approved, printed, issued, active, lost, damaged, revoked, expired, and replaced cards.
Service usage report
Usage by service type, provider, period, and organization.
Transport report
Ride/pass usage, route/provider utilization, and monthly settlement.
Cafeteria/coupon report
Coupons issued, redeemed, remaining, meal cost, and provider report.
Consumer association report
Membership, purchase volume, limit usage, and anomalies.
Transfer report
Transferred employees, pending approvals, receiving institutions, and transfer aging.
Audit report
Sensitive changes, card reprints, entitlement overrides, and exports.
Data quality report
Missing photo, missing documents, duplicate risk, and invalid assignments.
13.1 Dashboard KPIs
Total active employees by organization and sub-city.
Card coverage percentage = active employees with active cards / active employees.
Pending approval count by workflow type and age.
Lost/replaced card trend.
Service entitlement utilization by service type.
Denied verification attempts by reason.
Data quality score by institution.
Top service providers by transaction volume and settlement amount.
14. Implementation Roadmap and MVP
Phase
Deliverables
Indicative duration
Phase 0: Discovery & Master Data
Official organization list, role matrix, employee data template, card design, pilot institutions, integration inventory.
2-4 weeks
Phase 1: Core Foundation
Organization hierarchy, users/roles, employee registry, workflow, audit, import/export.
6-8 weeks
Phase 2: ID Card Platform
Card request/approval, QR/token, template, print batch, issuance, verification.
6-8 weeks
Phase 3: Pilot Services
Transport entitlement, cafeteria/coupon pilot, provider portal, and usage reports.
6-10 weeks
Phase 4: Scale & Integrations
Consumer association, settlement, SMS/email, APIs, data warehouse, mobile scanner.
ongoing
14.1 MVP Acceptance Criteria
At minimum, the city-level root, 11 sub-cities, sample woredas, and selected bureaus appear correctly in the hierarchy.
Users can view only scoped employees based on organization scope.
Employee registration, verification, approval, and card request submission are functional.
QR scan returns active/inactive card result and service entitlement result.
When a card is lost or damaged, the old card is suspended and replacement history is visible.
Audit logs are created for employee updates, card printing, and entitlement changes.
Core reports can be exported to Excel/PDF by authorized users.
15. Laravel 12 Technical Approach
Laravel 12 continues the slim application structure introduced in Laravel 11, where middleware, routing, and exception configuration can be organized through bootstrap/app.php. This project should start as a modular monolith because the domains are strongly connected through employee identity, hierarchy, workflow, entitlement, and audit data. Later, verification or transaction services can be extracted if scale requires it.
15.1 Recommended Application Structure
app/  Actions/    Organizations/    Employees/    IdCards/    Entitlements/    Transfers/    ServiceTransactions/  Enums/  Http/    Controllers/    Requests/    Resources/  Models/  Policies/  Observers/  Services/    CardRendering/    Verification/    EntitlementRules/    Settlement/  Jobs/  Events/  Listeners/  Support/bootstrap/app.phproutes/web.phproutes/api.php
15.2 Main Technical Choices
Area
Recommendation
Why
Auth
Laravel Breeze/Jetstream/Fortify + Sanctum for API
Separate web users and API clients through guards/tokens.
Permissions
spatie/laravel-permission + custom organization scopes
Roles and permissions remain simple while organization scope remains domain-specific.
Audit
spatie/laravel-activitylog or custom immutable audit_logs
Government records require traceability and accountability.
Queues
Redis + Horizon
Card rendering, notifications, imports, and reports can run in background.
Files
S3-compatible/private storage
Documents, photos, signatures, and rendered cards require secure storage.
UUID
Laravel native UUID/ULID/UUIDv7 strategy
Distributed unique IDs and public-safe identifiers.
Frontend
Inertia 2.0 + React/Vue + Tailwind CSS
Efficient enterprise dashboard development.
Testing
Pest/PHPUnit feature and policy tests
Validate workflows, permissions, scopes, and entitlement rules.
15.3 Example Domain Actions
CreateOrganizationAction
PublishHierarchyVersionAction
RegisterEmployeeAction
SubmitCardRequestAction
ApproveCardRequestAction
GenerateCardTokenAction
VerifyCardForServiceAction
GrantEntitlementAction
RecordServiceTransactionAction
RequestEmployeeTransferAction
ApproveEmployeeTransferAction
GenerateSettlementReportAction
15.4 Test Categories
Policy tests: users cannot access employees outside their organization scope.
Workflow tests: a card request cannot be printed before approval.
Hierarchy tests: published hierarchy versions become effective on the correct date.
Entitlement tests: inactive employees are denied transport or coupon usage.
Audit tests: sensitive updates create immutable logs.
API tests: invalid or expired tokens are denied with safe responses.
Import tests: duplicate employees are flagged before insert.
16. Success Metrics, Risks, and Controls
16.1 Success Metrics
95%+ of active employees are registered under the correct institution.
90%+ of active employees have active unified ID cards.
Average card verification response time is under 2 seconds.
Duplicate employee records are below 1% after data cleansing.
All card prints and entitlement overrides have audit records.
Monthly service usage and settlement reports are generated without manual reconciliation.
16.2 Risks and Mitigation
Risk
Impact
Mitigation
Incomplete organization master data
Wrong employee assignments and inaccurate reports.
Official master data approval, versioning, and data steward role.
Duplicate employee records
Two cards and duplicated benefits for one person.
Duplicate detection, merge workflow, and mandatory unique employee number.
QR token leakage
Unauthorized lookup or fraud.
Signed tokens, rate limits, short verification response, and revocation support.
Service provider misuse
Benefit misuse or fraudulent transactions.
Provider scope, device binding, anomaly reports, and audit review.
Network outages
Verification failure at service points.
Offline cache policy, deferred sync, and fail-closed design for sensitive services.
Uncontrolled structure changes
Broken access scopes and inconsistent reports.
Draft-review-publish workflow and impact analysis before publish.
Privacy breach
Exposure of personal employee data.
Data minimization, encryption, audit logs, export control, and role-based masking.
Appendix A: Data Dictionary Example
Table
Purpose
Important fields
organizations
Institutions and structural units.
code, name, type, status, legal_basis_ref, effective dates.
organization_edges
Parent-child, oversight, and service scope relationships.
parent_id, child_id, relationship_type, version_id.
hierarchy_versions
Published structure versions.
version, approved_by, approved_at, status.
employees
Employee identity records.
employee_number, names, status, current_assignment_id.
employee_assignments
Employee location/position history.
employee_id, organization_id, position_id, valid_from/to.
id_cards
Card data and status.
card_number, token_hash, status, issued_at, expires_at.
card_requests
Card request workflow.
employee_id, request_type, status, workflow_id.
entitlements
Service benefit grants.
employee_id, service_type_id, quota, period, status.
service_transactions
Service usage records.
employee_id, provider_id, service_type_id, amount, result.
employee_transfers
Transfer workflow and history.
from_org, to_org, status, approved_at.
audit_logs
User action history.
actor, event, old_values, new_values, ip, device.
Appendix B: Example Role Matrix
Role
Organization
Employee
Card
Transactions
Reports
Super Admin
All
All
All
All
All
City Admin
All city scope
All city scope
Approve
View all
View all
Institution Admin
Own subtree
Own subtree
Verify/approve scoped
Own subtree
Own subtree
HR Officer
Own org
Create/update draft
Submit
Limited
Own org
ID Officer
Read approved
No
Print/issue
Verification logs
Card reports
Service Provider
No HR data
No
No
Own transactions
Own reports
Auditor
Read
No
No
Read all scoped
Read all scoped
Appendix C: Organization Change Process
Register a structure change request with change type, legal basis, effective date, and affected organizations.
Generate a system impact analysis: affected employees, active users, pending workflows, active entitlements, and reports.
Create a draft hierarchy version.
Data steward and authorized approver review the draft.
Publish the version on the effective date.
A background job updates search indexes, materialized paths or closure tables, user scopes, and report caches.
Every change is recorded in the audit log.
Appendix D: Source Notes
S1 - Mayor Office Cabinet Members: https://addismayor.gov.et/team-cabinets. Used for Mayor Office and cabinet context.
S2 - Public Service and Human Resource Development Bureau: https://www.pshrdb.gov.et/. Used for Bureau leadership, services, and functions.
S3 - Ethiopian News Agency 2016 E.C. executive organs re-establishment news: https://www.ena.et/web/amh/w/amh_3636086. Used for City Council proclamation approval and listed organs.
S4 - AMALI GovHub Addis Ababa City Profile: https://www.amaligovhub.africa/amali-cities/addis-ababa. Used for city profile, 11 sub-cities, and 120 woredas reference.
S5 - Addis Ababa City Administration Landholding Registration and Information Agency Subcities: https://www.addiscadaster.gov.et/subcities. Used for initial sub-city list and woreda lookup reference.
S6 - Executive Organs of the Addis Ababa City Government legal summary: https://dmethiolawyers.com/executive-organs-of-the-addis-ababa-city-government/. Used for Proclamation 74/2021 summary and sub-city/woreda hierarchy description.
S7 - Proclamation 84/2016 PDF listing: https://investaddisababa.com/wp-content/uploads/2026/02/3.-Proclamation-84-2016-final.pdf. Used as a reference for the proclamation document listing.
Appendix E: Next Analysis Activities
Official master data collection: final list of bureaus, authorities, agencies, commissions, sub-cities, woredas, branches, pools, and service providers.
Employee data template approval: required fields, validation rules, unique identifiers, duplicate detection rules, and import format.
Card design approval: visible fields, print layout, security features, QR/barcode/NFC decision, and validity period.
Pilot scope selection: 2-3 bureaus, 1-2 sub-cities, selected woredas, and selected service providers.
Integration assessment: transport systems, cafeteria/POS systems, SMS gateway, finance/payment systems, and settlement requirements.
Data governance charter: data owner, data steward, approval authority, data retention, export policy, and audit review cadence.