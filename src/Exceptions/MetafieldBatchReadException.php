<?php

declare(strict_types=1);

namespace Nvl\Metafields\Exceptions;

/**
 * @api
 An unsupported adapter, missing reference or exceeded batch bound fails closed. */
final class MetafieldBatchReadException extends MetafieldException {}
