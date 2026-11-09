# ADR 0021: Bulk ID card printing and the print station

- Status: accepted (2026-11-01)
- Context: cards printed one at a time. Each print froze the card as a snapshot
  (`IdCardPrintSnapshotService::prepare`), the operator printed it from the browser, and confirmed
  it (`confirm`), which marked the card printed. Print batches existed but only grouped cards
  waiting for a first print: a batch could not render, print or download its cards, and "mark
  printed" required every card to have been confirmed one by one first. The owner asked for a page
  that prints or downloads many cards at once, by organization or organizational unit, and a print
  page that manages printing to a printer properly.

## Decision

1. **Bulk selection** (`/print-batches/bulk`, `IdCardPrintSelection`): current, printable cards in
   the actor's organization scope, by organization, unit (with or without its sub-units), need
   (waiting for first print, needing a reprint, or any printable card) and search. One query serves
   the listing and "batch everything matching", so the count shown is the count batched.
2. **A batch takes first prints and reprints**: any current card the single-card flow can print
   (pending print, printed, issued, active). Building a batch keeps its own permission
   (`id-cards.createPrintBatch`); the print or reprint permission is checked per card when the
   batch is prepared, by the same snapshot service.
3. **One open batch per card**: a card already waiting in a draft or printing batch cannot join
   another, and the bulk page leaves it out, so no card is printed twice by accident.
4. **At most 500 cards per batch** (`CreatePrintBatchAction::MAX_CARDS`); the station prints a
   range at a time, sized to the printer's hopper.
5. **The station prints frozen snapshots only**: preparing creates one snapshot per card
   (20 per request, the page repeats until done); the sheet and the ZIP use those files; recording
   a good card calls the existing `confirm` on that snapshot. A bulk run leaves the same audit
   trail as a single card. Snapshots older than 20 hours are prepared again, because `confirm`
   refuses one older than a day.
6. **Results are per card**: printed (confirmed) or spoiled with a reason. A spoiled card stays
   printable and can go straight into a new batch. A batch completes when no card is waiting.
7. **Printing goes through the browser's print dialog**, not a direct printer connection. A web
   page cannot address a printer itself; the card printer's driver is installed on the operator's
   computer and chosen in the dialog. The sheet page sets the exact physical page size (`@page`)
   for the chosen **printer profile**: a card printer (one face per page; front/back interleaved
   for duplex, or all fronts then all backs) or a desktop printer (A4/Letter, n-up, cut guides,
   backs mirrored for long-edge duplex). Profiles carry calibration offsets and scale, are shared,
   and need `id-cards.print` or `cards.manage` to manage.
8. **Downloads are vector SVG** (front and back per card, plus a UTF-8 manifest), because they are
   the frozen files and print sharp at any size. PNG export needs Imagick with SVG support, which
   not every server has.

## Consequences

- The batch screen keeps its contract with the standalone Core app (ADR 0010); EUISIS adds an
  optional `stationUrl` prop, so Core's copy is unchanged.
- A direct printer connection (a local print agent such as QZ Tray) can be added later behind the
  same sheet without changing the batch model.
