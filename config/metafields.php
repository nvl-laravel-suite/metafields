<?php

declare(strict_types=1);

return [
    'name' => 'Metafields',

    /*
    |--------------------------------------------------------------------------
    | Optional HTTP API
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'enabled' => false,
        'prefix' => 'api/v1',
        'middleware' => ['api'],
        'management_middleware' => ['auth', 'throttle:metafields-management'],
        'rate_limit_per_minute' => 60,
    ],

    'migrations' => [
        'enabled' => true,
    ],

    'transactions' => [
        'attempts' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | Set owner_ability to null to authorize owner mutations through the
    | owner's update policy. A named Gate ability may be configured instead.
    |
    */
    'authorization' => [
        'owner_ability' => null,
        'definition_ability' => null,
        'reference_ability' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Owner Registry
    |--------------------------------------------------------------------------
    |
    | Applications opt models into Metafields with stable aliases. No host
    | application models are assumed by the package.
    |
    | 'articles' => [
    |     'model' => Domain\Content\Models\Article::class,
    |     'label' => 'Articles',
    |     'supported_types' => ['string', 'integer'],
    |     'sections' => ['general'],
    |     'runtime_status' => 'live',
    | ],
    |
    */
    'owners' => [],

    /*
    |--------------------------------------------------------------------------
    | Reference Model Registry
    |--------------------------------------------------------------------------
    |
    | Reference metafields may target every registered owner model plus the
    | additional stable aliases below. Persisted definitions use the alias so
    | application namespace refactors do not invalidate stored references.
    |
    | 'reference_models' => [
    |     'collections' => Domain\Catalog\Models\Collection::class,
    | ],
    |
    */
    'reference_models' => [],

    'limits' => [
        'maximum_json_bytes' => 262_144,
        'maximum_json_depth' => 16,
        'maximum_json_items' => 1_000,
        'maximum_schema_properties' => 100,
        'maximum_sync_items' => 100,
    ],
];
