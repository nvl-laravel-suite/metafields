---
name: nvl-metafields
description: Use when integrating nvl/metafields typed definitions, registered owner sections, localized/reference values, atomic sync, catalog grants/imports, optimistic revisions or bounded owner/filter reads.
---

# NVL Metafields

Use the PHP 8.4 / Laravel 13 native services, models, enums and shaped arrays.
The host owns requests, controllers, application Actions/DTOs, resources and UI.

## References

- [Installation](references/installation.md): dependencies, migrations, config and publication choices.
- [Native API](references/api.md): exact native service and authorization declarations.
- [Usage](references/usage.md): owner registration, definitions, value sync, batches, references and catalog copy.

## Choose the service

| Need | Native service |
| --- | --- |
| Definition create/update/archive/delete and grant/revoke/import | `MetafieldDefinitions` |
| Atomic value sync, handle-based set and clear/delete | `MetafieldValues` |
| Definition list, single owner fields and bounded owner batches | `MetafieldReader` |
| Host owner types, allowed sections/types | `MetafieldOwnerRegistry` |
| Stored scalar host comparisons | Native `whereNvlMetafield` model scope |

## Register identity and authorize deliberately

Declare `nvl-metafields.owners` stable aliases with native models, supported types
and sections. Additional reference aliases live in `reference_models`; registration
retains Laravel's host morph identity and exact keys. It never grants foreign
model/Ownership access or changes the morph map.

Every native service takes a persisted Eloquent Authenticatable or explicit
`SystemActor::Background` and checks its `metafields.*` capability Gate. Both deny
without host grants. Actor facts reload canonically; null is not background authority.

`MetafieldAuthorization` additionally authorizes definitions and canonical owners.
Writes require `MetafieldAbility::MutateOwner` under lock; clears also require `MetafieldAbility::DeleteOwnerValue` with
the definition. References use `MetafieldReferenceAuthorization`. Nonempty batch
reads need `MetafieldBatchAuthorization` with SQL visibility and loaded-fact checks.
Catalog grants/revocations retain independent `MetafieldCatalogAccess` authority.

## Input, locale and revision rules

- Create definitions with native namespace/key/type/copy and required owner assignment.
- Definition update/archive/delete take expected revision as a separate argument.
- Each sync item has `definitionId` plus exactly one of value/translations/true clear.
- Existing active value writes need `expected_revision`; omission never means write null.
- `translation_mode` requires translations; Patch keeps omitted locales and Replace removes them.
- Null locale values are invalid; clear uses its dedicated semantic and required-field checks.
- Validate every value/default/reference against its persisted definition and assignment.
- Use catalog import's exact grant/source revisions and UUID idempotency key for retry.

## Safe reads and optional isolation

Single owner reads return definition/assignment/value model entries. Batch output
is an object map keyed by exact morph/key plus a separate order list, including
explicit empty owner results. Empty batches avoid metafield-data SQL; actor admission still applies. Canonical owner
admission retains host scopes; stored/default references fail closed when unavailable.

Stored scalar filters require active filterable definitions and SQL visibility.
Missing/default-only rows do not match. Group caller OR clauses inside mandatory
host/definition/value predicates. Do not turn model relations into an unscoped
substitute for authorized reader/service operations.

## Models, transactions and checks

Choose `nvl-metafields.models.definition` before boot with a native concrete subclass.
Preserve UUID, required casts, revision/default/handle hooks, relations, ownership
and package-configured storage. Values, translations, grants and assignments remain
fixed models; custom columns use host migrations. Native Ownership is optional
for unowned hosts and requires reviewed adoption for declared owned storage.

Services own source transactions, owner/definition locks and outer-commit events.
Permanent owner deletion is an explicit host lifecycle. Grant `metafields.purge`
and `MetafieldAbility::PurgeOwnerValues` deliberately, retain `MetafieldAbility::MutateOwner`/`MetafieldAbility::DeleteOwnerValue` policy,
then call `MetafieldValues::purgeBeforeOwnerDeletion` inside an existing transaction
on the exact owner/value connection before permanently deleting the owner there.
It hard-purges active/cleared values and locale rows in bounded batches, preserves
definitions, and follows the outer commit. Soft deletion normally retains values.
No automatic hooks exist; raw deletion or key reuse bypasses this lifecycle.
Doctor's bounded reference sample requires an explicit `MetafieldDiagnosticAccess`
binding approving `SystemActor::Background`; its default reports inspection skipped.
It retains host scopes and reports unresolved references, not a global
orphan inventory; previously orphaned data requires reviewed host recovery.
Definition imports create independent schemas from exact immutable granted facts.
Test type/null/locale modes, canonical permissions, references, required clears,
revision conflicts, whole-sync rollback, idempotent imports and bounded SQL reads.
Use [usage](references/usage.md) to resolve host policy decisions rather than inventing
DTO wrappers, external-asset integrations or transport APIs.
