# Upgrading NVL Metafields

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

1. Set `metafields.migrations.enabled=false` for existing tables.
2. Run `php artisan nvl:metafields:doctor --strict --format=json`.
3. Register unique owner-model aliases and stable reference aliases.
4. Bind `MetafieldAuthorization` and `MetafieldReferenceAuthorization`, or configure their Gate abilities.
5. Convert incompatible polymorphic owner identifiers to the registered morph aliases in an application-owned bridge.
6. Move textual localized values into dedicated translation rows.
7. Replace direct row writes with create/update DTOs and Actions. Supply expected revisions for definition updates/deletes and existing value updates/clears.
8. Replace the legacy owner-value index with `metafields_owner_definition_unique` ordered by owner type, owner identifier, and definition identifier.

Validate row counts, handles, assignments, references, localized values, and rollback before enabling management routes.

## Shared owner registry compatibility

Move model identity declarations to `nvl-core.owners` and reference the alias from `metafields` capability configuration as described in the [README](README.md#shared-owner-identity). Preserve package contracts, resolvers/handlers, visibility scopes, and mutation authorization. Core does not grant package capabilities. Conflicting aliases or multiple canonical aliases for one model fail before use.

Legacy class inputs remain accepted for one major cycle and are reported through Core diagnostics. Existing Content, Taxonomy, and Metafields mappings keep their established aliases. Legacy SEO, Media, Comments, Templates, Pages, and Translatable class-backed behavior does not automatically create a new morph alias. Existing host morph mappings are respected.

Adding a canonical Core alias changes Laravel's write-time morph type for that model. Before adding it to an existing class-backed deployment, explicitly convert the known package-owned morph columns and reconcile every other affected host relationship. Keep unrelated rows and host-owned morph tables unchanged. This release performs no automatic owner-data conversion and does not ship `nvl:owners:upgrade`. Preserve existing aliases when no conversion is required, rebuild configuration caches, and restart workers after the cutover.

## Shared Doctor integration

The loaded package provider now contributes its existing inspection checks to Core's `nvl:doctor --strict --format=json`. The package command remains available. The shared gate fails errors and, in strict mode, warnings; no data upgrade is required for diagnostics.

## Next major: isolated schema identities

This is a breaking schema identity change. Back up storage and migration history, pause writes/workers, install this code with automatic package migrations disabled, and select one owner for migrations (vendor or published).

```sh
php artisan nvl:doctor --strict --format=json
php artisan nvl:schema:upgrade --package=metafields --claim-legacy --dry-run --format=json
php artisan nvl:schema:upgrade --package=metafields --claim-legacy --format=json
```

The command validates released columns and relational keys plus creating migration history, renames owned legacy tables to the effective `tables.*` targets and rewrites exact package migration identities while retaining batches and unrelated host records. It refuses foreign/incomplete shapes and conflicting targets. Explicit old table mappings retain those names; remove them when choosing new defaults. A second run is empty.

Unmodified published files, including changed timestamps, map by verified checksum to the exact vendor migration identity and current package migration implementation. Modified host copies remain host-owned. Disable vendor loading when retaining a published owner; duplicate ownership fails before migration. No migration files or stored morph types are rewritten.

DDL transactions are driver dependent and per connection. Inspect dry-run warnings for MySQL/MariaDB or split storage; after a failure, inspect completed steps before resuming. Schema-qualified rename targets require an explicit host schema move first. Re-enable your selected migration owner, migrate remaining package changes and rerun Doctor before resuming writes. See the suite upgrade guide for shared owner/locale inputs, Core option defaults and one-major deprecation rules.
