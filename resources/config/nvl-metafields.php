<?php

declare(strict_types=1);
use Nvl\Metafields\Models\MetafieldDefinition;

/** Common published defaults; advanced defaults remain in the package configuration. */
return [
    'models' => ['definition' => MetafieldDefinition::class],
    'ownership' => ['owner_resources' => []],
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
