<?php

declare(strict_types=1);

namespace Nvl\Metafields\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Nvl\Metafields\Actions\MetafieldDefinitions\CreateMetafieldDefinitionAction;
use Nvl\Metafields\Data\CreateMetafieldDefinitionPayload;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Support\MetafieldConfiguration;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Metafields\Support\MetafieldReferenceModelRegistry;

/**
 * MetafieldDefinitionAddCommand
 *
 * Interactive command to add a new metafield definition.
 */
final class MetafieldDefinitionAddCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nvl:metafields:definition-add 
                            {namespace? : The namespace (e.g., content)} 
                            {key? : The key identifier} 
                            {type? : The data type}
                            {ownerType? : The owner type (e.g., articles)}
                            {--is-translatable : Support translations}
                            {--is-required : Field is mandatory}
                            {--is-filterable : Field is filterable}
                            {--reference-model-type= : Stable reference model alias}
                            {--json-property-schema= : JSON array describing JSON properties}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add a new metafield definition interactively';

    /**
     * Execute the console command.
     */
    public function handle(
        CreateMetafieldDefinitionAction $action,
        MetafieldOwnerRegistry $ownerRegistry,
    ): int {
        $namespaceInput = $this->argument('namespace');
        $namespace = is_string($namespaceInput) && $namespaceInput !== ''
            ? $namespaceInput
            : $this->ask('Namespace (e.g., content)');
        $keyInput = $this->argument('key');
        $key = is_string($keyInput) && $keyInput !== ''
            ? $keyInput
            : $this->ask('Key identifier (e.g., color)');

        $type = $this->argument('type');
        if (! is_string($type) || $type === '') {
            $type = $this->choice(
                'Data type',
                array_column(MetafieldTypeEnum::cases(), 'value'),
                'string'
            );
        }

        if (! is_string($namespace)
            || ! is_string($key)
            || ! is_string($type)
            || MetafieldTypeEnum::tryFrom($type) === null) {
            $this->error('Namespace, key, and a supported metafield type are required.');

            return Command::FAILURE;
        }

        $titleInput = $this->ask('Human-readable title', ucfirst($key));
        $title = is_string($titleInput) && trim($titleInput) !== ''
            ? trim($titleInput)
            : ucfirst($key);
        $description = $this->ask('Description (optional)');

        try {
            $fieldType = MetafieldTypeEnum::from($type);
            $ownerType = $this->resolveOwnerType();
            $section = $ownerRegistry->forType($ownerType)->sections[0] ?? 'general';
            $configuredLocale = config('translatable.default_locale', 'en');
            $locale = is_string($configuredLocale) && $configuredLocale !== ''
                ? $configuredLocale
                : 'en';
            $payload = [
                'namespace' => $namespace,
                'key' => $key,
                'type' => $type,
                'isTranslatable' => (bool) $this->option('is-translatable'),
                'isRequired' => (bool) $this->option('is-required'),
                'isFilterable' => (bool) $this->option('is-filterable'),
                'translations' => [
                    $locale => [
                        'title' => $title,
                        'description' => is_string($description) && trim($description) !== ''
                            ? trim($description)
                            : null,
                    ],
                ],
                'assignment' => [
                    'ownerType' => $ownerType,
                    'section' => $section,
                    'displayOrder' => 0,
                    'isRequired' => false,
                    'isActive' => true,
                ],
            ];

            $referencedModelType = $this->resolveReferencedModelType($fieldType);

            if (is_string($referencedModelType)) {
                $payload['referencedModelType'] = $referencedModelType;
            }

            $jsonPropertySchema = $this->resolveJsonPropertySchema($fieldType);

            if (is_array($jsonPropertySchema)) {
                $payload['jsonPropertySchema'] = $jsonPropertySchema;
            }

            $data = CreateMetafieldDefinitionPayload::validateAndCreate($payload);
            $definition = $action->execute($data);
            $this->info("Successfully created metafield definition: {$definition->handle}");

            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->error('Error creating definition: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Resolve a stable reference alias for reference-backed definition types.
     */
    private function resolveReferencedModelType(MetafieldTypeEnum $type): ?string
    {
        if (! in_array($type, [
            MetafieldTypeEnum::Reference,
            MetafieldTypeEnum::ReferenceList,
        ], true)) {
            return null;
        }

        $aliases = array_keys(MetafieldReferenceModelRegistry::all());
        $configuredAlias = $this->option('reference-model-type');

        if (is_string($configuredAlias) && $configuredAlias !== '') {
            return $configuredAlias;
        }

        if ($aliases === []) {
            throw new InvalidArgumentException('No metafield reference model aliases are registered.');
        }

        $selected = $this->choice('Reference model type', $aliases, $aliases[0]);

        if (! is_string($selected) || $selected === '') {
            throw new InvalidArgumentException('A reference model alias is required.');
        }

        return $selected;
    }

    /**
     * Resolve and decode the property schema required by JSON definitions.
     *
     * @return list<array<string, mixed>>|null
     */
    private function resolveJsonPropertySchema(MetafieldTypeEnum $type): ?array
    {
        if ($type !== MetafieldTypeEnum::Json) {
            return null;
        }

        $schemaInput = $this->option('json-property-schema');

        if (! is_string($schemaInput) || trim($schemaInput) === '') {
            $schemaInput = $this->ask('JSON property schema');
        }

        if (! is_string($schemaInput) || trim($schemaInput) === '') {
            throw new InvalidArgumentException('A JSON property schema is required.');
        }

        $schema = json_decode($schemaInput, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($schema) || ! array_is_list($schema)) {
            throw new InvalidArgumentException('The JSON property schema must be a JSON array.');
        }

        /** @var list<array<string, mixed>> $schema */
        return $schema;
    }

    private function resolveOwnerType(): string
    {
        $ownerType = $this->argument('ownerType');

        if (is_string($ownerType) && $ownerType !== '') {
            return $ownerType;
        }

        $ownerTypes = MetafieldConfiguration::ownerAliases();

        if ($ownerTypes === []) {
            throw new Exception('No metafield owner types are registered in [metafields.owners].');
        }

        $selected = $this->choice(
            'Owner type',
            $ownerTypes,
            $ownerTypes[0],
        );

        if (! is_string($selected)) {
            throw new Exception('A valid metafield owner type must be selected.');
        }

        return $selected;
    }
}
