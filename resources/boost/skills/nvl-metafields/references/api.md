# Public API: nvl/metafields

Generated from the package public contract. Use this reference to confirm fully qualified names, parameter order, defaults, native array shapes and return types. Read [usage](usage.md) for policy and operation semantics; declarations alone never grant authority.

Search for the relevant fully qualified service or contract and read only its matching symbol sections; this is a lookup catalog, not an instruction to load every declaration. `resolved` annotations expand imported aliases and domain type shapes. Framework inheritance and host-authored extensions remain outside this package contract.

## Symbols

- `Nvl\Metafields\Contracts\HasMetafieldScope`
- `Nvl\Metafields\Contracts\HasSqlMetafieldScope`
- `Nvl\Metafields\Contracts\MetafieldAuthorization`
- `Nvl\Metafields\Contracts\MetafieldBatchAuthorization`
- `Nvl\Metafields\Contracts\MetafieldCatalogAccess`
- `Nvl\Metafields\Contracts\MetafieldDiagnosticAccess`
- `Nvl\Metafields\Contracts\MetafieldReferenceAuthorization`
- `Nvl\Metafields\Database\Factories\MetafieldDefinitionAssignmentFactory`
- `Nvl\Metafields\Database\Factories\MetafieldDefinitionFactory`
- `Nvl\Metafields\Database\Factories\MetafieldDefinitionTranslationFactory`
- `Nvl\Metafields\Database\Factories\MetafieldFactory`
- `Nvl\Metafields\Database\Factories\MetafieldTranslationFactory`
- `Nvl\Metafields\Enums\MetafieldAbility`
- `Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum`
- `Nvl\Metafields\Enums\MetafieldTypeEnum`
- `Nvl\Metafields\Events\MetafieldDefinitionCatalogGrantAudited`
- `Nvl\Metafields\Events\MetafieldSet`
- `Nvl\Metafields\Events\MetafieldsSynced`
- `Nvl\Metafields\Exceptions\InvalidMetafieldMutationException`
- `Nvl\Metafields\Exceptions\MetafieldBatchReadException`
- `Nvl\Metafields\Exceptions\MetafieldDefinitionNotFoundException`
- `Nvl\Metafields\Exceptions\MetafieldException`
- `Nvl\Metafields\Exceptions\MetafieldIntegrityException`
- `Nvl\Metafields\Exceptions\StaleMetafieldVersionException`
- `Nvl\Metafields\Models\Metafield`
- `Nvl\Metafields\Models\MetafieldDefinition`
- `Nvl\Metafields\Models\MetafieldDefinitionAssignment`
- `Nvl\Metafields\Models\MetafieldDefinitionTranslation`
- `Nvl\Metafields\Models\MetafieldTranslation`
- `Nvl\Metafields\Ownership\MetafieldOwnerCatalog`
- `Nvl\Metafields\Services\LegacyMetafieldImporter`
- `Nvl\Metafields\Services\MetafieldDefinitions`
- `Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionCatalog`
- `Nvl\Metafields\Services\MetafieldModelRegistry`
- `Nvl\Metafields\Services\MetafieldReader`
- `Nvl\Metafields\Services\MetafieldValues`
- `Nvl\Metafields\Services\Metafields\OwnerMetafieldAssignmentCatalog`
- `Nvl\Metafields\Services\Metafields\OwnerMetafieldBatchReader`
- `Nvl\Metafields\Support\MetafieldOwnerRegistry`
- `Nvl\Metafields\Traits\HasMetafields`

## Nvl\Metafields\Contracts\HasMetafieldScope

```text
interface Nvl\Metafields\Contracts\HasMetafieldScope
public abstract function metafieldScopeKey(): ?string
public abstract static function metafieldScopeOptions(): array
@return list<array{value: string, label: string}>
```

## Nvl\Metafields\Contracts\HasSqlMetafieldScope

```text
interface Nvl\Metafields\Contracts\HasSqlMetafieldScope extends Nvl\Metafields\Contracts\HasMetafieldScope
public abstract static function metafieldScopeColumn(): string
public abstract function metafieldScopeKey(): ?string
public abstract static function metafieldScopeOptions(): array
@return list<array{value: string, label: string}>
```

## Nvl\Metafields\Contracts\MetafieldAuthorization

```text
interface Nvl\Metafields\Contracts\MetafieldAuthorization
public abstract function authorizeDefinition(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Metafields\Enums\MetafieldAbility $ability, ?Nvl\Metafields\Models\MetafieldDefinition $definition=null): void
public abstract function authorizeOwner(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Metafields\Enums\MetafieldAbility $ability, ?Illuminate\Database\Eloquent\Model $owner=null, ?Nvl\Metafields\Models\MetafieldDefinition $definition=null): void
```

## Nvl\Metafields\Contracts\MetafieldBatchAuthorization

```text
interface Nvl\Metafields\Contracts\MetafieldBatchAuthorization
public abstract function allowsDefinition(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model $owner, Nvl\Metafields\Models\MetafieldDefinition $definition): bool
public abstract function authorizeOwners(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Support\Owners\OwnerBatch $owners): void
public abstract function authorizeReferences(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Support\Owners\OwnerBatch $owners, array $definitions, array $values, array $facts): void
@param list<MetafieldDefinition> $definitions
resolved @param list<Nvl\Metafields\Models\MetafieldDefinition> $definitions
@param list<Metafield> $values
resolved @param list<Nvl\Metafields\Models\Metafield> $values
@param array<string, array<array-key, Model>> $facts
resolved @param array<string, array<array-key, Illuminate\Database\Eloquent\Model>> $facts
public abstract function scopeAssignments(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Builder $query, Nvl\Support\Owners\OwnerBatch $owners): void
@param Builder<MetafieldDefinitionAssignment> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinitionAssignment> $query
public abstract function scopeHostValues(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Builder $query, Illuminate\Database\Eloquent\Model $ownerPrototype, string $handle): void
@param Builder<Metafield> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield> $query
public abstract function scopeReferenceTargets(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Builder $query, string $registeredReference, Nvl\Support\Owners\OwnerBatch $owners): void
@param Builder<Model> $query
resolved @param Illuminate\Database\Eloquent\Builder<Illuminate\Database\Eloquent\Model> $query
public abstract function scopeValues(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Builder $query, Nvl\Support\Owners\OwnerBatch $owners): void
@param Builder<Metafield> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield> $query
```

## Nvl\Metafields\Contracts\MetafieldCatalogAccess

```text
interface Nvl\Metafields\Contracts\MetafieldCatalogAccess
public abstract function assertCanGrant(string $sourceId, int $revision): void
public abstract function assertCanRevoke(string $grantId): void
```

## Nvl\Metafields\Contracts\MetafieldDiagnosticAccess

```text
interface Nvl\Metafields\Contracts\MetafieldDiagnosticAccess
public abstract function allows(Nvl\Support\Enums\SystemActor $actor): bool
```

## Nvl\Metafields\Contracts\MetafieldReferenceAuthorization

```text
interface Nvl\Metafields\Contracts\MetafieldReferenceAuthorization
public abstract function authorize(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Model $owner, Nvl\Metafields\Models\MetafieldDefinition $definition, Illuminate\Database\Eloquent\Model $reference): void
@param Model $owner
resolved @param Illuminate\Database\Eloquent\Model $owner
@param MetafieldDefinition $definition
resolved @param Nvl\Metafields\Models\MetafieldDefinition $definition
@param Model $reference
resolved @param Illuminate\Database\Eloquent\Model $reference
```

## Nvl\Metafields\Database\Factories\MetafieldDefinitionAssignmentFactory

```text
final class Nvl\Metafields\Database\Factories\MetafieldDefinitionAssignmentFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<MetafieldDefinitionAssignment>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Metafields\Models\MetafieldDefinitionAssignment>
public function definition(): array
@return array<model-property<MetafieldDefinitionAssignment>, mixed>
resolved @return array<model-property<Nvl\Metafields\Models\MetafieldDefinitionAssignment>, mixed>
public function forDefinition(Nvl\Metafields\Models\MetafieldDefinition $definition): self
public function forOwnerType(string|BackedEnum $ownerType): self
public function inactive(): self
public function inSection(string $section): self
public function required(): self
```

## Nvl\Metafields\Database\Factories\MetafieldDefinitionFactory

```text
final class Nvl\Metafields\Database\Factories\MetafieldDefinitionFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<MetafieldDefinition>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Metafields\Models\MetafieldDefinition>
public function definition(): array
@return array<model-property<MetafieldDefinition>, mixed>
resolved @return array<model-property<Nvl\Metafields\Models\MetafieldDefinition>, mixed>
public function filterable(): self
public function modelName(): string
@return class-string<MetafieldDefinition>
resolved @return class-string<Nvl\Metafields\Models\MetafieldDefinition>
public function ofType(Nvl\Metafields\Enums\MetafieldTypeEnum $type): self
public function required(): self
public function translatable(): self
public function withDefaultValue(mixed $value): self
public function withJsonPropertySchema(array $schema): self
@param array<int, array{key: string, type: string, isRequired: bool}> $schema
public function withValidationRules(array $rules): self
@param list<string> $rules
```

## Nvl\Metafields\Database\Factories\MetafieldDefinitionTranslationFactory

```text
final class Nvl\Metafields\Database\Factories\MetafieldDefinitionTranslationFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<MetafieldDefinitionTranslation>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Metafields\Models\MetafieldDefinitionTranslation>
public function definition(): array
@return array<model-property<MetafieldDefinitionTranslation>, mixed>
resolved @return array<model-property<Nvl\Metafields\Models\MetafieldDefinitionTranslation>, mixed>
public function forDefinition(Nvl\Metafields\Models\MetafieldDefinition $parent): static
```

## Nvl\Metafields\Database\Factories\MetafieldFactory

```text
final class Nvl\Metafields\Database\Factories\MetafieldFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<Metafield>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Metafields\Models\Metafield>
public function definition(): array
@return array<model-property<Metafield>, mixed>
resolved @return array<model-property<Nvl\Metafields\Models\Metafield>, mixed>
public function forDefinition(Nvl\Metafields\Models\MetafieldDefinition $definition): self
public function forOwner(Illuminate\Database\Eloquent\Model $owner): static
public function withValue(mixed $value): self
```

## Nvl\Metafields\Database\Factories\MetafieldTranslationFactory

```text
final class Nvl\Metafields\Database\Factories\MetafieldTranslationFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<MetafieldTranslation>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Metafields\Models\MetafieldTranslation>
public function definition(): array
@return array<model-property<MetafieldTranslation>, mixed>
resolved @return array<model-property<Nvl\Metafields\Models\MetafieldTranslation>, mixed>
public function forMetafield(Nvl\Metafields\Models\Metafield $parent): static
public function forOwner(Illuminate\Database\Eloquent\Model $owner): static
```

## Nvl\Metafields\Enums\MetafieldAbility

```text
enum Nvl\Metafields\Enums\MetafieldAbility implements BackedEnum, UnitEnum: string
public const Nvl\Metafields\Enums\MetafieldAbility CreateDefinition=Nvl\Metafields\Enums\MetafieldAbility::CreateDefinition="create_definition"
public const Nvl\Metafields\Enums\MetafieldAbility DeleteDefinition=Nvl\Metafields\Enums\MetafieldAbility::DeleteDefinition="delete_definition"
public const Nvl\Metafields\Enums\MetafieldAbility DeleteOwnerValue=Nvl\Metafields\Enums\MetafieldAbility::DeleteOwnerValue="delete_owner_value"
public const Nvl\Metafields\Enums\MetafieldAbility ListDefinitions=Nvl\Metafields\Enums\MetafieldAbility::ListDefinitions="list_definitions"
public const Nvl\Metafields\Enums\MetafieldAbility ListOwners=Nvl\Metafields\Enums\MetafieldAbility::ListOwners="list_owners"
public const Nvl\Metafields\Enums\MetafieldAbility MutateOwner=Nvl\Metafields\Enums\MetafieldAbility::MutateOwner="mutate_owner"
public const Nvl\Metafields\Enums\MetafieldAbility PurgeOwnerValues=Nvl\Metafields\Enums\MetafieldAbility::PurgeOwnerValues="purge_owner_values"
public const Nvl\Metafields\Enums\MetafieldAbility UpdateDefinition=Nvl\Metafields\Enums\MetafieldAbility::UpdateDefinition="update_definition"
public const Nvl\Metafields\Enums\MetafieldAbility ViewDefinition=Nvl\Metafields\Enums\MetafieldAbility::ViewDefinition="view_definition"
public const Nvl\Metafields\Enums\MetafieldAbility ViewOwner=Nvl\Metafields\Enums\MetafieldAbility::ViewOwner="view_owner"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum

```text
enum Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum implements BackedEnum, UnitEnum: string
public const Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum Boolean=Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum::Boolean="boolean"
public const Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum Color=Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum::Color="color"
public const Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum Date=Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum::Date="date"
public const Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum DateTime=Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum::DateTime="datetime"
public const Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum Float=Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum::Float="float"
public const Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum Integer=Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum::Integer="integer"
public const Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum RichText=Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum::RichText="rich_text"
public const Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum String=Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum::String="string"
public const Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum Text=Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum::Text="text"
public const Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum Url=Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum::Url="url"
public function getValidationRules(): array
@return list<string>
public function toMetafieldType(): Nvl\Metafields\Enums\MetafieldTypeEnum
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Metafields\Enums\MetafieldTypeEnum

```text
enum Nvl\Metafields\Enums\MetafieldTypeEnum implements BackedEnum, UnitEnum: string
public const Nvl\Metafields\Enums\MetafieldTypeEnum ArrayValue=Nvl\Metafields\Enums\MetafieldTypeEnum::ArrayValue="array"
public const Nvl\Metafields\Enums\MetafieldTypeEnum Boolean=Nvl\Metafields\Enums\MetafieldTypeEnum::Boolean="boolean"
public const Nvl\Metafields\Enums\MetafieldTypeEnum Color=Nvl\Metafields\Enums\MetafieldTypeEnum::Color="color"
public const Nvl\Metafields\Enums\MetafieldTypeEnum Date=Nvl\Metafields\Enums\MetafieldTypeEnum::Date="date"
public const Nvl\Metafields\Enums\MetafieldTypeEnum DateTime=Nvl\Metafields\Enums\MetafieldTypeEnum::DateTime="datetime"
public const Nvl\Metafields\Enums\MetafieldTypeEnum Decimal=Nvl\Metafields\Enums\MetafieldTypeEnum::Decimal="decimal"
public const Nvl\Metafields\Enums\MetafieldTypeEnum Enum=Nvl\Metafields\Enums\MetafieldTypeEnum::Enum="enum"
public const Nvl\Metafields\Enums\MetafieldTypeEnum Float=Nvl\Metafields\Enums\MetafieldTypeEnum::Float="float"
public const Nvl\Metafields\Enums\MetafieldTypeEnum Integer=Nvl\Metafields\Enums\MetafieldTypeEnum::Integer="integer"
public const Nvl\Metafields\Enums\MetafieldTypeEnum Json=Nvl\Metafields\Enums\MetafieldTypeEnum::Json="json"
public const Nvl\Metafields\Enums\MetafieldTypeEnum Reference=Nvl\Metafields\Enums\MetafieldTypeEnum::Reference="reference"
public const Nvl\Metafields\Enums\MetafieldTypeEnum ReferenceList=Nvl\Metafields\Enums\MetafieldTypeEnum::ReferenceList="reference_list"
public const Nvl\Metafields\Enums\MetafieldTypeEnum RichText=Nvl\Metafields\Enums\MetafieldTypeEnum::RichText="rich_text"
public const Nvl\Metafields\Enums\MetafieldTypeEnum String=Nvl\Metafields\Enums\MetafieldTypeEnum::String="string"
public const Nvl\Metafields\Enums\MetafieldTypeEnum Text=Nvl\Metafields\Enums\MetafieldTypeEnum::Text="text"
public const Nvl\Metafields\Enums\MetafieldTypeEnum Url=Nvl\Metafields\Enums\MetafieldTypeEnum::Url="url"
public function cast(mixed $value): mixed
public function getValidationRules(): array
@return array<string>
public function storeCast(mixed $value): mixed
public function supportsTranslations(): bool
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Metafields\Events\MetafieldDefinitionCatalogGrantAudited

```text
final readonly class Nvl\Metafields\Events\MetafieldDefinitionCatalogGrantAudited implements Nvl\Support\Contracts\DomainEvent, Nvl\Support\Ownership\Contracts\OwnershipQueuedJob
public function __construct(string $operation, string $grantId, string $ownerScopeKey, string $definitionId, int $sourceRevision, int $grantRevision, int $schemaVersion=1): mixed
public function ownershipJobEnvelope(): Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope
public function schemaVersion(): int
public readonly string $definitionId
public readonly string $grantId
public readonly int $grantRevision
public readonly string $operation
public readonly string $ownerScopeKey
public readonly int $schemaVersion
public readonly int $sourceRevision
```

## Nvl\Metafields\Events\MetafieldSet

```text
final readonly class Nvl\Metafields\Events\MetafieldSet implements Nvl\Support\Contracts\DomainEvent, Nvl\Support\Ownership\Contracts\OwnershipQueuedJob
public function __construct(string $metafieldId, string $ownerType, int|string $ownerId, string $definitionId, int $revision, int $schemaVersion=1): mixed
public function ownershipJobEnvelope(): Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope
public function schemaVersion(): int
public readonly string $definitionId
public readonly string $metafieldId
public readonly int|string $ownerId
public readonly string $ownerType
public readonly int $revision
public readonly int $schemaVersion
```

## Nvl\Metafields\Events\MetafieldsSynced

```text
final readonly class Nvl\Metafields\Events\MetafieldsSynced implements Nvl\Support\Contracts\DomainEvent, Nvl\Support\Ownership\Contracts\OwnershipQueuedJob
public function __construct(string $ownerType, int|string $ownerId, array $metafieldIds, int $schemaVersion=1): mixed
@param list<string> $metafieldIds
public function ownershipJobEnvelope(): Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope
public function schemaVersion(): int
public readonly array $metafieldIds
@param list<string> $metafieldIds
public readonly int|string $ownerId
public readonly string $ownerType
public readonly int $schemaVersion
```

## Nvl\Metafields\Exceptions\InvalidMetafieldMutationException

```text
final class Nvl\Metafields\Exceptions\InvalidMetafieldMutationException extends InvalidArgumentException
```

## Nvl\Metafields\Exceptions\MetafieldBatchReadException

```text
final class Nvl\Metafields\Exceptions\MetafieldBatchReadException extends Nvl\Metafields\Exceptions\MetafieldException
```

## Nvl\Metafields\Exceptions\MetafieldDefinitionNotFoundException

```text
final class Nvl\Metafields\Exceptions\MetafieldDefinitionNotFoundException extends InvalidArgumentException
```

## Nvl\Metafields\Exceptions\MetafieldException

```text
abstract class Nvl\Metafields\Exceptions\MetafieldException extends RuntimeException
```

## Nvl\Metafields\Exceptions\MetafieldIntegrityException

```text
final class Nvl\Metafields\Exceptions\MetafieldIntegrityException extends Nvl\Metafields\Exceptions\MetafieldException
public static function duplicateActiveOwnerDefinitionRecords(Illuminate\Database\Eloquent\Model $owner, array $definitionIds): self
@param list<string> $definitionIds
```

## Nvl\Metafields\Exceptions\StaleMetafieldVersionException

```text
final class Nvl\Metafields\Exceptions\StaleMetafieldVersionException extends Nvl\Metafields\Exceptions\MetafieldException
public static function forResource(string $resource, string $id): self
```

## Nvl\Metafields\Models\Metafield

```text
class Nvl\Metafields\Models\Metafield extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable, Nvl\Translatable\Contracts\TranslatableModel
public const string TABLE=\Nvl\Metafields\Definitions\Tables\MetafieldsTables::Metafields
public function definition(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<MetafieldDefinition, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Metafields\Models\MetafieldDefinition, $this>
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getAllTranslations(): Illuminate\Database\Eloquent\Collection
@return Collection<int, Model>
resolved @return Illuminate\Database\Eloquent\Collection<int, Illuminate\Database\Eloquent\Model>
public function getAvailableLocales(): array
@return list<string>
public function getConnectionName(): ?string
public function getCurrentLocale(): string
public function getRelationValue(mixed $key): mixed
@param string $key
public function getTable(): string
public function getTranslatedAttributes(?string $locale=null): array
@return array<string, mixed>
public function getTranslation(?string $locale=null, bool $withFallback=true): ?Illuminate\Database\Eloquent\Model
public function getValue(?string $locale=null): mixed
public function hasTranslation(?string $locale=null): bool
public function isTranslatableAttribute(string $key): bool
public function metafieldable(): Illuminate\Database\Eloquent\Relations\MorphTo
@return MorphTo<Model, $this>
resolved @return Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, $this>
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function resolveTranslation(string $field, ?string $locale=null): Nvl\Translatable\TranslationResolution
public function save(array $options=[]): bool
@param array<string, mixed> $options
public function scopeOrderByTranslated(Illuminate\Database\Eloquent\Builder $query, string $field, string $direction="asc", ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield>
public function scopeWhereTranslated(Illuminate\Database\Eloquent\Builder $query, string $field, mixed $operator, mixed $value=\Nvl\Translatable\Enums\TranslationComparisonValue::Omitted, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield>
public function scopeWhereTranslationNotNull(Illuminate\Database\Eloquent\Builder $query, string $field, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield>
public function scopeWhereTranslationNull(Illuminate\Database\Eloquent\Builder $query, string $field, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield>
public function scopeWithAllTranslations(Illuminate\Database\Eloquent\Builder $query): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield>
public function scopeWithJoinedTranslation(Illuminate\Database\Eloquent\Builder $query, ?string $locale=null, string $alias="nvl_translation"): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield>
public function scopeWithResolvedTranslations(Illuminate\Database\Eloquent\Builder $query, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield>
public function setLocale(string $locale): self
public function translated(string $field, ?string $locale=null): mixed
public function translation(?string $locale=null): Illuminate\Database\Eloquent\Relations\HasOne
@return HasOne<Model, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasOne<Illuminate\Database\Eloquent\Model, $this>
public function translationDefinition(): Nvl\Translatable\RelatedTranslationDefinition
public function translationResourceKey(): int|string
public function translations(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<Model, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Illuminate\Database\Eloquent\Model, $this>
@method static Builder<static> orderByTranslated(string $field, string $direction = 'asc', ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield> orderByTranslated(string $field, string $direction = 'asc', ?string $locale = null)
@method static Builder<static> whereTranslated(string $field, mixed $operator, mixed $value = TranslationComparisonValue::Omitted, ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield> whereTranslated(string $field, mixed $operator, mixed $value = Nvl\Translatable\Enums\TranslationComparisonValue::Omitted, ?string $locale = null)
@method static Builder<static> whereTranslationNotNull(string $field, ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield> whereTranslationNotNull(string $field, ?string $locale = null)
@method static Builder<static> whereTranslationNull(string $field, ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield> whereTranslationNull(string $field, ?string $locale = null)
@method static Builder<static> withAllTranslations()
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield> withAllTranslations()
@method static Builder<static> withJoinedTranslation(?string $locale = null, string $alias = 'nvl_translation')
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield> withJoinedTranslation(?string $locale = null, string $alias = 'nvl_translation')
@method static Builder<static> withResolvedTranslations(?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\Metafield> withResolvedTranslations(?string $locale = null)
@property (Carbon | null) $created_at
resolved @property (Illuminate\Support\Carbon | null) $created_at
@property-read MetafieldDefinition $definition
resolved @property-read Nvl\Metafields\Models\MetafieldDefinition $definition
@property string $definition_id
@property string $id
@property-read Model $metafieldable
resolved @property-read Illuminate\Database\Eloquent\Model $metafieldable
@property string $metafieldable_id
@property string $metafieldable_type
@property (string | null) $owner_scope_key
@property (string | null) $referenced_id
@property int $revision
@property-read Collection<int, Model> $translations
resolved @property-read Illuminate\Database\Eloquent\Collection<int, Illuminate\Database\Eloquent\Model> $translations
@property (Carbon | null) $updated_at
resolved @property (Illuminate\Support\Carbon | null) $updated_at
@property (string | null) $value
```

## Nvl\Metafields\Models\MetafieldDefinition

```text
class Nvl\Metafields\Models\MetafieldDefinition extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable, Nvl\Translatable\Contracts\TranslatableModel
public const string TABLE=\Nvl\Metafields\Definitions\Tables\MetafieldsTables::Definitions
public static function allowsCentralCatalog(): bool
public function assignments(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<MetafieldDefinitionAssignment, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\Metafields\Models\MetafieldDefinitionAssignment, $this>
public function clearDefaultValue(): void
public function delete(): mixed
@return ($this is Pivot ? (bool | int | null) : (bool | null))
resolved @return ($this is Illuminate\Database\Eloquent\Relations\Pivot ? (bool | int | null) : (bool | null))
public function displayDescription(?string $locale=null): ?string
public function displayHint(?string $locale=null): ?string
public function displayTitle(?string $locale=null): string
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public static function generateHandle(string $namespace, string $key): string
public function getAllTranslations(): Illuminate\Database\Eloquent\Collection
@return Collection<int, Model>
resolved @return Illuminate\Database\Eloquent\Collection<int, Illuminate\Database\Eloquent\Model>
public function getAvailableLocales(): array
@return list<string>
public function getConnectionName(): ?string
public function getCurrentLocale(): string
public function getDefaultValue(?string $locale=null): mixed
public function getRelationValue(mixed $key): mixed
@param string $key
public function getSerializableDefaultValue(?string $locale=null): mixed
public function getTable(): string
public function getTranslatedAttributes(?string $locale=null): array
@return array<string, mixed>
public function getTranslation(?string $locale=null, bool $withFallback=true): ?Illuminate\Database\Eloquent\Model
public function hasDefaultValue(?string $locale=null): bool
public function hasTranslation(?string $locale=null): bool
public function isTranslatableAttribute(string $key): bool
public function metafields(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<Metafield, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\Metafields\Models\Metafield, $this>
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function resolveRouteBinding(mixed $value, mixed $field=null): ?Nvl\Metafields\Models\MetafieldDefinition
@param mixed $value
@param (string | null) $field
public function resolveSoftDeletableRouteBinding(mixed $value, mixed $field=null): ?Nvl\Metafields\Models\MetafieldDefinition
@param mixed $value
@param (string | null) $field
public function resolveTranslation(string $field, ?string $locale=null): Nvl\Translatable\TranslationResolution
public function save(array $options=[]): bool
@param array<string, mixed> $options
public function scopeActive(Illuminate\Database\Eloquent\Builder $query): void
@param Builder<self> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition> $query
public function scopeInNamespace(Illuminate\Database\Eloquent\Builder $query, string $namespace): void
@param Builder<self> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition> $query
public function scopeOrderByTranslated(Illuminate\Database\Eloquent\Builder $query, string $field, string $direction="asc", ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition>
public function scopeWhereTranslated(Illuminate\Database\Eloquent\Builder $query, string $field, mixed $operator, mixed $value=\Nvl\Translatable\Enums\TranslationComparisonValue::Omitted, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition>
public function scopeWhereTranslationNotNull(Illuminate\Database\Eloquent\Builder $query, string $field, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition>
public function scopeWhereTranslationNull(Illuminate\Database\Eloquent\Builder $query, string $field, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition>
public function scopeWithAllTranslations(Illuminate\Database\Eloquent\Builder $query): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition>
public function scopeWithJoinedTranslation(Illuminate\Database\Eloquent\Builder $query, ?string $locale=null, string $alias="nvl_translation"): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition>
public function scopeWithResolvedTranslations(Illuminate\Database\Eloquent\Builder $query, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition>
public function setDefaultValue(mixed $value): void
public function setLocale(string $locale): self
public function translated(string $field, ?string $locale=null): mixed
public function translation(?string $locale=null): Illuminate\Database\Eloquent\Relations\HasOne
@return HasOne<Model, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasOne<Illuminate\Database\Eloquent\Model, $this>
public function translationDefinition(): Nvl\Translatable\RelatedTranslationDefinition
public function translationResourceKey(): int|string
public function translations(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<Model, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Illuminate\Database\Eloquent\Model, $this>
@method static Builder<static> orderByTranslated(string $field, string $direction = 'asc', ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition> orderByTranslated(string $field, string $direction = 'asc', ?string $locale = null)
@method static Builder<static> whereTranslated(string $field, mixed $operator, mixed $value = TranslationComparisonValue::Omitted, ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition> whereTranslated(string $field, mixed $operator, mixed $value = Nvl\Translatable\Enums\TranslationComparisonValue::Omitted, ?string $locale = null)
@method static Builder<static> whereTranslationNotNull(string $field, ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition> whereTranslationNotNull(string $field, ?string $locale = null)
@method static Builder<static> whereTranslationNull(string $field, ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition> whereTranslationNull(string $field, ?string $locale = null)
@method static Builder<static> withAllTranslations()
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition> withAllTranslations()
@method static Builder<static> withJoinedTranslation(?string $locale = null, string $alias = 'nvl_translation')
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition> withJoinedTranslation(?string $locale = null, string $alias = 'nvl_translation')
@method static Builder<static> withResolvedTranslations(?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinition> withResolvedTranslations(?string $locale = null)
@property (string | null) $active_handle
@property (Carbon | null) $archived_at
resolved @property (Illuminate\Support\Carbon | null) $archived_at
@property (string | null) $catalog_import_key
@property (string | null) $catalog_import_request_hash
@property (string | null) $catalog_source_hash
@property (string | null) $catalog_source_id
@property (int | null) $catalog_source_revision
@property (string | null) $default_referenced_id
@property (string | null) $default_value
@property (Carbon | null) $deleted_at
resolved @property (Illuminate\Support\Carbon | null) $deleted_at
@property int $display_order
@property string $handle
@property string $id
@property bool $is_filterable
@property bool $is_required
@property bool $is_translatable
@property (array<int, array{key: string, type: string, isRequired: bool}> | null) $json_property_schema
@property string $key
@property string $namespace
@property (string | null) $owner_scope_key
@property (string | null) $ownership_key
@property (string | null) $referenced_model_type
@property int $revision
@property-read Collection<int, Model> $translations
resolved @property-read Illuminate\Database\Eloquent\Collection<int, Illuminate\Database\Eloquent\Model> $translations
@property MetafieldTypeEnum $type
resolved @property Nvl\Metafields\Enums\MetafieldTypeEnum $type
@property (list<string> | null) $validation_rules
```

## Nvl\Metafields\Models\MetafieldDefinitionAssignment

```text
class Nvl\Metafields\Models\MetafieldDefinitionAssignment extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public const string TABLE=\Nvl\Metafields\Definitions\Tables\MetafieldsTables::DefinitionAssignments
public function definition(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<MetafieldDefinition, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Metafields\Models\MetafieldDefinition, $this>
public function delete(): mixed
@return ($this is Pivot ? (bool | int | null) : (bool | null))
resolved @return ($this is Illuminate\Database\Eloquent\Relations\Pivot ? (bool | int | null) : (bool | null))
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinitionAssignment>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
@property-read (MetafieldDefinition | null) $definition
resolved @property-read (Nvl\Metafields\Models\MetafieldDefinition | null) $definition
@property string $definition_id
@property int $display_order
@property string $id
@property bool $is_active
@property bool $is_required
@property (string | null) $owner_scope_key
@property (list<string> | null) $owner_scopes
@property string $owner_type
@property (string | null) $ownership_key
@property (string | null) $section
@property (array<string, mixed> | null) $ui_config
```

## Nvl\Metafields\Models\MetafieldDefinitionTranslation

```text
final class Nvl\Metafields\Models\MetafieldDefinitionTranslation extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public const string TABLE=\Nvl\Metafields\Definitions\Tables\MetafieldsTables::DefinitionsI18n
public function definition(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<MetafieldDefinition, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Metafields\Models\MetafieldDefinition, $this>
public function delete(): mixed
@return ($this is Pivot ? (bool | int | null) : (bool | null))
resolved @return ($this is Illuminate\Database\Eloquent\Relations\Pivot ? (bool | int | null) : (bool | null))
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldDefinitionTranslation>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
@property (string | null) $default_value
@property-read MetafieldDefinition $definition
resolved @property-read Nvl\Metafields\Models\MetafieldDefinition $definition
@property (string | null) $description
@property (string | null) $hint
@property string $id
@property string $locale
@property string $metafield_definition_id
@property (string | null) $owner_scope_key
@property (string | null) $ownership_key
@property (array<string, mixed> | null) $properties
@property string $title
```

## Nvl\Metafields\Models\MetafieldTranslation

```text
class Nvl\Metafields\Models\MetafieldTranslation extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public const string TABLE=\Nvl\Metafields\Definitions\Tables\MetafieldsTables::I18n
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function metafield(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<Metafield, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Metafields\Models\Metafield, $this>
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Metafields\Models\MetafieldTranslation>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
@property string $id
@property string $locale
@property-read Metafield $metafield
resolved @property-read Nvl\Metafields\Models\Metafield $metafield
@property string $metafield_id
@property (string | null) $value
```

## Nvl\Metafields\Ownership\MetafieldOwnerCatalog

```text
final readonly class Nvl\Metafields\Ownership\MetafieldOwnerCatalog
public function __construct(Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\Support\Ownership\Contracts\OwnershipContext $context, Nvl\Support\Ownership\Contracts\OwnershipReadiness $readiness, Nvl\Support\Ownership\Services\OwnershipResourceRegistry $resources, Nvl\Metafields\Contracts\MetafieldCatalogAccess $access, Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionCatalogProjector $projection, Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionWriter $writer, Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionAssignmentSyncer $assignments, Nvl\Metafields\Services\Metafields\MetafieldReferenceRecordResolver $references, Nvl\Support\Events\DomainEventDispatcher $events): mixed
public function grant(string $sourceId, int $revision): Nvl\Metafields\Models\MetafieldDefinitionOwnerGrant
public function revoke(string $grantId, int $expectedRevision): Nvl\Metafields\Models\MetafieldDefinitionOwnerGrant
```

## Nvl\Metafields\Services\LegacyMetafieldImporter

```text
final readonly class Nvl\Metafields\Services\LegacyMetafieldImporter
public function __construct(Illuminate\Database\DatabaseManager $database, Nvl\Metafields\Services\MetafieldActorPolicy $actors, Nvl\Support\Contracts\LocaleCatalog $locales, Nvl\Metafields\Support\MetafieldOwnerRegistry $owners, Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionInput $definitionInput, Nvl\Metafields\Services\Metafields\MetafieldReferenceRecordResolver $references): mixed
public function apply(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $sourceLocale, string $fingerprint, array $source=[]): array
@param Source $source
resolved @param array{connection?: string, owner_types?: array<string, string>, owner_morphs?: array<string, string>, owner_ids?: array<string, array<string, string>>, reference_types?: array<string, string>, reference_ids?: array<string, array<string, string>>} $source
@return Review
resolved @return array{schema_fingerprint: string, fingerprint: string, source: array<string, int>, staged: array<string, int>, conflicts: list<string>}
public function plan(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $sourceLocale, array $source=[]): array
@param Source $source
resolved @param array{connection?: string, owner_types?: array<string, string>, owner_morphs?: array<string, string>, owner_ids?: array<string, array<string, string>>, reference_types?: array<string, string>, reference_ids?: array<string, array<string, string>>} $source
@return Review
resolved @return array{schema_fingerprint: string, fingerprint: string, source: array<string, int>, staged: array<string, int>, conflicts: list<string>}
public function verify(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $sourceLocale, string $fingerprint, array $source=[]): array
@param Source $source
resolved @param array{connection?: string, owner_types?: array<string, string>, owner_morphs?: array<string, string>, owner_ids?: array<string, array<string, string>>, reference_types?: array<string, string>, reference_ids?: array<string, array<string, string>>} $source
@return Review
resolved @return array{schema_fingerprint: string, fingerprint: string, source: array<string, int>, staged: array<string, int>, conflicts: list<string>}
```

## Nvl\Metafields\Services\MetafieldDefinitions

```text
final readonly class Nvl\Metafields\Services\MetafieldDefinitions
public function __construct(Nvl\Metafields\Services\MetafieldActorPolicy $actors, Nvl\Metafields\Contracts\MetafieldAuthorization $authorization, Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionInput $input, Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionWriter $writer, Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionAssignmentSyncer $assignments, Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionMutationGuard $guard, Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionRemover $remover, Nvl\Metafields\Ownership\MetafieldOwnerCatalog $catalog): mixed
public function archive(Nvl\Metafields\Models\MetafieldDefinition|string $definition, bool $archived, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Metafields\Models\MetafieldDefinition
public function create(array $attributes, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Metafields\Models\MetafieldDefinition
@param array<string, mixed> $attributes
public function delete(Nvl\Metafields\Models\MetafieldDefinition|string $definition, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, bool $deleteValues=false): bool
public function grant(string $definitionId, int $sourceRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Metafields\Models\MetafieldDefinitionOwnerGrant
public function import(array $request, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Metafields\Models\MetafieldDefinition
@param array<string, mixed> $request
public function revoke(string $grantId, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Metafields\Models\MetafieldDefinitionOwnerGrant
public function update(Nvl\Metafields\Models\MetafieldDefinition|string $definition, array $attributes, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Metafields\Models\MetafieldDefinition
@param array<string, mixed> $attributes
```

## Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionCatalog

```text
final class Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionCatalog
public function findByHandle(string $handle): ?Nvl\Metafields\Models\MetafieldDefinition
public function list(): Illuminate\Support\Collection
@return Collection<int, MetafieldDefinition>
resolved @return Illuminate\Support\Collection<int, Nvl\Metafields\Models\MetafieldDefinition>
public function mapIdsByHandles(array $handles): Illuminate\Support\Collection
@param list<string> $handles
@return Collection<string, string>
resolved @return Illuminate\Support\Collection<string, string>
```

## Nvl\Metafields\Services\MetafieldModelRegistry

```text
final class Nvl\Metafields\Services\MetafieldModelRegistry
public function __construct(Illuminate\Contracts\Config\Repository $configuration, Nvl\Support\OwnerRegistry $identities): mixed
public function definitionClass(): string
@return class-string<MetafieldDefinition>
resolved @return class-string<Nvl\Metafields\Models\MetafieldDefinition>
```

## Nvl\Metafields\Services\MetafieldReader

```text
final readonly class Nvl\Metafields\Services\MetafieldReader
public function __construct(Nvl\Metafields\Services\MetafieldActorPolicy $actors, Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionCatalog $definitions, Nvl\Metafields\Contracts\MetafieldAuthorization $authorization, Nvl\Metafields\Services\Metafields\MetafieldOwnerModelResolver $owners, Nvl\Metafields\Services\Metafields\OwnerMetafieldFieldCatalog $fields, Nvl\Metafields\Services\Metafields\OwnerMetafieldBatchReader $batches): mixed
public function definitions(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Illuminate\Support\Collection
@return Collection<int, MetafieldDefinition>
resolved @return Illuminate\Support\Collection<int, Nvl\Metafields\Models\MetafieldDefinition>
public function forOwner(Illuminate\Database\Eloquent\Model $owner, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, ?string $locale=null): Illuminate\Support\Collection
@return Collection<int, array{definition: MetafieldDefinition, assignment: MetafieldDefinitionAssignment, value: ?Metafield}>
resolved @return Illuminate\Support\Collection<int, array{definition: Nvl\Metafields\Models\MetafieldDefinition, assignment: Nvl\Metafields\Models\MetafieldDefinitionAssignment, value: ?Nvl\Metafields\Models\Metafield}>
public function forOwners(array $owners, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, ?string $locale=null): array
@param list<Model> $owners
resolved @param list<Illuminate\Database\Eloquent\Model> $owners
@return array{owners: stdClass, order: list<array{type: string, id: string}>}
```

## Nvl\Metafields\Services\MetafieldValues

```text
final class Nvl\Metafields\Services\MetafieldValues
public function __construct(Nvl\Metafields\Support\MetafieldOwnerRegistry $ownerRegistry, Nvl\Metafields\Services\Metafields\OwnerMetafieldAssignmentCatalog $assignmentCatalog, Nvl\Metafields\Services\Metafields\OwnerMetafieldRecordFinder $recordFinder, Nvl\Metafields\Services\Metafields\OwnerMetafieldSyncValidator $syncValidator, Nvl\Metafields\Services\Metafields\OwnerMetafieldRecordWriter $recordWriter, Nvl\Metafields\Services\Metafields\MetafieldOwnerModelResolver $ownerResolver, Nvl\Support\Events\DomainEventDispatcher $domainEvents, Nvl\Metafields\Services\MetafieldActorPolicy $actors, Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionCatalog $definitionCatalog, Nvl\Metafields\Contracts\MetafieldAuthorization $authorization): mixed
public function delete(Illuminate\Database\Eloquent\Model $owner, Nvl\Metafields\Models\MetafieldDefinition|string $definition, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): bool
public function purgeBeforeOwnerDeletion(Illuminate\Database\Eloquent\Model $owner, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): int
@return int
public function set(Illuminate\Database\Eloquent\Model $owner, string $handle, mixed $value, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, ?string $locale=null, ?int $expectedRevision=null): Nvl\Metafields\Models\Metafield
public function sync(Illuminate\Database\Eloquent\Model $owner, array $items, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Illuminate\Support\Collection
@param Model $owner
resolved @param Illuminate\Database\Eloquent\Model $owner
@param list<array<string, mixed>> $items
@return Collection<int, Metafield>
resolved @return Illuminate\Support\Collection<int, Nvl\Metafields\Models\Metafield>
```

## Nvl\Metafields\Services\Metafields\OwnerMetafieldAssignmentCatalog

```text
final class Nvl\Metafields\Services\Metafields\OwnerMetafieldAssignmentCatalog
public function activeForOwner(string $ownerType, Illuminate\Database\Eloquent\Model $owner): Illuminate\Support\Collection
@return Collection<string, MetafieldDefinitionAssignment>
resolved @return Illuminate\Support\Collection<string, Nvl\Metafields\Models\MetafieldDefinitionAssignment>
public function activeForOwnerType(string $ownerType): Illuminate\Support\Collection
@return Collection<string, MetafieldDefinitionAssignment>
resolved @return Illuminate\Support\Collection<string, Nvl\Metafields\Models\MetafieldDefinitionAssignment>
```

## Nvl\Metafields\Services\Metafields\OwnerMetafieldBatchReader

```text
final readonly class Nvl\Metafields\Services\Metafields\OwnerMetafieldBatchReader
public function __construct(Nvl\Metafields\Support\MetafieldOwnerRegistry $registry, Nvl\Metafields\Contracts\MetafieldBatchAuthorization $policy, Nvl\Metafields\Services\Metafields\MetafieldBatchReferenceLoader $references, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\Support\Contracts\LocaleCatalog $locales): mixed
public function read(array $owners, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, ?string $locale=null): array
@param list<Model> $owners
resolved @param list<Illuminate\Database\Eloquent\Model> $owners
@return array{owners: \stdClass, order: list<array{type: string, id: string}>}
resolved @return array{owners: stdClass, order: list<array{type: string, id: string}>}
```

## Nvl\Metafields\Support\MetafieldOwnerRegistry

```text
final class Nvl\Metafields\Support\MetafieldOwnerRegistry
public function __construct(Nvl\Support\OwnerRegistry $identities): mixed
public function all(): array
@return array<string, array{model: class-string<Model>, label: string, supported_types: array<int, string>, sections: array<int, string>, runtime_status: ('live' | 'planned'), show_route?: string, show_route_parameters?: array<string, string>}>
resolved @return array<string, array{model: class-string<Illuminate\Database\Eloquent\Model>, label: string, supported_types: array<int, string>, sections: array<int, string>, runtime_status: ('live' | 'planned'), show_route?: string, show_route_parameters?: array<string, string>}>
public function configurationForType(string $type): array
@return array{model: class-string<Model>, label: string, supported_types: array<int, string>, sections: array<int, string>, runtime_status: ('live' | 'planned'), show_route?: string, show_route_parameters?: array<string, string>}
resolved @return array{model: class-string<Illuminate\Database\Eloquent\Model>, label: string, supported_types: array<int, string>, sections: array<int, string>, runtime_status: ('live' | 'planned'), show_route?: string, show_route_parameters?: array<string, string>}
public function forType(string $type): array
@return array{type: string, label: string, supportedTypes: array<int, string>, sections: array<int, string>, runtimeStatus: ('live' | 'planned'), supportsRuntimeEditing: bool}
public function register(string $type, string $model, string $label, array $sections=['general']): void
@param list<string> $sections
public function resolveOwnerType(Illuminate\Database\Eloquent\Model $owner): string
public function supports(string $ownerType, Nvl\Metafields\Enums\MetafieldTypeEnum $fieldType): bool
public function supportsRuntimeEditing(string $ownerType): bool
```

## Nvl\Metafields\Traits\HasMetafields

```text
trait Nvl\Metafields\Traits\HasMetafields
public function scopeWhereNvlMetafield(Illuminate\Database\Eloquent\Builder $query, string $handle, string|int|float|bool|null $value, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Metafields\Contracts\MetafieldBatchAuthorization $policy, string $operator="="): Illuminate\Database\Eloquent\Builder
@param Builder<$this> $query
resolved @param Illuminate\Database\Eloquent\Builder<$this> $query
@return Builder<$this>
resolved @return Illuminate\Database\Eloquent\Builder<$this>
```
