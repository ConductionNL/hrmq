# Stale change triage, corrected 2026-09-07

Ten changes sat at zero tasks done and untouched since 2026-08-22, carrying 119 declared tasks.

**The first pass of this document, on 2026-09-06, was wrong.** It sorted them by reading their
proposals: what each one *said* about the code when it was written on 2026-07-17. Re-measured
against the code itself, the picture is almost inverted.

**Seven of the ten were already built**, by other changes, under other names. Nobody ticked their
boxes, so they read as untouched. All 30 of their spec requirements were verified against the code
on 2026-09-07 and hold; their specs are now synced into `openspec/specs/` and the changes archived.

**Three are genuinely unstarted.** That is the real backlog, and it is 31 tasks, not 119.

## Archived as delivered (2026-09-07)

| Change | Tasks | What was verified |
|---|---|---|
| `uitzend-flexpool` | 10 | `uitzendFase`, `uitzendbedingVanToepassing`, `inlenersbeloningReferentie` on EmploymentContract; both labour rules; `cao-abu.json`; no `InhuurOpdracht`/`Bureau`, which REQ-UITZ-001 requires stay absent |
| `hris-api-public` | 8 | `IntegrationAccount` schema, both pages, README naming the six recommended schemas; no parallel REST/GraphQL/webhook/SCIM stack, which REQ-HRIS-001 requires stay absent |
| `30-procent-regeling` | 17 | `dertigProcentRegeling` table group; `CalculationInput::$thirtyPercentRulingRate` driving `thirtyPercentExemption`/`belastbaarLoon`, so the ruling reaches the engine |
| `wnt-disclosure` | 15 | `Employee.wntTopfunctionaris` + `.wntUitzonderingReden`; `WntDisclosure` schema; `nl-wnt-norm-overschrijding`; pages under PayrollGroup; seeds |
| `single-person-modes` | 17 | `hrAdministration.mode` enum; `runtime.user.administrationMode`; 4 mode-gated `visibleIf`; `NlSinglePersonChecks`; `GET /api/payroll/dga-status` |
| `humaniq-test-coverage-baseline` | 12 | `tests/` + `phpunit.xml`, 144 test files, 1420 tests, 10 e2e specs |
| `humaniq-employee-relations-widget` | 9 | `$ref: Employee` on both schemas; seeds resolving via `@ref:employee-jansen`; a `related` widget on both detail pages |

Their tasks are left **unticked on purpose**. They were not executed as written, and ticking them
would claim an execution that did not happen. Each proposal carries a note saying so, with the
evidence above.

## The real backlog

| Change | Tasks | Verified still unstarted |
|---|---|---|
| `humaniq-rule-compliance-enforcement` | 10 | `RuleComplianceGuard` does not exist. `RuleEngine::hasMandatory()`, whose whole purpose is "true when a lifecycle guard must block", has **zero production callers**: its only `lib/` occurrences are its own declaration and its closing comment, and its only call sites are two test assertions. |
| `humaniq-manifest-boot-and-http-cost` | 9 | `PageController` still reads the manifest with `file_get_contents` per request and sets no `Cache-Control`, `ETag` or `Last-Modified`. |
| `humaniq-mcp-adoption` | 12 | Zero `x-openregister-mcp` blocks and zero `#[McpTool]` attributes. humaniq holds BSNs, IBANs, salaries and payslips, so the schema classification is the substance, not the wiring. |

## The lesson worth keeping

Every one of the seven had a proposal opening with a confident, checkable claim about the codebase.
`humaniq-test-coverage-baseline` opens *"Humaniq ships zero automated tests of any kind"* against a
tree with 144 test files. Those claims were true on 2026-07-17 and the proposals were never
revisited.

A proposal's "Verified against HEAD" line is a **timestamp, not a fact**. Re-measure before acting
on one, and the command to re-measure is usually in the proposal itself.

---

# Second pass, 2026-09-07 evening: the three are done, and here is what is actually left

All three of "the real backlog" above are built and merged or in review:

| Change | Landed as |
|---|---|
| `humaniq-rule-compliance-enforcement` | #364, merged. The audit exits non-zero on a mandatory violation; `RuleEngine`'s docblock and `openspec/specs/hrm-rule-engine/` REQ-RULE-004 stopped claiming a `RuleComplianceGuard` that has never existed. The write-time hook is asked of OpenRegister as ConductionNL/openregister#3493. |
| `humaniq-manifest-boot-and-http-cost` | #370 + #374, merged. Its premise did not survive measurement: caching `/api/manifest` would have made a wrong answer faster, because the endpoint served the BASE manifest (11 pages, not 113). It now serves a generated, CI-verified effective manifest, then caches it. Section 2 was retired on evidence rather than built: it is a Vue 2 premise and this app is on Vue 3, where props are `shallowReactive`. |
| `humaniq-mcp-adoption` | #371, merged. 6 schemas of 57, read-only, 12 derived tools, zero writes, every exclusion argued and pinned by mutation-tested unit tests. |

## The nine changes that were NOT in the ten above

They carried ~55 open tasks between them and none had been touched today. Measured against the code, not read:

| Change | Open | What is actually true |
|---|---|---|
| `document-dossier-avg` | 10 | **Genuinely unbuilt. Now built** (PR #377): the loonbelastingverklaring retention rule, its corpus entry, the `Employee` storage-limitation ceiling, the dossier widget, seeds and tests. |
| `humaniq-boot-integrity` | 31 | **Part built, and the unticked boxes hide which part.** Sections 1 and 2 ship: `check:deps-drift` and `check:manifest-sentinels` exist, are wired into `package.json` and pass. Section 3 ships only its local half: `check-bundle-freshness.js` exists and `check:boot-integrity` composes all three, but `js/build-info.json` (3.3) is never emitted and `--sidecar` (3.4) does not exist. 7.1 shipped (`USE_LOCAL_LIB` is opt-IN). 6.2 is real: `package.json` says `0.1.0` while `appinfo/info.xml` says `0.2.6-unstable.…`. |
| `humaniq-i18n-locale-completeness` | n/a | **Empty shell, removed.** It only ever contained a `.openspec.yaml` stub dated 2026-07-07: no proposal, no tasks, no spec. The locale work it named is done anyway: `check:l10n`, `check:l10n-js` and `check:schema-l10n` all pass over 1,932 keys per catalogue. |
| `a-time-entry-can-be-booked-to-a-day` | 3 | Cross-app, not humaniq's to close: pipelinq's and planninq's migrations onto the schema, and the uid-to-employeeId resolution both consumers need. |
| `payroll-run-as-a-flow` | 3 | Its own section 7 "follow-ups", each waiting on another surface (a pay-date field, a schedule adoption recipe, a guard that needs adopted flows first). |
| `rules-onto-or-decision-tables` | 3 | 6.1 needs a live OpenRegister environment to run the audit before and after; 6.2 depends on 6.1; 6.3 is an explicit "next conversion wave". |
| `hours-leaf-for-any-object` | 2 | Both need a HOST app, and this repo's e2e rig has none. One is dossiq's (`case-kpis-hours` moving onto the leaf). The other reads as ours, an e2e journey for log-hours and the timer, but the leaf is the widget humaniq supplies to OTHER apps, and `code-quality.yml` passes `additional-apps: [openregister]` only. There is no seeded host object to log hours against without adding a second app to the shared workflow input. |
| `beta-surface-alignment` | 2 | 3.1 is an app-owner decision the change itself calls out of scope. 4.1 cannot be edited from this repo: it ships from `docusaurus-preset`'s own package. |
| `humaniq-namespaces-its-generated-document-slug` | 1 | Operator verification against a live install with existing rows. Not reproducible here: the e2e rig imports no humaniq register. |

## Two instruments that lied, both worth remembering

**`node tests/validate-widget-keys.js` exits 1; `npm run check:widget-keys` exits 0. Same command.** The script builds a throwaway probe bundle, and `@nextcloud/webpack-vue-config` reads `npm_package_name`/`npm_package_version` from the environment. Run bare, those are undefined, the probe build throws, and the script reports every layer-3 key as UNRESOLVED. Task 6.1's "currently FAILS with two unresolved" is that artifact, not a defect. **Invoke these through `npm run`.**

**Local hydra gates were two versions behind and failed gate-22 and gate-53 on a file the branch never touched.** The vendored `conduction/hydra-gates` carried manifest schema 2.32.0, which predates the `display` key #366 added; nc-vue ships 2.33.0. CI resolves the gates at `@main` and was green throughout. Reinstalling took the tree to 2.33.0 and the declared gate count from 88 to 90, so two gates had been absent locally as well. `composer.lock` needed no change: only the installed tree was stale.

## The lesson, restated

The first pass of this document sorted changes by reading their proposals. The correction was to measure. This pass measured again and found the same shape one level down: **an unticked box means nobody ticked it, and nothing more.** `humaniq-boot-integrity` reads as 31 tasks of untouched work and is roughly half shipped; `document-dossier-avg` read the same way and was genuinely unbuilt. The only way to tell them apart is to run the thing.
