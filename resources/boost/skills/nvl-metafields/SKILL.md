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
