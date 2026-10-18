<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Traits\HasMetafields;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** Native host target independent from NVL tenancy.
 * @property string $id
 * @property int $company_id
 * @property string $name
 * @property string $owner_scope_key
 */
class OwnershipMetafieldOwner extends Model implements Ownable
{
    use HasMetafields;
    use HasOwnership, HasUuids;

    protected $table = 'metafields_owned_subjects';

    protected $guarded = [];

    public $timestamps = false;

    public function getConnectionName(): ?string
    {
        return PackageStorage::connection('metafields');
    }

    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::column(OwnershipCompany::class, 'company_id');
    }
}
