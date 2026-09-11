---
capability: multi-administratie
status: done
built_by: openspec/changes/archive/2026-07-14-multi-administratie
---

# multi-administratie Specification

**Status**: done (automatic per-page scoping delivered — see #64 correction under Delivered scope;
REQ-MULTI-004's "Delivered" status is further corrected 2026-08-19 — see `humaniq-boot-integrity` below
and the requirement's own MODIFIED delta — it is live-conditional on the boot-integrity guards
passing, not unconditionally delivered)
**Scope**: humaniq (`kind: code+config`) — the accountant multi-client / multi-company tenant model.
Reuses the `PayrollRun` plain-string `administrationId` convention (ADR-062 rule 7: never a `$ref`),
the `userId`/`managerUserId` denormalization precedent, and ADR-001 Rule 3 (tenant switch, no menu
duplication). Adds zero payroll-engine logic.
**OpenSpec changes**:
- [multi-administratie](../../changes/archive/2026-07-14-multi-administratie/) _(archived 2026-07-14)_ —
- [the-hr-administration-is-a-facet](../../changes/archive/2026-09-06-the-hr-administration-is-a-facet/) _(archived 2026-09-06)_ — the HR administration is a facet of a legal entity rather than an entity of its own (REQ-MULTI-020) (kind: code)
  Administration + AdministrationAccess schemas, a denormalized `administrationId` rolled out across
  the HR/payroll schemas, an access-guarded per-user active-administratie selection
  (`GET/POST /api/administration/*`), a `Configuratie › Administraties` switcher, a
  `nl-administratie-scope-consistency` corpus rule, and seeds proving the switch.
- [humaniq-boot-integrity](../../changes/humaniq-boot-integrity/) — **Status**: in-progress — corrects
  REQ-MULTI-004's "Delivered" status (live-verified false: the token reaches the API unresolved on
  the currently-deployed bundle) and ties its status to the new `boot-integrity` capability's
  bundle-freshness and manifest-sentinel-resolution guards passing.

## Purpose

Let one humaniq instance carry multiple administraties (companies/clients) — the dominant NL
distribution wedge, where one accountant's office runs payroll for many SMBs (Nmbrs, Loket and
Employes all monetize this per-payslip). The tenant axis is a denormalized plain-string
`administrationId` on every scoped object plus a per-user active-administratie pointer, selected
behind an access guard, with a machine-checkable consistency rule keeping the denormalization honest.

## Requirements

### Requirement: Scoped schemas carry a plain-string administrationId (REQ-MULTI-001)

Every scoped HR/payroll schema carries an optional, nullable, plain-string
  `administrationId` (never a `$ref`), because the manifest filter grammar cannot reach OpenRegister
  owner/`@self` metadata or two-hop from a record to its employee's administration. Rolled out to
  Employee, EmploymentContract, Payslip, Timesheet, Expense, LeaveRequest, LeaveBalance,
  SickLeaveCase, Onboarding, Vacancy, Application, OrgUnit, OrgAssignment, AttendanceRecord, Asset,
  AssetAssignment, ReviewCycle, PerformanceReview, PensionFiling, LoonaangifteFiling (the payroll
  aggregates already carried it). **Delivered.**

#### Scenario: A scoped schema carries a plain-string administrationId
@e2e exclude Schema shape in the register fragments, not a browser behaviour.
- **WHEN** a scoped schema such as `Employee`, `Payslip` or `Timesheet` is read from `lib/Settings/register.d/`
- **THEN** it declares `administrationId` as an optional, nullable string
- **AND** that property carries no `$ref`

### Requirement: An Administration catalog and access membership model the tenant axis (REQ-MULTI-002)

An `Administration` catalog (name/KvK/loonheffingennummer/active) and an
  `AdministrationAccess` membership (userId → administratie, role accountant|hr|employee) model the
  tenant axis. **Delivered.**

#### Scenario: The catalog and the membership are declared
@e2e exclude Schema shape in the register fragment, not a browser behaviour.
- **WHEN** `lib/Settings/register.d/hr-administratie.json` is read
- **THEN** the administratie catalog schema (slug `hrAdministration` since REQ-MULTI-020) carries `name`, `kvkNumber`, `loonheffingennummer` and `active`
- **AND** `AdministrationAccess` carries `userId`, `administrationId` and a `role` of `accountant`, `hr` or `employee`

### Requirement: The active administratie is a per-user, access-guarded selection (REQ-MULTI-003)

The active administratie is a per-user selection persisted behind an
  access-guarded setter: `POST /api/administration/active` resolves the posted id to a caller
  `AdministrationAccess` row *before* storing (unknown/inaccessible → 404); routes precede the SPA
  catch-all. **Delivered.**

#### Scenario: An inaccessible administratie is refused before anything is stored
@e2e exclude Endpoint guard, covered by AdministrationControllerTest::testActivatingAnInaccessibleAdministratieReturns404AndNeverWrites.
- **GIVEN** a caller with no `AdministrationAccess` row for `ADM-002`
- **WHEN** they `POST /api/administration/active` with `administrationId: ADM-002`
- **THEN** the response is 404
- **AND** their active administratie is unchanged

#### Scenario: An accessible administratie becomes active
@e2e exclude Endpoint behaviour, covered by AdministrationControllerTest::testActivatingAnAccessibleAdministratiePersistsAndReturnsIt.
- **GIVEN** a caller with an `AdministrationAccess` row for `ADM-001`
- **WHEN** they `POST /api/administration/active` with `administrationId: ADM-001`
- **THEN** the response carries `activeAdministrationId: ADM-001` and the selection is stored for that caller
- **AND** both `/api/administration/*` routes are declared in `appinfo/routes.php` before the SPA catch-all

### Requirement: Every page is implicitly scoped to the active administratie (REQ-MULTI-004)

Every list and detail page is implicitly scoped to the active administratie via
  a base `administrationId` filter. **Delivered** (corrected 2026-07-16, #64 — see note below): every
  administration-scoped index/detail page's `filter` carries `administrationId:
  "@workspace.activeAdministrationId?"`; `App.vue` provides a reactive `cnWorkspaceContext` at the SPA
  root (seeded from `IInitialState`/`PageController::index()`, `loadState()` client-side) so the token
  resolves from first paint, and `AdministrationSwitcher.vue` writes into that SAME context on a
  successful switch so every page re-scopes without a reload. The `?`-optional grammar means an unset
  selection (or a single-administratie install) drops the clause and shows all accessible rows — no
  regression, exactly as originally intended.

#### Scenario: A scoped page filters on the active administratie
- **WHEN** an administration-scoped index or detail page is read from the effective manifest
- **THEN** its `filter` carries `administrationId: "@workspace.activeAdministrationId?"`

#### Scenario: Switching administratie re-scopes open pages
- **GIVEN** a user on a scoped page
- **WHEN** they switch administratie in `AdministrationSwitcher.vue`
- **THEN** the switcher writes the new id into the `cnWorkspaceContext` that `App.vue` provides
- **AND** the page re-scopes without a reload

### Requirement: A dedicated @administration filter token — SUPERSEDED (REQ-MULTI-005)

~~The `@administration` filter token is a first-class member of the CLOSED
  nextcloud-vue token vocabulary.~~ **Superseded — this requirement was a wrong assumption, not a real
  gap (#64).** The original author believed automatic per-page scoping needed a NEW filter token added
  upstream to nextcloud-vue. It did not: nextcloud-vue already ships a general **workspace** context
  (`@workspace.<key>` / `@workspace.<key>?`, `sentinelTokens.js`) for exactly this shape of problem —
  "page-level workspace state (e.g. a selected client)" — and its own vocabulary explicitly deprecates
  single-app token inventions in favour of it (e.g. pipelinq's `@currentFiscalYear` →
  `@workspace.<key>`). Inventing `@administration` would have been the anti-pattern the library
  forbids, not a legitimate follow-up. `cnWorkspaceContext` is a Vue provide/inject bag documented as
  "provided by CnDashboardPage" (page-scoped), but Vue's inject walks the WHOLE ancestor chain — humaniq
  provides it once at its own SPA root (`App.vue`) instead, which makes it available fleet-wide across
  every page type with zero nextcloud-vue change. No upstream issue was ever needed; none is filed.

#### Scenario: No dedicated @administration token is used
@e2e exclude The absence of a manifest token, which no page can assert.
- **WHEN** the manifest filters are searched for an `@administration` token
- **THEN** none is found
- **AND** the scoping uses the existing `@workspace.activeAdministrationId?` token instead

### Requirement: The switcher lives under Configuratie and adds no top-level menu (REQ-MULTI-006)

The switch lives under `Configuratie › Administraties` (a switcher SFC backed by
  `GET/POST /api/administration/*` + a catalog list), adding no top-level menu (ADR-001 Rule 3).
  **Delivered** (the Dashboard-widget `runtime.user` visibleIf wiring remains a separate, small,
  named follow-up — unrelated to the #64 correction above; the Dashboard page itself carries no
  `administrationId`-scoped widgets today).

#### Scenario: The switcher sits under Configuratie
- **WHEN** the effective manifest's menu is read
- **THEN** `Administraties` is a child of `ConfiguratieGroup` and opens the `AdministrationSwitcher` page at `/configuratie/administraties`
- **AND** no top-level menu entry exists for it

### Requirement: A consistency rule flags scope mismatches, and scoping is NOT a security boundary (REQ-MULTI-007)

`nl-administratie-scope-consistency` (recommended severity, auto-discovered by
  the RuleEngine, vacuous when `administrationId` is absent) flags a child whose administratie
  disagrees with its parent; and scoping is documented as **NOT a security boundary** (hard
  per-administratie OpenRegister-organisation isolation is a named security fast-follow).
  **Delivered.**

#### Scenario: A record whose administratie disagrees with its employee is flagged
@e2e exclude Audit-time predicate, covered by NlAdministratieChecksTest::testEmployeeAnchoredRecordMismatchingItsEmployeeViolates.
- **GIVEN** a `Timesheet` with `administrationId: ADM-002` whose `Employee` carries `administrationId: ADM-001`
- **WHEN** the rule audit runs
- **THEN** `nl-administratie-scope-consistency` reports a violation at `recommended` severity

#### Scenario: A record without an administratie is never flagged
@e2e exclude Audit-time predicate, covered by NlAdministratieChecksTest::testEmployeeAnchoredRecordWithNoOwnAdministrationIdIsVacuous.
- **GIVEN** a `Timesheet` with no `administrationId`
- **WHEN** the rule audit runs
- **THEN** `nl-administratie-scope-consistency` is satisfied

### Requirement: Seeds demonstrate the switch and the isolation (REQ-MULTI-008)

Seeds demonstrate the switch and the isolation: two `Administration` rows
  (ADM-001/ADM-002), `AdministrationAccess` granting the admin accountant access to both, existing
  core-entity seeds backfilled to ADM-001 plus a small isolated ADM-002 set. **Delivered.**

#### Scenario: The seed carries two administraties and access to both
@e2e exclude Seed data content, not a browser behaviour.
- **WHEN** `lib/Settings/register.d/hr-seed.json` is imported
- **THEN** `hrAdministration` rows `ADM-001` and `ADM-002` exist
- **AND** the `admin` user holds an `accountant` `AdministrationAccess` row for each
- **AND** a small set of other seeded objects carries `administrationId: ADM-002`

### Requirement: The HR administration is a facet of a legal entity (REQ-MULTI-020)

The HR administratie schema's slug SHALL be `hrAdministration` and SHALL NOT be
`Administration`.

A schema slug is global per organisation and `SchemaMapper::find()` matches
`LOWER(slug)`, so a bare `Administration` resolved to this record or to
shillinq's depending on which row was reached first.

The schema SHALL carry an `administration` property holding the UUID of the
shillinq `Administration` it belongs to. shillinq owns the legal entity; this
schema owns its HR view.

That reference SHALL be a plain uuid string and SHALL NOT be a `$ref`.
shillinq's register is a different register, and ADR-062 rule 7 gives a
cross-register target no `$ref`.

The reference MAY be empty. Without shillinq there is no entity record to point
at, and `administrationId` SHALL remain the only key.

The rename SHALL reach the fragment descriptor, the mock register AND the
register's own schema list. Three of the four agreeing is what a silent no-op
looks like.

`RuleEngine`'s check-map keys SHALL NOT move. They are an internal type
vocabulary paired between producer and consumer, dispatched by literal name and
not by slug.

#### Scenario: Every mapped rename reaches every descriptor

- **WHEN** the slug map is compared against the descriptors and the register list
- **THEN** each new slug is declared and listed, and no old slug remains.

#### Scenario: The HR view points at its owner

- **WHEN** the merged fragment is read
- **THEN** `hrAdministration` carries an `administration` property.

## Delivered scope

The entire capability ships, gate-green, and is now fully delivered with **zero nextcloud-vue
changes**: the tenant schemas, the denormalized `administrationId` rollout, the guarded
active-administratie endpoints + service, the `Configuratie › Administraties` switcher, the
consistency rule, seeds, AND the automatic per-page scoping (#64) — `filter: { administrationId:
"@workspace.activeAdministrationId?" }` on every administration-scoped index/detail page, a reactive
`cnWorkspaceContext` App.vue provides at the SPA root and seeds via `IInitialState`, and
`AdministrationSwitcher.vue` writing into that same context on switch. Scoping is a convenience layer,
**never** a security boundary — hard per-administratie isolation (mapping onto an OpenRegister
organisation) remains a named security fast-follow, unrelated to #64.

**Correction (2026-07-16, #64):** REQ-MULTI-005's premise — that automatic scoping required a NEW
`@administration` token added upstream to nextcloud-vue — was wrong. It was never filed as an upstream
issue and never will be; nextcloud-vue's existing, general `@workspace.<key>` context covers this
exact case and its own vocabulary deprecates single-app token inventions like the one REQ-MULTI-005
proposed. The fix was entirely local to humaniq: use the token that already existed, and provide the
`cnWorkspaceContext` Vue already supports injecting fleet-wide from humaniq's own SPA root rather than
only page-scoped. Said plainly, so this isn't silently rewritten: the original spec's assumption was
incorrect, not merely superseded by a later design choice.
