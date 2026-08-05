<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\Metafields;

use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Contracts\MetafieldReferenceAuthorization;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Support\MetafieldJsonPropertySchemaValidator;
use Nvl\Metafields\Support\MetafieldPayloadLimits;
use Nvl\Metafields\Support\MetafieldReferenceModelRegistry;
use Nvl\Metafields\Support\MetafieldValidationRuleCompiler;

/**
 * Validates typed owner values and authorizes every referenced record.
 */
final readonly class MetafieldValueValidator
{
    /**
     * Create the metafield value validator.
     */
    public function __construct(
        private MetafieldReferenceAuthorization $referenceAuthorization,
    ) {}

    /**
     * Return configured rules that are not supported by the package boundary.
     *
     * @return array<int, string>
     */
    public function invalidCustomRules(MetafieldDefinition $definition): array
    {
        if ($definition->type->value === 'json'
            && is_array($definition->json_property_schema)
            && $definition->json_property_schema !== []) {
            return [];
        }

        return MetafieldValidationRuleCompiler::invalidCustomRules(
            $definition->type,
            $this->customRules($definition),
        );
    }

    /**
     * Determine whether an owner value satisfies its effective definition contract.
     *
     * @param  MetafieldDefinition  $definition  Definition describing the value
     * @param  Model  $owner  Owner receiving the value
     * @param  mixed  $value  Candidate value
     * @param  bool  $required  Effective definition and assignment requirement
     */
    public function passes(
        MetafieldDefinition $definition,
        Model $owner,
        mixed $value,
        bool $required,
    ): bool {
        if ($definition->type === MetafieldTypeEnum::Reference) {
            return $this->referencePasses($definition, $owner, $value, $required);
        }

        if ($definition->type === MetafieldTypeEnum::ReferenceList) {
            return $this->referenceListPasses($definition, $owner, $value, $required);
        }

        if (in_array($definition->type, [
            MetafieldTypeEnum::Json,
            MetafieldTypeEnum::ArrayValue,
        ], true) && ! MetafieldPayloadLimits::accepts($value)) {
            return false;
        }

        if ($definition->type->value === 'json'
            && is_array($definition->json_property_schema)
            && $definition->json_property_schema !== []) {
            return MetafieldJsonPropertySchemaValidator::passes(
                $this->jsonPropertySchema($definition),
                $value,
                $required,
            );
        }

        return MetafieldValidationRuleCompiler::passes(
            $definition->type,
            $required,
            $this->customRules($definition),
            $value,
        );
    }

    private function referencePasses(
        MetafieldDefinition $definition,
        Model $owner,
        mixed $value,
        bool $required,
    ): bool {
        if ($value === null || $value === '') {
            return ! $required;
        }

        if (! MetafieldValidationRuleCompiler::passes(
            $definition->type,
            $required,
            $this->customRules($definition),
            $value,
        )) {
            return false;
        }

        $reference = MetafieldReferenceModelRegistry::findReferencedRecord(
            $definition->referenced_model_type,
            $value,
        );

        if (! $reference instanceof Model) {
            return false;
        }

        $this->referenceAuthorization->authorize($owner, $definition, $reference);

        return true;
    }

    private function referenceListPasses(
        MetafieldDefinition $definition,
        Model $owner,
        mixed $value,
        bool $required,
    ): bool {
        if ($value === null || $value === []) {
            return ! $required;
        }

        if (! MetafieldValidationRuleCompiler::passes(
            $definition->type,
            $required,
            $this->customRules($definition),
            $value,
        ) || ! is_array($value)) {
            return false;
        }

        foreach ($value as $reference) {
            $referencedRecord = MetafieldReferenceModelRegistry::findReferencedRecord(
                $definition->referenced_model_type,
                $reference,
            );

            if (! $referencedRecord instanceof Model) {
                return false;
            }

            $this->referenceAuthorization->authorize($owner, $definition, $referencedRecord);
        }

        return true;
    }

    /**
     * @return list<mixed>|null
     */
    private function customRules(MetafieldDefinition $definition): ?array
    {
        if (! is_array($definition->validation_rules)) {
            return null;
        }

        return $definition->validation_rules;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jsonPropertySchema(MetafieldDefinition $definition): array
    {
        if (! is_array($definition->json_property_schema)) {
            return [];
        }

        return array_values($definition->json_property_schema);
    }
}
