## ADDED Requirements

### Requirement: The compliance audit is a machine-actionable signal (REQ-RULE-008)

`occ humaniq:rules:audit` SHALL exit with a non-zero status code when the compliance report contains
one or more `mandatory`-severity violations, and SHALL exit `0` otherwise, so the audit can be
wired into CI/ops pipelines as an enforceable gate rather than only human-read output.

**Feature tier**: MVP

#### Scenario: The audit exits non-zero when a mandatory violation exists

- GIVEN the register contains at least one object with a `mandatory`-severity rule violation
- WHEN `occ humaniq:rules:audit` runs
- THEN the command MUST print the violation in its report
- AND MUST exit with a non-zero status code

#### Scenario: The audit exits zero when fully compliant

- GIVEN the register contains no `mandatory`-severity rule violations
- WHEN `occ humaniq:rules:audit` runs
- THEN the command MUST exit with status code `0`

### Requirement: Documentation accurately describes enforcement scope (REQ-RULE-009)

The codebase SHALL NOT claim write-time enforcement infrastructure that does not exist.
`lib/Standards/RuleEngine.php`'s documentation SHALL accurately state that the engine is
advisory/reporting-only unless and until a real write-time guard is implemented, rather than
referencing a non-existent `RuleComplianceGuard` class.

**Feature tier**: MVP

#### Scenario: The RuleEngine docblock matches actual behaviour

- GIVEN a reader inspects `lib/Standards/RuleEngine.php`'s class-level documentation
- WHEN they check whether a lifecycle guard enforces mandatory violations at write time
- THEN the documentation MUST NOT reference a class that does not exist in the codebase
- AND MUST accurately describe the audit as reporting-only (or reference the real guard, if one
  has since been implemented)

## MODIFIED Requirements

### Requirement: The engine SHALL offer a block decision that nothing yet enforces (REQ-RULE-004)

This requirement described a `lib/Lifecycle/RuleComplianceGuard.php` that has never existed. It is
restated here to say what the engine actually contracts to do, because a requirement asserting an
absent file in a spec marked `status: done` is what put the same false claim into
`lib/Standards/RuleEngine.php`'s docblock.

`RuleEngine` SHALL remain a pure evaluator and SHALL expose `hasMandatory()` so that a future
write-time guard can ask it whether to block, without the engine itself loading objects or knowing
about lifecycles.

Write-time blocking is NOT implemented and SHALL NOT be claimed as implemented. It is blocked on an
OpenRegister capability that does not exist yet: a schema-declarative pre-save validation hook
point, requested in ConductionNL/openregister#3493. `LifecycleGuardInterface` is not a substitute,
because it attaches to a named transition in `x-openregister-lifecycle`, and none of the five
compliance-checked schemas (Employee, EmploymentContract, Payslip, PayrollRun, LoonaangifteFiling)
declares a lifecycle or has any business workflow that would justify one.

Until that hook exists, the enforcement point is REQ-RULE-008: the audit's exit code, which CI and
ops gate on. Detection is therefore on a cadence rather than at the point of write, and that
trade-off is recorded in this change's `design.md`.

#### Scenario: The engine offers a decision, and does not act on it

- GIVEN an object that violates a rule with severity `mandatory`
- WHEN `RuleEngine::evaluate()` runs over it
- THEN `hasMandatory()` reports the breach
- AND no write is refused, because no guard is wired

#### Scenario: The claim matches the code

- GIVEN a reader looks for the guard this requirement once named
- WHEN they search the repository for `RuleComplianceGuard`
- THEN they find no such class
- AND the specification says so rather than asserting it exists
