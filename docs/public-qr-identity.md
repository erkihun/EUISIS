# Public QR identity — what a scan shows

Amharic: የሕዝብ QR ማንነት — ቅኝት ምን ያሳያል

## Employee QR (`/service-feedback/{token}`)

The landing screen confirms the person the visitor is writing about:

```
[ Employee photo ]
Employee full name
Job designation (የሥራ መደብ)
```

- **Job designation**: the title of the post the employee holds now, from the
  current assignment (`is_current = true` and `assignment_status = active`),
  through its `Position`. This is the pair transfers maintain:
  `CompleteTransferAction` closes the old row and opens the new one. A
  pending-transfer row (not yet a place of work) and a closed row (history)
  never supply a title.
- Shown in the visitor's language: `title_am` on an Amharic page, `title_en` on
  an English page, falling back to the other when only one exists. No title is
  hard-coded.
- No current active post: the line is hidden. The page, the photo, the name
  and every form keep working. Nothing is invented.
- No designation field was added to `Employee`. The title is resolved on each
  scan, so the **same QR** shows the new title the day a transfer takes effect.
- Submissions keep their submission-time snapshot (`employee_assignment_id`,
  `organization_id`, `organization_unit_id`, `position_id`,
  `target_name_snapshot`). A later transfer never moves them.

### Public payload (`PublicEmployeeFeedbackIdentity`)

```json
{ "target_type": "employee", "display_name": "…", "position_title": "…", "photo_url": "/service-feedback/{token}/photo?…signed…" }
```

Never sent: employee id or number, national ID, phone, e-mail, date of birth,
salary, grade, position code or id, unit, institution, assignment id or
history, or the photo's storage path. A test asserts each one is absent from
the page.

## Institution QR (`/feedback/{token}`)

Shows the institution's name, short name and logo, as before.

## Security

- The token is opaque. The server resolves it to exactly one target and
  resolves it again on every POST: a revoked token or an employee who left
  service blocks the send.
- Ids, target types and assignments in a payload are ignored. Employee A's QR
  can never produce a row about employee B or another institution.
