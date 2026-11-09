# ADR 0028: A grievance committee member is registered together with their signature

- Status: accepted
- Date: 2026-11-07
- Deciders: product owner (requirement), engineering (design, decisions below taken as defaults)

## Context

The owner requires that when a grievance committee is configured, the signatures of
its members are recorded with it ("ቅሬታ ኮሚቴ ሲዋቀር የኮሚቴ አባላት ፊርማ አብሮ መመዝገብ አለበት").
Committee minutes and decision letters are signed by members (Regulation 201/2018
Arts. 30–35); correspondence already applies an employee's `signature_path` image
when a letter is signed with the "signature image" method.

## Decision

1. **Captured at appointment.** Adding a member (`grievances.committees.members.store`)
   takes a signature image (PNG, JPG or WebP, at most 1 MB). The file is stored on the
   private `local` disk under `employee-signatures/`, becomes the employee's
   `signature_path` (the one correspondence uses), and is snapshotted on the member's
   term row: `signature_path`, `signature_sha256`, `signature_recorded_at`,
   `signature_recorded_by`. A term keeps the signature as it was when the member
   served even if the employee's signature changes later.
2. **Fallback, not a loophole.** If the employee already has a signature on file and
   none is uploaded, that image is snapshotted onto the term. Without either, the
   member is not registered (`grievances.errors.committee_member_signature_required`).
3. **Approval gate.** A grievance committee is not approved while a serving member has
   no signature on file (`grievances.errors.committee_signatures_missing`). The rule is
   enforced at approval only: committees approved before this ADR keep handling cases,
   and the committee page flags members without a signature so the gap can be closed.
4. **Viewing.** The image is served privately (`members.signature`, `no-store`) to users
   who may view the committee; it is never a public URL.
5. **Not a digital signature.** As with letters, the image is a handwritten signature on
   file, not a qualified electronic signature.

## Consequences

- New migration adds four nullable columns to `grievance_committee_members`.
- The demo seeder stores a placeholder image per demo member so demo committees still
  approve.
- Seeding or importing members through `GrievanceCommitteeService::addMember` must pass
  `signature` (upload) or `signature_path` (already stored) unless the employee has a
  signature.
