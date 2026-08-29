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

- Use `CreateMetafieldDefinitionAction`, `UpdateMetafieldDefinitionAction`, and `ArchiveMetafieldDefinitionAction`.
- Require expected versions on editable definitions and values.
- Use `SetMetafieldAction`, `SyncOwnerMetafieldsAction`, and `DeleteOwnerMetafieldAction`.
- Distinguish patch from replace semantics.
- Enforce type eligibility before creating localized value rows.
- Bound JSON depth, items, bytes, formats, and recursion.
- Bound bulk synchronization and structured definition metadata with the
  package limits.

## Read and operate

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
