<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Services\LegacyMetafieldImporter;
use Nvl\Metafields\Services\MetafieldReader;
use Nvl\Metafields\Services\MetafieldValues;
use Nvl\Metafields\Tests\CommittedMetafieldsTestCase;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;
use Nvl\Support\Enums\SystemActor;

uses(CommittedMetafieldsTestCase::class);

it('stages definitions and scoped Metafield history with their original identities', function (): void {
    foreach (['import', 'write', 'read'] as $ability) {
        Gate::define('metafields.'.$ability, static fn (SystemActor $actor): bool => $actor === SystemActor::Background);
    }
    Gate::define('metafields.owner', static fn (SystemActor $actor): bool => true);
    config([
        'nvl-metafields.authorization.owner_ability' => 'metafields.owner',
        'nvl-translatable.locales' => ['en', 'bg'],
        'nvl-metafields.owners' => ['products' => ['model' => TestMetafieldOwner::class, 'label' => 'Products', 'sections' => ['general']]],
        'nvl-metafields.reference_models' => ['products' => TestMetafieldOwner::class],
    ]);
    $schemas = [
        'metafields_definitions' => ['namespace', 'key', 'handle', 'type', 'referenced_model_type', 'is_translatable', 'is_required', 'is_filterable', 'validation_rules', 'json_property_schema', 'title', 'description', 'hint', 'default_value', 'default_referenced_id', 'display_order'],
        'metafield_definition_assignments' => ['definition_id', 'owner_type', 'section', 'display_order', 'is_required', 'is_active', 'ui_config', 'owner_scopes'],
        'metafields' => ['definition_id', 'metafieldable_type', 'metafieldable_id', 'referenced_id', 'value'],
        'metafields_translations' => ['metafield_id', 'locale', 'value'],
    ];
    foreach ($schemas as $table => $columns) {
        Schema::create($table, static function (Blueprint $blueprint) use ($columns): void {
            $blueprint->uuid('id')->primary();
            foreach ($columns as $column) {
                $blueprint->text($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->softDeletes();
        });
    }
    $owner = TestMetafieldOwner::query()->create(['name' => 'Target product', 'category' => 'digital']);
    [$definitionId, $assignmentId, $oldId, $lastId, $translationId, $referenceDefinition, $referenceValue] = array_map(static fn (): string => (string) Str::uuid(), range(1, 7));
    $time = '2026-05-05 09:10:11';
    $timestamps = ['created_at' => $time, 'updated_at' => $time];
    DB::table('metafields_definitions')->insert([
        'id' => $definitionId, 'namespace' => 'legacy', 'key' => 'note', 'handle' => 'legacy.note', 'type' => 'string',
        'title' => 'Старо име', 'hint' => 'Указание', 'is_translatable' => '1', 'is_required' => '0', 'is_filterable' => '0',
        'default_value' => 'По подразбиране', 'display_order' => 0, ...$timestamps,
    ]);
    DB::table('metafield_definition_assignments')->insert([
        'id' => $assignmentId, 'definition_id' => $definitionId, 'owner_type' => 'old-products', 'section' => 'general',
        'display_order' => 3, 'is_required' => '0', 'is_active' => '1', 'owner_scopes' => '["digital"]', ...$timestamps,
    ]);
    DB::table('metafields_definitions')->insert([
        'id' => $referenceDefinition, 'namespace' => 'legacy', 'key' => 'reference', 'handle' => 'legacy.reference', 'type' => 'reference',
        'title' => 'Reference', 'referenced_model_type' => 'OldProduct', 'is_translatable' => '0', 'is_required' => '0', 'is_filterable' => '0',
        'default_referenced_id' => 'old-id', 'display_order' => 0, ...$timestamps,
    ]);
    $valueRow = ['definition_id' => $definitionId, 'metafieldable_type' => 'OldProduct', 'metafieldable_id' => 'old-id', ...$timestamps];
    DB::table('metafields')->insert([
        ['id' => $oldId, 'deleted_at' => '2026-05-05 12:00:00', ...$valueRow],
        ['id' => $lastId, 'deleted_at' => '2026-05-06 12:00:00', ...$valueRow],
    ]);
    DB::table('metafields_translations')->insert([
        'id' => $translationId, 'metafield_id' => $lastId, 'locale' => 'bg', 'value' => 'Изтрито',
        'deleted_at' => '2026-05-06 12:00:00', ...$timestamps,
    ]);
    DB::table('metafields')->insert([
        ...$valueRow, 'id' => $referenceValue, 'definition_id' => $referenceDefinition, 'referenced_id' => 'old-id',
    ]);
    $ids = ['OldProduct' => ['old-id' => (string) $owner->getKey()]];
    $source = [
        'owner_types' => ['old-products' => 'products'], 'owner_morphs' => ['OldProduct' => 'products'],
        'owner_ids' => $ids, 'reference_types' => ['OldProduct' => 'products'], 'reference_ids' => $ids,
    ];
    $actor = SystemActor::Background;
    $importer = app(LegacyMetafieldImporter::class);
    $plan = $importer->plan($actor, 'bg', $source);
    expect($plan['conflicts'])->toBeEmpty()
        ->and($plan['source']['values'])->toBe(3)
        ->and($plan['staged']['definitions'])->toBe(0);
    expect(fn () => $importer->verify($actor, 'bg', $plan['fingerprint'], $source))->toThrow(LogicException::class, 'incomplete');

    $interrupted = false;
    DB::connection()->beforeExecuting(static function (string $sql) use (&$interrupted): void {
        if (! $interrupted && str_starts_with($sql, 'insert into "nvl_metafields_definition_assignments"')) {
            $interrupted = true;
            throw new RuntimeException('simulated interruption');
        }
    });
    expect(fn () => $importer->apply($actor, 'bg', $plan['fingerprint'], $source))->toThrow(RuntimeException::class, 'simulated interruption')
        ->and($importer->plan($actor, 'bg', $source)['staged']['definitions'])->toBe(2)
        ->and($importer->apply($actor, 'bg', $plan['fingerprint'], $source)['staged'])->toBe($plan['source'])
        ->and($importer->apply($actor, 'bg', $plan['fingerprint'], $source)['conflicts'])->toBeEmpty();
    expect($importer->verify($actor, 'bg', $plan['fingerprint'], $source)['staged'])->toBe($plan['source']);

    $definition = MetafieldDefinition::query()->findOrFail($definitionId);
    expect($definition->translations()->pluck('locale')->all())->toBe(['bg'])
        ->and($definition->translations()->sole()->default_value)->toBe('По подразбиране')
        ->and($definition->assignments()->findOrFail($assignmentId)->owner_scopes)->toBe(['digital'])
        ->and($definition->created_at?->format('Y-m-d H:i:s'))->toBe($time)
        ->and(Metafield::onlyTrashed()->count())->toBe(2)
        ->and(MetafieldDefinition::query()->findOrFail($referenceDefinition)->default_referenced_id)->toBe((string) $owner->getKey())
        ->and(Metafield::query()->findOrFail($referenceValue)->referenced_id)->toBe((string) $owner->getKey())
        ->and(app(MetafieldReader::class)->forOwner($owner, $actor, 'bg')->sole()['value'])->toBeNull();

    $restored = app(MetafieldValues::class)->set($owner, 'legacy.note', 'Ново', $actor, 'bg');
    expect($restored->id)->toBe($lastId)
        ->and($restored->translations()->sole()->value)->toBe('Ново')
        ->and(DB::table($restored->translations()->getRelated()->getTable())->where('id', $translationId)->whereNotNull('deleted_at')->exists())->toBeTrue()
        ->and(fn () => DB::transaction(fn () => Metafield::factory()->forDefinition($definition)->forOwner($owner)->create()))->toThrow(QueryException::class);
});
