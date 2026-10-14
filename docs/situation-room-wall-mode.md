# Executive Situation Room — Wall Mode

A full-screen display for an operations room (`/situation-room/wall`,
grant `situation_room.wall.view` + `situation_room.view`).

* **Aggregates only.** The server builds the wall from wall-eligible
  categories (Overview, Workforce, Complaints, Feedback, Tip-offs, Daily
  Operations, Field Work — those the account may see) and keeps, per
  category, its headline figures and up to two charts. Tables and every
  `*_employees` panel are dropped **on the server**, whatever the account's
  grants. Tested in `SituationRoomActionsAndExportsTest`.
* **Scope.** The wall shows the signed-in account's scope (optionally focused
  with `?organization_id=`), like any other page. Use a dedicated account whose
  scope matches the room the screen is in.
* **Rotation and refresh.** Screens rotate every `wall_rotation_seconds`
  (default 30); the figures reload every `wall_refresh_seconds` (default 300)
  as a partial reload — no full page reload, no flicker. The footer shows when
  the figures were computed.
* **Controls.** Previous / next, pause (also Space, ← →), full screen,
  language, theme, exit. Large type, no sidebar.
* **Nothing stored in the browser** beyond the existing language preference.
* Small-group rule applies as everywhere else.

## Kiosk setup

1. Create a user with `situation_room.view` + `situation_room.wall.view` and the
   category grants to display, scoped to the organization the room covers.
2. Sign in on the display, open Wall Mode, press **Full screen**.
3. Session timeouts follow the security settings; plan re-authentication for
   long-running displays.
