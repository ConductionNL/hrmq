# Humaniq review: completeness, abstraction, usefulness

**Date:** 2026-09-06
**Scope:** the lens applied to dossiq, decidiq and pipelinq this week. Is the domain modelled at the right level, does every declared thing actually work, and is the result what an HR user wants?

## Verdict

Humaniq is the broadest app in the fleet and, on the mechanical measures, the healthiest: 54 schemas, 113 pages, all applicable hydra gates green. What it had not had is a second pass over its own shape.

Three findings were real. Two of them only showed up when the app ran against a live OpenRegister, which is the main lesson of this review: a declaration being present is not the same as the thing working.

## What was wrong, and is now fixed

### 1. Approving leave never moved the balance

`LeaveBalance.usedHours` was written in exactly one place, `LeaveAccrualJob.php:262`, and the value it wrote was `0.0`. Nothing else ever wrote it, and nothing reacted to a `LeaveRequest` reaching `approved`. Timesheets had that wiring all along; leave had the same lifecycle, the same guard, the same four pages, and no listener.

Three things followed. Every employee saw their full entitlement forever. Three rule checks that read `usedHours` could never fire. And final settlement paid out `max(0, entitled + bovenwettelijk - used)` for every leaver, which with `usedHours` at zero is the full annual entitlement, ignoring leave already taken.

Fixed in #320. `LeaveApprovalListener` hands each changed request to `LeaveBalanceProjectionService`, which recomputes `usedHours` as the sum of the approved requests behind it. Recompute rather than increment, so a replayed or missed event cannot make it drift and every balance already wrong corrects itself. Verified live: approving a five-working-day request carrying no explicit hours moved `usedHours` from 0 to 40.

### 2. `remainingHours` had no column to be materialised into

The balance's remaining hours are declared under `configuration.x-openregister-calculations` with `materialise: true`, and deliberately not as a property. OpenRegister builds each schema's physical table from its properties, so there was no column to write to. The field was absent from every response, and the `LeaveBalances` page rendered that column blank.

Fixed in #334 by making it a virtual calculation, which `RenderObject` works out at read time and which needs no column.

I had dismissed this exact signal earlier in the review as a false positive, because the field *was* declared. Declared and present are not the same thing, and only the live instance settled it.

### 3. Twenty index pages over seven schemas

gate-68 reported seven duplicate pairs. Eight of the thirteen duplicates differed from their canonical page only by a filter, which ADR-097 Decision 5 calls a role lens rather than a page. Those are collapsed into `menu[].query` presets in #336.

**Five are not lenses.** `MijnUren` omits `employeeId` from its create form on purpose, because an employee booking their own hours must not get an employee picker. Four others carry stricter `actionToggles` than their canonical page, so collapsing them would have handed an employee delete and copy affordances on their own payslips and timesheets. I collapsed all thirteen first and the e2e caught it.

gate-68 counts pages per schema without asking whether they differ only by filter. Its seven pairs overstate the duplication; the real count is eight.

That work needed a library fix first. `menu[].query` values skipped token resolution, so a `?userId=@me` preset filtered on the literal four characters and listed nothing. ADR-097 Decision 5's own remedy was inert for exactly the lenses it describes. Fixed in nextcloud-vue #999, released as 2.37.1, and verified live: the preset now reaches the API as `userId=admin`.

## Development was red, and is green

Four separate breakages, none of them in the features that landed them:

- the Store shipped without its `en.json` keys, without its parity baseline, with an eslint error, and against a gate schema that did not know the `store` page type (#318);
- `#321` and `#323` landed a Dutch schema title used as a translation key, a stale parity baseline after a slug rename, and two tests that a coverage-driver run reports as risky, which reddened all six PHPUnit cells while passing locally (#327);
- one E2E test asserted `getByText("Performance")`, which resolved to a hidden `<option>` in a select, so a page rendering all three reports correctly failed on a timeout (#325).

## Things I checked that turned out to be fine

Recorded so the next reviewer does not spend the time again.

- **Chrome ordering.** Documentation at order 90 looks like it collides with People at order 90. They are in different sections, so they do not compete.
- **The DGA page.** "My customary wage" is gated by `visibleIf: user.administrationMode eq dga_single_person`, not shown to everyone.
- **Duplicate schema keys.** `Employee`, `EmploymentContract` and `TimeEntry` are each defined in two fragments. `hr-cost-rate.json` contributes properties-only overlays that the ADR-037 deep merge unions by key. Working as designed.
- **Four schemas with no menu entry.** `AdministrationAccess`, `CostAdditionPolicy`, `JurisdictionPack` and `ReceiptExtraction` all have real back-office surfaces. Back-office machinery does not need a nav row.

## What is still open

- **Nine of twenty open changes have zero tasks done** and have not been touched since 2026-08-22: `30-procent-regeling`, `hris-api-public`, `humaniq-employee-relations-widget`, `humaniq-manifest-boot-and-http-cost`, `humaniq-mcp-adoption`, `humaniq-rule-compliance-enforcement`, `humaniq-test-coverage-baseline`, `single-person-modes`, `uitzend-flexpool`, `wnt-disclosure`.
- **Two changes are complete and unarchived**: `humaniq-i18n-form-surface` (8/8) and `humaniq-timesheet-approved-typed-event` (13/13).
- **Time registration sits under "Leave and absence".** Time entries, timesheets and timesheet approval are neither leave nor absence, and "Scheduling" already exists next door as a better neighbour.
- **Three duplicate-index pairs remain** by design, being the five non-lens pages above. gate-68 will keep reporting them; that is the honest count.
