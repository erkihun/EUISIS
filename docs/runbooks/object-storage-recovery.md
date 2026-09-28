# File and object storage recovery

Database metadata alone is not a recoverable EUISIS system. No approved remote object store
was found. Current recovery design must protect BOTH local disks (including public media)
using independent, encrypted, versioned storage; provisioning the actual snapshot/archive
transport is an infrastructure deployment gate, not an unencrypted `rsync --delete` mirror.

## Inventory to snapshot and validate

| Source | Examples / owning code |
|---|---|
| `storage/app/private` | Employee photos (`EmployeePhotoStorage`), employee documents, transfer/grievance documents, ID-card templates/snapshots, performance evidence/appeals, daily-activity attachments |
| `storage/app/public` | Organization logos, user profile photos, public announcements/media, legacy employee photos and card assets |
| Configurable attachment disks | Organizational change attachments, employee-document `storage_disk`; enumerate actual deployment overrides |
| Exports/generated files | Keep those covered by approved retention; identify regeneration costs and audit requirements |
| External cafeteria application | Separate application/config/database, if deployed: inventory and protect separately; this main-DB integration does not back it up |

1. Inventory configured disks AND explicitly selected disks in application services. Capture
   ownership, path, size, checksums/object version IDs and DB recovery time. Audit cafeteria
   attachments/other deployment extensions even where no active file feature is currently found.
2. Configure encrypted snapshot/versioned archive to approved independent storage with TLS/SSH.
   Keep historical versions/tombstones through the database recovery window and holds; restrict
   listing/download/delete, enable access logs and test that anonymous access is denied.
   Object lock/WORM is recommended only when the actual platform supports it.
3. Use immutable object keys and version IDs where available. PostgreSQL and file snapshots
   are not one transaction: document snapshot start/end and retention overlap. For stronger
   consistency, quiesce file-changing writers during the coordinated checkpoint/snapshot or
   design application version mapping. Do not claim atomic consistency from close timestamps.
4. Restore a matching versioned file snapshot into an isolated root; never overwrite current
   files before incident preservation. Compare manifest checksums and object version IDs,
   then sample/fully validate required DB references according to the incident scope.
5. Recover private/public separation and OS ownership. Private evidence and employee photos
   must not become web-public. Test authorized image/document/card rendering and unauthorized
   access denial. `restore_check.php` samples employee documents on the current local disks;
   the broader checklist and file_recovery attestation require all inventoried file classes.
6. For an approved object store, reuse that platform with versioning/lifecycle aligned to
   pgBackRest retention and least-privilege credentials. Extend the isolated reference checker
   for that deployment; an unrecognized remote disk currently fails the checker.
7. Record reference mismatches, chosen historical versions, checksums and recovery duration
   in restricted evidence. Set file_recovery true only after successful end-to-end validation.

Do not move large files into PostgreSQL to simplify backup. Do not use production HR files
as developer fixtures. Do not count a live deletion-propagating replica as versioned backup.
