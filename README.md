# NVL Metafields — API and usage

[← NVL Laravel Suite](https://github.com/nvl-laravel-suite)

For support, [open an issue](https://github.com/nvl-laravel-suite/metafields/issues). For vulnerabilities, use
[private reporting](https://github.com/nvl-laravel-suite/metafields/security/advisories/new). See [Contributing](CONTRIBUTING.md).

See the [installation and publishing guide](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/installation.md) for Composer setup, configuration, migration ownership, and agent skills.

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/metafields:^5.0` |
| Module identifier | `nvl/metafields` |
| PHP namespace | `Nvl\Metafields` |
| Service provider | `Nvl\Metafields\Providers\MetafieldsServiceProvider` |
| Configuration | `config/nvl-metafields.php` |

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

- PHP 8.4 or newer
- Laravel 13
- `nvl/core`
- `nvl/tenancy`
- `nvl/translatable`
- `nvl/translatable`

Package-owned rows use UUID primary keys. Polymorphic owner and referenced
identifiers are stored as strings, allowing integer, UUID, ULID, and other
stable application keys.

## Installation

```bash
composer require nvl/metafields:^5.0
php artisan migrate
php artisan vendor:publish --tag=nvl-metafields-config
```

Package discovery registers `MetafieldsServiceProvider`. Migrations load
automatically unless `nvl-metafields.migrations.enabled` is false. Optional
resources are published with:

```bash
php artisan vendor:publish --tag=nvl-metafields-translations
php artisan vendor:publish --tag=nvl-metafields-skills
```

Choose exactly one migration owner. For automatic vendor loading, leave
`nvl-metafields.migrations.enabled=true` and do not publish
`nvl-metafields-migrations`. For host-owned migrations, publish
`nvl-metafields-migrations` with
`php artisan vendor:publish --tag=nvl-metafields-migrations`, set
`nvl-metafields.migrations.enabled=false` before the first migration, and maintain
the copied files as application migrations.
Never run both sources; Laravel retimestamps published migrations.

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
`nvl-metafields.limits.maximum_sync_items` items per request (100 by default).

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

For list screens, inject `ListAuthorizedOwnersMetafieldsContract` and bind an
explicit host `MetafieldBatchAuthorization` SQL adapter:

```php
use Nvl\Metafields\Contracts\ListAuthorizedOwnersMetafieldsContract;
use Nvl\Metafields\Contracts\MetafieldBatchAuthorization;

$this->app->bind(MetafieldBatchAuthorization::class, App\Authorization\MetafieldBatchPolicy::class);
$fields = app(ListAuthorizedOwnersMetafieldsContract::class)->execute($articles->all(), 'bg');
$articleFields = $fields->owners->{$article->getMorphClass()}->{(string) $article->getKey()}->fields;
```

Results contain JSON object maps at both the native morph-type and owner-key
levels, plus separate request `order`. Assignment capability aliases retain their
existing meaning; they never replace native identity in values or result keys.
The reader reloads canonical owners through retained host scopes and active Tenancy;
absent/deleted owners and foreign connections fail closed.

All policy methods are query-free. Authorize canonical owners, restrict assignments
and values in SQL before reads, decide loaded definition visibility and admit stored
**and default** reference targets through loaded `MetafieldReferenceFacts`. Keep
`scopeAssignments` consistent with `allowsDefinition`. SQL callback OR clauses are
nested inside mandatory package predicates. Existing imperative Gate callbacks
require a batch adapter; missing adapters raise `MetafieldBatchReadException` with
the binding to provide. Existing host bindings take precedence.

Limits are 100 input entries, 100 active definitions per native owner type, 10,000
current values, 1,000 distinct stored/default references and ten requested/fallback
locales. Exact identities deduplicate with first-request order preserved. Overflow,
missing targets and denied targets reject the batch. Target models remain internal;
only admitted identifiers reach DTOs. Empty input returns `{}` without storage SQL.

With the same owner-class mix, locale chain and populated field options, localized
reads use seven SQL queries at 1/25/100 owners, including real active Tenancy.
Disabled Tenancy adds one cold installation-table probe (eight total, seven warm).
Each reference target class adds one grouped query; additional concrete owner
classes add grouped admission queries. No single-owner Action or translated model
getter runs in the batch projection.

`HasMetafields::whereNvlMetafield($handle, $value, $policy, $operator = '=')` adds
a correlated **stored-value-only** filter retaining caller columns and scopes.
It admits live owners only: native `SoftDeletes` columns remain mandatory even
after `withoutGlobalScopes()` or `withTrashed()`, including custom deleted-at columns.
Models without `SoftDeletes` retain their existing behavior. Caller OR predicates
are grouped inside the live-owner, active-tenant and stored-value guards, including
direct adapter calls. Changed storage shapes and UNION queries fail before definition SQL.
Missing and default-only fields never match. Definitions must be active, assigned
and filterable. Operators are `=`, `!=`, `<`, `<=`, `>` and `>=`; null permits only
`=` and `!=`. Numeric types compare numerically. Localized, reference and structured
comparisons require a separately supported SQL adapter. `scopeHostValues` must
express owner, definition and value visibility in SQL. Native identity correlations
use exact text/binary comparisons on SQLite, PostgreSQL, MySQL and MariaDB.

From the suite root, verify the default and real tenant profiles with:

```bash
vendor/bin/pest --test-directory=packages/nvl/metafields/tests --configuration=packages/nvl/metafields/phpunit.xml.dist --bootstrap=vendor/autoload.php --compact packages/nvl/metafields/tests/Feature/BatchedOwnerReadsTest.php packages/nvl/metafields/tests/Tenancy/Feature/BatchedOwnerReadsTest.php
php tools/run-package-quality.php metafields
```

Use `ListAuthorizedOwnerMetafieldsAction` for application-facing single-owner reads:

```php
use Nvl\Metafields\Actions\Metafields\ListAuthorizedOwnerMetafieldsAction;

final readonly class ShowArticleEditor
{
    public function __construct(
        private ListAuthorizedOwnerMetafieldsAction $metafields,
    ) {}

    public function __invoke(Article $article, string $locale): array
    {
        return $this->metafields->execute($article, $locale)->all();
    }
}
```

The Action authorizes `MetafieldAbility::ViewOwner` before any storage query,
then returns the existing `OwnerMetafieldField` projection with assignments,
definitions, localized copy, typed values, and reference metadata. Its populated
projection uses at most seven queries whether one or 25 fields are returned.
The result is deliberately uncached because values, definitions, locale, and
authorization are mutation- and request-sensitive.

`ListOwnerMetafieldsAction` remains the storage-focused composition primitive
used by package adapters. New consumer management reads should use the
authorized Action instead of reproducing authorization or eager loading.

## Authorization

All optional HTTP operations call `MetafieldAuthorization`. Every reference
write calls `MetafieldReferenceAuthorization`.
`ConfiguredMetafieldAuthorization` fails closed unless named Gate abilities are
configured. `ConfiguredMetafieldReferenceAuthorization` also fails closed until
`nvl-metafields.authorization.reference_ability` is configured. Owner mutations may
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

The optional HTTP controllers invoke `MetafieldAuthorization` before delegating
to mutation Actions. Direct mutation Actions are trusted application-service
operations: they validate definitions, assignments, values, and revisions, but
do not invoke owner or definition authorization. Consumer controllers and other
user-driven compositions must authorize before calling them.

For example, with constructor-injected authorization and Actions:

```php
$this->authorization->authorizeDefinition(MetafieldAbility::CreateDefinition);
$definition = $this->createDefinition->execute($definitionPayload);

$this->authorization->authorizeOwner(MetafieldAbility::MutateOwner, $owner);
$values = $this->syncOwnerMetafields->execute($owner, $valuesPayload);
```

`MetafieldAbility` is `Nvl\Metafields\Enums\MetafieldAbility`. Use
`UpdateDefinition` for definition updates, archiving, and assignment changes;
`DeleteDefinition` for definition deletion; and `DeleteOwnerValue` with the
owner and definition for clearing one owner value. `ListAuthorizedOwnerMetafieldsAction`
performs its own `ViewOwner` authorization. Reference values independently pass
through `MetafieldReferenceAuthorization` inside value validation; permission
to reference a record does not authorize mutation of its owner.

## Optional management API

Routes are disabled by default. To enable them:

```php
'routes' => [
    'enabled' => true,
    'prefix' => 'nvl/api/v1',
    'middleware' => ['api'],
    'management_middleware' => ['auth', 'throttle:nvl.metafields.management'],
    'rate_limit_per_minute' => 60,
],
```

The resulting surface is `/nvl/api/v1/metafields/...` with route names under
`nvl.metafields.management.*`. It covers definitions, archive/delete,
registered owners, list/read, bulk synchronization, and value deletion.
Every operation is authorized. No UI is included.

## Database and adoption

### Optional tenant ownership and definition catalogs

Installing Metafields also installs inert `nvl/tenancy`; the feature remains
disabled until the host explicitly adopts it. Definitions, assignments, and
definition translations share one partition. Values and localized values always
inherit the canonical owner's tenant. A configured class is not authority:
owners and every reference target must resolve through a registered tenant
resource, and unknown classifications fail closed.

With `nvl-tenancy.sharing.metafields=copy`, a platform grant exposes only an exact
scalar definition snapshot. Import requires exact grant/source revisions, an
idempotency fingerprint, an explicit collision-free target handle, and a total
reference map. It creates an ordinary independent tenant definition with copied
locale/default/type/schema data and immutable provenance. Revocation and source
deletion block future imports without changing committed copies or values.

Adopt in maintenance through prepare → bounded backfill → verify → activate.
Only source/schema repair consistent with the immutable reviewed mapping may
resume. A changed mapping requires the pre-cutover restore or a new reviewed
prepare; dropping tenant columns is not a rollback after duplicate handles
exist.

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

DTOs register with Core's Data provider and generate under `Nvl.Metafields.*`:

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

## Shared owner identity

Declare a model once in `config/nvl-core.php`:

```php
'owners' => [Article::class],
```

Enable this package capability separately in `config/nvl-metafields.php`:

```php
'owners' => [
    'article' => ['model' => Article::class, 'label' => 'Articles', 'sections' => ['content'], 'runtime_status' => 'live'],
],
```

Declare the model class explicitly; the capability key remains its application-facing API key. Preserve supported types, sections, planned/live status, and mutation authorization. Core registration does not add the model to this package's allowlist.

Laravel's `getMorphClass()` determines stored identity. These class declarations do not install host morph maps. Keep resolvers, handlers and authorization independent; use `nvl:doctor --strict --format=json` to review legacy alias mismatches or stored identity drift. See [UPGRADING.md](UPGRADING.md) before changing the host's morph map.

## Shared consumer diagnostics

Run `php artisan nvl:doctor --strict --format=json` to combine the read-only checks from loaded NVL package providers. Errors fail the gate, and strict mode also fails warnings. This package's existing Doctor command remains available and uses the same package-owned inspection service.

## Next major: isolated schema identities

Use `nvl-metafields.tables.<logical-key>` for every table and `nvl-metafields.connection` for its database connection. Null connection inherits `nvl-core.connection`, then Laravel's default. Tables are resolved at runtime by the package table definition helper.

| Logical key | New default | Previous name |
| --- | --- | --- |
| `metafields` | `nvl_metafields_metafields` | `metafields` |
| `definitions` | `nvl_metafields_definitions` | `metafields_definitions` |
| `definitions_i18n` | `nvl_metafields_definitions_i18n` | `metafields_definitions_i18n` |
| `definition_assignments` | `nvl_metafields_definition_assignments` | `metafield_definition_assignments` |
| `i18n` | `nvl_metafields_i18n` | `metafields_i18n` |
| `tenant_grants` | `nvl_metafields_tenant_grants` | `metafield_definition_tenant_grants` |
| `tenant_grant_locks` | `nvl_metafields_tenant_grant_locks` | `metafield_definition_tenant_grant_locks` |
| `tenant_adoption_copies` | `nvl_metafields_tenant_adoption_copies` | `metafield_definition_tenant_adoption_copies` |

Migration filenames contain `nvl_metafields_`. Existing installations must complete the upgrade in `UPGRADING.md` before running new migrations. A pending creator rejects an existing target before that owned migration runs; use `nvl:schema:preflight` for an explicit whole-batch check; legacy storage with old history needs an ownership decision.

## Canonical configuration ownership

Use `nvl-metafields` settings in `config/nvl-metafields.php` and canonical package environment names. Old generic roots are foreign unless an upgrading NVL host explicitly selects them in Core's default-off compatibility. Canonical false/null/empty values win; no old roots are populated or written back. Keep logical package/resource IDs unchanged. Review [Core's rename inventory and cache/worker cutover](https://github.com/nvl-laravel-suite/core/blob/main/UPGRADING.md#major-5-canonical-configuration-and-environment).
