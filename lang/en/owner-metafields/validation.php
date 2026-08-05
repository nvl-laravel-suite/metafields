<?php

declare(strict_types=1);

return [
    'attributes' => [
        'items' => 'metafields',
        'definitionId' => 'definition',
        'definition_id' => 'definition',
        'clear' => 'clear flag',
        'value' => 'value',
        'translations' => 'translations',
    ],
    'custom' => [
        'expectedRevision' => [
            'required' => 'The expected revision is required when changing an existing metafield value.',
        ],
        'items' => [
            'required' => 'At least one metafield must be supplied.',
            'distinct' => 'Each metafield definition may appear only once in a sync operation.',
            'assigned' => 'The following definitions are not assigned to this owner type: :definitions.',
            'missing_required' => 'The following required metafields have no submitted, stored, or default value: :definitions.',
        ],
        'clear' => [
            'required_assignment' => 'Required metafields without a default value cannot be cleared.',
        ],
        'definitionId' => [
            'missing_definition' => 'The selected metafield definition could not be loaded.',
            'unsupported_type' => 'This owner type does not support the selected metafield definition type.',
            'invalid_definition_rules' => 'The selected metafield definition has unsupported validation rules: :rules.',
        ],
        'value' => [
            'invalid' => 'The metafield value is invalid for the selected definition.',
            'translatable_forbidden' => 'Use translations instead of a single value for translatable metafields.',
        ],
        'translations' => [
            'required' => 'Translations are required for translatable metafields.',
            'not_allowed' => 'Translations are not allowed for non-translatable metafields.',
            'invalid' => 'The translated value is invalid for the selected definition.',
            'locale_key' => 'Each translation must use a valid locale key.',
            'null_not_allowed' => 'A supplied translation must contain a value; omit the locale in patch mode or use replace mode to remove it.',
            'reference_not_supported' => 'Translatable reference metafields are not supported.',
        ],
    ],
];
