# Tasks — humaniq-mcp-adoption

## 1. Declare the dialect (config only — no PHP)

- [x] 1.1 `lib/Settings/register.d/hr-ats.json` — add `configuration.x-openregister-mcp` to `Vacancy` (`enabled: true`; `search` + `get`; `scope: read`; `readOnlyHint: true`; filters `status`, `department`), as a **sibling** of the existing `x-openregister-lifecycle`. `Application` gets **no** block.
- [x] 1.2 `lib/Settings/register.d/hr-org.json` — add a `configuration` object to `OrgUnit` containing only the MCP block (filters `type`, `parentUnitId`, `active`). `OrgAssignment` gets **no** block.
- [x] 1.3 `lib/Settings/register.d/hr-assets.json` — `Asset` (filters `category`, `status`, `active`; sibling of its lifecycle) and `AssetAssignment` (new `configuration` object; filters `assetId`, `employeeId`).
- [x] 1.4 `lib/Settings/register.d/hr-timesheet.json` — `Timesheet` (filters `employeeId`, `period`, `status`, `projectId`; sibling of its lifecycle).
- [x] 1.5 `lib/Settings/register.d/hr-expense.json` — `Expense` (filters `employeeId`, `status`, `category`; sibling of its lifecycle).
- [x] 1.6 Write a genuinely useful agent-facing `description` on each of the 12 verb configs (this string is what the LLM reads to choose the tool).

## 2. Prove the exclusions hold

- [x] 2.1 Assert **no** `x-openregister-mcp` block exists on `Employee`, `EmploymentContract`, `Payslip`, `PayrollRun`, `PayrollGLPost`, `PayrollPaymentBatch`, `PensionFiling`, `LoonaangifteFiling`, `SickLeaveCase`, `LeaveRequest`, `LeaveBalance`, `Application`, `AttendanceRecord`, `Onboarding`, `Offboarding`, `OrgAssignment` or `GeneratedDocument` — `grep -rn "x-openregister-mcp" lib/Settings/` must hit exactly the 6 allowlisted schemas.
- [x] 2.2 Assert no write verb and no curated tool anywhere: no `create` / `update` / `delete` key under any `x-openregister-mcp.tools`, and `grep -rn "McpTool" lib/` returns nothing.

## 3. Verify

- [x] 3.1 `python3 -m json.tool` on each of the five touched fragments; all valid, humaniq schema count unchanged (57, not the 23 this task assumed: measured before and after, delta 0, no schema added or removed), every pre-existing `x-openregister-lifecycle` block byte-for-byte unchanged.
- [x] 3.2 Cross-check all 6 `search.filters` lists against each schema's `properties` map (an unknown filter fails the whole register import, not just the tool).
- [x] 3.3 Import into OpenRegister: OpenRegister's own `McpAnnotationValidator` was run directly over all six schemas: **zero errors**, derived surface exactly **12 tools**, all `readOnlyHint: true`. The negative assertions are pinned permanently by `tests/Unit/Settings/McpSurfaceTest.php` rather than checked once, and that suite was mutation-tested: adding a `create` verb to `Expense` and an MCP block to `Employee` fails 4 of its 7 tests.
- [x] 3.4 CHANGELOG entry (ADR-063 adoption: 6 read-only schemas, zero writes; salary/BSN/IBAN, sick-leave and candidate data explicitly not exposed).
