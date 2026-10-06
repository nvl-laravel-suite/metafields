<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Optional HTTP API
    |--------------------------------------------------------------------------
    */
    'routes' => ['enabled' => false, 'prefix' => 'nvl/api/v1', 'middleware' => ['api'], 'management_middleware' => ['auth', 'throttle:nvl.metafields.management'], 'rate_limit_per_minute' => 60],
    'migrations' => ['enabled' => true],
    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | Set owner_ability to null to authorize owner mutations through the
    | owner's update policy. A named Gate ability may be configured instead.
    |
    */
    'authorization' => ['owner_ability' => null, 'definition_ability' => null, 'reference_ability' => null],
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
];
