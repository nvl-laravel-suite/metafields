# NVL metafields events

This document describes the implemented source behavior. Executable acceptance proof is pending the final testing phase. The authoritative machine-readable schema is [event-catalog.json](../resources/event-catalog.json), catalog version `1`. Event `schemaVersion` is independent of catalog version.

## Publication and listener timing

The native host dispatcher receives the captured event after the supplied source connection outer commit, or immediately when that connection has no active transaction.

Callbacks attach to the matching native connection and current nesting record; native outer/savepoint rollback discards the corresponding callbacks.

Missing source transaction records fail before commit; Mail Notifications reports and drops unusable observations.

Host after-commit listeners and queue after_commit policies can add their own deferral after publication. Host transaction infrastructure and dispatcher bindings are preserved.

Local callbacks are not an outbox. Process exit between commit and callback can lose delivery; no crash durability or exactly-once delivery is promised.

One canonical object is dispatched per qualifying producer call. This is local publication, not cross-process deduplication or a guarantee that repeated observations are unique.

Use Nvl\Support\Events\DomainEventDispatcher::dispatch($event, $writerConnection). Native Event::dispatch() is immediate and has no package interception.

## Payload security and no-op behavior

Value-free metafield/definition/grant and persisted morph-owner identities, revisions and synchronized UUID lists. No owner/model/collection or stored metafield value.

Existing set/sync/grant revision guards govern publication; synced emits the identifiers of the returned synchronized set. No stored value is copied. No blanket request deduplication is added.

Actor/owner identifiers do not grant access. Listeners must preserve the captured ownership and apply their own authorization when reading storage. Readonly payload fields and native value objects are schema facts; public constructors with mixed arrays do not create a new recursive sanitization boundary. Package producer shapes are documented below; hosts must not attach models, mutable service objects or private arbitrary data.

## Canonical events

| Event | Schema version | Trigger |
| --- | --- | --- |
| [MetafieldDefinitionCatalogGrantAudited](#metafielddefinitioncataloggrantaudited) | 1 | Definition catalog tenant grant changed. |
| [MetafieldSet](#metafieldset) | 1 | Metafield revision persisted. |
| [MetafieldsSynced](#metafieldssynced) | 1 | Owner synchronization completed. |

### MetafieldDefinitionCatalogGrantAudited

`Nvl\Metafields\Events\MetafieldDefinitionCatalogGrantAudited` · [source](../src/Events/MetafieldDefinitionCatalogGrantAudited.php) · event schema `1`.

Definition catalog tenant grant changed.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$operation` | `string` | public | `required` | — |
| `$grantId` | `string` | public | `required` | — |
| `$tenantId` | `string` | public | `required` | — |
| `$definitionId` | `string` | public | `required` | — |
| `$sourceRevision` | `int` | public | `required` | — |
| `$grantRevision` | `int` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$operation` | `string` | — |
| `$grantId` | `string` | — |
| `$tenantId` | `string` | — |
| `$definitionId` | `string` | — |
| `$sourceRevision` | `int` | — |
| `$grantRevision` | `int` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Actions/GrantMetafieldDefinitionToTenantAction.php](../src/Actions/GrantMetafieldDefinitionToTenantAction.php) | `$grant->getConnection()` |
| [Actions/RevokeMetafieldDefinitionTenantGrantAction.php](../src/Actions/RevokeMetafieldDefinitionTenantGrantAction.php) | `$grant->getConnection()` |

### MetafieldSet

`Nvl\Metafields\Events\MetafieldSet` · [source](../src/Events/MetafieldSet.php) · event schema `1`.

Metafield revision persisted.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$metafieldId` | `string` | public | `required` | — |
| `$ownerType` | `string` | public | `required` | — |
| `$ownerId` | `int\|string` | public | `required` | — |
| `$definitionId` | `string` | public | `required` | — |
| `$revision` | `int` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$metafieldId` | `string` | — |
| `$ownerType` | `string` | — |
| `$ownerId` | `int\|string` | — |
| `$definitionId` | `string` | — |
| `$revision` | `int` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Actions/Metafields/SetMetafieldAction.php](../src/Actions/Metafields/SetMetafieldAction.php) | `$metafield->getConnection()` |

Deprecated alias: `Nvl\Metafields\Events\MetafieldSetEvent` ([shim](../src/Events/MetafieldSetEvent.php)). It is the same canonical class with this constructor.

### MetafieldsSynced

`Nvl\Metafields\Events\MetafieldsSynced` · [source](../src/Events/MetafieldsSynced.php) · event schema `1`.

Owner synchronization completed.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$ownerType` | `string` | public | `required` | — |
| `$ownerId` | `int\|string` | public | `required` | — |
| `$metafieldIds` | `array` | public | `required` | `list<string>` |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$ownerType` | `string` | — |
| `$ownerId` | `int\|string` | — |
| `$metafieldIds` | `array` | `list<string>` |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Actions/Metafields/SyncOwnerMetafieldsAction.php](../src/Actions/Metafields/SyncOwnerMetafieldsAction.php) | `(new Metafield)->getConnection()` |

Deprecated alias: `Nvl\Metafields\Events\MetafieldsSyncedEvent` ([shim](../src/Events/MetafieldsSyncedEvent.php)). It is the same canonical class with this constructor.

## Major 5 listener and queue migration

Legacy names are deprecated for major 5 and removed no earlier than major 6. class_alias preserves imports, instanceof, listener type hints and the new versioned constructor, not the former model-bearing API.

The native Laravel exact-listener bridge reads getRawListeners at delivery time and prepares legacy listeners through makeListener; strict-identical canonical registrations are skipped. Late registration, subscribers, cached discovery and queued listener preparation use the native dispatcher seams; proof is pending.

One canonical object is emitted once. Native wildcard/interface listeners see the canonical event once; suffix-specific *Event wildcards must migrate. The bridge does not redispatch a legacy string.

Drain old queued model-bearing payloads and restart workers before upgrade. New serialized alias instances restore the canonical versioned class without model restoration.

Event fakes/filters and assertions use canonical class names. EventAliases::canonicalName() helps migrate old names; old fake filters are not automatically rewritten.

Host custom dispatchers are retained. Explicit EventAliases::listen($event, $listener) maps through the canonical adapter; absent native bridging/host adapter, Doctor reports the deprecated-name limitation.

```php
$aliases = app(\Nvl\Support\Events\EventAliases::class);
$canonical = $aliases->canonicalName($legacyClass);
$aliases->listen($canonical, $listener);
```

## Owner identity representation

`MetafieldSet` captures the persisted `metafieldable_type` and `metafieldable_id` columns from the returned row. `MetafieldsSynced` captures the configured owner alias and the native owner primary key, preserving integer or string identity, plus a contiguous list of the returned metafield UUIDs. Neither event carries stored values or owner models.

## Deferred acceptance checks

Final testing must compare catalog types/defaults/aliases with actual classes, recursively inspect producer payloads, and prove source outer commit, nested rollback, unrelated connection independence and retry behavior without an uncommitted test-harness transaction. Where applicable it must cover legacy exact/cached/queued listeners, canonical fakes and wildcard delivery, tenant capture, package no-op guards and observational failure containment. This document does not report those checks as passing.
