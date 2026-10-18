<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Config\PackageStorage;

/** Native host owner without an NVL tenancy or authentication package. */
final class OwnershipCompany extends Model
{
    protected $table = 'metafields_owned_companies';

    protected $guarded = [];

    public $timestamps = false;

    public function getConnectionName(): ?string
    {
        return PackageStorage::connection('metafields');
    }
}
