# Tasks

## Shipped in the first pass

- [x] Add `src/integrations/CnHoursWidget.vue` — total, recent bookings, log-hours and timer actions; a dash rather than a zero when the read fails.
- [x] Add `src/integrations/registerHoursLeaf.js` — the `humaniq-hours` descriptor with the mount/unmount DOM hand-off, and a load-order-safe registry stub.
- [x] Add `lib/Listener/RegisterHoursLeafListener.php` — the server half, behind a `class_exists` check so an install without OpenRegister skips it.
- [x] Wire both halves in (`src/main.js`, `lib/AppInfo/Application.php`).
- [x] Add `scripts/check-integration-parity.{sh,js}` — gate-24's entry point, correlating leaf ids and their bound fields across both halves. Proven to fail against planted drift.
- [x] Add the eight l10n keys with Dutch.

## Continuation: get it onto the page, and finish the surface

- [x] Add `src/leaves.js` and a `leaves` webpack entry so `js/humaniq-leaves.js` is built. Verified by the artifact existing AND by the surface appearing on a consuming page, never by the build's exit code alone.
- [x] Add `timer` to the `origin` enum in `lib/Settings/register.d/hr-timesheet.json`. `endedAt` turned out to be optional already: `TimeEntry.required` is `[]` on `development`, so only the enum needed widening.
- [x] Teach `TimeEntryStampListener` that an entry with no end is a running timer: stamp it, set `hours: 0`, skip the span checks. Refuse a write with neither a start nor an end as before.
- [x] Add `lib/Controller/TimeEntryController.php` with the three timer routes, resolving the entry from the caller and refusing a second start by naming the object the running one belongs to.
- [x] Register the three routes in `appinfo/routes.php`, before the SPA catch-all.
- [x] Rebuild `CnHoursWidget.vue` as the KPI tile: total as headline, the caller's own hours beneath, and a running-timer face with elapsed time.
- [x] Add `src/dialogs/HoursBookingDialog.vue` — the booking dialog Humaniq renders over the host page, seeded with the object reference and offering neither reference field for editing.
- [x] Point the view-hours action at `/apps/humaniq/time-entries` filtered on the host object. It currently points at `/timesheets`, which is a different schema's page.
- [x] Restore a running timer on mount, and say so rather than offering a start when the caller's timer belongs to another object.
- [x] Move dossiq's `case-kpis-hours` onto this leaf, retiring the last cross-app register query on the case detail page. Done in ConductionNL/dossiq#2368: the widget is an `integration` placement, and `src/manifest.json` names the humaniq register nowhere, checked over 49 pages and 80 widgets.
- [x] Add PHPUnit coverage for the controller's refusal paths and the listener's open-entry branch, each mutation-checked against a planted break.
- [x] Add an e2e journey covering start / leave / return / stop against a seeded
  host object: `tests/e2e/spec-coverage/hours-leaf-timer.spec.ts`. It asks a
  SECOND request context what is running, which is the only version of "survives
  the page" a test can hold, and it reads the STORED row rather than the
  response. That is what caught the `origin` allowlist swallowing the timer
  marker; every response along the way looked correct.
- [x] The book-hours dialog and the view-hours link are covered in dossiq's
  `tests/e2e/case-hours-leaf.spec.ts`, which mounts the leaf where it really
  renders. That half needs humaniq installed and dossiq's CI installs only
  openregister, so it is registered behind `DOSSIQ_E2E_HUMANIQ=1`. It has now
  RUN: 3 passed, exit 0, against the dev instance on 2026-09-11 (tile, booking
  through the dialog, timer surviving a reload). Getting it green found four
  spec defects, fixed in ConductionNL/dossiq#2510, the worst of which left 10
  hours of test bookings on a real timesheet.
- [x] Add the new l10n keys with Dutch.

## Acceptance criteria

- `js/humaniq-leaves.js` exists after a build and registers `humaniq-hours` on a consuming app's page.
- The tile on a dossiq case shows a total that matches the entries behind it, and a caller share that is a subset of that total.
- A timer started on a case is still running after a reload and after navigating away and back.
- A second start is refused by the server, not only by the widget.
- A timesheet holding a running entry reports the same total as it did before that entry existed.
