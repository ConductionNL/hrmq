# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.6] - 2026-09-07

### Added

- MCP surface (ADR-063 adoption), declared as `x-openregister-mcp` config with no
  hand-written tool code: 6 schemas of 57, `search` and `get` only, 12 derived tools,
  every one of them read-only.
  - Exposed: `Vacancy`, `OrgUnit`, `Asset`, `AssetAssignment`, `Timesheet`, `Expense`.
  - Zero write verbs anywhere. humaniq's back-office actions are payroll runs, tax
    filings, contract generation and approval transitions, and none of them may be
    agent-initiated.
  - Not exposed, argued and refused: the whole remuneration and identity domain
    (`Employee` with bsn/iban/grossMonthlySalary, `EmploymentContract`, `Payslip`,
    `PayrollRun`, `PayrollGLPost`, `PayrollPaymentBatch`, `PensionFiling`,
    `LoonaangifteFiling`); the health domain (`SickLeaveCase` under AVG art. 9, and
    `LeaveRequest` and `LeaveBalance` with it, because sick leave is a VALUE of the
    shared `leaveType` enum and the dialect cannot filter at value level); and
    recruitment (`Application`: candidate name, e-mail, phone, CV, motivation).
  - The derived surface has no field-level projection, so a `get` returns the whole
    object. That is why the line is drawn at the schema and drawn conservatively.
- `tests/Unit/Settings/McpSurfaceTest.php` pins all of the above, because a `create`
  verb or a block on `Employee` is a config edit that would otherwise pass every check
  in this repository.
