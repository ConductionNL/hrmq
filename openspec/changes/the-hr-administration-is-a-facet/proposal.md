# The HR administration is a facet, not a second legal entity

## Why

A schema slug is global per organisation and `SchemaMapper::find()` matches
`LOWER(slug)`, so a bare `Administration` was answered for by shillinq's record
as readily as by this app's.

The two are the same Dutch legal entity. They share `kvkNumber` and `name`:
this app holds the HR view (loonheffingennummer, ABP obligation, the short code
every HR record carries), shillinq holds the finance view (fiscal year, VAT
regime, chart of accounts, consolidation).

Counting shared fields would have called this a rename-apart: 2 of 34. What
decides it is *which* fields are shared. A KvK number identifies a legal entity,
and two apps carrying the same KvK number are not describing two companies.

## What changes

The slug becomes `hrAdministration` and the schema gains an `administration`
uuid pointing at shillinq's `Administration`, which owns the entity. A plain
uuid and not a `$ref`, because shillinq's register is a different register and
ADR-062 rule 7 gives a cross-register target a plain string.

Empty when shillinq is absent, in which case `administrationId` remains the
only key and nothing about single-app operation changes.

This app already hands payroll net pay to shillinq through `ObjectService`
(`PayrollPaymentBatch`), so the cross-app direction is established.

## Deliberately not moved

`RuleEngine`'s check map is keyed by an internal type vocabulary
(`'Administration' => [...]` in `NlSinglePersonChecks` and `RuleAuditService`,
read back by `NlAbpChecks`). Those keys are paired between the producer and the
consumer and are dispatched by literal type name, not by schema slug. Renaming
one side without the other would silently disable a compliance check.
