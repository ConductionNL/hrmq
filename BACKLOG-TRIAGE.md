# Stale change triage, 2026-09-06

Ten changes in `openspec/changes/` sit at zero tasks done and have not been touched since
2026-08-22, carrying 119 declared tasks between them. Most were written against HEAD on
2026-07-17 and open by stating a fact about the code. Those facts were re-checked today.

**Four have had their premise overtaken.** Three of the four say so in their own proposal:
they were written to argue against an earlier design, and the argument has since been won by
other work. Archiving those is not dropping scope, it is closing a question already answered.

**Three are still true and still worth doing.**

**Three have been partly delivered** by changes that landed under different names, so what is
left is smaller than the task count suggests and needs re-scoping rather than starting.

## Archive: the premise is gone

| Change | Tasks | What it asserts | What is true today |
|---|---|---|---|
| `humaniq-test-coverage-baseline` | 12 | "Humaniq ships zero automated tests of any kind... no `tests/` directory at all... no `phpunit.xml` anywhere" | `tests/` exists with **142 test files**, `phpunit.xml` is present, and the suite runs **1407 tests**. Every sentence of the premise is now false. |
| `hris-api-public` | 8 | The 2026-05-23 draft designed a parallel REST plus GraphQL plus webhooks plus SCIM stack; the proposal itself argues humaniq **already has** a general-purpose RBAC-enforced API through OpenRegister | `openregister/appinfo/routes.php` carries **168** `api/objects` route references. The proposal's own conclusion is "do not build the parallel stack". It is a decision record, not pending work. |
| `uitzend-flexpool` | 10 | The draft designed humaniq as the **inlener's** tool; the proposal argues at length that this is "the wrong side for humaniq" | Same shape: the change exists to reject its own original scope. Nothing in it is waiting to be built. |
| `humaniq-employee-relations-widget` | 9 | "neither the `Timesheet` nor the `Expense` schema declares `employeeId` as an OpenRegister relation... `grep -n "relation"` returns nothing" | Both now declare `"$ref": "Employee"` on `employeeId`. The `related` widgets it was written about are also gone: `grep -c '"related"' src/manifest.json` returns **0**. |

## Keep: still true, still worth doing

| Change | Tasks | Verified today |
|---|---|---|
| `humaniq-mcp-adoption` | 12 | Still exact. Zero `x-openregister-mcp` blocks and zero `#[McpTool]` attributes in the app. Its own argument, that humaniq is the sharpest privacy case in the fleet because its schemas hold BSNs, IBANs, salaries and payslips, is the reason to do this deliberately rather than by default. |
| `humaniq-rule-compliance-enforcement` | 10 | Still exact, and worse than it reads. `RuleComplianceGuard` does not exist. `RuleEngine::hasMandatory()`, whose entire purpose is "true when a lifecycle guard must block", has **no production caller at all**: its only two call sites are assertions in `NlRetroChecksTest` and `NlRosterChecksTest`. The method is tested and unused. |
| `humaniq-manifest-boot-and-http-cost` | 9 | Still true. `PageController` still reads the manifest with `file_get_contents` per request, and still sets no `Cache-Control`, `ETag` or `Last-Modified`. |

## Re-scope: partly delivered under another name

| Change | Tasks | What already landed |
|---|---|---|
| `30-procent-regeling` | 17 | The engine gap it describes has largely closed. `dertigProcentRegeling` is in `lib/Standards/tables/nl-2026.json`, and `thirtyPercent` is referenced in `CalculationInput` (5 sites), `CalculationInputMapper` and `PackValidator`. Re-measure before starting: the remaining gap is not 17 tasks. |
| `wnt-disclosure` | 15 | Its premise, "humaniq has no WNT concept anywhere today", is false. `WntDisclosure` is a shipped schema in the register and three files under `lib/Standards/` carry WNT checks. |
| `single-person-modes` | 17 | Partly shipped. `Employee.isDga` is declared (6 sites), `dga-payroll-mode` and `proforma-payslip` are both archived as done, and `user.administrationMode` already drives four `visibleIf` conditions in the manifest. What is left is the eenmanszaak half, not both modes. |

## Suggested order

1. Archive the four whose premise is gone. One PR, no code.
2. Re-measure the three partly-delivered ones and rewrite their task lists to what is actually
   left, or archive them too if the remainder is not worth a change.
3. Schedule the three that are still true. `humaniq-rule-compliance-enforcement` is the one I
   would take first: a mandatory-violation check that nothing calls is a compliance guarantee the
   app appears to make and does not.

None of these block anything today, and no gate reports them.
