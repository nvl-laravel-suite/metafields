---
name: nvl-metafields
description: Implement, integrate, test, or review nvl/metafields in Laravel 13. Use for typed custom-field definitions, owner and reference registries, localized definitions or values, validation limits, optimistic concurrency, bulk synchronization, query helpers, deletion policy, or authorization.
---

# NVL Metafields

Use definitions as the schema and metafield rows as owner-specific values. Route mutations through package Actions.

## Register boundaries

- Register stable owner aliases in `MetafieldOwnerRegistry`.
- Register reference aliases in `MetafieldReferenceModelRegistry`.
- Reuse an application's existing morph alias exactly; conflicting aliases or
  models must fail before the global morph map changes.
- Keep model classes, identifier resolution, authorization, and deletion behavior application-owned.
- Never add consumer-specific owner enums or direct model imports to the package.

## Define and mutate fields

- Direct mutation Actions are trusted operations. In user-driven compositions,
  call `MetafieldAuthorization` before the Action; optional HTTP controllers
  already do this. Use `authorizeDefinition(MetafieldAbility::CreateDefinition)`
  for creation and `authorizeOwner(MetafieldAbility::MutateOwner, $owner)` for
  owner synchronization. Use `MetafieldAbility::UpdateDefinition` for updates,
  archiving, and assignments, `MetafieldAbility::DeleteDefinition` for definition
  deletion, and `MetafieldAbility::DeleteOwnerValue` with the owner and definition
  for clearing a value.
- Value validation separately invokes `MetafieldReferenceAuthorization` for
  each referenced record. Reference permission does not grant owner mutation.
- Use `CreateMetafieldDefinitionAction`, `UpdateMetafieldDefinitionAction`, and `ArchiveMetafieldDefinitionAction`.
- Require expected versions on editable definitions and values.
- Use `SetMetafieldAction`, `SyncOwnerMetafieldsAction`, and `DeleteOwnerMetafieldAction`.
- Distinguish patch from replace semantics.
- Enforce type eligibility before creating localized value rows.
- Bound JSON depth, items, bytes, formats, and recursion.
- Bound bulk synchronization and structured definition metadata with the
  package limits.

## Read and operate

- Keep tenancy opt-in and register every owner/reference model as a Foundation
  resource. A configured model alias alone is never authorization.
- Definitions may include a platform catalog partition, but values always
  inherit the canonical tenant owner and never retain live platform references.
- Import only a granted scalar snapshot. Require exact revisions, idempotency,
  an explicit target handle, a total reference map, and immutable provenance.
- Resume adoption only for repair consistent with the immutable mapping; changed
  mappings require a pre-cutover restore and new reviewed prepare.

- Use `ListAuthorizedOwnerMetafieldsAction` for consumer-facing owner reads. It
  authorizes the owner-view ability before querying and returns the bounded
  `OwnerMetafieldField` projection with localized definitions and values.
- Treat `ListOwnerMetafieldsAction` as a storage-focused composition primitive;
  do not call it directly from new consumer management code.
- Query only registered scalar comparisons; do not expose arbitrary JSON paths.
- Keep management routes disabled and authorize every owner and definition.
- Run `nvl:metafields:doctor --strict --format=json` before adoption.

## Verify

Test every field type, translation eligibility, invalid schemas, oversized JSON, references, identifier strategies, stale writes, uniqueness, delete policies, patch/replace behavior, query plans, and database parity.

## Configurable-tenancy release discipline

- Preserve disabled compatibility and package independence; tenant support never creates an undeclared Auth or Suite dependency.
- Use registered package-owned resources, adoption adapters, Actions, and lifecycle APIs. Never add a generic tenant delete-all path or raw cross-package cleanup.
- Treat mapping/configuration hashes, interruption checkpoints, conservation evidence, worker context, tenant-leading queries, and standalone consumption as release contracts.

## Shared owner identities

- Declare canonical owner identity once in `nvl-core.owners`; reference its alias in `metafields` capability configuration.
- The omitted model defaults to the shared alias matching the capability key. An explicit model may also reference an alias. Preserve supported types, sections, planned/live status, and mutation authorization.
- Keep the package allowlist and authorization independent of Core registration. Never authorize a model merely because Core knows it.
- Accept legacy class/resolver/handler inputs during the documented one-major compatibility cycle. Report deprecated host identity inputs through `nvl:doctor`; preserve established write-time morph types.
- Before introducing an alias for historical FQCN-backed data, explicitly convert reviewed package-owned columns and reconcile affected host relations. Never silently rewrite host morph tables or enable `enforceMorphMap()` globally.

## Shared consumer diagnostics

Run `php artisan nvl:doctor --strict --format=json` to combine checks from loaded NVL providers. Retain the package Doctor command for its detailed report; both paths reuse the package-owned inspection service.

### Brownfield storage identities

Resolve all package tables through the table helper and canonical `metafields.tables.*`, connections through `metafields.connection` with Core/Laravel inheritance. Defaults use `nvl_metafields_*`; migration filenames include that package slug. Never silently adopt a matching table or generic migration filename. Run shared `nvl:doctor --strict --format=json` and the explicit `nvl:schema:upgrade --package=metafields --claim-legacy --dry-run --format=json` before upgrading owned legacy storage. Validate the complete plan and choose one migration owner. Preserve host records, constraint names and stored morph values. Deprecated config inputs last one major; canonical options take precedence.
