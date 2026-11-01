<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services;

use Nvl\Metafields\Contracts\MetafieldDiagnosticAccess;
use Nvl\Support\Enums\SystemActor;

/** Keeps optional structural data inspection ungranted until the host decides explicitly.
 * @internal
 */
final readonly class DenyMetafieldDiagnosticAccess implements MetafieldDiagnosticAccess
{
    public function allows(SystemActor $actor): bool
    {
        return false;
    }
}
