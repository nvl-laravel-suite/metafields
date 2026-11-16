<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Contracts\HasSqlMetafieldScope;
use Nvl\Metafields\Traits\HasMetafields;

final class TestMetafieldOwner extends Model implements HasSqlMetafieldScope
{
    use HasMetafields;

    protected $table = 'test_metafield_owners';

    protected $guarded = [];

    public function metafieldScopeKey(): ?string
    {
        $category = $this->getAttribute('category');

        return is_string($category) ? $category : null;
    }

    public static function metafieldScopeOptions(): array
    {
        return [['value' => 'digital', 'label' => 'Digital'], ['value' => 'physical', 'label' => 'Physical']];
    }

    public static function metafieldScopeColumn(): string
    {
        return 'category';
    }
}
