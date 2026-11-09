# Communication & Collaboration

Messages, groups, channels and official announcements for employees, with
tasks from messages, moderation, blocking and controlled investigation access.

| Page | Who |
|---|---|
| `/communication` | Everyone with `communication.access`: unread counts, active conversations, announcements |
| `/communication/messages` | Chat workspace (direct, groups, channels) |
| `/communication/channels` | Browse, join and create channels |
| `/communication/announcements` | Feed; authoring and reports for `announcements.*` |
| `/communication/tasks` | Tasks assigned to me / by me |
| `/communication/preferences` | Own preferences and blocked people |
| `/communication/moderation` | `communication.moderate` |
| `/communication/investigations` | `communication.break_glass.*`, `communication.audit` |
| `/communication/admin/settings` | `communication.settings.manage` (works while the module is off) |

### Entry point and navigation

- Communication is **not a sidebar item**. It opens from the Messages icon in
  the top bar (`TopNav/CommunicationButton`): first of the top-bar actions,
  unread badge (none at 0, then the count, "99+" above 99), tooltip, active
  state on every Communication page. Shown only when the module is on and the
  person holds `communication.access`; the routes refuse everyone else.
- Every Communication page uses the layout's communication mode
  (`<AuthenticatedLayout mode="communication">`): the desktop sidebar drops to
  its icon rail (the saved preference is untouched) and the workspace's own
  tab strip (`CommunicationNav`) sits under the top bar — Messages, Channels,
  Announcements, Tasks, Overview, and a More menu for Preferences, Moderation,
  Investigations and Settings, each by permission (`CommunicationNavigation`).
  The chat workspace has no heading band and fills the screen; only its panels
  scroll.
- Every page carries one small shared prop, `communication`:
  `{enabled, unread_count, realtime, nav}`; `nav` is filled on Communication
  pages only. Conversations, messages, members and announcements load only
  inside the workspace.
- Conversation header: avatar, name, presence and (direct) position · unit or
  organization; buttons for search in the conversation, pinned messages and the
  details panel. Search (`GET …/conversations/{id}/search?q=`, at least two
  characters, 30 newest matches, deleted messages excluded, same visibility as
  reading) opens in the details panel and jumps to the message.
- Notification links open the conversation (`/communication/messages/{id}`);
  a mention opens it at the mentioning message (`?message={id}`).

## Groups and channels

One conversation model serves direct messages, groups and channels; a channel
wraps a conversation with a scope and rules. Memberships are rows in
`conversation_members` with a `role` (owner, admin, member), a `source`
(MANUAL, AUTO, SYSTEM) and, when they end, a `left_reason` (LEFT, REMOVED, SYNC).
Rows are never deleted: a returning person reuses theirs.

### Groups

- Created by anyone with `groups.create` while the system allows employee
  groups. The creator is the owner; members must be within the creator's
  directory reach and not blocked either way. At most
  `communication.max_group_members` people (default 256).
- Owners and admins add and remove members; only the owner changes roles or
  hands over ownership (the old owner becomes an admin). The owner cannot be
  removed, and an admin cannot remove another admin.
- When the owner leaves, the longest-standing admin (else member) becomes owner;
  when the last person leaves, the group is archived. Archived conversations
  stay readable to members and accept no posts.
- A newcomer's read pointer starts at the latest message, so joining is not a
  wall of unread history. System notes ("X added Y") never count as unread.

### Channels

| Kind | Who creates | Visible to | Membership |
|---|---|---|---|
| Organization (`PUBLIC_ORGANIZATION`) | `channels.create` in the organization's scope | People placed in its scope (browse and join) | Join, or automatic |
| Official (`SYSTEM_MANAGED`) | `channels.create` + `channels.manage` in scope | Members only | Automatic and mandatory; members cannot leave |
| Private / Project | Managers, or any user when "Employees may create channels" is on | Members only | Added by owners/admins |

- A channel may be bound to an organization, a unit, or neither (city-wide), and
  may include everything under it. Employee-created channels always belong to
  the creator's organization and are never automatic.
- Posting: all members, or owners and admins only. Official channels post as
  admins only.
- Someone an admin removed cannot rejoin an organization channel by themselves.

### Automatic membership

Channels with `auto_membership` hold every active user with
`communication.access` whose current assignment falls in the channel's scope.

- **Creating the channel** queues `SyncChannelMemberships`.
- **A change to someone's assignment** (organization, unit, status, current
  assignment) or account status queues `SyncEmployeeCommunicationMemberships`
  for that person, after the transaction commits.
- **`communication:sync-memberships`** runs nightly (`--queue`) and catches what
  no save announces, such as a role losing `communication.access`.

Rules: AUTO members who no longer match are removed (`SYNC`) when the channel
says `remove_on_transfer`, and come back if they are placed in scope again.
People who left or were removed are not re-added, except in official channels.
MANUAL members are never removed by the sync. Each change is audited
(`communication.memberships_synced`).

## Announcements

Official, one-way notices. One row per announcement and one per target; no
row per recipient is created when it is published.

### Lifecycle

`DRAFT` → `SCHEDULED` (publish time set) → `PUBLISHED` → `EXPIRED` (expiry passed),
or `CANCELLED` (withdrawn, with a reason) from scheduled or published.

- Drafts and scheduled announcements can be edited; published ones cannot
  (withdraw and publish a corrected one).
- HR (`announcements.create`, `announcements.edit`) drafts; the organization's
  admin (`announcements.publish`) publishes or schedules, and is recorded as the
  publisher. Saving with "publish" without that permission keeps it as a draft.
- `communication:publish-scheduled` (every minute) puts due announcements live;
  `communication:expire-announcements` (every five minutes) records expiry.
  Readers stop seeing an announcement the moment it expires either way.

### Audience

| Target | Reaches | Author needs |
|---|---|---|
| City | Everyone | the permission without organization restriction |
| Organization | People placed in it | the organization in scope |
| Organization and everything under it | It and its descendants | the organization in scope |
| Unit | The unit and its sub-units | the unit's organization in scope |
| Employee | One person | their organization in scope |
| Role | Everyone holding the role | unrestricted scope |
| Group | Members of a group the author belongs to | membership |

A reader's audience keys (their organization and those above it, their unit
and those above it, their employee record, roles, groups) are matched against
the targets through an index, so a city-wide notice costs one row. The reverse
query (`AnnouncementAudience::recipients`) counts the audience, sends
notifications and builds the acknowledgement report.

### Delivery and receipts

- Every reader sees it in their feed and in the unread badge.
- Important, urgent and emergency announcements, and any needing an
  acknowledgement, send an in-app notification (Amharic title for Amharic
  readers). Urgent and emergency ones are emailed when "Email urgent
  announcements" is on; emergencies go by SMS through the budgeted SMS gateway
  when "SMS emergency announcements" is on.
- A receipt row is created the first time a reader meets the announcement:
  delivered (listed in their feed), viewed (opened), acknowledged (confirmed).
  Times only move forward; acknowledging twice changes nothing.
- Managers see audience / delivered / viewed / acknowledged counts, a paged
  list of recipients filtered by status, and can export it as CSV (UTF-8 with
  BOM for Excel; cells that could be read as formulas are neutralized). Every
  export is audited.
- Files (up to 10) go through the same private storage and type checks as chat
  attachments, and download only for people who may view the announcement.

## Tasks, reports and blocks

### Message → task

Any member with `communication.tasks.create` can turn a message into a task
for someone in the same conversation. The task links back to the message
(only for people who can still read that conversation), the conversation gets
a short note ("X created a task for Y") and the assignee is notified. The
assignee and the creator move its status; only the creator (or a task manager
on it) changes its details, reassigns it within the conversation or cancels it.
Everyone sees their own tasks at `/communication/tasks`.

### Reporting and moderation

- Members report a message (not their own) or a group or channel. Reporting the
  same thing again while it is open returns the same report. The audit row holds
  ids only, never the text.
- Moderators (`communication.moderate`) review at `/communication/moderation`.
  Opening a report shows **only the reported message and three messages either
  side** (or the latest few of a reported group or channel), never the rest of a
  private conversation; it moves the report to "in review" and is audited
  (`communication.reported_content_accessed`).
- A moderator never sees reports they filed or reports about themselves.
- Closing a report needs a note; "action taken" may remove the message (members
  then see "This message was deleted"; audited as a moderator deletion).

### Blocking

Blocking someone stops both of you from starting or posting in a direct
conversation and from adding the other to a group. The blocked person is not
told: they see the same "cannot message this person" as for anyone else.
Block or unblock from the details of a direct conversation.

## Investigation access (break-glass)

Private conversations are never opened by role. Super Admin has no bypass: the
policies for conversations, messages, files, reports and access requests read
permissions from the person's roles (`checkPermissionTo`), whatever the global
Super Admin rule says.

1. **Request** (`communication.break_glass.request`): one conversation, or every
   conversation of one employee, with a case reference, a written reason of at
   least 30 characters and the hours needed. Approvers are notified.
2. **Decide** (`communication.break_glass.approve`): someone other than the
   requester approves (for no more hours than asked, and never more than the
   "Investigation access limit" setting, default 24, maximum 72) or rejects,
   with a note either way.
3. **Read**: only the requester, only while approved and unexpired. Read-only:
   no posting, reacting, read receipts or downloads; deleted messages are shown
   and marked. Every page read is audited (`communication.access_used`) with the
   conversation and message ids.
4. **End**: at expiry (`communication:expire-investigations`, every five
   minutes; access stops at the expiry time regardless), or earlier when an
   approver or the investigator revokes it.

Approvers and auditors (`communication.audit`) see requests and decisions, never
the content. City Admin does not get these permissions by default.

## Realtime

HTTP is the source of truth. Realtime only tells an open page that something
changed, so chat works (more slowly) without it.

| Situation | What the page does |
|---|---|
| `BROADCAST_CONNECTION` is `log`/`null`, or no `VITE_REVERB_APP_KEY` | Polls: open conversation every 5 s, conversation list every 15 s, details every 30 s. |
| Socket connected | Events update the page at once; a 30 s safety poll still runs in case broadcasts stop (for example, no queue worker). |
| Socket dropped after being connected | Shows "Live updates paused, reconnecting…", polls every 5 s, and re-reads the conversation when the socket returns. |

### Running it

```bash
php artisan reverb:start                      # WebSocket server (REVERB_HOST/PORT)
php artisan queue:work --queue=broadcasts     # only if COMMUNICATION_BROADCAST_QUEUE=broadcasts
```

- Broadcast events are queued (`ShouldBroadcast`, after commit). With
  `COMMUNICATION_BROADCAST_QUEUE` empty they use the default queue and any
  normal worker delivers them; set it to a dedicated queue to keep chat quick
  when other jobs are busy, and run a worker for that queue.
- Typing indicators are sent immediately (`ShouldBroadcastNow`) and are never
  stored. A failed broadcast is logged and counted; it never fails the request.
- The socket client (`laravel-echo`, `pusher-js`) loads only when the server
  reports realtime as configured; it is then started by the top-bar badge on
  any page (for `unread.changed`) and shared with the workspace.
- `unread.changed` is sent to everyone whose cached totals are cleared (a
  message, a read, an acknowledgement, a membership change); in groups and
  channels up to 500 members are told per message, larger audiences catch up
  by the badge's one-minute poll.
- With `BROADCAST_CONNECTION=log` (or `null`) nothing is queued for broadcasting.
- Rebuild the frontend after changing any `VITE_REVERB_*` value.

### Channels (`routes/channels.php`)

| Channel | Who may subscribe | Carries |
|---|---|---|
| `private-communication.conversation.{id}` | Current members who may use Communication | message sent/edited/deleted, reactions, read pointers, typing, member changes |
| `private-communication.user.{id}` | That user only | new conversations, membership, `unread.changed` (no count: the top-bar badge re-reads the cached summary) |
| `private-communication.announcements` | Users with `announcements.view` | an announcement id and priority; the audience is checked when the page fetches it |

- `/broadcasting/auth` runs behind `auth`, `mfa`, `force.password`,
  `admin.access` and a rate limit, like the pages.
- There is no Super Admin bypass for conversation channels. Break-glass
  investigators read through the audited investigation views, never a live
  subscription.
- Payloads carry ids and the minimum needed to update the page.

## Settings and preferences

`/communication/admin/settings` edits the `communication` settings group (every
change audited by the shared settings action) and shows the module's status:
volumes, open reports, pending and active investigations, and the realtime
pipeline (driver, queue, waiting broadcasts, failed broadcasts). It sits outside
the module switch so the module can be turned back on.

Each person sets, at `/communication/preferences`: whether colleagues may start
direct conversations with them, whether others see them online or their last
seen time (only where the system allows presence), whether urgent announcements
are emailed and emergency ones texted to them (only where the system allows it),
and who they blocked.

## Retention

`communication:prune` (daily at 03:55; `--dry-run` to count only) applies the
retention settings and nothing else:

- Messages **deleted** longer ago than "Message retention (days)" are removed for
  good with their files. Live messages are never removed by age.
- Expired or withdrawn announcements older than "Announcement retention (days)"
  are archived: out of the managers' list, kept for the record.
- Files uploaded but never sent are removed after a day.

Blank settings mean no policy. Conversations with an open report, or covered by
a pending or in-force investigation request, are on hold and kept. Each run that
changes anything is audited (`communication.retention_applied`) with counts.

## Scheduled work

| Command | When |
|---|---|
| `communication:publish-scheduled` | every minute |
| `communication:expire-announcements` | every 5 minutes |
| `communication:expire-investigations` | every 5 minutes |
| `communication:sync-memberships --queue` | daily 03:40 |
| `communication:prune` | daily 03:55 |

