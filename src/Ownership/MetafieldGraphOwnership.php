<?php

declare(strict_types=1);

namespace Nvl\Metafields\Ownership;

use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Services\MetafieldModelRegistry;
use Nvl\Metafields\Services\Metafields\MetafieldReferenceRecordResolver;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/** Rechecks the concrete schema and reference graph during every native save. @internal */
final readonly class MetafieldGraphOwnership
{
    public function __construct(private MetafieldReferenceRecordResolver $references) {}

    public function assertWrite(Model $model): void
    {
        if (! MetafieldOwnership::declared()) {
            return;
        }
        if ($model instanceof Metafield) {
            $definition = MetafieldModelRegistry::query()->whereKey($model->getAttribute('definition_id'))->lockForUpdate()->first();
            if ($definition === null) {
                throw new OwnershipViolation('A value requires its canonical definition in the current owner.');
            }
            $type = $definition->type;
            $alias = $definition->referenced_model_type;
            $id = $model->getAttribute('referenced_id');
            $value = $model->getAttribute('value');
        } elseif ($model instanceof MetafieldDefinition) {
            $type = $model->type;
            $alias = $model->referenced_model_type;
            $id = $model->getAttribute('default_referenced_id');
            $value = $model->getAttribute('default_value');
        } else {
            return;
        }
        $ids = $type === MetafieldTypeEnum::ReferenceList ? $type->cast($value) : ($type === MetafieldTypeEnum::Reference && $id !== null ? [$id] : []);
        if (is_array($ids)) {
            foreach ($ids as $reference) {
                if ($this->references->resolve($alias, $reference, lock: true) === null) {
                    throw new OwnershipViolation('Metafield references require their canonical current owner.');
                }
            }
        }
    }
}
