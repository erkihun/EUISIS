# Transfer Module Guide

## Overview

The Transfer Module is a standalone subsystem that manages inter-organization employee transfers through a structured, configurable workflow.

The module replaces the legacy `employee_transfers` direct-action flow with a multi-step announcement → application → screening → approval → completion pipeline.

---

## Architecture

### Database Tables

| Table | Purpose |
|---|---|
| `transfer_settings` | Singleton row — configurable transfer policies |
| `transfer_announcements` | Transfer vacancy postings per organization/position |
| `transfer_applications` | Employee applications to announcements |
| `transfer_application_documents` | Supporting documents per application |
| `transfer_screening_reviews` | Audit trail of screening actions |
| `transfer_approvals` | Release / Receiving / Final approval records |
| `transfer_rule_overrides` | Override requests for policy exceptions |

### Key Invariants

- **Employee UUID never changes.** Only `EmployeeAssignment` records are modified.
- **Employee number never changes.**
- **No assignment change before all required approvals are complete.**
- **Every state change is audited** via `WriteAuditLogAction`.

---

## Workflow

```
Announcement (draft → published)
    ↓
Application submitted by employee
    ↓
Screener moves to UnderReview
    ↓
[Optional] Documents verified
    ↓
Candidate selected → approval chain begins
    ↓
  [if releasing_consent_required]  ReleasePending → ReleaseApproval
    ↓
  [if receiving_consent_required]  ReceivingPending → ReceivingApproval
    ↓
  [if final_approval_required]     FinalApprovalPending → FinalApproval
    ↓
Application status = Approved → CompleteTransferAction runs
    ↓
  old assignment closed (effective_to set, is_current = false)
  new assignment created (organization = receiving org)
  employee.current_assignment_id updated
  [if card policy] CardRequest created
  [if service policy] Entitlements recalculated
  Application status = Transferred
```

---

## Transfer Settings

Managed via `TransferSetting` (singleton). Access via `TransferSetting::current()`.

| Setting | Default | Description |
|---|---|---|
| `require_same_position` | false | Position must match |
| `require_same_grade` | false | Grade level must match |
| `require_same_salary` | false | Salary range must match |
| `allow_cross_institution` | true | Cross-institution transfers allowed |
| `allow_exceptional_override` | false | Allow policy overrides |
| `override_approver_roles` | null | Roles that can approve overrides |
| `required_documents` | null | List of required document types |
| `minimum_service_months` | 0 | Minimum months before transfer |
| `releasing_consent_required` | true | Releasing org must approve |
| `receiving_consent_required` | true | Receiving org must approve |
| `final_approval_required` | false | City/Bureau final approval |
| `card_reprint_policy` | `request_reprint` | `no_reprint` / `request_reprint` / `auto_reprint` |
| `service_recalculation_policy` | `recalculate_from_effective_date` | `no_recalculation` / `recalculate_from_transfer` / `recalculate_from_effective_date` |

---

## Permissions

| Permission | Description |
|---|---|
| `transfers.view` | Access Transfer Management module |
| `transfers.settings.manage` | View/update Transfer Settings |
| `transfers.announcements.view` | View announcements |
| `transfers.announcements.create` | Create announcements |
| `transfers.announcements.update` | Edit draft announcements |
| `transfers.announcements.publish` | Publish announcements |
| `transfers.announcements.close` | Close/cancel announcements |
| `transfers.applications.view` | View applications |
| `transfers.applications.create` | Submit applications (employees) |
| `transfers.applications.screen` | Screen / select / reject |
| `transfers.release.approve` | Approve/reject releasing consent |
| `transfers.receiving.approve` | Approve/reject receiving consent |
| `transfers.final.approve` | City/Bureau final approval |
| `transfers.override.approve` | Approve policy override requests |
| `transfers.complete` | Execute final transfer |
| `transfers.reports.view` | View reports |
| `transfers.reports.export` | Export reports |

---

## Key Files

### Enums
- `app/Enums/TransferAnnouncementStatus.php`
- `app/Enums/TransferApplicationStatus.php`
- `app/Enums/TransferApprovalType.php`
- `app/Enums/TransferApprovalStatus.php`
- `app/Enums/TransferDocumentVerificationStatus.php`
- `app/Enums/TransferOverrideStatus.php`
- `app/Enums/TransferCardReprintPolicy.php`
- `app/Enums/TransferServiceRecalculationPolicy.php`

### Models
- `app/Models/TransferSetting.php`
- `app/Models/TransferAnnouncement.php`
- `app/Models/TransferApplication.php`
- `app/Models/TransferApplicationDocument.php`
- `app/Models/TransferScreeningReview.php`
- `app/Models/TransferApproval.php`
- `app/Models/TransferRuleOverride.php`

### Actions
- `app/Actions/Transfers/UpdateTransferSettingsAction.php`
- `app/Actions/Transfers/CreateTransferAnnouncementAction.php`
- `app/Actions/Transfers/PublishTransferAnnouncementAction.php`
- `app/Actions/Transfers/CreateTransferApplicationAction.php`
- `app/Actions/Transfers/ScreenTransferApplicationAction.php`
- `app/Actions/Transfers/SelectTransferCandidateAction.php`
- `app/Actions/Transfers/RejectTransferApplicationAction.php`
- `app/Actions/Transfers/ApproveTransferApprovalAction.php`
- `app/Actions/Transfers/RejectTransferApprovalAction.php`
- `app/Actions/Transfers/CompleteTransferAction.php` ← critical

### Controllers
- `app/Http/Controllers/Transfers/TransferDashboardController.php`
- `app/Http/Controllers/Transfers/TransferSettingController.php`
- `app/Http/Controllers/Transfers/TransferAnnouncementController.php`
- `app/Http/Controllers/Transfers/TransferApplicationController.php`

### Policies
- `app/Policies/TransferSettingPolicy.php`
- `app/Policies/TransferAnnouncementPolicy.php`
- `app/Policies/TransferApplicationPolicy.php`

### UI Pages
- `resources/js/Pages/Transfers/Dashboard.tsx`
- `resources/js/Pages/Transfers/Settings.tsx`
- `resources/js/Pages/Transfers/Announcements/Index.tsx`
- `resources/js/Pages/Transfers/Announcements/Create.tsx`
- `resources/js/Pages/Transfers/Announcements/Show.tsx`
- `resources/js/Pages/Transfers/Applications/Index.tsx`
- `resources/js/Pages/Transfers/Applications/Show.tsx`

---

## Routes

| Method | URI | Name | Action |
|---|---|---|---|
| GET | `/transfers` | `transfers.dashboard` | Dashboard |
| GET | `/transfers/settings` | `transfer-settings.show` | Settings |
| PATCH | `/transfers/settings` | `transfer-settings.update` | Update settings |
| GET | `/transfer-announcements` | `transfer-announcements.index` | List announcements |
| POST | `/transfer-announcements` | `transfer-announcements.store` | Create |
| GET | `/transfer-announcements/{id}` | `transfer-announcements.show` | Show |
| PATCH | `/transfer-announcements/{id}` | `transfer-announcements.update` | Update |
| POST | `/transfer-announcements/{id}/publish` | `transfer-announcements.publish` | Publish |
| POST | `/transfer-announcements/{id}/close` | `transfer-announcements.close` | Close |
| GET | `/transfer-applications` | `transfer-applications.index` | List applications |
| POST | `/transfer-applications` | `transfer-applications.store` | Apply |
| GET | `/transfer-applications/{id}` | `transfer-applications.show` | Show |
| POST | `…/screen` | `transfer-applications.screen` | Start review |
| POST | `…/select` | `transfer-applications.select` | Select candidate |
| POST | `…/reject` | `transfer-applications.reject` | Reject |
| POST | `…/withdraw` | `transfer-applications.withdraw` | Withdraw |
| POST | `…/approve-release` | `transfer-applications.approve-release` | Release approval |
| POST | `…/reject-release` | `transfer-applications.reject-release` | Reject release |
| POST | `…/approve-receiving` | `transfer-applications.approve-receiving` | Receiving approval |
| POST | `…/reject-receiving` | `transfer-applications.reject-receiving` | Reject receiving |
| POST | `…/approve-final` | `transfer-applications.approve-final` | Final approval |
| POST | `…/reject-final` | `transfer-applications.reject-final` | Reject final |

---

## Testing

```bash
# Run Transfer Module tests only
php artisan test --filter=TransferModule

# Run full suite
php artisan test
```

### Test coverage
- Transfer settings retrieval and update
- Announcement date validation
- Announcement publish guards
- Employee application submission
- Duplicate application blocking
- Closed announcement rejection
- Employee identity preservation (UUID, number unchanged)
- Old assignment closed / new assignment created
- Approval-required guard (cannot complete without approval)
- Full approval chain (release → receiving → auto-complete)

---

## Assignment Update Rule

`CompleteTransferAction` is the **only** place where assignments change:

```php
// 1. Lock employee
$employee = $application->employee()->lockForUpdate()->firstOrFail();
$currentAssignment = $employee->currentAssignment()->lockForUpdate()->firstOrFail();

// 2. Close old assignment
$currentAssignment->update([
    'assignment_status' => AssignmentStatus::Closed,
    'is_current' => false,
    'effective_to' => now()->toDateString(),
]);

// 3. Create new assignment
$newAssignment = EmployeeAssignment::query()->create([
    'employee_id' => $employee->id,  // SAME UUID
    'organization_id' => $application->receiving_organization_id,
    ...
]);

// 4. Update pointer
$employee->update(['current_assignment_id' => $newAssignment->id]);

// 5. Mark transferred
$application->update(['status' => TransferApplicationStatus::Transferred]);
```

Employee `id` and `employee_number` are **never touched**.
