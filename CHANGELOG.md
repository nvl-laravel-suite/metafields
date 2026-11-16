# Changelog


All notable changes to `nvl/metafields` are documented here.

## [Unreleased]

## [7.1.0] - 2026-11-16

### Highlights

- Import legacy metafields, limit assignments to declared owner scopes, and restore cleared values while retaining history.

<!-- NVL GENERATED CHANGES START -->
### Changed

- close NVL consumer adoption and portability gaps
- Merge pull request #21 from nicolasvlachos/codex/consumer-postgresql-validation
<!-- NVL GENERATED CHANGES END -->

## [7.0.3] - 2026-11-13

### Fixed

- Harden stored-value query predicates across SQLite and PostgreSQL, tighten JSON property schema and definition mutation guards, and isolate Doctor schema probes safely.

<!-- NVL GENERATED CHANGES START -->
### Changed

- harden package runtime safety, PostgreSQL portability and test performance
<!-- NVL GENERATED CHANGES END -->

## [7.0.2] - 2026-11-11

### Fixed

- Resolve a reference list in one admitted query and verify loaded value and definition locale rows in one admitted query per read, instead of one ownership check per reference and locale row; default accessors verify the loaded graph once per call.
- Normalize registered owners and read host ownership declarations once per registry state, recomputing when configuration, code registrations or shared owner identities change.
- Apply the JSON depth, item and byte limits to structured values and defaults supplied as JSON strings, which previously bypassed the depth and item bounds.
- Reject infinite, not-a-number and overflowing float values and defaults; they were stored and then failed to read.
- Reject reference identifiers an integer target key cannot hold (non-numeric, signed, zero-padded or out-of-range) as validation failures instead of sending them to the database.
- Report a namespace and key already held by another active definition as a validation failure on create and rename instead of a raw unique-constraint exception; a concurrent race is still decided by the unique index.
- Report a malformed definition identifier as not found on update, archive and delete instead of a PostgreSQL type error.
- Compare stored text exactly in `whereNvlMetafield` `=` and `!=` including case and trailing spaces.
- Delete a definition's values in batches of 100 when cascading, instead of binding every value identifier in one statement.

### Removed

- Remove the unreferenced internal `MetafieldValueSerializer` and `OwnerMetafieldBooleanFilter` helpers; use `whereNvlMetafield` for stored boolean comparisons.

<!-- NVL GENERATED CHANGES START -->
### Changed

- harden input, reference reads and stored-value filters
<!-- NVL GENERATED CHANGES END -->

## [7.0.1] - 2026-11-09

### Changed

- Refresh the inherited Ownership deletion API reference for ordinary models and native pivots.

<!-- NVL GENERATED CHANGES START -->
### Changed

- refresh inherited ownership deletion contracts
<!-- NVL GENERATED CHANGES END -->

## [7.0.0] - 2026-11-03

- Adopt the reviewed NVL 7 family for PHP 8.4+ and Laravel 13, with compatible required and optional NVL peers. Review the package upgrade guide before deployment.

### Fixed

- Add an explicit, default-denied purge-before-owner-deletion lifecycle with bounded hydration, exact transaction/source checks, retained owner/value policies, localized history cleanup and outer-commit events.
- Add definition-leading value lookup support through a non-destructive forward migration and bounded, explicitly authorized owner-reference diagnostics.

### Changed

- Align NVL peers and consumer contracts on the coordinated 7.x line. Review [UPGRADING.md](UPGRADING.md) for adoption and application-owned integration requirements.

## [6.1.0] - 2026-10-23

<!-- NVL GENERATED CHANGES START -->
### Changed

- expose safe host APIs and harden package boundaries
<!-- NVL GENERATED CHANGES END -->

## [6.0.1] - 2026-10-21

### Changed

- Share configured definition registration, queue capture and native backfill. Isolate retained legacy field guards while preserving typed references and catalog identity.
- Require optional Ownership >=6.0.1 when installed so new shared backfill callers cannot resolve an older runtime. Require Core >=6.0.1 for the shared model, queue or compatibility implementation.

## [6.0.0] - 2026-10-20

### Changed

- Resolve configured definition/value subclasses and exact host resources; enforce canonical definition, owner and reference ownership during native adoption and reads/writes. Keep shared source catalogs explicit and reference mappings complete.
- Align required/development NVL peers on `^6.0`, optional peer conflicts on major 6, and `dev-main` on `6.x-dev`. Review [UPGRADING.md](UPGRADING.md) before rollout.

## [5.0.1] - 2026-10-08

### Changed

- Correct published-family verification and adoption guidance for local Dagger CI; runtime contracts are unchanged.


## [5.0.0] - 2026-10-08

### Added

- Added focused injectable contracts for all 14 selected public workflows, with native signatures and conditional defaults preserving host bindings.

- Bounded authorized many-owner DTO reads, grouped canonical/reference admission and explicit query-free SQL policy contracts.
- Exact native identity object maps and payload ceilings, preserving existing single-owner APIs.
- Prefixed `whereNvlMetafield` stored-scalar filtering with exact portable owner correlations and retained host scopes/selections.

### Changed

- Classify the supported consumer PHP surface with explicit source annotations and restrict package model handles to declared identity and in-memory read fields; preserve existing workflow behavior and concrete signatures.
- Adopt lockstep major 5 with required and development NVL peer floors of `^5.0`.
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
