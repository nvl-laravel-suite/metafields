---
name: nvl-metafields
description: Implement, integrate, test, or review nvl/metafields in Laravel 13. Use for typed definitions, registered owners and references, localized values, grants and imports, optimistic concurrency, batch reads, and authorization.
---

# NVL Metafields

Read the package README and source service signatures before integrating. The public native services are `MetafieldDefinitions`, `MetafieldValues`, and `MetafieldReader`. Use native Eloquent models, enums, scalars, and shaped arrays. HTTP, application view data, and UI belong to the host.

## Registration and identity

- Register stable owner aliases, supported types and sections in `nvl-metafields.owners`; register reference targets in `nvl-metafields.reference_models`. A configured alias alone never admits a model outside its native host or Ownership scope.
- Preserve Laravel's host-authored morph identity and exact owner key. Package capability declarations do not change the morph map.
- Use native Ownership resources for isolation. Do not use retired Tenancy bridge APIs or infer authority from legacy tenant columns.

## Authorization

- Pass a persisted `Authenticatable` actor or explicit `SystemActor::Background` to every public native service call. Both identities deny by default; the host must grant the relevant `metafields.*` Gate ability. Never infer the current guard user.
- Authorize single owner reads and writes through `MetafieldAuthorization` using canonical persisted owners. Writes require `MutateOwner` under the owner lock; clears also require `DeleteOwnerValue` with the definition. Authorize every reference mutation through `MetafieldReferenceAuthorization`. The configured Gate adapters use the passed actor. A missing binding or ability denies.
- Bind `MetafieldBatchAuthorization` for nonempty batch reads. Every method receives the explicit canonical actor. Its SQL scopes constrain assignments, values, and reference targets; imperative callbacks do not replace SQL visibility. Query-free `authorizeOwners`, `allowsDefinition`, and `authorizeReferences` inspect admitted facts.

## Mutations and reads

- Create and update definitions with validated native arrays. Restrict owner assignments to registered type and section; reject invalid type, locale, default, schema, rule, or reference configuration. Update/archive/delete take expected revisions. Delete live values only with explicit cascade.
- Use `MetafieldValues::sync` for atomic owner writes. Each item requires exactly one of `value`, `translations`, or true `clear`, and `expected_revision` on existing rows. Omission never writes null. `translation_mode` requires `translations`. Validate each heterogeneous item against its persisted definition, prevent duplicate IDs, and enforce required section fields. Localized patch preserves omitted locales; replace removes them; explicit null locale values are invalid.
- Use `MetafieldReader::forOwner` for authorized single owner fields and `forOwners` for bounded lists. Batch output is an exact morph/key object map with separate order. Empty input has no storage queries. Admission reloads canonical owners through retained host scopes. Missing or denied stored/default references fail closed.
- For stored scalar comparisons use `whereNvlMetafield` only on active filterable definitions with a SQL visibility policy. Missing and default-only rows never match. Keep caller OR clauses inside mandatory host, definition, and value predicates.
- Catalog grant, revoke, and import use immutable source snapshots, explicit expected revisions, recipient authority, and idempotency. Imported definitions become independent copies.

## Verification

Run the package's direct Pest suite from the suite root using `--test-directory=packages/nvl/metafields/tests --configuration=packages/nvl/metafields/phpunit.xml.dist --bootstrap=vendor/autoload.php`. The Historical directory preserves retired API fixtures and is excluded by the package XML. Check 1/25/100 owner SQL budgets against actual domain queries, including quoted table names and separate metadata SQL. Use package Pint and configured maximum PHPStan when the shared bootstrap is coherent.
