# Upgrading NVL Metafields

## Consumer contracts, committed events and runtime policy (5.x)

Prefer focused public interfaces in constructor injection; native implementations remain container defaults and host prebindings win. Returned models are documented identity/data handles: use package contracts for reads/writes and capability-specific batch readers instead of direct package queries. Enable the shipped Core PHPStan include in your host; do not invoke the suite workbench static audit command in a consumer.

Events now carry immutable schemaVersion=1 and scalar/DTO snapshots. Replace model-bearing event fields with the IDs listed in [events](docs/events.md); load only through an authorized public reader when needed. Only six declared legacy `*Event` names are retained as PHP aliases for major 5, removal no earlier than major 6. Migrate exact imports/listeners/fakes to canonical names, replace suffix wildcard patterns explicitly, drain old queued payloads, rebuild event caches and restart workers. Framework Verified/PasswordReset remain native classes. Source-connection callbacks are process-local after-commit publication, not a durable outbox or exactly-once delivery.

Package failures have a marker and optional response metadata. Opt into Core's JSON renderer deliberately; preserve existing host handlers and request-locale selection. Missing required host adapters produce `binding_required`/500; genuine configured authorization denial retains native handling. See the README error table and required-bindings section where applicable.

Factories ship in runtime package mappings for host tests. Ordinary make may persist parents; withoutParents()->make creates detached fixtures. Supply persisted native owners/parents and active tenants explicitly, retain source revisions, and never treat a factory row as a real storage/provider/workflow effect. Core's optional installer publishes common config without enabling features; strict Doctor and explicit deployment cache/worker steps belong in the host release process. The PHP 8.4/Laravel 13 local Dagger release gate and fresh public Composer installation passed for 5.0.0. Additional compatibility legs need separate evidence; hosts must verify their own adoption.


## Bounded authorized batch reads

Replace per-owner read loops with the bound `ListAuthorizedOwnersMetafieldsContract`.
Bind a query-free `MetafieldBatchAuthorization` SQL adapter explicitly; single-owner
Gate callbacks cannot prove batch visibility and raise an actionable package
exception. An active authorization implementation that also implements the batch
contract is compatible; explicit host bindings take precedence.

The reader reloads owners with host scopes and active tenant boundaries. Result
keys use native morph types and normalized keys as JSON objects, with order separate.
Absent/deleted owners, foreign storage and denied/missing stored or default references
fail closed. Assignment aliases and existing single-owner APIs retain their semantics.

Respect the 100-input, 100-definition-per-type, 10,000-value, 1,000-reference and
ten-locale ceilings. `whereNvlMetafield` compares stored scalar values only;
missing/default-only fields never match. Legacy filters remain available. New host
filters admit live owners only. Removing global scopes or using `withTrashed()`
does not remove native `SoftDeletes` exclusion; custom deleted-at columns are honored.
Non-SoftDeletes owners retain their existing behavior. Caller OR predicates stay
inside the mandatory guards, and UNION queries fail before definition lookup.
Host filters require active assigned filterable definitions and an explicit SQL policy;
localized/reference/structured comparisons reject unsupported adapters. SQLite
execution and PostgreSQL/MySQL/MariaDB SQL generation are covered; native execution
on supported versions remains a family release gate.

## Tenant ownership adoption

Tenancy remains disabled by default. Register every concrete owner and reference
target as a Foundation tenant resource, take a pre-cutover backup, and review
shared legacy definitions before preparing the `metafields` adapter. Shared
definitions are copied per canonical owner tenant with assignments, locale rows,
defaults, archived/deleted history, and remapped values; ambiguous or unmapped
references deny activation.

Run expand, bounded backfill, verify, and activate under maintenance. Source or
schema repair that preserves the prepared mapping may resume. If any assignment,
split, destination UUID, or target tenant changes, restore and create a new
reviewed prepare. Never claim that removing tenant columns is rollback after
tenant-local duplicate handles exist. Platform grants authorize copy only and
must never be used as live definition/reference access.

## Upgrading to 1.0

Version 1.0 replaces fixed owner enums and consumer access classes with explicit registries and Actions.

1. Set `nvl-metafields.migrations.enabled=false` for existing tables.
2. Run `php artisan nvl:metafields:doctor --strict --format=json`.
3. Register unique owner-model aliases and stable reference aliases.
4. Bind `MetafieldAuthorization` and `MetafieldReferenceAuthorization`, or configure their Gate abilities.
5. Convert incompatible polymorphic owner identifiers to the registered morph aliases in an application-owned bridge.
6. Move textual localized values into dedicated translation rows.
7. Replace direct row writes with create/update DTOs and Actions. Supply expected revisions for definition updates/deletes and existing value updates/clears.
8. Replace the legacy owner-value index with `metafields_owner_definition_unique` ordered by owner type, owner identifier, and definition identifier.

Validate row counts, handles, assignments, references, localized values, and rollback before enabling management routes.

## Shared owner registry compatibility

Declare owner classes in `nvl-core.owners`, for example `'owners' => [Article::class]`, and reference the same model class from each package capability. Laravel's `getMorphClass()` is the stored owner identity: it returns the host-authored morph alias or the FQCN when no map exists. Core declarations and package allowlists do not add or enforce a host morph map and do not grant authorization.

Legacy alias references remain read compatibility during major 5 and are removed in major 6. A legacy configured alias must agree with the model's current `getMorphClass()`; mismatches are diagnostics and require a host decision. Doctor can inspect declared package owner columns for stored-versus-current identities without rewriting them. If the host introduces or changes its morph map, review and convert only the affected stored columns and reconcile host relationships before cutover. No automatic owner-data conversion or `nvl:owners:upgrade` is provided. Rebuild configuration caches and restart workers after the coordinated change.

## Shared Doctor integration

The loaded package provider now contributes its existing inspection checks to Core's `nvl:doctor --strict --format=json`. The package command remains available. The shared gate fails errors and, in strict mode, warnings; no data upgrade is required for diagnostics.

## Next major: isolated schema identities

This is a breaking schema identity change. Back up storage and migration history, pause writes/workers, install this code with automatic package migrations disabled, and select one owner for migrations (vendor or published).

```sh
php artisan nvl:doctor --strict --format=json
php artisan nvl:schema:upgrade --package=metafields --claim-legacy --migration-owner=vendor --dry-run --format=json
php artisan nvl:schema:upgrade --package=metafields --claim-legacy --migration-owner=vendor --format=json
```

The command validates released columns and relational keys plus creating migration history, renames owned legacy tables to the effective `tables.*` targets and rewrites exact package migration identities while retaining batches and unrelated host records. It refuses foreign/incomplete shapes and conflicting targets. Explicit old table mappings retain those names; remove them when choosing new defaults. A second run is empty.

Declare each published path and canonical identity explicitly in `nvl-core.migrations.published`; retimestamped history also needs an exact `legacy` mapping. Use `--migration-owner=vendor` after manually archiving declared copies outside loaded paths, or `--migration-owner=published` after manually replacing executable copies with current migration code and disabling vendor loading. The plan verifies ownership and preserves batches; checksums do not automatically claim files. Modified host copies remain host-owned. No migration files or stored morph types are rewritten.

DDL transactions are driver dependent and per connection. Inspect dry-run warnings for MySQL/MariaDB or split storage; after a failure, inspect completed steps before resuming. Schema-qualified rename targets require an explicit host schema move first. Re-enable your selected migration owner, run `nvl:schema:preflight` with the same selected paths and connection, then migrate remaining package changes and rerun Doctor before resuming writes. See the suite upgrade guide for shared owner/locale inputs, Core option defaults and one-major deprecation rules.

## Tagged consumer PHP boundary

Use source `@api` workflows, extension contracts, and value types for application integration. Direct use of untagged implementations or `@internal` members is unsupported. This classification keeps existing concrete Action signatures and runtime behavior; it does not authorize package model persistence, ad hoc queries, relation traversal, or generic model serialization. Returned models are identity/result handles with only the explicitly declared in-memory read fields described in the README.

`HasMetafields::metafields()` are internal storage or lifecycle seams. Migrate direct traversal, eager/lazy loading, and aggregate queries to the package authorized read Actions or explicit C1 host scopes/adapters. Native host queries and opted-in Translatable behavior remain supported.

`OwnerMetafieldField::fromAssignment` is an internal projector. Use `ListAuthorizedOwnerMetafieldsAction` for an authorized owner read or the bounded `ListAuthorizedOwnersMetafieldsContract` for owner lists rather than reading assignment/definition/value relationships yourself.

## Major 5 workflow injection

Replace host constructor dependencies on selected concrete Actions with their focused `Nvl\Metafields\Contracts\*Contract` equivalents listed in the README. Existing equivalent workflow contracts are reused. Native concrete constructors, qualifiers, argument defaults, result types, and execution behavior remain compatible. Internal package Action/service chains retain their existing concrete dependencies.

Default workflow registrations use `bindIf`, retaining host interfaces/instances registered before discovery. Register substitutes at the interface key; newly resolved host services receive late replacements. Substituting a workflow does not exercise the native authorization, storage, or lifecycle invariants, which require the owning integration coverage.

The six existing mutation interfaces and C1 batched reader are reused. Their native value/owner/revision semantics remain in the implementations; formerly unconditional mutation interface defaults become conditional.
