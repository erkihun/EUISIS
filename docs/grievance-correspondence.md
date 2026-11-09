# Grievance correspondence

`GrievanceCorrespondenceService` manages effective templates, token rendering, drafts, recipients, attachments, finalization, signing, seal use, issuance, voiding/revision, dispatch and authorized downloads. English, Amharic and bilingual variants use organization-specific templates where available, with defaults as fallback.

## Official artifacts

Drafts remain editable and previews are marked as drafts. Finalization requires a To recipient and assigns the outgoing reference through the shared code-rule system. The reference is distinct from the case and decision numbers. Recipients capture To/CC and recipient identity/display details; attachment records link supporting materials.

Signing records the acting user's own employee, current position, signature method and timestamp. Supported methods include electronic approval and a stored signature image. **Qualified digital signatures are explicitly unavailable** and rejected. An image or application approval record must not be described as a qualified legal signature.

An approved active seal must belong to the issuing organization, and seal application requires authority and an audit event. Issuance renders a private PDF, records its SHA-256, and changes the letter to issued. The service refuses to overwrite an existing artifact. The model protects issued content against ordinary model edits; corrections use a reasoned void followed by a new draft referencing the prior letter. Voiding preserves the original artifact.

Issuing a decision letter advances the related decision/case and enables employee access according to visibility. Correcting an already issued letter must preserve the original decision's appeal window. Downloads recheck permission and checksum and return private, no-store responses.

## Delivery

Dispatches track channel, recipient, status and acknowledgment. Notices contain a case reference and action link rather than confidential decision content. SMS depends on the configured gateway. An application dispatch record is not proof of external delivery; confirm provider behavior and delivery evidence before treating email, SMS or manual delivery as completed service.

PDF rendering uses the grievance letter view and private signature/seal assets. Visually review representative English, Amharic and bilingual letters, long decisions, page breaks, letterhead, signature and seal placement before release. The CLI's default 128 MB was insufficient for the test font rendering path; focused tests run with 512 MB. Establish a measured production worker limit.

## NEEDS_DECISION

Approve official wording, letterhead, numbering scopes/reset calendar, authorized signatories and seal custodians, whether seals are mandatory, valid service channels and delivery evidence, and legal signature requirements. Choose any qualified-signature integration explicitly; none is claimed here. Reconcile legacy PDFs lacking checksums and confirm their private storage paths.
