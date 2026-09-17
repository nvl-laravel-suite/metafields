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

<!-- tenancy-program-p2 -->
Configurable-tenancy implementation and adoption documentation are present. The final consolidated verification matrix is pending; do not treat this package as release-ready until that gate passes.
