# Situation Room — Wall mode

A full-screen display for a screen in a leadership office:
`GET /situation-room/wall`, optionally with `?organization=<id>` and the
usual period filters.

## Access

- It is an **authenticated** route in the admin group, so the normal login,
  MFA and password-change rules apply.
- It needs `situation_room.view` **and** `situation_room.wall_mode`.
- It shows the scope of the signed-in user. A wall account should be a user
  whose scope is exactly what the screen should show.

## What it shows

- The institution in view, the period and *Last updated*
- Six KPIs: feedback, suggestions, complaints, unresolved, resolved, unrouted
- The community feedback trend
- Institutions compared (Top 10)
- Categories (Top 10)
- Status buckets

## What it never shows

- No person's name: not employees, not visitors.
- No submission text, identity or attachments.
- No filters, menus or buttons, except "Leave wall mode".

A test checks that the wall page data contains none of a fixture's message,
visitor name or employee name.

## Refresh

- The page reloads only the `wall` prop every `refreshSeconds` (300 seconds),
  through `router.reload({ only: ['wall'] })`.
- The server caches the wall payload for the same 300 seconds per scope and
  filters. Ten screens on the same view cost one computation per five minutes.

## Presentation

- `WallLayout` has no sidebar, uses large type and draws on the theme tokens,
  so the light and dark themes both keep their contrast.
- It is sized for 1920×1080 and stays readable on a laptop.
- Charts carry a screen-reader summary.
- The reload interval is stated on screen.

## Open points

- **D8: kiosk session.** The user session times out as it does everywhere.
  A display account with a longer session, or a signed display link, needs a
  decision. Neither is built.
- Choosing the scope of a wall: today it is the signed-in user's scope, plus
  an optional institution in the URL.
