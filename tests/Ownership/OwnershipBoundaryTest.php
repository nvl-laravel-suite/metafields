<?php

declare(strict_types=1);
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Nvl\Metafields\Actions\MetafieldDefinitions\CreateMetafieldDefinitionAction;
use Nvl\Metafields\Actions\Metafields\ListAuthorizedOwnersMetafieldsAction;
use Nvl\Metafields\Actions\Metafields\SetMetafieldAction;
use Nvl\Metafields\Contracts\MetafieldBatchAuthorization;
use Nvl\Metafields\Contracts\MetafieldReferenceAuthorization;
use Nvl\Metafields\Data\CreateMetafieldDefinitionPayload;
use Nvl\Metafields\Events\MetafieldSet;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Services\MetafieldModelRegistry;
use Nvl\Metafields\Services\Metafields\MetafieldReferenceRecordResolver;
use Nvl\Metafields\Tests\Fixtures\BatchMetafieldPolicy;
use Nvl\Metafields\Tests\Fixtures\OwnershipActor;
use Nvl\Metafields\Tests\Fixtures\OwnershipCompany;
use Nvl\Metafields\Tests\Fixtures\OwnershipMetafieldDefinition;
use Nvl\Metafields\Tests\Fixtures\OwnershipMetafieldOwner;
use Nvl\Metafields\Tests\Fixtures\OwnershipMetafieldScenario;
use Nvl\Metafields\Tests\OwnershipTestCase;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OwnershipTestCase::class);
it('isolates configured definitions assignments localized values and loaded defaults', function (): void {
    $foreign = null;
    foreach ([7, 8] as $id) {
        app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail($id), OwnershipActor::firstOrFail(), function () use (&$foreign, $id): void {
            $owner = app(OwnershipWriter::class)->create(OwnershipMetafieldOwner::class, static function (OwnershipAssignment $a): OwnershipMetafieldOwner {
                $m = new OwnershipMetafieldOwner;
                $m->forceFill([...$a->attributes(), 'name' => 'Product'])->save();

                return $m;
            });
            $definition = app(CreateMetafieldDefinitionAction::class)->execute(CreateMetafieldDefinitionPayload::from(['namespace' => 'product', 'key' => 'subtitle', 'type' => 'string', 'isTranslatable' => true, 'assignment' => ['ownerType' => 'products', 'section' => 'general'], 'translations' => ['en' => ['title' => 'Subtitle', 'defaultValue' => 'Default']]]));
            $value = app(SetMetafieldAction::class)->execute($owner, 'product.subtitle', 'Private value', 'en');
            expect($definition::class)->toBe(OwnershipMetafieldDefinition::class)->and($definition->assignments()->count())->toBe(1)->and(MetafieldModelRegistry::query()->count())->toBe(1);
            if ($id === 7) {
                $foreign = $definition;
            } else {
                expect(MetafieldModelRegistry::query()->find($foreign->id))->toBeNull();
                $value->setRelation('definition', $foreign);
                expect(fn () => $value->getValue())->toThrow(OwnershipViolation::class);
            }
        });
    }
});

it('rejects foreign references defaults and raw definition pivots', function (): void {
    app()->bind(MetafieldReferenceAuthorization::class, fn () => new class implements MetafieldReferenceAuthorization
    {
        public function authorize(Model $owner, MetafieldDefinition $definition, Model $reference): void {}
    });
    $foreignOwner = null;
    $foreignDefinition = null;
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(8), OwnershipActor::firstOrFail(), function () use (&$foreignOwner, &$foreignDefinition): void {
        $foreignOwner = OwnershipMetafieldScenario::owner();
        $foreignDefinition = OwnershipMetafieldScenario::definition();
    });
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function () use ($foreignOwner, $foreignDefinition): void {
        $owner = OwnershipMetafieldScenario::owner();
        $definition = OwnershipMetafieldScenario::definition('ref', 'reference');
        $definition->default_referenced_id = $foreignOwner->id;
        expect(fn () => $definition->saveQuietly())->toThrow(OwnershipViolation::class);
        $definition->discardChanges();
        expect(app(MetafieldReferenceRecordResolver::class)->resolve('products', $owner->id.' '))->toBeNull();
        $value = app(SetMetafieldAction::class)->execute($owner, 'product.ref', $owner->id);
        $value->referenced_id = $foreignOwner->id;
        expect(fn () => $value->saveQuietly())->toThrow(OwnershipViolation::class);
        $row = $value->getRawOriginal();
        $row['id'] = (string) Str::uuid();
        $row['definition_id'] = $foreignDefinition->id;
        expect(fn () => $value->getConnection()->transaction(fn () => $value->getConnection()->table($value->getTable())->insert($row)))->toThrow(QueryException::class);
    });
});
it('keeps native batch query counts bounded across one and twenty five host records', function (): void {
    app()->bind(MetafieldBatchAuthorization::class, BatchMetafieldPolicy::class);
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function (): void {
        $definition = OwnershipMetafieldScenario::definition();
        $owners = [];
        for ($i = 0; $i < 25; $i++) {
            $owner = OwnershipMetafieldScenario::owner();
            $owners[] = $owner;
            app(SetMetafieldAction::class)->execute($owner, 'product.text', 'Value');
        }
        $measure = function (array $owners): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $result = app(ListAuthorizedOwnersMetafieldsAction::class)->execute($owners, 'en');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();
            expect($result->order)->toHaveCount(count($owners));

            return $count;
        };
        $one = $measure([$owners[0]]);
        $many = $measure($owners);
        expect($many)->toBeLessThanOrEqual($one + 2);
    });
});

it('captures native owner authority on committed scalar value events', function (): void {
    $events = [];
    Event::listen(MetafieldSet::class, function (MetafieldSet $event) use (&$events): void {
        $events[] = $event;
    });
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function (): void {
        $owner = OwnershipMetafieldScenario::owner();
        OwnershipMetafieldScenario::definition();
        app(SetMetafieldAction::class)->execute($owner, 'product.text', 'Private scalar value');
    });
    expect($events)->toHaveCount(1)->and($events[0])->toBeInstanceOf(OwnershipQueuedJob::class)
        ->and($events[0]->ownershipJobEnvelope()->data['owner']['key'])->toBe('7')->and(serialize($events))->not->toContain('Private scalar value');
});
