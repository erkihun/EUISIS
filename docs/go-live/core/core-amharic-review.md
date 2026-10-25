# Core Golden Path: native Amharic review

**Status: NOT REVIEWED.** The automated audit (`npm run i18n:audit`, strict)
finds no missing keys and no blocking glossary findings. It cannot judge
whether the wording is clear, correct and natural. Only a native reviewer
can, so linguistic quality is not marked as passed.

## What to review

[core-amharic-review.csv](core-amharic-review.csv) has 2,921 strings,
generated from the language files of the release candidate. Each row shows
the screen area, the source file, the key, the English text and the current
Amharic.

| Area | Strings | Main screens |
|---|---|---|
| authentication | 177 | sign-in, password change, two-step sign-in |
| employee | 396 | employee list, registration, verification, documents |
| organization | 710 | organizations, units, positions, seats, hierarchy versions |
| assignment | 392 | placements and transfers |
| id card | 195 | card requests, print batches, quality check, card check page |
| security | 70 | Security Center, break-glass, MFA |
| reports | 682 | Core screens (dashboard, reports, data quality, notifications, System Health) |
| errors | 247 | common messages, confirmations, validation errors |
| notifications | 52 | in-app notifications and e-mail |

## How

1. The reviewer works through the screens in Amharic on the UAT environment
   (UAT scenario L-01), with the CSV open beside them.
2. For each string they fill in `reviewer_verdict` (ok / change / unclear),
   plus `suggested_wording` and `comment` where needed. Glossary terms (for
   example the words for organization, position, placement and card) should
   be decided once and applied everywhere.
3. The application owner applies the changes in the EUISIS source language
   files. These are written with an editor, never through a shell, which
   corrupts Ge'ez text. The owner then syncs the Core, runs
   `npm run i18n:audit` again, and the reviewer checks the changed screens.
4. The reviewer signs the review in the UAT sign-off record (scenario L-01)
   with their name and date. Until then, risk R-11 stays open.
