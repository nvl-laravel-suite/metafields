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
        'defaultValue' => 'default value',
        'validationRules' => 'validation rules',
        'jsonPropertySchema' => 'JSON property schema',
        'displayOrder' => 'display order',
        'assignment' => 'resource assignment',
        'ownerType' => 'owner type',
        'section' => 'section',
        'isActive' => 'active flag',
        'uiConfig' => 'UI configuration',
    ],
    'custom' => [
        'structured_limit' => 'The structured value exceeds the configured Metafields payload limits.',
        'referencedModelType' => [
            'required_if' => 'The referenced model type is required for reference metafields.',
            'not_allowed' => 'The referenced model type is not supported for metafields.',
        ],
        'isTranslatable' => [
            'unsupported_type' => 'The selected metafield type cannot store localized values.',
        ],
        'assignment' => [
            'ownerType' => [
                'unsupported_type' => 'The selected owner type does not support this metafield type.',
            ],
            'section' => [
                'unsupported' => 'The selected section is not registered for this owner type.',
            ],
        ],
        'translations' => [
            'new_locale_title' => 'A title is required when adding a new definition locale.',
            'defaultValue' => [
                'localized_storage' => 'Localized defaults are only allowed when the definition is translatable.',
            ],
        ],
        'defaultValue' => [
            'invalid_type' => 'The default value must match the selected metafield type.',
            'invalid_reference' => 'The default reference must point to an existing record.',
            'nonlocalized_storage' => 'Translatable definitions must store defaults inside their locale entries.',
        ],
        'definition' => [
            'active_values_shape_change' => 'The :field cannot be changed while active metafield values exist.',
            'active_values_delete' => 'This definition still has active owner values. Set deleteValues to true to delete them explicitly.',
            'active_handle_conflict' => 'This definition cannot be restored because another active definition uses the same handle.',
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
    ],
];
