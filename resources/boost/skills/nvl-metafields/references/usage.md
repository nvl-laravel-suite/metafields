# Metafields host setup and native usage

Use [installation](installation.md) for setup and [API](api.md) for full declarations.
`App\...` names below are application-owned example types.

## Registered owner and reference capabilities

```php
config(['nvl-metafields.owners.article' => [
    'model' => \App\Models\Article::class,
    'label' => 'Articles',
    'supported_types' => ['string', 'integer', 'reference'],
    'sections' => ['general'],
    'runtime_status' => 'live',
]]);
config(['nvl-metafields.reference_models.collection' => \App\Models\Collection::class]);
```

Configure before use. `MetafieldOwnerRegistry::register($type,$model,$label,$sections)`
provides code-owned composition registration when needed. Do not register the same
model under competing aliases. Model class references/shared aliases must retain
host-authored morph identity and exact keys; registration is capability, not policy.
A planned owner declaration is not live writable storage. Reference targets include
registered owner models plus explicit `reference_models` aliases.

## Capability Gates and owner/reference policy

Every service first checks a native `metafields.<operation>` Gate: read, write,
create, update, archive, delete, grant, revoke or import according to the method.
Human actors require canonical persisted Eloquent identity; background work uses
explicit SystemActor and still passes Gate policy. Neither an ambient guard nor
null supplies authority.

`MetafieldAuthorization` is the additional definition/owner seam. Configure
`authorization.definition_ability`, `owner_ability`, `reference_ability` or bind
actual host contract implementations:

```php
$this->app->bind(\Nvl\Metafields\Contracts\MetafieldAuthorization::class,
    \App\Metafields\HostMetafieldAuthorization::class);
$this->app->bind(\Nvl\Metafields\Contracts\MetafieldReferenceAuthorization::class,
    \App\Metafields\HostReferenceAuthorization::class);
```

The default definition adapter uses configured ability with capability string and
optional definition; without one it uses native create/read/update/delete abilities.
Archive additionally checks coarse `metafields.archive` and UpdateDefinition.
The default owner adapter uses configured ability with capability string, owner
and optional definition, otherwise the host owner's `update` policy. Single read
therefore needs an explicit policy choice rather than assuming universal visibility.
The reference adapter denies until its ability or contract is bound; it receives
canonical actor, receiving owner, definition and referenced model.

For batches bind `MetafieldBatchAuthorization`. The default can derive it from a
custom MetafieldAuthorization that also implements the batch contract; otherwise
nonempty batches fail closed. Methods authorize loaded owners/definitions/references
and constrain assignments, values, reference-target and correlated-host SQL.
They must not run per-owner queries. Imperative authorization does not replace SQL
visibility before ranking/filtering. Bind `MetafieldCatalogAccess` separately for
grant/revoke recipient authority; its shipped default denies.

## Define a typed field

```php
use Nvl\Metafields\Services\MetafieldDefinitions;

$definitions = app(MetafieldDefinitions::class);
$definition = $definitions->create([
    'namespace' => 'editorial', 'key' => 'strapline', 'type' => 'string',
    'isTranslatable' => false, 'isFilterable' => true,
    'translations' => ['en' => ['title' => 'Strapline']],
    'assignment' => ['ownerType' => 'article', 'section' => 'general'],
], $manager);
$definition = $definitions->update($definition, [
    'translations' => ['en' => ['description' => null]],
], $definition->revision, $manager);
```

Creation requires `namespace`, `key`, `type`, nonempty localized copy and assignment.
Namespace/key use canonical lowercase identifiers; the native handle is namespace.key.
Additional keys are `referencedModelType`, `isTranslatable`, `isRequired`,
`isFilterable`, `validationRules`, `jsonPropertySchema`, `defaultValue`, `displayOrder`.
Unknown/provenance/owner/revision fields fail. Assignment accepts `ownerType`,
`section`, `displayOrder`, `isRequired`, `isActive`, `uiConfig`. Copy rows accept
`title`, nullable `description`, `hint`, typed `defaultValue`, `properties`.

Types come from `MetafieldTypeEnum`: strings/text/rich text, integer/decimal/float,
boolean, date/datetime, JSON/array/enum, reference/reference list, URL and color.
Use the actual native validation/cast contract; for example, JSON values are JSON
strings rather than an invented arbitrary object DTO. JSON property schemas are
lists of `key`, `type`, optional `isRequired`; custom rules use the package allowlist.
Reference definitions name a registered reference alias/model capability. A schema
must admit the chosen owner type/section and every default, including references.

Update preserves omitted fields; explicit nullable default/rule/schema/copy values
clear only where permitted. It retains native immutable/unsafe-type-change guards.
Update/archive/delete take a separate expected revision. Archive's boolean explicitly
archives/restores; delete with live values requires deliberate `deleteValues=true`.
Do not silently cascade to make an editor operation succeed.

## Atomic value sync, localization and clear

```php
use Nvl\Metafields\Services\MetafieldValues;
use Nvl\Metafields\Services\MetafieldReader;

$values = app(MetafieldValues::class);
$saved = $values->sync($article, [[
    'definitionId' => $definition->id, 'value' => 'From the editorial team',
]], $editor)->first();
$saved = $values->set($article, 'editorial.strapline', 'Updated copy',
    $editor, expectedRevision: $saved->revision);
$fields = app(MetafieldReader::class)->forOwner($article, $viewer, 'en');
```

Each sync item allows exactly `definitionId`, `clear`, `value`, `translations`,
`translation_mode`, `expected_revision`. Supply one of value/translations/true clear;
`translation_mode` requires translations. Items are a nonempty bounded list with
distinct definition UUIDs. The service locks/reloads the receiving owner and current
assignments/definitions, validates the complete set and commits all or nothing.

An existing active value requires its exact revision. For a new value omit the
revision; zero is not the new-row convention here. An expected revision for a missing
value is stale. `value=null` is distinct from omission and must satisfy type/required
validation. Clear uses `['definitionId'=>$id,'clear'=>true,'expected_revision'=>$revision]`
and additionally needs `MetafieldAbility::DeleteOwnerValue` authority; required assignments cannot clear
without an admissible default. Cleared rows are absent from the returned saved list.

Localized definitions use `translations=>['en'=>$typedValue,...]`. Supported locale
keys are mandatory; null locale values are invalid. Patch retains omitted locales,
Replace removes them. Handle-based `set` requires an explicit locale for localized
definitions and an expected revision when editing an existing row. Defaults are
read-time behavior and do not imply a persisted value row or SQL filter match.

## Safe single/batch/filter reads

`definitions($actor)` returns native definitions. `forOwner` returns a collection
of `['definition'=>MetafieldDefinition,'assignment'=>...,'value'=>Metafield|null]`.
Choose a locale explicitly and map consumer output without exposing private reference
models or ownership/provenance fields. Stored/default references must remain visible
and canonical under native scopes and reference policy.

`forOwners($owners,$actor,$locale)` returns `owners` as a nested stdClass object map
keyed by native morph type and exact key, plus an ordered list of type/id identities.
Empty batches issue no metafield-data SQL; canonical actor admission may still query. Batch authorization applies before rankings and
reference loading; unavailable/denied stored or default references fail closed.
Preserve bounds on owners/definitions/values/references/locales instead of per-row
fallback queries. `Nvl\Metafields\Traits\HasMetafields` supplies model relation/filter convenience;
its `whereNvlMetafield($handle,$value,$actor,$policy,$operator)` requires an explicit
`MetafieldBatchAuthorization` policy. It compares stored scalars only for active,
filterable definitions with the host SQL policy; absent and default-only values do
not match. Nest caller OR predicates inside mandatory host/definition/value scope.

## Grants, imports, model extension and verification

`grant($definitionId,$sourceRevision,$actor)` and `revoke($grantId,$expectedRevision,
$actor)` operate under explicit native owner/recipient context and the independent
catalog access policy. Imports take `grantId`, `expectedGrantRevision`,
`expectedSourceRevision`, UUID `idempotency_key`, target `namespace`, `key`,
`assignment`, optional `referenceMap`. The request selects an exact granted immutable
source snapshot; use one key for the same retry. Imported definitions become
independent target copies, not live references to central mutable schema. Do not
accept a forged source snapshot, owner identity or provenance in editor input.

Set `nvl-metafields.models.definition` before boot. A concrete native subclass must
retain UUID, required casts, handle/default/revision hooks, relations, ownership and
package effective storage. Values, assignment, translation and grant models remain
fixed. Add columns with host migrations; preserve parent hooks and use observers or
bootable traits for extra listeners. Native Ownership is optional for unowned hosts
and requires explicit declarations, runtime admission and reviewed adoption when owned.
No Media/Content/DTO runtime is needed for basic typed fields; do not invent feature
flags or bypass reference policy when composing optional packages.

Services own source transactions, definition/owner locks and outer-commit events.
Handle validation, missing definition, stale version, required-clear and policy
failures at the application boundary without leaking SQL, private references or
catalog recipient facts. Test types/defaults, explicit null/locale modes, reference
denial, owner identity, revisions, whole-sync rollback, import replay and bounded
batch/filter privacy. Keep host HTTP/resource assertions outside domain tests.
