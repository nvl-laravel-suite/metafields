<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Nvl\Metafields\Contracts\MetafieldReferenceAuthorization;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldTranslation;
use Nvl\Metafields\Services\MetafieldDefinitions;
use Nvl\Metafields\Services\MetafieldModelRegistry;
use Nvl\Metafields\Services\MetafieldValues;
use Nvl\Metafields\Tests\Fixtures\OwnershipActor;
use Nvl\Metafields\Tests\Fixtures\OwnershipCompany;
use Nvl\Metafields\Tests\Fixtures\OwnershipMetafieldOwner;
use Nvl\Metafields\Tests\Fixtures\OwnershipMetafieldScenario;
use Nvl\Metafields\Tests\OwnershipTestCase;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\OptsIntoAdoptedSchemaReuse;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OwnershipTestCase::class, OptsIntoAdoptedSchemaReuse::class);

/** Count the storage queries one read performs. */
function metafieldReadQueries(Closure $read): int
{
    DB::enableQueryLog();
    DB::flushQueryLog();
    $read();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $queries;
}

/** Create a localized value with both locale rows in the admitted owner. */
function localizedOwnershipValue(string $key): Metafield
{
    $actor = OwnershipActor::firstOrFail();
    app(MetafieldDefinitions::class)->create(['namespace' => 'product', 'key' => $key, 'type' => 'string', 'isTranslatable' => true, 'assignment' => ['ownerType' => 'products', 'section' => 'general'], 'translations' => ['en' => ['title' => 'Subtitle', 'defaultValue' => 'Default'], 'bg' => ['title' => 'Подзаглавие', 'defaultValue' => 'По подразбиране']]], $actor);
    $owner = OwnershipMetafieldScenario::owner();
    $value = app(MetafieldValues::class)->set($owner, 'product.'.$key, 'English', $actor, 'en');
    $value = app(MetafieldValues::class)->set($owner, 'product.'.$key, 'Български', $actor, 'bg', $value->revision);

    return Metafield::query()->with(['definition', 'translations'])->findOrFail($value->id);
}

it('verifies loaded value locale rows in one admitted query and still rejects substituted rows', function (): void {
    $foreign = null;
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(8), OwnershipActor::firstOrFail(), function () use (&$foreign): void {
        $foreign = localizedOwnershipValue('subtitle')->translations->firstOrFail();
    });
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function () use ($foreign): void {
        $value = localizedOwnershipValue('subtitle');
        $other = localizedOwnershipValue('summary');
        $english = $value->translations->firstWhere('locale', 'en');
        expect($value->translations)->toHaveCount(2)->and($value->getValue('en'))->toBe('English')->and($value->getValue('bg'))->toBe('Български');

        $both = metafieldReadQueries(fn () => $value->getValue('en'));
        $value->setRelation('translations', $value->translations->where('locale', 'en')->values());
        expect(metafieldReadQueries(fn () => $value->getValue('en')))->toBe($both);

        $value->setRelation('translations', collect([$english, $foreign]));
        expect(fn () => $value->getValue('en'))->toThrow(OwnershipViolation::class);

        $value->setRelation('translations', collect([$english, $other->translations->firstOrFail()]));
        expect(fn () => $value->getValue('en'))->toThrow(OwnershipViolation::class, 'A loaded locale row differs from its canonical value.');

        $moved = MetafieldTranslation::query()->findOrFail($other->translations->firstOrFail()->id);
        $moved->setRawAttributes([...$moved->getRawOriginal(), 'metafield_id' => $value->id], true);
        $value->setRelation('translations', collect([$english, $moved]));
        expect(fn () => $value->getValue('en'))->toThrow(OwnershipViolation::class);

        $dirty = MetafieldTranslation::query()->findOrFail($english->id);
        $dirty->owner_scope_key = 'forged';
        $value->setRelation('translations', collect([$dirty]));
        expect(fn () => $value->getValue('en'))->toThrow(OwnershipViolation::class);

        $deleted = MetafieldTranslation::query()->findOrFail($english->id);
        $value->getConnection()->table($deleted->getTable())->where('id', $deleted->id)->delete();
        $value->setRelation('translations', collect([$deleted]));
        expect(fn () => $value->getValue('en'))->toThrow(OwnershipViolation::class);
    });
});

it('verifies loaded definition locale rows once per default or display read', function (): void {
    $foreign = null;
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(8), OwnershipActor::firstOrFail(), function () use (&$foreign): void {
        $foreign = localizedOwnershipValue('subtitle')->definition->translations()->firstOrFail();
    });
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function () use ($foreign): void {
        $definition = MetafieldModelRegistry::query()->with('translations')->findOrFail(localizedOwnershipValue('subtitle')->definition_id);
        expect($definition->translations)->toHaveCount(2)
            ->and($definition->displayTitle('bg'))->toBe('Подзаглавие')
            ->and($definition->getDefaultValue('bg'))->toBe('По подразбиране')
            ->and($definition->getSerializableDefaultValue('en'))->toBe('Default');

        $presence = metafieldReadQueries(fn () => $definition->hasDefaultValue('en'));
        expect(metafieldReadQueries(fn () => $definition->getDefaultValue('en')))->toBe($presence)
            ->and(metafieldReadQueries(fn () => $definition->getSerializableDefaultValue('en')))->toBe($presence);

        $both = metafieldReadQueries(fn () => $definition->displayTitle('en'));
        $english = $definition->translations->firstWhere('locale', 'en');
        $definition->setRelation('translations', collect([$english]));
        expect($both)->toBeLessThanOrEqual(metafieldReadQueries(fn () => $definition->displayTitle('en')));

        $definition->setRelation('translations', collect([$english, $foreign]));
        expect(fn () => $definition->displayTitle('en'))->toThrow(OwnershipViolation::class);
    });
});

it('resolves an owned reference list in one admitted query and omits foreign targets', function (): void {
    app()->bind(MetafieldReferenceAuthorization::class, fn () => new class implements MetafieldReferenceAuthorization
    {
        public function authorize(Authenticatable|SystemActor $actor, Model $owner, MetafieldDefinition $definition, Model $reference): void {}
    });
    $foreign = null;
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(8), OwnershipActor::firstOrFail(), function () use (&$foreign): void {
        $foreign = OwnershipMetafieldScenario::owner();
    });
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function () use ($foreign): void {
        $actor = OwnershipActor::firstOrFail();
        $owner = OwnershipMetafieldScenario::owner();
        OwnershipMetafieldScenario::definition('bundle', 'reference_list');
        $targets = collect(range(1, 4))->map(static fn (): string => OwnershipMetafieldScenario::owner()->id)->all();
        $value = app(MetafieldValues::class)->set($owner, 'product.bundle', [$targets[0]], $actor);
        $value = Metafield::query()->with('definition')->findOrFail($value->id);
        $one = metafieldReadQueries(fn () => $value->getValue());

        $value = app(MetafieldValues::class)->set($owner, 'product.bundle', array_reverse($targets), $actor, null, $value->revision);
        $value = Metafield::query()->with('definition')->findOrFail($value->id);
        $resolved = null;
        $many = metafieldReadQueries(function () use ($value, &$resolved): void {
            $resolved = $value->getValue();
        });
        expect($resolved->map(static fn (OwnershipMetafieldOwner $target): string => $target->id)->all())->toBe(array_reverse($targets))
            ->and($many)->toBe($one);

        $value->getConnection()->table($value->getTable())->where('id', $value->id)->update(['value' => json_encode([$foreign->id, $targets[0]], JSON_THROW_ON_ERROR)]);
        $value = Metafield::query()->with('definition')->findOrFail($value->id);
        expect($value->getValue()->map(static fn (OwnershipMetafieldOwner $target): string => $target->id)->all())->toBe([$targets[0]]);
    });
});
