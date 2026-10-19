# Photo access

The verification response carries `employee.photo_url` so a terminal operator can
confirm the person holding the card is its owner.

- Present only when the caller may read photos: an application with
  `employees.photo_read`, or a person with `employees.view`. Otherwise `null`.
- It is a **signed, temporary** URL to
  `GET /api/v1/employees/{employee_uuid}/photo` valid for 5 minutes
  (`photo_expires_in`, seconds). It carries the issuing principal.
- At serve time the API re-checks that principal: still active, still holding photo
  access, employee still inside its organization bound. Any failure → 404.
- A forged, altered or expired link → 403 (`signed` middleware).
- Served from the private disk with `Cache-Control: private, no-store` and
  `X-Content-Type-Options: nosniff`.

Partners must display the image and discard it. Do not store the image or the
URL; request a fresh verification instead.

The previous permanent `/storage/...` URL is no longer returned.
