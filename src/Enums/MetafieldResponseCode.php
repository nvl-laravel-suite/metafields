<?php

declare(strict_types=1);

namespace Nvl\Metafields\Enums;

use Nvl\Support\Contracts\ResponseCode;

/**
 * @api
 * Catalog of response codes for the Metafields module.
 *
 * Each case value is a short, opaque discriminator emitted by controllers in
 * `flash.message`. The frontend `useAction` hook does NOT translate these —
 * the action declares its own success/error messages via typed `t()` calls.
 */
enum MetafieldResponseCode: string implements ResponseCode
{
    case StaleMetafieldVersion = 'stale_metafield_version';
    case MetafieldIntegrityConflict = 'metafield_integrity_conflict';
    case BatchReadUnavailable = 'batch_read_unavailable';

    case Updated = 'updated';
    case Deleted = 'deleted';

    case DefinitionNotFound = 'definition_not_found';
    case InvalidMetafieldMutation = 'invalid_metafield_mutation';
    case OperationFailed = 'operation_failed';
}
