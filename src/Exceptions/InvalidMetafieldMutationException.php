<?php

declare(strict_types=1);

namespace Nvl\Metafields\Exceptions;

use InvalidArgumentException;
use Nvl\Metafields\Enums\MetafieldResponseCode;
use Nvl\Support\Contracts\RespondableException;
use Nvl\Support\Exceptions\ExceptionResponse;
use Nvl\Support\Traits\InteractsWithPackageFailure;

/** Expected metafield mutation boundary failure.
 * @api
 */
final class InvalidMetafieldMutationException extends InvalidArgumentException implements RespondableException
{
    use InteractsWithPackageFailure;

    protected function exceptionResponse(): ExceptionResponse
    {
        return new ExceptionResponse('metafields', MetafieldResponseCode::InvalidMetafieldMutation, 422);
    }
}
