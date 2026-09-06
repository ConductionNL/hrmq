# Multi-administratie

## MODIFIED Requirements

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
