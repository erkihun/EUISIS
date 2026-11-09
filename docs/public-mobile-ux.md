# Public mobile UX

Conventions for public pages that people open on a phone, mostly by scanning
a QR code. Applies first to the feedback QR pages
(`resources/js/Pages/Public/ServiceFeedback.tsx`, `FeedbackAppLayout`).

## Frame

`FeedbackAppLayout` renders a phone-width column (`max-w-md`) on every
screen size: a slim sticky header (back or brand mark, title, language, theme)
and the screen below it. On wider screens the same column is centred, so a
tablet or desktop visitor sees the same app.

## QR bottom action dock

`resources/js/Components/public/PublicQrActionDock.tsx`, shown only on the
QR landing (choice) screen. Form, receipt and unavailable screens do not have
it, so it never competes with a form's Send button or the on-screen keyboard.

| Width | Behaviour |
| --- | --- |
| < 768 px (`max-md`): phones, portrait and small landscape | Fixed dock at the bottom; the choice cards stay above it |
| ≥ 768 px (`md`): tablets, desktop | No dock; the choice cards are the way in, unchanged |

- **Actions**: the enabled submission types, then Call when the institution
  has a valid public number (see `docs/public-qr-mobile-actions.md`). With
  three actions each takes a third of the width; there is never an empty slot.
- **Position**: `position: fixed`, left/right 0, centred to the same
  `max-w-md` column with matching side borders, `z-30`, a top border and a
  light shadow. No fixed heights: the dock grows with its content and the
  browser's own toolbars do not cover it.
- **Safe area**: `padding-bottom: env(safe-area-inset-bottom)` keeps the
  buttons above the iPhone home indicator.
- **Content clearance**: the landing screen adds
  `calc(6rem + env(safe-area-inset-bottom))` bottom padding below `md`, so the
  dock never covers the last card or the privacy note.
- **Touch targets**: each action is at least 60 px tall and a quarter (or
  third) of the column wide.
- **Labels**: an icon and a short visible label in the visitor's language
  (`serviceFeedback.dock*`); 13 px semibold, allowed to wrap rather than
  truncate. Amharic is left-to-right like English.
- **Icons**: the project's icon set (`@/Components/NavIcons`, Solar), the same
  icon and colour as the matching choice card: star (Feedback), chat
  (Suggestion), warning triangle (Complaint), phone (Call, primary colour).
- **States**: hover and press tint the background; keyboard focus shows a
  2 px primary outline inside the button. No action is marked "current".
- **Accessibility**: the dock is a `<nav>` named "Quick actions"; each action
  is a link with its visible label as its name. Call's accessible name says
  whose number it is and includes it (for example "Call this institution's
  public contact number, 011 123 4567"), and contains the visible word "Call".
- **Toasts** appear at the top of the screen, clear of the dock.

## Checks before changing these pages

Test at 320, 375, 390 and 430 px, tablet portrait (768 px) and desktop, in
Amharic and English, and on a phone in landscape. There must be no horizontal
scroll, every action at least 44 px tall, and the last line of content visible
above the dock.
