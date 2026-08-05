# Upgrading NVL Metafields

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
