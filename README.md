# NVL Metafields

Typed, optionally localized custom fields for registered Eloquent owners on Laravel 13 and PHP 8.4. This package provides native services and model results; hosts own their HTTP and UI layers.

## Install and register owners

Install `nvl/metafields:^6.0` with its declared `nvl/core` and `nvl/translatable` dependencies. Use `php artisan nvl:install metafields` to publish common configuration, choose one migration owner, and run the package migrations. The installer does not run migrations or enable an ownership policy.

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

## Authorize native operations

Every public operation requires an explicit persisted `Authenticatable` actor or `SystemActor::Background`. Host Gate abilities named `metafields.create`, `metafields.update`, `metafields.archive`, `metafields.delete`, `metafields.grant`, `metafields.revoke`, `metafields.import`, `metafields.write`, and `metafields.read` authorize the respective operations. Undefined abilities deny both people and background work. The package does not infer an actor from a guard.

Owner reads also use `MetafieldAuthorization::authorizeOwner`. Configure `nvl-metafields.authorization.owner_ability` or bind that contract; the default requires the host's `update` policy for the owner. Reference writes use `MetafieldReferenceAuthorization` and deny unless the host configures `reference_ability` or binds that contract. `MetafieldBatchAuthorization` is required for nonempty multi-owner reads and supplies SQL visibility for assignments, values, and targets.

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

`MetafieldDefinitions` exposes `create(array $attributes, $actor)`, `update($definition, array $attributes, int $expectedRevision, $actor)`, `archive($definition, bool $archived, int $expectedRevision, $actor)`, `delete($definition, int $expectedRevision, $actor, bool $deleteValues = false)`, `grant(string $definitionId, int $sourceRevision, $actor)`, `revoke(string $grantId, int $expectedRevision, $actor)`, and `import(array $request, $actor)`. Definition and assignment input is allowlisted, and owner type, section, reference target, locale, default, and rules are validated against registered capabilities. `delete` requires explicit cascading when live values exist. Import uses an exact granted catalog snapshot with expected revisions and an idempotency key.

## Values and reads

```php
use Nvl\Metafields\Services\MetafieldValues;
use Nvl\Metafields\Services\MetafieldReader;

$value = app(MetafieldValues::class)->set($product, 'product.headline', 'Hello', $actor);
$fields = app(MetafieldReader::class)->forOwner($product, $actor, 'en');
```

`MetafieldValues` exposes `set(Model $owner, string $handle, mixed $value, $actor, ?string $locale = null, ?int $expectedRevision = null)`, `delete(Model $owner, MetafieldDefinition|string $definition, int $expectedRevision, $actor)`, and `sync(Model $owner, array $items, $actor)`. Each sync item has `definitionId` and either `value`, `translations`, or `clear`; an existing value requires `expectedRevision`. Localized writes accept `TranslationSyncMode::Patch` or `Replace`; omitted locales are preserved by patch and explicit null values are rejected. A sync validates every item against its persisted definition and commits atomically.

`MetafieldReader` exposes `definitions($actor)`, `forOwner(Model $owner, $actor, ?string $locale = null)`, and `forOwners(array $owners, $actor, ?string $locale = null)`. The first two return collections of native models or arrays containing `definition`, `assignment`, and nullable `value` models. The batch result contains `owners` as a nested JSON object keyed by morph type and exact owner key, plus a separate `order` list. Empty input returns an empty object map. Nonempty batches admit canonical owners under host scopes, apply SQL visibility, and reject unavailable or denied stored and default references. Batches have bounded owner, definition, value, reference, and locale limits.

For stored scalar comparisons on active filterable definitions, use `whereNvlMetafield($handle, $value, $actor, $batchPolicy, $operator = '=')` on a registered owner query. Missing and default-only values do not match.

## Events and diagnostics

`MetafieldDefinitionCatalogGrantAudited`, `MetafieldSet`, and `MetafieldsSynced` contain scalar identities and revisions and dispatch after the source transaction commits. See [events](docs/events.md). Run `php artisan nvl:metafields:doctor --strict --format=json` for package diagnostics or the shared `nvl:doctor` command for loaded providers.

## Verify

From the suite root, run the owning Pest suite with its package configuration:

```sh
vendor/bin/pest --test-directory=packages/nvl/metafields/tests --configuration=packages/nvl/metafields/phpunit.xml.dist --bootstrap=vendor/autoload.php
```

The suite's `tests/Historical` directory preserves retired HTTP, Action, DTO, and Tenancy bridge tests outside active discovery. Native service, ownership, localization, reference, and batch tests are active.
