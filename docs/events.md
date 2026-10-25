# NVL Metafields events

The [event catalog](../resources/event-catalog.json) records the native payload schema. The package publishes canonical events through Core's `DomainEventDispatcher` on the source connection after its outer transaction commits. Rollback discards the callback. Delivery is not a durable outbox.

| Event | Producer | Payload |
| --- | --- | --- |
| `Nvl\Metafields\Events\MetafieldDefinitionCatalogGrantAudited` | `MetafieldOwnerCatalog::grant` and `revoke` | `operation`, `grantId`, `ownerScopeKey`, `definitionId`, `sourceRevision`, `grantRevision`, `schemaVersion` |
| `Nvl\Metafields\Events\MetafieldSet` | `MetafieldValues::set` | `metafieldId`, `ownerType`, `ownerId`, `definitionId`, `revision`, `schemaVersion` |
| `Nvl\Metafields\Events\MetafieldsSynced` | `MetafieldValues::sync` | `ownerType`, `ownerId`, `metafieldIds`, `schemaVersion` |

All three events capture an `OwnershipJobEnvelope` at construction. Their public fields contain scalar identities, revisions, and synchronized UUID lists. They contain no owner model, reference target, or stored metafield value. Listeners must authorize subsequent storage reads in the captured ownership context. `schemaVersion` is currently `1`.

A `set` call uses the canonical sync pipeline and publishes a sync fact and a set fact after successful commit. A direct `sync` publishes one sync fact with identifiers of returned values. Host listener and queue policies can add their own deferral. Use canonical event names in listeners and fakes.
