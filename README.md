# NVL Metafields

Typed, validated, queryable, and optionally localized custom fields for
registered Eloquent owners.

## Purpose

`nvl/metafields` supplies source-independent field definitions, owner
assignments, typed values, reference resolution, localized copy, optimistic
concurrency, and a secured optional management API. It is headless and assumes
no application model, identifier type, frontend, or authorization role.

It is not a schema-less database, unrestricted JSON query language, application
settings engine, or secret store.

## Requirements and dependencies

- PHP 8.3 or 8.4
- Laravel 12 or 13
- `nvl/data`
- `nvl/support`
- `nvl/translatable`

Package-owned rows use UUID primary keys. Polymorphic owner and referenced
identifiers are stored as strings, allowing integer, UUID, ULID, and other
stable application keys.

## Installation

```bash
composer require nvl/laravel-suite:^1.0
php artisan migrate
php artisan vendor:publish --tag=metafields-config
php artisan vendor:publish --tag=metafields-migrations
```

Package discovery registers `MetafieldsServiceProvider`. Migrations load
automatically unless `metafields.migrations.enabled` is false. Optional
resources are published with:

```bash
php artisan vendor:publish --tag=metafields-translations
php artisan vendor:publish --tag=metafields-skills
```

English and Bulgarian validation copy ships with the package.

## Register owners and references

Every owner uses a stable alias and an explicit allowlist:

```php
return [
    'owners' => [
        'articles' => [
            'model' => Domain\Content\Article::class,
            'label' => 'Articles',
            'supported_types' => [
                'string',
                'text',
                'rich_text',
                'integer',
                'decimal',
                'boolean',
                'date',
                'datetime',
                'json',
                'enum',
                'reference',
                'reference_list',
            ],
            'sections' => ['content', 'publishing'],
            'runtime_status' => 'live',
        ],
    ],

    'reference_models' => [
        'authors' => Domain\People\Author::class,
    ],
];
```

Persisted definitions and polymorphic owner rows store stable aliases, not PHP
class names. The owner registry rejects invalid models, types, sections,
duplicate models, inheritance-ambiguous owners, conflicting application morph
maps, and duplicate or empty aliases. The reference registry also requires one
stable alias per model. Reference values must resolve through the reference
allowlist, identify an existing record, and pass the consumer-owned reference
authorization boundary.

## Definition localization

Definition title, description, hint, localized defaults, and presentation
properties live only in `metafields_definitions_i18n`. Base definition
copy columns are intentionally absent from the clean schema. Applications
adopting an unrelated or pre-package table must move base copy into the
configured locale through their application-owned bridge.

`metafields.definitions` and `metafields.values` automatically register with
the central `nvl/translatable` resource registry.

## Create a definition

External input must use `validateAndCreate()`:

```php
use Nvl\Metafields\Actions\MetafieldDefinitions\CreateMetafieldDefinitionAction;
use Nvl\Metafields\Data\CreateMetafieldDefinitionPayload;

$payload = CreateMetafieldDefinitionPayload::validateAndCreate([
    'namespace' => 'content',
    'key' => 'editor_note',
    'type' => 'rich_text',
    'isTranslatable' => true,
    'assignment' => [
        'ownerType' => 'articles',
        'section' => 'content',
        'isRequired' => false,
        'isActive' => true,
    ],
    'translations' => [
        'en' => [
            'title' => 'Editor note',
            'description' => 'Localized supporting copy.',
        ],
        'bg' => [
            'title' => 'Бележка на редактора',
        ],
    ],
]);

$definition = app(CreateMetafieldDefinitionAction::class)->execute($payload);
```

Translation maps accept `title`, `description`, `hint`, `defaultValue`, and
`properties`. Definition copy is always localized. Only value types that
support localization may set `isTranslatable`.

Updates use `UpdateMetafieldDefinitionPayload` and require
`expectedRevision`. Omitted optional definition fields are preserved, explicit
nulls clear nullable fields, and existing localized rows patch only supplied
fields. A title is required only when a new locale is introduced. Shape-changing
updates are rejected while active owner values would become unreadable.

Available definition Actions are:

- `CreateMetafieldDefinitionAction`
- `UpdateMetafieldDefinitionAction`
- `ArchiveMetafieldDefinitionAction`
- `DeleteMetafieldDefinitionAction`
- `ListMetafieldDefinitionsAction`

## Types and validation

Supported value types are:

- string, text, and rich text
- integer, decimal, and float
- boolean
- date and date-time
- JSON with a bounded property schema
- array
- enum
- single reference and reference list
- URL and color

The JSON boundary limits encoded bytes, depth, item count, recursion, and
schema properties. JSON definitions require a declared property schema and
cannot use unrestricted custom JSON paths. Non-JSON types may add only
allowlisted Laravel validation rules. The same structured-value limits apply
to array defaults, localized presentation properties, and assignment UI
configuration. Bulk owner synchronization accepts at most
`metafields.limits.maximum_sync_items` items per request (100 by default).

References are checked for allowed alias, identifier shape, record existence,
and consumer authorization before persistence. Raw configurable validation
rules cannot perform database queries, network lookups, or arbitrary regular
expressions.

## Synchronize owner values

`SyncOwnerMetafieldsAction` is the canonical bulk write path:

```php
use Nvl\Metafields\Actions\Metafields\SyncOwnerMetafieldsAction;
use Nvl\Metafields\Data\SyncOwnerMetafieldsPayload;

$payload = SyncOwnerMetafieldsPayload::validateAndCreate([
    'items' => [
        [
            'definitionId' => $definition->id,
            'translations' => [
                'en' => 'Handle with care.',
                'bg' => 'Работете внимателно.',
            ],
            'translationMode' => 'patch',
            'expectedRevision' => 1,
        ],
    ],
]);

$values = app(SyncOwnerMetafieldsAction::class)->execute($article, $payload);
```

Use `patch` to preserve omitted localized rows and `replace` to remove them.
Creating a value omits `expectedRevision`; every update or clear must provide
the current revision. The action acquires current-row locks inside its
transaction, rejects stale revisions, rolls back the entire payload if any item
fails, and dispatches `MetafieldsSyncedEvent` after commit. Recreating a cleared
value does not require the hidden revision of its soft-deleted storage row.

Focused operations are available through `SetMetafieldAction`,
`DeleteOwnerMetafieldAction`, and `ListOwnerMetafieldsAction`.

## Querying

Definitions are indexed by namespace, key, active handle, archive state, and
assignment. Values are indexed by definition and polymorphic owner. Query
helpers operate on registered definitions and supported scalar values; raw
request columns, relations, and arbitrary JSON paths are never accepted.

Load definition and translation relationships before serializing collections
to avoid N+1 queries.

## Authorization

All optional HTTP operations call `MetafieldAuthorization`. Every reference
write calls `MetafieldReferenceAuthorization`.
`ConfiguredMetafieldAuthorization` fails closed unless named Gate abilities are
configured. `ConfiguredMetafieldReferenceAuthorization` also fails closed until
`metafields.authorization.reference_ability` is configured. Owner mutations may
fall back to the owner's `update` policy only after the owner has been resolved
from its registered alias.

For application-specific rules, bind the contract:

```php
$app->bind(
    Nvl\Metafields\Contracts\MetafieldAuthorization::class,
    Domain\Security\MetafieldAuthorizer::class,
);

$app->bind(
    Nvl\Metafields\Contracts\MetafieldReferenceAuthorization::class,
    Domain\Security\MetafieldReferenceAuthorizer::class,
);
```

Programmatic callers are responsible for invoking the same authorization
boundary before accepting untrusted input.

## Optional management API

Routes are disabled by default. To enable them:

```php
'routes' => [
    'enabled' => true,
    'prefix' => 'api/v1',
    'middleware' => ['api'],
    'management_middleware' => ['auth', 'throttle:metafields-management'],
    'rate_limit_per_minute' => 60,
],
```

The resulting surface is `/api/v1/metafields/...` with route names under
`nvl.metafields.management.*`. It covers definitions, archive/delete,
registered owners, list/read, bulk synchronization, and value deletion.
Every operation is authorized. No UI is included.

## Database and adoption

The package owns:

- `metafields_definitions`
- `metafields_definitions_i18n`
- `metafield_definition_assignments`
- `metafields`
- `metafields_i18n`

Definition and value rows carry integer revisions. Active definition handles
are unique, and each owner/definition pair reuses one soft-deletable value row.
Owner-first composite indexes support runtime reads. Because the package has no
published migration history, clean-install create migrations define the
complete schema directly and fail loudly if package-owned table names collide.

Applications adopting existing tables may temporarily set:

```php
'migrations' => ['enabled' => false],
```

Then inspect without mutation:

```bash
php artisan nvl:metafields:doctor --strict --format=json
```

The doctor checks tables, required columns, ordered index columns and
uniqueness, owner and reference registrations, both authorization bindings,
and optional route authentication and rate limiting.

Operational commands are:

```bash
php artisan nvl:metafields:list
php artisan nvl:metafields:definition-add
php artisan nvl:metafields:definition-remove content.editor_note
php artisan nvl:metafields:doctor --strict
```

Review [UPGRADING.md](UPGRADING.md) before adopting an existing schema.

## TypeScript

DTOs register with `nvl/data` and generate under `Nvl.Metafields.*`:

```bash
php artisan nvl:data:types:generate
php artisan nvl:data:types:check
```

Mutation DTOs are write contracts. Display DTOs resolve localized copy and
never expose arbitrary application model state.

## Failure behavior

- Unknown owners, definitions, reference aliases, and records fail closed.
- Stale revisions raise package-specific concurrency exceptions.
- Invalid type changes and oversized or malformed payloads fail before writes.
- Actions own their transactions; success events dispatch after commit.
- APIs remain absent when disabled.
- The package does not swallow database, cast, or reference failures.

## Development

```bash
composer install
composer quality
```

The isolated Pest suite covers all declared type casts, definition and value
localization, patch/replace behavior, references, identifier strategies,
authorization, revision enforcement, uniqueness, JSON bounds, management
routes, schema diagnostics, and the consumer workflow above. CI runs the
stateful package suite on SQLite, PostgreSQL, and MySQL.

See [SECURITY.md](SECURITY.md), [UPGRADING.md](UPGRADING.md),
[CONTRIBUTING.md](CONTRIBUTING.md), and [CHANGELOG.md](CHANGELOG.md).

## License

Released under the [MIT License](LICENSE).
