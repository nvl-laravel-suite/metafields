# NVL Metafields

Typed, optionally localized custom fields for registered Eloquent owners on Laravel 13 and PHP 8.4. This package provides native services and model results; hosts own their HTTP and UI layers.

Permanent owner deletion is an explicit host lifecycle. Grant `metafields.purge`, authorize `PurgeOwnerValues` and retain `MutateOwner`/`DeleteOwnerValue` policy. Inside an existing transaction on the exact owner/value connection, call `MetafieldValues::purgeBeforeOwnerDeletion($owner, $actor)`, then permanently delete that owner before committing. The service locks the canonical owner, purges active and cleared values/locales in bounded batches, preserves definitions and other owners, and publishes its synchronization fact after the outer commit. A connection alias cannot substitute for the same transaction. Normal soft deletion retains values; no destructive trait hooks run automatically. Raw deletion and key reuse bypass this lifecycle.

Doctor's owner-reference sample requires a host `MetafieldDiagnosticAccess` binding explicitly approving `SystemActor::Background` and current Ownership admission when declared. Its default denies and reports inspection skipped. This dedicated read policy never routes a background enum through unrelated global Gate hooks. It inspects at most 100 visible value references, detects additional rows with one sentinel, and retains configured host scopes while including soft-deleted owners. Unresolved references may be scoped out or unregistered; this is a bounded diagnostic, not a global orphan inventory or deletion authority. Previously orphaned identities require reviewed host recovery before reuse.

## Install and register owners

Install `nvl/metafields:^7.0` with its declared `nvl/core` and `nvl/translatable` dependencies. Publish the package configuration with Laravel vendor publishing when overrides are needed, choose one migration owner, and run the package migrations. Configure authorization explicitly before enabling operations.

Configure stable aliases in `nvl-metafields.owners` and `nvl-metafields.reference_models`:

```php
'owners' => [
    'products' => [
        'model' => App\Models\Product::class,
        'label' => 'Products',
        'sections' => ['general'],
        'supported_types' => ['string', 'integer', 'reference'],
    ],
],
'reference_models' => [
    'categories' => App\Models\Category::class,
],
```

Owners and reference targets must be persisted, registered models. Values store the host model's native morph identity and exact string representation of its key. Configure native Ownership resources when isolating definitions or owners; unscoped hosts can use ordinary models.

Implement `HasMetafieldScope` when a model's records have different eligible fields. Return the record's scope key and declare the allowed `value` and `label` pairs. Set `assignment.ownerScopes` to select keys. A null assignment applies to every record. Lists, defaults, required-field checks and writes all use the canonical owner's scope. For SQL comparisons of scoped fields, implement `HasSqlMetafieldScope` with the persisted column representing that key. A computed PHP scope remains available through the record and batch APIs.

## Authorize native operations

Every public operation requires an explicit persisted `Authenticatable` actor or `SystemActor::Background`. Host Gate abilities named `metafields.create`, `metafields.update`, `metafields.archive`, `metafields.delete`, `metafields.grant`, `metafields.revoke`, `metafields.import`, `metafields.write`, and `metafields.read` authorize the respective operations. Undefined abilities deny both people and background work. The package does not infer an actor from a guard.

Definition creation, listing, updating, archiving, and deletion additionally call `MetafieldAuthorization::authorizeDefinition` with the freshly loaded actor. Update, archive, and delete receive the canonical definition locked inside their transaction. The default adapter uses `nvl-metafields.authorization.definition_ability` when configured, passing the capability string and optional definition to that Gate. Otherwise it uses the native `metafields.create`, `metafields.read`, `metafields.update`, or `metafields.delete` Gate with the optional definition; archive uses `UpdateDefinition` in addition to its coarse `metafields.archive` Gate. Catalog grants and imports retain their independent `MetafieldCatalogAccess` boundary.

Owner reads and writes also use `MetafieldAuthorization::authorizeOwner` with the canonical persisted owner. Writes authorize `MutateOwner` inside the owner lock; clearing a value additionally authorizes `DeleteOwnerValue` with its persisted definition. Configure `nvl-metafields.authorization.owner_ability` or bind that contract; the default requires the host's `update` policy for the owner. Reference writes use `MetafieldReferenceAuthorization` and deny unless the host configures `reference_ability` or binds that contract. `MetafieldBatchAuthorization` is required for nonempty multi-owner reads and supplies SQL visibility for assignments, values, and targets.

## Definitions

```php
use Nvl\Metafields\Services\MetafieldDefinitions;

$definition = app(MetafieldDefinitions::class)->create([
    'namespace' => 'product',
    'key' => 'headline',
    'type' => 'string',
    'assignment' => ['ownerType' => 'products', 'section' => 'general'],
    'translations' => ['en' => ['title' => 'Headline']],
], $actor);

$updated = app(MetafieldDefinitions::class)->update(
    $definition,
    ['translations' => ['en' => ['hint' => 'Short headline']]],
    $definition->revision,
    $actor,
);
```

`MetafieldDefinitions` exposes `create(array $attributes, $actor)`, `update($definition, array $attributes, int $expectedRevision, $actor)`, `archive($definition, bool $archived, int $expectedRevision, $actor)`, `delete($definition, int $expectedRevision, $actor, bool $deleteValues = false)`, `grant(string $definitionId, int $sourceRevision, $actor)`, `revoke(string $grantId, int $expectedRevision, $actor)`, and `import(array $request, $actor)`. Definition and assignment input is allowlisted, and owner type, section, reference target, locale, default, and rules are validated against registered capabilities. `delete` requires explicit cascading when live values exist. Import uses an exact granted catalog snapshot with expected revisions and an idempotency key. A namespace and key held by another active definition, on create or rename, is a `ValidationException` on `key`; a malformed definition identifier is a `ModelNotFoundException`. While a definition has values, `update` rejects a `namespace`, `key` or `assignment.ownerType` change; the combined handle is at most 255 characters.

## Values and reads

```php
use Nvl\Metafields\Services\MetafieldValues;
use Nvl\Metafields\Services\MetafieldReader;

$value = app(MetafieldValues::class)->set($product, 'product.headline', 'Hello', $actor);
$fields = app(MetafieldReader::class)->forOwner($product, $actor, 'en');
```

This example creates the initial value. Subsequent `set` calls must supply `expectedRevision: $value->revision` from the last canonical read; existing localized values also retain their locale-specific input rules.

`MetafieldValues` exposes `set(Model $owner, string $handle, mixed $value, $actor, ?string $locale = null, ?int $expectedRevision = null)`, `delete(Model $owner, MetafieldDefinition|string $definition, int $expectedRevision, $actor)`, and `sync(Model $owner, array $items, $actor)`. Each sync item has `definitionId` and exactly one of `value`, `translations`, or a true `clear`; an existing value requires `expected_revision` in that item. Omission never writes null; use an explicit nullable `value` or `clear`. Localized writes accept `translation_mode` as `TranslationSyncMode::Patch` or `Replace` only alongside `translations`; omitted locales are preserved by patch and explicit null locale values are rejected. A sync validates every item against its persisted definition and commits atomically. Float values must be finite, JSON and array values given as JSON strings are bounded like their decoded form, and a reference identifier, given as an integer or string, is trimmed and must then be the exact string form of the target key. A blank optional number is stored as null.

`MetafieldReader` exposes `definitions($actor)`, `forOwner(Model $owner, $actor, ?string $locale = null)`, and `forOwners(array $owners, $actor, ?string $locale = null)`. The first two return collections of native models or arrays containing `definition`, `assignment`, and nullable `value` models. The batch result contains `owners` as a nested JSON object keyed by morph type and exact owner key, plus a separate `order` list. Empty input returns an empty object map. Nonempty batches admit canonical owners under host scopes, apply SQL visibility, and reject unavailable or denied stored and default references. Batches have bounded owner, definition, value, reference, and locale limits.

For stored scalar comparisons on active filterable definitions, use `whereNvlMetafield($handle, $value, $actor, $batchPolicy, $operator = '=')` on a registered owner query. Missing and default-only values do not match. Text `=` and `!=` compare exactly, including case and trailing spaces, on every supported database; numeric types compare by value, and stored text that is not a number, is longer than 1000 characters or has an exponent of four or more digits never matches. Comparison text must be valid UTF-8 without NUL bytes.

PostgreSQL and SQLite enforce one active value per owner and definition while retaining cleared history. A subsequent set can restore the newest cleared value when the owner has no active value. Localized clears retain earlier translations as history. Catalog copies inherit reviewed scopes when the owner type matches. Supply `assignment.ownerScopes` explicitly when mapping to another owner type.

## Legacy adoption

Enable the native schema on an empty standalone target and retain the legacy `metafields_definitions`, `metafield_definition_assignments`, `metafields`, and `metafields_translations` tables. Freeze source and target writes during staging. Use a separate source connection when legacy and native PostgreSQL index names conflict.

`LegacyMetafieldImporter::plan($actor, $sourceLocale, $source)` requires the `metafields.import` capability and an explicit supported locale. Options accept `connection`, `owner_types` for assignment aliases, `owner_morphs` for value owner aliases, and `reference_types` for reference aliases. `owner_ids` and `reference_ids` map original type and key pairs to target keys. Adopted definitions seed copy in the single source locale. Existing definition, assignment, value and value-translation IDs and timestamps are preserved.

Review the plan and resolve its conflicts before calling `apply($actor, $sourceLocale, $fingerprint, $source)`. Retrying with the same fingerprint reuses exact staged rows. Call `verify($actor, $sourceLocale, $fingerprint, $source)` before switching the host's readers and writers.

For large graphs, run the same adoption through `php artisan nvl:adopt metafields plan|apply|verify|activate manifest.json`. The manifest holds `locale` plus the options above. Definitions and their copy, assignments, values and value translations commit in keyset batches with a checkpoint each; resume a failed apply with `--run`, then verify and activate before switching readers and writers. A resume first re-maps the source rows behind each stored checkpoint and stops when a staged row or definition copy is missing or differs. Deleted assignment generations are adopted beside their active replacement; only active rows must be unique per definition and owner type.

## Events and diagnostics

`MetafieldDefinitionCatalogGrantAudited`, `MetafieldSet`, and `MetafieldsSynced` contain scalar identities and revisions and dispatch after the source transaction commits. See [events](docs/events.md). Run `php artisan nvl:metafields:doctor --strict --format=json` for package diagnostics. Core does not register a shared `nvl:doctor` by default.

Definition CLI mutations use `SystemActor::Background`. Grant the appropriate `metafields.*` capability and native definition/owner policy explicitly; running a command supplies no background bypass.

## Verify

From the suite root, run the owning Pest suite with its package configuration:

```sh
vendor/bin/pest --test-directory=packages/nvl/metafields/tests --configuration=packages/nvl/metafields/phpunit.xml.dist --bootstrap=vendor/autoload.php
```

Tests are not part of the distributed package.

Shared [service contracts, model extensions and composition](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/consumer-api.md) apply alongside the package-specific rules above.
