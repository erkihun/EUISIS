# Public evidence — Tip-off and Complaint

Amharic: የሕዝብ ማስረጃ — ጥቆማ እና ቅሬታ

A Tip-off or a Complaint may carry optional **photo, video, audio or PDF**
evidence. Evidence is what the citizen submitted. It is never treated or
labelled as proof.

## 1. What is accepted (`PublicEvidencePolicy`, `PublicEvidenceFile`)

| Kind | Content types (sniffed) | Extensions | Max per file |
|---|---|---|---|
| Photo | image/jpeg, png, webp, heic, heif | jpg jpeg png webp heic heif | 5 MB |
| Video | video/mp4, quicktime, webm, 3gpp, x-m4v | mp4 mov webm 3gp m4v | 50 MB |
| Audio | audio/mpeg, mp4, x-m4a, aac, x-hx-aac-adts, wav, x-wav, ogg, opus, amr | mp3 m4a aac wav ogg oga opus amr | 20 MB |
| Document | application/pdf | pdf | 5 MB |

- Up to **5 files**, **75 MB** in total, per submission.
- The **content** decides. PHP's finfo reads the type from the bytes, and the
  browser's claim is ignored. The size limit applied is the one for the kind
  the content actually is.
- The extension must also be on the list, and the name must pass
  `SafeUploadName` (no paths, control characters, or script/executable
  extensions anywhere in it, so `photo.php.jpg` is refused).
- Refused in tests: an executable named `.jpg`, HTML named `.mp4`, PHP named
  `.mp3`, a real photo named `.php`, a double extension, an oversize photo,
  and a sixth file.
- Real formats were checked with ffmpeg-encoded samples: MP3, AAC/ADTS, M4A,
  WAV, Ogg/Opus, WebM, MP4, MOV, 3GP, JPEG, PNG and WebP each sniff to an
  allowed type.

## 2. Storage

- Private `local` disk at `service-feedback/{submission-id}/{uuid7}.{ext}`. The
  original name is kept only as sanitised display text.
- PostgreSQL holds metadata only (`employee_service_feedback_attachments`:
  disk, path, display name, MIME, size, SHA-256, scan status). It never holds
  file bytes.
- No public or permanent URL exists. Download goes through
  `service-submissions.attachments.download`, which requires view access,
  organization scope, not being the target employee, and
  `service_feedback.view_sensitive_attachments`. It verifies the SHA-256, sends
  `Cache-Control: private, no-store` and `nosniff`, and is audited
  (`complaint_attachment_downloaded` or `tip_off_attachment_downloaded`).
- If storing any file fails, the whole submission rolls back and any files
  already written are deleted.

## 3. Malware scanning — **MALWARE_SCANNER_REQUIRED**

No malware scanner is integrated in this system. Every file is stored with
`scan_status = not_scanned`, and staff screens say so ("Not scanned for
malware"). Nothing claims a file was scanned. Production governance should
add a scanner (for example ClamAV through a queued job) before evidence is
opened on staff machines.

## 4. Server upload limits — deployment blocker

`docs/infrastructure-security.md` documents `upload_max_filesize = 5M` and
`max_file_uploads = 5`. With those values PHP drops any video or audio file
over 5 MB before the application sees it. To accept the evidence policy
above, production needs at least:

```ini
upload_max_filesize = 50M
post_max_size = 80M
max_file_uploads = 5
```

The reverse proxy needs `client_max_body_size 80m;` (nginx) or the
equivalent. Until then, keep large video out of scope or lower the video and
audio limits to 5 MB in `PublicEvidencePolicy`.

## 5. Not decided — **OFFICIAL_RETENTION_POLICY_REQUIRED**

No retention period is defined for evidence or Tip-offs. Nothing is purged.
