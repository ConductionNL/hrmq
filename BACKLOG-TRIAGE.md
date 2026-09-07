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
