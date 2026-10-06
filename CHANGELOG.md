# Changelog

## Unreleased — consumer runtime integration

- Added focused consumer contract/testing guidance and shipped-factory usage limits.
- Versioned committed event payloads and documented canonical aliases, source connections, failure metadata and optional safe rendering.
- Added explicit first-use/installer and deployment guidance; new acceptance checks remain pending.


All notable changes to `nvl/metafields` are documented here.

## [Unreleased]

### Added

- Added focused injectable contracts for all 14 selected public workflows, with native signatures and conditional defaults preserving host bindings.

- Bounded authorized many-owner DTO reads, grouped canonical/reference admission and explicit query-free SQL policy contracts.
- Exact native identity object maps and payload ceilings, preserving existing single-owner APIs.
- Prefixed `whereNvlMetafield` stored-scalar filtering with exact portable owner correlations and retained host scopes/selections.

### Changed

- Classify the supported consumer PHP surface with explicit source annotations and restrict package model handles to declared identity and in-memory read fields; preserve existing workflow behavior and concrete signatures.
- Prepare lockstep major 5 with required and development NVL peer floors of `^5.0`. This candidate has not been tagged or published.
- Preserve host routing through a gated plain provider and namespaced route families.
- Use Laravel morph identity for owner values without changing host morph maps.
- Review [UPGRADING.md](UPGRADING.md) before adopting the new names and infrastructure boundaries.

## [2.2.1] - 2026-09-26

### Documentation

- Clarify public support, contribution, and private security reporting paths.

## [2.2.0] - 2026-09-25

### Changed

- Prepare `nvl/metafields` for independent Composer and Git publication; require `nvl/core` for shared Support and Data services.

## [2.0.1] - 2026-09-22

### Added

- Published the existing `ImportPlatformMetafieldDefinitionData` contract in
  the generated TypeScript declarations.
- Added opt-in tenant ownership for the complete definition/value graph,
  reviewed split adoption, canonical owner/reference enforcement, and Doctor
  readiness checks while preserving disabled standalone installs.
- Added concrete platform definition grants and independent tenant imports with
  exact revision/idempotency checks, total reference remapping, copied locale and
  schema data, immutable provenance, and revocation-safe committed copies.

## [2.0.0] - 2026-08-29

### Changed

- Established authorized definition/value Actions and `HasMetafields` owner
  relations as the 2.0 consumer boundary; direct consumer Metafields-model
  queries and relation aggregates now fail Suite audit.

## [1.0.7] - 2026-08-22

### Changed

- Aligned the documented runtime requirement with the PHP 8.4+ package
  baseline.

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
