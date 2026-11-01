<?php

declare(strict_types=1);

namespace Nvl\Metafields\Contracts;

use Nvl\Support\Enums\SystemActor;

/**
 * Host permission for bounded identity-only diagnostics, separate from value mutation.
 *
 * @api
 */
interface MetafieldDiagnosticAccess
{
    /** Explicitly grant inspection of visible owner-reference metadata; no value payload access is granted. */
    public function allows(SystemActor $actor): bool;
}
