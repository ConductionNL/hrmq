---
capability: mileage-rules
status: done
built_by: openspec/changes/archive/2026-07-14-mileage-rules
---

# mileage-rules Specification

**Status**: done
**Scope**: humaniq (`kind: code+config`) — Dutch mileage/commute reimbursement (reiskosten) as a
machine-checkable corpus rule on the existing `Expense` schema: the 2026 onbelaste
kilometervergoeding (EUR 0,23/km) as versioned rule-corpus data, a check flagging over-onbelast
per-km reimbursement, and two additive `Expense` fields the check needs. Reuses the existing
Expense submit/approve/reject/reimburse lifecycle unchanged; adds no new lifecycle, no gross-up,
and no UI/manifest change.
**OpenSpec changes**:
- [mileage-rules](../../changes/archive/2026-07-14-mileage-rules/) _(archived 2026-07-14)_ —
  `Expense.travelType`/`distanceKm`, the `nl-reiskosten-onbelast-tarief` corpus rule
  (`lib/Standards/rules/payroll.json`), `NlTravelExpenseChecks` (auto-discovered by `RuleEngine`), and
  one compliant mileage seed.

## Purpose

Bring the Belastingdienst's onbelaste kilometervergoeding — the per-kilometer amount an employer
may reimburse tax-free for zakelijke (business) and woon-werkverkeer (commute) kilometers — into
the same machine-checkable rule corpus that already covers `nl-vakantiebijslag-8procent` and
`nl-zvw-werkgeversheffing`: a numeric threshold, versioned as data, checked by a small vacuous-scope
predicate over `Expense`. Reimbursing more per km than the rate without additional withholding
makes the excess (bovenmatige vergoeding) taxable wage; this change surfaces that as an audit-time
compliance signal (`occ humaniq:rules:audit`), never a write-time guard.

## Requirements

### Requirement: Expense SHALL carry two additive, nullable mileage fields (REQ-MILE-001)

`Expense` (`lib/Settings/register.d/hr-expense.json`) SHALL carry two additive, nullable properties
outside `required`: `travelType` (enum `business`/`commute`) and `distanceKm` (number, minimum 0).
No `category` change, no `required` change, no lifecycle change; every previously stored Expense
stays valid without migration. **Delivered** (`Expense.version` 0.4.0 to 0.5.0;
`lib/Settings/humaniq_register.json` `info.version` 0.9.0 to 0.10.0). Both were bumped fresh from
their actual HEAD values, one increment past the versions the proposal was originally scoped
against, since an intervening change had already consumed the 0.3.0/0.8.0 to 0.4.0/0.9.0 step.

#### Scenario: The mileage fields are optional
@e2e exclude Schema shape in the register fragment, not a browser behaviour.
- **WHEN** the `Expense` schema is read from `lib/Settings/register.d/hr-expense.json`
- **THEN** it declares `travelType` as a nullable string with enum `business`/`commute`
- **AND** it declares `distanceKm` as a nullable number with minimum 0
- **AND** neither field appears in `required`

### Requirement: The rule corpus SHALL carry the onbelast per-km rate as versioned data (REQ-MILE-002)

`lib/Standards/rules/payroll.json` SHALL carry `nl-reiskosten-onbelast-tarief` (domain `tax`,
jurisdiction `NL`, framework `nl-loonheffingen`, source Wet LB 1964 art. 31a lid 2, severity
`mandatory`, `machineCheckable: true`, `effectiveDate: 2026-01-01`, sourced to the
Belastingdienst's kilometervergoeding-verhoging notice) carrying `parameters.rateEurPerKm: 0.23`.
The predicate reads that parameter and never hardcodes it, so a later rate change (including the
Belastingdienst's own mid-2026 EUR 0,23 to EUR 0,25 increase) is a one-number JSON edit.
**Delivered** (`RuleCatalogue::VERSION` bumped 2026-07.18 to 2026-07.19, one increment past the value
the proposal was scoped against for the same reason as REQ-MILE-001).

#### Scenario: The rate is read from the corpus
@e2e exclude Audit-time predicate, covered by NlTravelExpenseChecksTest::testRateIsVersionedCatalogueData.
- **WHEN** the `nl-reiskosten-onbelast-tarief` entry in `lib/Standards/rules/payroll.json` is read
- **THEN** it carries severity `mandatory`, `machineCheckable: true` and `parameters.rateEurPerKm: 0.23`
- **AND** `NlTravelExpenseChecks` takes the rate from that parameter rather than from a constant

### Requirement: A check provider SHALL flag a per-km reimbursement above the onbelast rate (REQ-MILE-003)

`lib/Standards/Checks/NlTravelExpenseChecks.php` SHALL implement `CheckProvider`, registering
`checks()['Expense']['nl-reiskosten-onbelast-tarief']`. It violates only when `category` is
`travel`, `travelType` is `business`/`commute`, `distanceKm` is a positive number, `amount` is
numeric, and `amount / distanceKm` exceeds the catalogue's `rateEurPerKm`. Every other shape is
vacuously satisfied: wrong category, missing or invalid `travelType`, absent or non-positive
`distanceKm`, non-numeric `amount`, or an unreadable catalogue parameter. It is auto-discovered by
`RuleEngine::providers()`'s existing `Checks/*.php` glob, with zero `RuleEngine.php` edits.
**Delivered** and proven reachable: `RuleEngine::evaluate('Expense', ...)` raises the violation for
an over-rate claim (EUR 0,30/km) and none for an at-rate claim (EUR 0,23/km);
`RuleAuditService::audit()` reports the rule in both `RuleCatalogue::machineCheckable()` and
`RuleEngine::checkedRuleIds()` and flags exactly the seeded over-rate fixture.

#### Scenario: An over-rate mileage claim is flagged
@e2e exclude Audit-time predicate, covered by NlTravelExpenseChecksTest::testOverRateMileageClaimViolates.
- **GIVEN** an `Expense` with `category: travel`, `travelType: business`, `distanceKm: 100` and `amount: 30`
- **WHEN** `RuleEngine::evaluate('Expense', ...)` runs
- **THEN** it reports a violation of `nl-reiskosten-onbelast-tarief`

#### Scenario: An at-rate mileage claim passes
@e2e exclude Audit-time predicate, covered by NlTravelExpenseChecksTest::testAtRateMileageClaimPasses.
- **GIVEN** an `Expense` with `category: travel`, `travelType: commute`, `distanceKm: 100` and `amount: 23`
- **WHEN** `RuleEngine::evaluate('Expense', ...)` runs
- **THEN** it reports no violation of `nl-reiskosten-onbelast-tarief`

#### Scenario: A claim that is not mileage is out of scope
@e2e exclude Audit-time predicate, covered by NlTravelExpenseChecksTest::testNonTravelCategoryIsVacuous and testAbsentOrNonPositiveDistanceKmIsVacuous.
- **GIVEN** an `Expense` whose `category` is not `travel`, or whose `distanceKm` is absent
- **WHEN** `RuleEngine::evaluate('Expense', ...)` runs
- **THEN** `nl-reiskosten-onbelast-tarief` is satisfied

### Requirement: The Expense lifecycle SHALL stay unchanged by the mileage rule (REQ-MILE-004)

No state, transition, or guard of `Expense`'s existing `x-openregister-lifecycle`
(`submit`/`approve`/`reject`/`reimburse`, `NoSelfApprovalGuard`) SHALL be added, removed, or altered.
The onbelast-tarief rule is an audit-time signal only, never a write-time block, so a violating
claim still submits, approves and reimburses exactly as before. **Delivered.**

#### Scenario: An over-rate claim still moves through its lifecycle
@e2e exclude The rule is audit-only and the lifecycle is unchanged schema, so no page shows a difference.
- **GIVEN** an `Expense` that violates `nl-reiskosten-onbelast-tarief`
- **WHEN** it is submitted, approved and reimbursed
- **THEN** each transition succeeds as it would for any other claim
- **AND** the violation shows up only in `occ humaniq:rules:audit`

## Non-Goals (MVP, named follow-ups)

- No loonheffing gross-up of the bovenmatige (excess) vergoeding onto any Payslip/PayrollRun.
- No vaste (fixed monthly) reiskostenvergoeding / 214-dagenregeling modelling.
- No new lifecycle transition, guard, or status; no UI/manifest change (the existing generic
  `ExpenseDetail` data widget renders the two new fields schema-driven, with no manifest edit).
- Tracking the Belastingdienst's own mid-2026 EUR 0,23 to EUR 0,25 increase as a
  `parameters.rateEurPerKm` data bump is named as the immediate next data-only follow-up.
