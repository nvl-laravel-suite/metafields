<?php

declare(strict_types=1);

return [
    'attributes' => [
        'namespace' => 'namespace',
        'key' => 'key',
        'type' => 'type',
        'title' => 'title',
        'description' => 'description',
        'hint' => 'hint',
        'referencedModelType' => 'referenced model type',
        'referenced_model_type' => 'referenced model type',
        'isTranslatable' => 'translatable flag',
        'is_translatable' => 'translatable flag',
        'isRequired' => 'required flag',
        'is_required' => 'required flag',
        'isFilterable' => 'filterable flag',
        'is_filterable' => 'filterable flag',
        'validationRules' => 'validation rules',
        'validation_rules' => 'validation rules',
        'jsonPropertySchema' => 'JSON property schema',
        'defaultValue' => 'default value',
        'default_value' => 'default value',
        'displayOrder' => 'display order',
        'display_order' => 'display order',
    ],
    'custom' => [
        'namespace' => [
            'regex' => 'The namespace may only contain lowercase letters, numbers, underscores, and hyphens.',
        ],
        'key' => [
            'regex' => 'The key may only contain lowercase letters, numbers, underscores, and hyphens.',
        ],
        'referencedModelType' => [
            'required_if' => 'A referenced model type is required for reference metafields.',
            'not_allowed' => 'The referenced model type is not supported for metafields.',
        ],
        'referenced_model_type' => [
            'required_if' => 'A referenced model type is required for reference metafields.',
            'not_allowed' => 'The referenced model type is not supported for metafields.',
        ],
        'defaultValue' => [
            'invalid_type' => 'The default value must match the selected metafield type.',
            'invalid_reference' => 'The default reference must point to an existing record.',
        ],
        'default_value' => [
            'invalid_type' => 'The default value must match the selected metafield type.',
            'invalid_reference' => 'The default reference must point to an existing record.',
        ],
        'jsonPropertySchema' => [
            'required_for_json' => 'JSON metafields must define at least one JSON property.',
            'only_for_json' => 'JSON properties are only supported for JSON metafields.',
            'unique_keys' => 'Each JSON property key must be unique.',
        ],
        'validationRules' => [
            'invalid_rule' => 'The validation rule ":rule" is not supported for this metafield type.',
            'forbidden_for_json' => 'Use JSON properties instead of free-form validation rules for JSON metafields.',
        ],
        'validation_rules' => [
            'invalid_rule' => 'The validation rule ":rule" is not supported for this metafield type.',
        ],
    ],
];
