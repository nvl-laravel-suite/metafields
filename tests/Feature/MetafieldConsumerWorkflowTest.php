<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Nvl\Metafields\Actions\MetafieldDefinitions\ArchiveMetafieldDefinitionAction;
use Nvl\Metafields\Actions\MetafieldDefinitions\CreateMetafieldDefinitionAction;
use Nvl\Metafields\Actions\MetafieldDefinitions\DeleteMetafieldDefinitionAction;
use Nvl\Metafields\Actions\MetafieldDefinitions\UpdateMetafieldDefinitionAction;
use Nvl\Metafields\Actions\Metafields\ListOwnerMetafieldsAction;
use Nvl\Metafields\Actions\Metafields\SetMetafieldAction;
use Nvl\Metafields\Actions\Metafields\SyncOwnerMetafieldsAction;
use Nvl\Metafields\Contracts\MetafieldReferenceAuthorization;
use Nvl\Metafields\Data\ArchiveMetafieldDefinitionPayload;
use Nvl\Metafields\Data\CreateMetafieldDefinitionPayload;
use Nvl\Metafields\Data\OwnerMetafieldField;
use Nvl\Metafields\Data\OwnerMetafieldValue;
use Nvl\Metafields\Data\SyncOwnerMetafieldsPayload;
use Nvl\Metafields\Data\UpdateMetafieldDefinitionPayload;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Events\MetafieldsSyncedEvent;
use Nvl\Metafields\Exceptions\StaleMetafieldVersionException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Models\MetafieldDefinitionTranslation;
use Nvl\Metafields\Models\MetafieldTranslation;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionCatalog;
use Nvl\Metafields\Services\Metafields\OwnerMetafieldRecordFinder;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Metafields\Support\MetafieldValidationRuleCompiler;
use Nvl\Metafields\Support\OwnerMetafieldBooleanFilter;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;

function metafieldTestOwner(): TestMetafieldOwner
{
    return TestMetafieldOwner::query()->oldest()->firstOrFail();
}

function assignMetafieldTestDefinition(
    MetafieldDefinition $definition,
    bool $required = false,
    string $section = 'general',
): MetafieldDefinitionAssignment {
    return MetafieldDefinitionAssignment::factory()
        ->forDefinition($definition)
        ->forOwnerType('products')
        ->inSection($section)
        ->state(['is_required' => $required])
        ->create();
}

beforeEach(function (): void {
    config([
        'translatable.locales' => ['en', 'bg'],
        'translatable.fallback_locales' => ['en'],
        'metafields.owners' => [
            'products' => [
                'model' => TestMetafieldOwner::class,
                'label' => 'Products',
                'supported_types' => array_map(
                    static fn (MetafieldTypeEnum $type): string => $type->value,
                    MetafieldTypeEnum::cases(),
                ),
                'sections' => ['general', 'details'],
                'runtime_status' => 'live',
            ],
        ],
        'metafields.reference_models' => [
            'products' => TestMetafieldOwner::class,
        ],
    ]);

    app()->instance(
        MetafieldReferenceAuthorization::class,
        new class implements MetafieldReferenceAuthorization
        {
            public function authorize(
                Model $owner,
                MetafieldDefinition $definition,
                Model $reference,
            ): void {}
        },
    );

    TestMetafieldOwner::query()->create(['name' => 'Owner']);
});

it('validates and casts url color boolean and json values without silent coercion', function (): void {
    expect(Validator::make(
        ['value' => 'https://example.com/product'],
        ['value' => MetafieldTypeEnum::Url->getValidationRules()],
    )->passes())->toBeTrue()
        ->and(Validator::make(
            ['value' => 'ftp://example.com/file'],
            ['value' => MetafieldTypeEnum::Url->getValidationRules()],
        )->fails())->toBeTrue()
        ->and(Validator::make(
            ['value' => '#aabbcc'],
            ['value' => MetafieldTypeEnum::Color->getValidationRules()],
        )->passes())->toBeTrue()
        ->and(Validator::make(
            ['value' => 'red'],
            ['value' => MetafieldTypeEnum::Color->getValidationRules()],
        )->fails())->toBeTrue()
        ->and(MetafieldTypeEnum::Boolean->cast('1'))->toBeTrue()
        ->and(MetafieldTypeEnum::Boolean->cast('0'))->toBeFalse();

    expect(fn (): mixed => MetafieldTypeEnum::Boolean->cast('definitely'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): mixed => MetafieldTypeEnum::Json->cast('{invalid'))
        ->toThrow(JsonException::class);
});

it('casts every supported metafield type through its declared storage contract', function (): void {
    $cases = [
        [MetafieldTypeEnum::String, 'value', 'value'],
        [MetafieldTypeEnum::Text, 'long value', 'long value'],
        [MetafieldTypeEnum::RichText, '<p>value</p>', '<p>value</p>'],
        [MetafieldTypeEnum::Integer, '42', 42],
        [MetafieldTypeEnum::Decimal, '42.500', '42.5'],
        [MetafieldTypeEnum::Float, '42.5', 42.5],
        [MetafieldTypeEnum::Boolean, '0', false],
        [MetafieldTypeEnum::Date, '2026-07-29', '2026-07-29'],
        [MetafieldTypeEnum::Json, '{"enabled":true}', ['enabled' => true]],
        [MetafieldTypeEnum::ArrayValue, '["one","two"]', ['one', 'two']],
        [MetafieldTypeEnum::Enum, 'published', 'published'],
        [MetafieldTypeEnum::Reference, 'record-id', 'record-id'],
        [MetafieldTypeEnum::ReferenceList, '["one","two"]', ['one', 'two']],
        [MetafieldTypeEnum::Url, 'https://example.com', 'https://example.com'],
        [MetafieldTypeEnum::Color, '#aabbcc', '#aabbcc'],
    ];

    foreach ($cases as [$type, $input, $expected]) {
        expect($type->cast($input))->toBe($expected);
    }

    expect(MetafieldTypeEnum::DateTime->cast('2026-07-29 12:30:00')->format('Y-m-d H:i:s'))
        ->toBe('2026-07-29 12:30:00');
});

it('synchronizes typed owner values and emits a commit-aware event', function (): void {
    Event::fake([MetafieldsSyncedEvent::class]);
    $definition = MetafieldDefinition::factory()->create([
        'namespace' => 'product',
        'key' => 'material',
        'type' => MetafieldTypeEnum::String,
    ]);
    assignMetafieldTestDefinition($definition);

    $result = app(SyncOwnerMetafieldsAction::class)->execute(
        metafieldTestOwner(),
        SyncOwnerMetafieldsPayload::validateAndCreate([
            'items' => [[
                'definitionId' => $definition->id,
                'value' => 'linen',
            ]],
        ]),
    );

    $metafield = $result->sole();

    expect($metafield->getValue())->toBe('linen')
        ->and($metafield->metafieldable->is(metafieldTestOwner()))->toBeTrue()
        ->and(OwnerMetafieldValue::fromModel($metafield, 'products')->toArray())
        ->toMatchArray([
            'definitionId' => $definition->id,
            'type' => 'string',
            'value' => 'linen',
            'translations' => null,
        ]);

    Event::assertDispatched(
        MetafieldsSyncedEvent::class,
        fn (MetafieldsSyncedEvent $event): bool => $event->owner->is(metafieldTestOwner())
            && $event->metafields->sole()->is($metafield),
    );
});

it('locks owner values inside the action-owned transaction', function (): void {
    $definition = MetafieldDefinition::factory()->create([
        'namespace' => 'product',
        'key' => 'transactional',
    ]);
    assignMetafieldTestDefinition($definition);
    $transactionLevels = [];

    DB::listen(function (QueryExecuted $query) use (&$transactionLevels): void {
        if (str_contains($query->sql, 'metafields')) {
            $transactionLevels[] = $query->connection->transactionLevel();
        }
    });

    app(SyncOwnerMetafieldsAction::class)->execute(
        metafieldTestOwner(),
        SyncOwnerMetafieldsPayload::validateAndCreate([
            'items' => [[
                'definitionId' => $definition->id,
                'value' => 'locked',
            ]],
        ]),
    );

    expect($transactionLevels)->not->toBeEmpty()
        ->and(min($transactionLevels))->toBeGreaterThan(0);
});

it('keeps owner reads lock-free while allowing mutation lookups to request row locks', function (): void {
    $definition = MetafieldDefinition::factory()->create();
    $owner = metafieldTestOwner();
    $connection = DB::connection();
    $originalGrammar = $connection->getQueryGrammar();
    $connection->setQueryGrammar(new PostgresGrammar($connection));

    try {
        $readQueries = $connection->pretend(
            fn (): mixed => app(OwnerMetafieldRecordFinder::class)->mapCurrentByDefinitionIds(
                $owner,
                [$definition->id],
            ),
        );
        $mutationQueries = $connection->pretend(
            fn (): mixed => app(OwnerMetafieldRecordFinder::class)->mapCurrentByDefinitionIds(
                $owner,
                [$definition->id],
                lockForUpdate: true,
            ),
        );
    } finally {
        $connection->setQueryGrammar($originalGrammar);
    }

    expect(collect($readQueries)->pluck('query')->implode(' '))->not->toContain('for update')
        ->and(collect($mutationQueries)->pluck('query')->implode(' '))->toContain('for update');
});

it('keeps owner field projection queries independent of assigned field count', function (): void {
    $owner = metafieldTestOwner();
    $create = static function (int $index) use ($owner): void {
        $definition = MetafieldDefinition::factory()->create([
            'namespace' => 'query',
            'key' => "field-{$index}",
        ]);
        assignMetafieldTestDefinition($definition);
        Metafield::factory()
            ->forDefinition($definition)
            ->forOwner($owner)
            ->withValue("value-{$index}")
            ->create();
    };
    $measure = static function () use ($owner): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $fields = app(ListOwnerMetafieldsAction::class)->execute($owner, 'en');
        $queryCount = count(DB::getQueryLog());
        $fields->each(static fn (OwnerMetafieldField $field): mixed => $field->value);

        expect(DB::getQueryLog())->toHaveCount($queryCount);
        DB::disableQueryLog();

        return $queryCount;
    };

    $create(1);
    $singleQueryCount = $measure();

    foreach (range(2, 25) as $index) {
        $create($index);
    }

    $populatedQueryCount = $measure();

    expect($singleQueryCount)->toBeLessThanOrEqual(7)
        ->and($populatedQueryCount)->toBe($singleQueryCount);
});

it('supports patch and replace semantics for localized owner values', function (): void {
    $definition = MetafieldDefinition::factory()->translatable()->create([
        'namespace' => 'product',
        'key' => 'care',
        'type' => MetafieldTypeEnum::Text,
    ]);
    assignMetafieldTestDefinition($definition);
    $action = app(SyncOwnerMetafieldsAction::class);

    $created = $action->execute(metafieldTestOwner(), SyncOwnerMetafieldsPayload::validateAndCreate([
        'items' => [[
            'definitionId' => $definition->id,
            'translations' => [
                'en' => 'Hand wash',
                'bg' => 'Ръчно пране',
            ],
        ]],
    ]))->sole();

    $patched = $action->execute(metafieldTestOwner(), SyncOwnerMetafieldsPayload::validateAndCreate([
        'items' => [[
            'definitionId' => $definition->id,
            'translations' => ['en' => 'Gentle hand wash'],
            'translationMode' => 'patch',
            'expectedRevision' => $created->revision,
        ]],
    ]))->sole();

    $metafield = Metafield::query()->with('translations')->sole();

    expect($metafield->getValue('en'))->toBe('Gentle hand wash')
        ->and($metafield->getValue('bg'))->toBe('Ръчно пране')
        ->and($metafield->translations->pluck('locale')->sort()->values()->all())->toBe(['bg', 'en']);

    $action->execute(metafieldTestOwner(), SyncOwnerMetafieldsPayload::validateAndCreate([
        'items' => [[
            'definitionId' => $definition->id,
            'translations' => ['en' => 'Machine wash'],
            'translationMode' => 'replace',
            'expectedRevision' => $patched->revision,
        ]],
    ]));

    expect($metafield->refresh()->translations()->pluck('locale')->all())->toBe(['en']);
});

it('rejects null localized values before reaching the non-null translation column', function (): void {
    $definition = MetafieldDefinition::factory()->translatable()->create([
        'namespace' => 'product',
        'key' => 'nullable_translation',
        'type' => MetafieldTypeEnum::Text,
    ]);
    assignMetafieldTestDefinition($definition);

    expect(fn () => app(SyncOwnerMetafieldsAction::class)->execute(
        metafieldTestOwner(),
        SyncOwnerMetafieldsPayload::from([
            'items' => [[
                'definitionId' => $definition->id,
                'translations' => ['en' => null],
            ]],
        ]),
    ))->toThrow(ValidationException::class)
        ->and(Metafield::query()->where('definition_id', $definition->id)->exists())->toBeFalse();
});

it('clears localized owner values through the shared translation writer', function (): void {
    $definition = MetafieldDefinition::factory()->translatable()->create([
        'namespace' => 'product',
        'key' => 'washing',
        'type' => MetafieldTypeEnum::Text,
    ]);
    assignMetafieldTestDefinition($definition);
    $action = app(SyncOwnerMetafieldsAction::class);
    $metafield = $action->execute(
        metafieldTestOwner(),
        SyncOwnerMetafieldsPayload::validateAndCreate([
            'items' => [[
                'definitionId' => $definition->id,
                'translations' => [
                    'en' => 'Hand wash',
                    'bg' => 'Ръчно пране',
                ],
            ]],
        ]),
    )->sole();

    $action->execute(
        metafieldTestOwner(),
        SyncOwnerMetafieldsPayload::validateAndCreate([
            'items' => [[
                'definitionId' => $definition->id,
                'clear' => true,
                'expectedRevision' => $metafield->revision,
            ]],
        ]),
    );

    expect(Metafield::query()->whereKey($metafield->id)->exists())->toBeFalse()
        ->and(Metafield::withTrashed()->findOrFail($metafield->id)->trashed())->toBeTrue()
        ->and(MetafieldTranslation::query()->where('metafield_id', $metafield->id)->exists())
        ->toBeFalse();
});

it('starts values at revision one and recreates cleared values without a hidden revision', function (): void {
    $definition = MetafieldDefinition::factory()->create([
        'namespace' => 'product',
        'key' => 'recreatable',
    ]);
    assignMetafieldTestDefinition($definition);
    $action = app(SyncOwnerMetafieldsAction::class);
    $created = $action->execute(
        metafieldTestOwner(),
        SyncOwnerMetafieldsPayload::validateAndCreate([
            'items' => [[
                'definitionId' => $definition->id,
                'value' => 'first',
            ]],
        ]),
    )->sole();

    expect($created->revision)->toBe(1);

    $action->execute(
        metafieldTestOwner(),
        SyncOwnerMetafieldsPayload::validateAndCreate([
            'items' => [[
                'definitionId' => $definition->id,
                'clear' => true,
                'expectedRevision' => $created->revision,
            ]],
        ]),
    );

    $recreated = $action->execute(
        metafieldTestOwner(),
        SyncOwnerMetafieldsPayload::validateAndCreate([
            'items' => [[
                'definitionId' => $definition->id,
                'value' => 'second',
            ]],
        ]),
    )->sole();

    expect($recreated->id)->toBe($created->id)
        ->and($recreated->revision)->toBeGreaterThan($created->revision)
        ->and($recreated->getValue())->toBe('second');
});

it('preserves omitted localized definition fields during a definition patch', function (): void {
    $createPayload = CreateMetafieldDefinitionPayload::validateAndCreate([
        'namespace' => 'product',
        'key' => 'origin',
        'type' => 'string',
        'assignment' => [
            'ownerType' => 'products',
            'section' => 'details',
        ],
        'translations' => [
            'en' => [
                'title' => 'Origin',
                'description' => 'Country of manufacture',
                'hint' => 'Use the legal country name',
                'properties' => ['width' => 'half'],
            ],
        ],
    ]);
    $definition = app(CreateMetafieldDefinitionAction::class)->execute($createPayload);

    expect($definition->displayTitle('en'))->toBe('Origin');

    $updatePayload = UpdateMetafieldDefinitionPayload::validateAndCreate([
        'namespace' => 'product',
        'key' => 'origin',
        'type' => 'string',
        'expectedRevision' => $definition->revision,
        'assignment' => [
            'ownerType' => 'products',
            'section' => 'details',
        ],
        'translations' => [
            'en' => ['description' => 'Manufactured in the declared country'],
        ],
    ]);
    app(UpdateMetafieldDefinitionAction::class)->execute($definition, $updatePayload);

    $translation = MetafieldDefinitionTranslation::query()
        ->where('metafield_definition_id', $definition->id)
        ->sole();

    expect($translation->title)->toBe('Origin')
        ->and($translation->description)->toBe('Manufactured in the declared country')
        ->and($translation->hint)->toBe('Use the legal country name')
        ->and($translation->properties)->toBe(['width' => 'half'])
        ->and($definition->refresh()->displayTitle('en'))->toBe('Origin');
});

it('requires a title when a definition patch introduces a locale', function (): void {
    $definition = app(CreateMetafieldDefinitionAction::class)->execute(
        CreateMetafieldDefinitionPayload::validateAndCreate([
            'namespace' => 'product',
            'key' => 'subtitle',
            'type' => 'string',
            'assignment' => [
                'ownerType' => 'products',
                'section' => 'details',
            ],
            'translations' => [
                'en' => ['title' => 'Subtitle'],
            ],
        ]),
    );
    $payload = UpdateMetafieldDefinitionPayload::validateAndCreate([
        'namespace' => 'product',
        'key' => 'subtitle',
        'type' => 'string',
        'expectedRevision' => $definition->revision,
        'assignment' => [
            'ownerType' => 'products',
            'section' => 'details',
        ],
        'translations' => [
            'bg' => ['description' => 'Bulgarian subtitle'],
        ],
    ]);

    expect(fn () => app(UpdateMetafieldDefinitionAction::class)->execute($definition, $payload))
        ->toThrow(ValidationException::class);
});

it('hydrates and persists JSON property schemas through the update contract', function (): void {
    $definition = MetafieldDefinition::factory()->create([
        'namespace' => 'product',
        'key' => 'specification',
        'type' => MetafieldTypeEnum::Json,
    ]);
    assignMetafieldTestDefinition($definition);
    $payload = UpdateMetafieldDefinitionPayload::validateAndCreate([
        'namespace' => 'product',
        'key' => 'specification',
        'type' => 'json',
        'expectedRevision' => $definition->revision,
        'assignment' => [
            'ownerType' => 'products',
            'section' => 'details',
        ],
        'jsonPropertySchema' => [[
            'key' => 'material',
            'type' => 'string',
            'isRequired' => true,
        ]],
    ]);

    $updated = app(UpdateMetafieldDefinitionAction::class)->execute($definition, $payload);

    expect($updated->json_property_schema)->toBe([[
        'key' => 'material',
        'type' => 'string',
        'isRequired' => true,
    ]]);
});

it('preserves omitted optional definition settings during updates', function (): void {
    $definition = app(CreateMetafieldDefinitionAction::class)->execute(
        CreateMetafieldDefinitionPayload::validateAndCreate([
            'namespace' => 'content',
            'key' => 'reading_time',
            'type' => 'integer',
            'isRequired' => true,
            'isFilterable' => true,
            'defaultValue' => 5,
            'assignment' => [
                'ownerType' => 'products',
                'section' => 'details',
            ],
            'translations' => [
                'en' => ['title' => 'Reading time'],
            ],
        ]),
    );

    $updated = app(UpdateMetafieldDefinitionAction::class)->execute(
        $definition,
        UpdateMetafieldDefinitionPayload::validateAndCreate([
            'namespace' => 'content',
            'key' => 'reading_time',
            'type' => 'integer',
            'expectedRevision' => $definition->revision,
            'assignment' => [
                'ownerType' => 'products',
                'section' => 'details',
            ],
            'translations' => [
                'en' => ['title' => 'Estimated reading time'],
            ],
        ]),
    );

    expect($updated->is_required)->toBeTrue()
        ->and($updated->is_filterable)->toBeTrue()
        ->and($updated->getSerializableDefaultValue())->toBe(5);
});

it('rejects definition rules that invalidate a preserved default value', function (): void {
    $definition = app(CreateMetafieldDefinitionAction::class)->execute(
        CreateMetafieldDefinitionPayload::validateAndCreate([
            'namespace' => 'content',
            'key' => 'minimum_quantity',
            'type' => 'integer',
            'defaultValue' => 5,
            'assignment' => [
                'ownerType' => 'products',
                'section' => 'details',
            ],
            'translations' => [
                'en' => ['title' => 'Minimum quantity'],
            ],
        ]),
    );

    expect(fn () => app(UpdateMetafieldDefinitionAction::class)->execute(
        $definition,
        UpdateMetafieldDefinitionPayload::validateAndCreate([
            'namespace' => 'content',
            'key' => 'minimum_quantity',
            'type' => 'integer',
            'validationRules' => ['min:10'],
            'expectedRevision' => $definition->revision,
            'assignment' => [
                'ownerType' => 'products',
                'section' => 'details',
            ],
        ]),
    ))->toThrow(ValidationException::class);

    expect($definition->refresh()->getSerializableDefaultValue())->toBe(5)
        ->and($definition->validation_rules)->toBeNull()
        ->and($definition->revision)->toBe(1);
});

it('moves default ownership to localized rows when a definition becomes translatable', function (): void {
    $definition = app(CreateMetafieldDefinitionAction::class)->execute(
        CreateMetafieldDefinitionPayload::validateAndCreate([
            'namespace' => 'content',
            'key' => 'localized_default_transition',
            'type' => 'string',
            'defaultValue' => 'Root default',
            'assignment' => [
                'ownerType' => 'products',
                'section' => 'details',
            ],
            'translations' => [
                'en' => ['title' => 'Localized default transition'],
            ],
        ]),
    );

    $updated = app(UpdateMetafieldDefinitionAction::class)->execute(
        $definition,
        UpdateMetafieldDefinitionPayload::validateAndCreate([
            'namespace' => 'content',
            'key' => 'localized_default_transition',
            'type' => 'string',
            'isTranslatable' => true,
            'expectedRevision' => $definition->revision,
            'assignment' => [
                'ownerType' => 'products',
                'section' => 'details',
            ],
            'translations' => [
                'en' => ['defaultValue' => 'Localized default'],
            ],
        ]),
    );

    expect($updated->default_value)->toBeNull()
        ->and($updated->getSerializableDefaultValue('en'))->toBe('Localized default');
});

it('validates localized definition defaults and their storage mode', function (): void {
    $referenceOne = TestMetafieldOwner::query()->create(['name' => 'Reference one']);
    $referenceTwo = TestMetafieldOwner::query()->create(['name' => 'Reference two']);

    expect(fn () => CreateMetafieldDefinitionPayload::validateAndCreate([
        'namespace' => 'content',
        'key' => 'localized_url',
        'type' => 'url',
        'isTranslatable' => true,
        'assignment' => [
            'ownerType' => 'products',
            'section' => 'details',
        ],
        'translations' => [
            'en' => [
                'title' => 'Localized URL',
                'defaultValue' => 'ftp://example.com/file',
            ],
        ],
    ]))->toThrow(ValidationException::class);

    expect(fn () => CreateMetafieldDefinitionPayload::validateAndCreate([
        'namespace' => 'content',
        'key' => 'nonlocalized_default',
        'type' => 'string',
        'assignment' => [
            'ownerType' => 'products',
            'section' => 'details',
        ],
        'translations' => [
            'en' => [
                'title' => 'Nonlocalized default',
                'defaultValue' => 'wrong storage',
            ],
        ],
    ]))->toThrow(ValidationException::class);

    expect(fn () => CreateMetafieldDefinitionPayload::validateAndCreate([
        'namespace' => 'content',
        'key' => 'localized_root_default',
        'type' => 'string',
        'isTranslatable' => true,
        'defaultValue' => 'wrong storage',
        'assignment' => [
            'ownerType' => 'products',
            'section' => 'details',
        ],
        'translations' => [
            'en' => ['title' => 'Localized root default'],
        ],
    ]))->toThrow(ValidationException::class);

    expect(fn () => CreateMetafieldDefinitionPayload::validateAndCreate([
        'namespace' => 'content',
        'key' => 'limited_reference_list',
        'type' => 'reference_list',
        'referencedModelType' => 'products',
        'validationRules' => ['max:1'],
        'defaultValue' => [
            (string) $referenceOne->getKey(),
            (string) $referenceTwo->getKey(),
        ],
        'assignment' => [
            'ownerType' => 'products',
            'section' => 'details',
        ],
        'translations' => [
            'en' => ['title' => 'Limited references'],
        ],
    ]))->toThrow(ValidationException::class);
});

it('rejects definition assignments outside the owner type and section allowlists', function (): void {
    config()->set('metafields.owners.products.supported_types', ['string']);
    config()->set('metafields.owners.products.sections', ['general']);

    expect(fn () => CreateMetafieldDefinitionPayload::validateAndCreate([
        'namespace' => 'content',
        'key' => 'unsupported_type',
        'type' => 'integer',
        'assignment' => [
            'ownerType' => 'products',
            'section' => 'general',
        ],
        'translations' => [
            'en' => ['title' => 'Unsupported type'],
        ],
    ]))->toThrow(ValidationException::class);

    expect(fn () => CreateMetafieldDefinitionPayload::validateAndCreate([
        'namespace' => 'content',
        'key' => 'unsupported_section',
        'type' => 'string',
        'assignment' => [
            'ownerType' => 'products',
            'section' => 'unknown',
        ],
        'translations' => [
            'en' => ['title' => 'Unsupported section'],
        ],
    ]))->toThrow(ValidationException::class);
});

it('accepts required fields already stored or backed by defaults', function (): void {
    $storedRequired = MetafieldDefinition::factory()->required()->create([
        'namespace' => 'product',
        'key' => 'sku_label',
    ]);
    $defaultRequired = MetafieldDefinition::factory()->required()->withDefaultValue('standard')->create([
        'namespace' => 'product',
        'key' => 'tier',
    ]);
    $optional = MetafieldDefinition::factory()->create([
        'namespace' => 'product',
        'key' => 'note',
    ]);
    assignMetafieldTestDefinition($storedRequired, true);
    assignMetafieldTestDefinition($defaultRequired, true);
    assignMetafieldTestDefinition($optional);
    $action = app(SyncOwnerMetafieldsAction::class);

    $action->execute(metafieldTestOwner(), SyncOwnerMetafieldsPayload::validateAndCreate([
        'items' => [[
            'definitionId' => $storedRequired->id,
            'value' => 'SKU',
        ]],
    ]));

    $result = $action->execute(metafieldTestOwner(), SyncOwnerMetafieldsPayload::validateAndCreate([
        'items' => [[
            'definitionId' => $optional->id,
            'value' => 'Consumer note',
        ]],
    ]));

    expect($result->sole()->definition_id)->toBe($optional->id);
});

it('rejects genuinely missing required section values', function (): void {
    $required = MetafieldDefinition::factory()->required()->create([
        'namespace' => 'product',
        'key' => 'required',
    ]);
    $optional = MetafieldDefinition::factory()->create([
        'namespace' => 'product',
        'key' => 'optional',
    ]);
    assignMetafieldTestDefinition($required, true);
    assignMetafieldTestDefinition($optional);

    expect(fn () => app(SyncOwnerMetafieldsAction::class)->execute(
        metafieldTestOwner(),
        SyncOwnerMetafieldsPayload::validateAndCreate([
            'items' => [[
                'definitionId' => $optional->id,
                'value' => 'value',
            ]],
        ]),
    ))->toThrow(ValidationException::class);

    expect(Metafield::query()->count())->toBe(0);
});

it('rejects null values required only by the owner assignment', function (): void {
    $definition = MetafieldDefinition::factory()->create([
        'namespace' => 'product',
        'key' => 'assignment_required',
        'is_required' => false,
    ]);
    assignMetafieldTestDefinition($definition, true);

    expect(fn () => app(SyncOwnerMetafieldsAction::class)->execute(
        metafieldTestOwner(),
        SyncOwnerMetafieldsPayload::validateAndCreate([
            'items' => [[
                'definitionId' => $definition->id,
                'value' => null,
            ]],
        ]),
    ))->toThrow(ValidationException::class)
        ->and(Metafield::query()->where('definition_id', $definition->id)->exists())->toBeFalse();
});

it('rejects duplicate definitions even for directly constructed trusted payloads', function (): void {
    $definition = MetafieldDefinition::factory()->create();
    assignMetafieldTestDefinition($definition);

    $payload = SyncOwnerMetafieldsPayload::from([
        'items' => [
            ['definitionId' => $definition->id, 'value' => 'one'],
            ['definitionId' => $definition->id, 'value' => 'two'],
        ],
    ]);

    expect(fn () => app(SyncOwnerMetafieldsAction::class)->execute(metafieldTestOwner(), $payload))
        ->toThrow(ValidationException::class);

    expect(Metafield::query()->count())->toBe(0);
});

it('rejects an empty directly constructed synchronization batch', function (): void {
    $payload = SyncOwnerMetafieldsPayload::from(['items' => []]);

    expect(fn () => app(SyncOwnerMetafieldsAction::class)->execute(metafieldTestOwner(), $payload))
        ->toThrow(ValidationException::class);
});

it('rolls back the entire owner sync when a later value is invalid', function (): void {
    $stringDefinition = MetafieldDefinition::factory()->create([
        'namespace' => 'product',
        'key' => 'label',
    ]);
    $integerDefinition = MetafieldDefinition::factory()->ofType(MetafieldTypeEnum::Integer)->create([
        'namespace' => 'product',
        'key' => 'quantity',
    ]);
    assignMetafieldTestDefinition($stringDefinition);
    assignMetafieldTestDefinition($integerDefinition);

    expect(fn () => app(SyncOwnerMetafieldsAction::class)->execute(
        metafieldTestOwner(),
        SyncOwnerMetafieldsPayload::validateAndCreate([
            'items' => [
                ['definitionId' => $stringDefinition->id, 'value' => 'written first'],
                ['definitionId' => $integerDefinition->id, 'value' => 'not an integer'],
            ],
        ]),
    ))->toThrow(ValidationException::class);

    expect(Metafield::query()->count())->toBe(0);
});

it('resolves reference aliases without persisting application class names', function (): void {
    $target = TestMetafieldOwner::query()->create(['name' => 'Referenced product']);
    $definition = app(CreateMetafieldDefinitionAction::class)->execute(
        CreateMetafieldDefinitionPayload::validateAndCreate([
            'namespace' => 'product',
            'key' => 'related',
            'type' => 'reference',
            'referencedModelType' => 'products',
            'assignment' => [
                'ownerType' => 'products',
                'section' => 'details',
            ],
            'translations' => [
                'en' => ['title' => 'Related item'],
            ],
        ]),
    );
    expect($definition->getAttributes())->toMatchArray([
        'namespace' => 'product',
        'key' => 'related',
        'type' => 'reference',
        'referenced_model_type' => 'products',
    ]);

    $metafield = app(SyncOwnerMetafieldsAction::class)->execute(
        metafieldTestOwner(),
        SyncOwnerMetafieldsPayload::validateAndCreate([
            'items' => [[
                'definitionId' => $definition->id,
                'value' => (string) $target->getKey(),
            ]],
        ]),
    )->sole();

    expect($metafield->referenced_id)->toBe((string) $target->getKey())
        ->and($metafield->getValue()->is($target))->toBeTrue()
        ->and($definition->referenced_model_type)->toBe('products');
});

it('requires explicit cascading deletion when active owner values exist', function (): void {
    $definition = MetafieldDefinition::factory()->create();
    assignMetafieldTestDefinition($definition);
    app(SyncOwnerMetafieldsAction::class)->execute(
        metafieldTestOwner(),
        SyncOwnerMetafieldsPayload::validateAndCreate([
            'items' => [[
                'definitionId' => $definition->id,
                'value' => 'preserve me',
            ]],
        ]),
    );
    $action = app(DeleteMetafieldDefinitionAction::class);

    expect(fn () => $action->execute($definition, $definition->revision))
        ->toThrow(ValidationException::class)
        ->and($definition->fresh())->not->toBeNull()
        ->and(Metafield::query()->count())->toBe(1);

    expect($action->execute($definition, $definition->revision, true))->toBeTrue()
        ->and(MetafieldDefinition::query()->find($definition->id))->toBeNull()
        ->and(Metafield::query()->count())->toBe(0);
});

it('rejects malformed owner registry configuration with an actionable exception', function (): void {
    config()->set('metafields.owners.products.model', stdClass::class);

    expect(fn () => app(MetafieldOwnerRegistry::class)->forType('products'))
        ->toThrow(InvalidArgumentException::class, 'must configure an Eloquent model class');
});

it('rejects stale definition and value mutations while returning current revisions', function (): void {
    $definition = MetafieldDefinition::factory()->create([
        'namespace' => 'content',
        'key' => 'label',
    ]);
    assignMetafieldTestDefinition($definition);
    $definitionRevision = $definition->revision;

    $payload = UpdateMetafieldDefinitionPayload::validateAndCreate([
        'namespace' => 'content',
        'key' => 'label',
        'type' => 'string',
        'expectedRevision' => $definitionRevision,
        'assignment' => [
            'ownerType' => 'products',
            'section' => 'general',
        ],
        'translations' => [
            'en' => ['title' => 'Updated label'],
        ],
    ]);
    $updated = app(UpdateMetafieldDefinitionAction::class)->execute($definition, $payload);

    expect($updated->revision)->toBe($definitionRevision + 1)
        ->and(fn () => app(UpdateMetafieldDefinitionAction::class)->execute($updated, $payload))
        ->toThrow(StaleMetafieldVersionException::class);

    $metafield = app(SyncOwnerMetafieldsAction::class)->execute(
        metafieldTestOwner(),
        SyncOwnerMetafieldsPayload::validateAndCreate([
            'items' => [[
                'definitionId' => $definition->id,
                'value' => 'one',
            ]],
        ]),
    )->sole();

    $valueRevision = $metafield->revision;
    $action = app(SyncOwnerMetafieldsAction::class);
    $action->execute(
        metafieldTestOwner(),
        SyncOwnerMetafieldsPayload::validateAndCreate([
            'items' => [[
                'definitionId' => $definition->id,
                'value' => 'two',
                'expectedRevision' => $valueRevision,
            ]],
        ]),
    );

    expect(fn () => $action->execute(
        metafieldTestOwner(),
        SyncOwnerMetafieldsPayload::validateAndCreate([
            'items' => [[
                'definitionId' => $definition->id,
                'value' => 'three',
                'expectedRevision' => $valueRevision,
            ]],
        ]),
    ))->toThrow(StaleMetafieldVersionException::class);
});

it('archives definitions and allows a new active definition to reuse the handle', function (): void {
    $definition = MetafieldDefinition::factory()->create([
        'namespace' => 'content',
        'key' => 'summary',
    ]);

    $archived = app(ArchiveMetafieldDefinitionAction::class)->execute(
        $definition,
        ArchiveMetafieldDefinitionPayload::validateAndCreate([
            'archived' => true,
            'expectedRevision' => $definition->revision,
        ]),
    );

    $replacement = MetafieldDefinition::factory()->create([
        'namespace' => 'content',
        'key' => 'summary',
    ]);
    assignMetafieldTestDefinition($replacement);
    $resolved = app(MetafieldDefinitionCatalog::class)->findByHandle('content.summary');
    $stored = app(SetMetafieldAction::class)->execute(
        metafieldTestOwner(),
        'content.summary',
        'Replacement summary',
    );

    expect($archived->archived_at)->not->toBeNull()
        ->and($archived->active_handle)->toBeNull()
        ->and($replacement->active_handle)->toBe('content.summary')
        ->and($resolved?->is($replacement))->toBeTrue()
        ->and($stored->definition_id)->toBe($replacement->id);
});

it('rejects restoring an archived definition when its handle has an active replacement', function (): void {
    $definition = MetafieldDefinition::factory()->create([
        'namespace' => 'content',
        'key' => 'restorable',
    ]);
    $action = app(ArchiveMetafieldDefinitionAction::class);
    $archived = $action->execute(
        $definition,
        ArchiveMetafieldDefinitionPayload::validateAndCreate([
            'archived' => true,
            'expectedRevision' => $definition->revision,
        ]),
    );
    $replacement = MetafieldDefinition::factory()->create([
        'namespace' => 'content',
        'key' => 'restorable',
    ]);

    expect(fn () => $action->execute(
        $archived,
        ArchiveMetafieldDefinitionPayload::validateAndCreate([
            'archived' => false,
            'expectedRevision' => $archived->revision,
        ]),
    ))->toThrow(ValidationException::class);

    expect($archived->refresh()->archived_at)->not->toBeNull()
        ->and($replacement->refresh()->active_handle)->toBe('content.restorable');
});

it('omits archived definitions from owner field catalogs', function (): void {
    $definition = MetafieldDefinition::factory()->create([
        'namespace' => 'content',
        'key' => 'archived_field',
    ]);
    assignMetafieldTestDefinition($definition);

    app(ArchiveMetafieldDefinitionAction::class)->execute(
        $definition,
        ArchiveMetafieldDefinitionPayload::validateAndCreate([
            'archived' => true,
            'expectedRevision' => $definition->revision,
        ]),
    );

    expect(app(ListOwnerMetafieldsAction::class)->execute(metafieldTestOwner()))->toBeEmpty();
});

it('enforces decimal precision reference lists and bounded structured payloads', function (): void {
    expect(MetafieldTypeEnum::Decimal->cast('120.3400'))->toBe('120.34');

    $targetOne = TestMetafieldOwner::query()->create(['name' => 'One']);
    $targetTwo = TestMetafieldOwner::query()->create(['name' => 'Two']);
    $references = MetafieldDefinition::factory()->ofType(MetafieldTypeEnum::ReferenceList)->create([
        'namespace' => 'content',
        'key' => 'related',
        'referenced_model_type' => 'products',
    ]);
    assignMetafieldTestDefinition($references);

    $stored = app(SyncOwnerMetafieldsAction::class)->execute(
        metafieldTestOwner(),
        SyncOwnerMetafieldsPayload::validateAndCreate([
            'items' => [[
                'definitionId' => $references->id,
                'value' => [(string) $targetOne->getKey(), (string) $targetTwo->getKey()],
            ]],
        ]),
    )->sole();

    expect($stored->getValue()->map->getKey()->all())
        ->toBe([$targetOne->getKey(), $targetTwo->getKey()])
        ->and(OwnerMetafieldValue::fromModel($stored, 'products')->value)
        ->toBe([(string) $targetOne->getKey(), (string) $targetTwo->getKey()]);

    $references->validation_rules = ['max:1'];
    $references->save();

    expect(fn () => app(SyncOwnerMetafieldsAction::class)->execute(
        metafieldTestOwner(),
        SyncOwnerMetafieldsPayload::validateAndCreate([
            'items' => [[
                'definitionId' => $references->id,
                'value' => [(string) $targetOne->getKey(), (string) $targetTwo->getKey()],
                'expectedRevision' => $stored->revision,
            ]],
        ]),
    ))->toThrow(ValidationException::class);

    config()->set('metafields.limits.maximum_json_items', 2);
    $structured = MetafieldDefinition::factory()->ofType(MetafieldTypeEnum::Json)->create([
        'namespace' => 'content',
        'key' => 'structured',
    ]);
    assignMetafieldTestDefinition($structured);

    expect(fn () => app(SyncOwnerMetafieldsAction::class)->execute(
        metafieldTestOwner(),
        SyncOwnerMetafieldsPayload::validateAndCreate([
            'items' => [[
                'definitionId' => $structured->id,
                'value' => ['one' => 1, 'two' => 2, 'three' => 3],
            ]],
        ]),
    ))->toThrow(ValidationException::class);

    config()->set('metafields.limits.maximum_sync_items', 1);

    expect(fn () => SyncOwnerMetafieldsPayload::validateAndCreate([
        'items' => [
            ['definitionId' => (string) Str::uuid(), 'value' => 'one'],
            ['definitionId' => (string) Str::uuid(), 'value' => 'two'],
        ],
    ]))->toThrow(ValidationException::class);

    config()->set('metafields.limits.maximum_json_items', 1);

    expect(fn () => CreateMetafieldDefinitionPayload::validateAndCreate([
        'namespace' => 'content',
        'key' => 'bounded_metadata',
        'type' => 'string',
        'assignment' => [
            'ownerType' => 'products',
            'section' => 'general',
            'uiConfig' => ['width' => 'half', 'tone' => 'muted'],
        ],
        'translations' => ['en' => ['title' => 'Bounded metadata']],
    ]))->toThrow(ValidationException::class);
});

it('filters owners only through definitions explicitly marked filterable', function (): void {
    $otherOwner = TestMetafieldOwner::query()->create(['name' => 'Other owner']);
    $definition = MetafieldDefinition::factory()->ofType(MetafieldTypeEnum::Boolean)->create([
        'namespace' => 'content',
        'key' => 'featured',
        'is_filterable' => false,
    ]);
    assignMetafieldTestDefinition($definition);
    app(SyncOwnerMetafieldsAction::class)->execute(
        metafieldTestOwner(),
        SyncOwnerMetafieldsPayload::validateAndCreate([
            'items' => [[
                'definitionId' => $definition->id,
                'value' => true,
            ]],
        ]),
    );

    $unfiltered = OwnerMetafieldBooleanFilter::apply(
        TestMetafieldOwner::query(),
        'metafields',
        $definition->handle,
        true,
    )->pluck('id');

    $definition->is_filterable = true;
    $definition->save();

    $filtered = OwnerMetafieldBooleanFilter::apply(
        TestMetafieldOwner::query(),
        'metafields',
        $definition->handle,
        true,
    )->pluck('id');

    expect($unfiltered)->toHaveCount(2)
        ->and($filtered->all())->toBe([metafieldTestOwner()->getKey()])
        ->and($filtered->contains($otherOwner->getKey()))->toBeFalse();
});

it('rejects decimal formats that cannot be cast for storage', function (): void {
    $definition = MetafieldDefinition::factory()->ofType(MetafieldTypeEnum::Decimal)->create([
        'namespace' => 'content',
        'key' => 'precise_decimal',
    ]);
    assignMetafieldTestDefinition($definition);

    expect(fn () => app(SyncOwnerMetafieldsAction::class)->execute(
        metafieldTestOwner(),
        SyncOwnerMetafieldsPayload::validateAndCreate([
            'items' => [[
                'definitionId' => $definition->id,
                'value' => '1e3',
            ]],
        ]),
    ))->toThrow(ValidationException::class);
});

it('rejects unsafe configurable validation rules', function (): void {
    expect(MetafieldValidationRuleCompiler::invalidCustomRules(
        MetafieldTypeEnum::String,
        ['exists:users,id', 'unique:users,email', 'active_url', 'regex:/(a+)+$/'],
    ))->toBe([
        'exists:users,id',
        'unique:users,email',
        'active_url',
        'regex:/(a+)+$/',
    ]);
});

it('authorizes every referenced record before persistence', function (): void {
    $target = TestMetafieldOwner::query()->create(['name' => 'Restricted']);
    $definition = MetafieldDefinition::factory()->ofType(MetafieldTypeEnum::Reference)->create([
        'namespace' => 'content',
        'key' => 'restricted_reference',
        'referenced_model_type' => 'products',
    ]);
    assignMetafieldTestDefinition($definition);
    app()->instance(
        MetafieldReferenceAuthorization::class,
        new class implements MetafieldReferenceAuthorization
        {
            public function authorize(
                Model $owner,
                MetafieldDefinition $definition,
                Model $reference,
            ): void {
                throw new AuthorizationException('Reference denied.');
            }
        },
    );

    expect(fn () => app(SyncOwnerMetafieldsAction::class)->execute(
        metafieldTestOwner(),
        SyncOwnerMetafieldsPayload::validateAndCreate([
            'items' => [[
                'definitionId' => $definition->id,
                'value' => (string) $target->getKey(),
            ]],
        ]),
    ))->toThrow(AuthorizationException::class)
        ->and(Metafield::query()->where('definition_id', $definition->id)->exists())->toBeFalse();
});

it('reports a healthy standalone schema through the machine-readable doctor', function (): void {
    $this->artisan('nvl:metafields:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ])->assertSuccessful();
});
