# Changelog

All notable changes to `nvl/metafields` are documented here.

## [Unreleased]

## [1.0.5] - 2026-08-12

### Changed

- Released unchanged under the suite's shared version.

## [1.0.2] - 2026-08-12

- Consolidated the final definition/value schema into create migrations and removed obsolete base-copy columns.
- Standardized localized definition and value tables as `metafields_definitions_i18n` and `metafields_i18n`.
- Added the documented `metafields-migrations` publish tag.
- Moved owner-value locks inside retryable transactions, required revisions for existing-resource mutations, and kept cleared-value recreation reachable without hidden revisions.
- Added active-definition scopes, stable owner morph aliases, and ambiguity checks for duplicate owner models.
- Added fail-closed reference authorization and identifier-only reference-list API serialization.
- Split definition creation and update DTOs and reduced the definition display DTO to output concerns.
- Hardened configurable validation, management API throttling, schema indexes, and doctor diagnostics.
- Rejected application morph-map and inheritance-ambiguous owner registrations before they can corrupt polymorphic resolution.
- Kept owner field reads lock-free while retaining explicit row locks inside mutation transactions.
- Bounded bulk synchronization and structured definition metadata, and removed test/build files from release archives.

## [1.0.0] - 2026-08-08

- Added registered owners and references with string-compatible identifiers.
- Added typed definition and value Actions, optimistic concurrency, and patch/replace synchronization.
- Added bounded structured validation and eligible localized definitions and values.
- Removed commerce-specific enums, access classes, and model assumptions.
