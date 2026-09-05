# Tasks

- [x] 1.1 Move the key, slug, register-list entry and seed schema references
      **files**: lib/Settings/register.d/hr-administratie.json, lib/Settings/humaniq_register.json, lib/Settings/humaniq_mock_register.json, lib/Settings/register.d/hr-seed.json
- [x] 1.2 Add the `administration` uuid pointing at shillinq's owner, and say so in the description
      **files**: lib/Settings/register.d/hr-administratie.json
- [x] 2.1 Follow the slug in the two schema reads, leaving the RuleEngine type vocabulary alone
      **files**: lib/Service/RuleAuditService.php, lib/Service/AdministrationService.php
- [x] 3.1 Add the rename to the existing slug map
      **files**: lib/Repair/MigrateSchemaSlug.php
- [x] 3.2 Drive the descriptor assertion off the map so a future entry cannot skip it
      **files**: tests/Unit/Repair/MigrateSchemaSlugTest.php
- [x] 4.1 Repoint the stubs keyed on the old slug
      **files**: tests/Unit/Service/AdministrationServiceTest.php, tests/Unit/Service/RuleAuditServiceTest.php
