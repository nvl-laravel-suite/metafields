---
name: nvl-metafields
description: Implement, integrate, test, or review nvl/metafields in Laravel 13. Use for typed custom-field definitions, owner and reference registries, localized definitions or values, validation limits, optimistic concurrency, bulk synchronization, query helpers, deletion policy, or authorization.
---

# NVL Metafields

## Authorized batch reads

Inject `ListAuthorizedOwnersMetafieldsContract` for list screens and bind an explicit
`MetafieldBatchAuthorization`. Imperative Gate callbacks cannot be inferred as SQL
policies. Policy methods are query-free: use canonical owners and loaded
`MetafieldReferenceFacts`, constrain assignment/value/target queries before reads,
and keep SQL assignment visibility consistent with `allowsDefinition`. The reader
nests callbacks within mandatory owner/tenant predicates so OR cannot widen them.

Pass at most 100 persisted model entries. Results use native morph/key JSON objects
and separate request order. Capability assignment aliases remain metadata. The
reader retains host scopes and active tenant boundaries and rejects foreign storage,
deleted owners and missing/denied stored **or default** references. Limits are 100
definitions per owner type, 10,000 values, 1,000 references and ten locales. Empty
input performs no SQL. Localized budgets are seven queries at 1/25/100 owners;
disabled Tenancy adds one cold probe and each reference class adds one query.

Use `whereNvlMetafield($handle, $value, $policy, $operator = '=')` for active assigned
filterable nonlocalized scalar fields. It compares stored values; missing/default-only
fields never match. Its host policy must express owner/definition/value visibility.
The host filter admits live owners only. Preserve the native SoftDeletes predicate
through `getQualifiedDeletedAtColumn()` even after removed scopes or `withTrashed()`;
honor custom deleted-at columns and leave non-SoftDeletes models unchanged. Group
pre-existing caller OR predicates before adding mandatory guards, including direct
adapter use, and reject UNION/storage drift before definition SQL.
Keep the prior boolean scopes unchanged.

Batch projections must use admitted preloaded rows with `TranslationResolver` and
`getRelation`. Existing translated model getters and even the loaded `translations`
property issue ownership SQL under real Tenancy. Verify 1/25/100 budgets with the
actual enabled tenant boundary.

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

- Declare owner class lists in `nvl-core.owners` and reference model classes in `metafields` capability configuration. Laravel `getMorphClass()` supplies the host-authored stored identity; declarations do not add global host morph mappings.
- The omitted model defaults to the shared alias matching the capability key. An explicit model may also reference an alias. Preserve supported types, sections, planned/live status, and mutation authorization.
- Keep the package allowlist and authorization independent of Core registration. Never authorize a model merely because Core knows it.
- Preserve resolvers, handlers and authorization. Legacy aliases require agreement with native `getMorphClass()` and are removed in major 6; Doctor reports mismatches and stored identity drift without conversion.
- If the host changes its morph map, explicitly reconcile reviewed package-owned columns and affected host relations before cutover. Core and package capability registration never mutate the host morph map or rewrite stored values.

## Shared consumer diagnostics

Run `php artisan nvl:doctor --strict --format=json` to combine checks from loaded NVL providers. Retain the package Doctor command for its detailed report; both paths reuse the package-owned inspection service.

### Brownfield storage identities

Resolve all package tables through the table helper and canonical `nvl-metafields.tables.*`, connections through `nvl-metafields.connection` with Core/Laravel inheritance. Defaults use `nvl_metafields_*`; migration filenames include that package slug. Never silently adopt a matching table or generic migration filename. Run shared `nvl:doctor --strict --format=json` and the explicit `nvl:schema:upgrade --package=metafields --claim-legacy --dry-run --format=json` before upgrading owned legacy storage. Validate the complete plan and choose one migration owner. Preserve host records, constraint names and stored morph values. Deprecated config inputs last one major; canonical options take precedence.

## Canonical configuration ownership

- Read/write `nvl-metafields` configuration and publish only canonical `nvl-<package>-<resource>` tags. Keep logical package/tenant resource identifiers unchanged.
- Generic config roots and unprefixed package environment names are foreign by default. For an upgrading NVL host only, select `nvl-core.compatibility.legacy_config` package IDs and `legacy_env` explicitly; both default off. Canonical presence wins, including false/null/empty values. Legacy inputs are read without writing back and are removed in major 6.
- Use canonical `NVL_<PACKAGE>_*` variables only in config evaluation, then rebuild configuration caches and restart workers after cutover. Shared Laravel environment variables retain their names. Consult Core's versioned `support/resources/global-names.json` for all renames.
- Old global aliases and legacy route families require separate explicit `global_aliases`/`legacy_routes` package selections. Preserve collisions and use Doctor diagnostics; never grant generic permissions automatically or claim signed-link compatibility without the same authorization/signature checks.
