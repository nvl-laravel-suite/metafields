<?php

declare(strict_types=1);

namespace Nvl\Metafields\Exceptions;

use Exception;
use Nvl\Metafields\Enums\MetafieldResponseCode;
use Nvl\Support\Contracts\RespondableException;
use Nvl\Support\Exceptions\ExceptionResponse;
use Nvl\Support\Traits\InteractsWithPackageFailure;

/**
 * @api
 * Base exception for the Metafields domain.
 */
abstract class MetafieldException extends Exception implements RespondableException
{
    use InteractsWithPackageFailure;

    /** Resolve the declared safe failure for this native hierarchy. */
    protected function exceptionResponse(): ExceptionResponse
    {
        return match (static::class) {
            StaleMetafieldVersionException::class => new ExceptionResponse('metafields', MetafieldResponseCode::StaleMetafieldVersion, 409),
            MetafieldIntegrityException::class => new ExceptionResponse('metafields', MetafieldResponseCode::MetafieldIntegrityConflict, 409),
            MetafieldBatchReadException::class => new ExceptionResponse('metafields', MetafieldResponseCode::BatchReadUnavailable, 500),
            default => new ExceptionResponse('metafields', MetafieldResponseCode::OperationFailed),
        };
    }
}
