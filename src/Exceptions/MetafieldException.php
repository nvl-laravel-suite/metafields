<?php

declare(strict_types=1);

namespace Nvl\Metafields\Exceptions;

use RuntimeException;

/**
 * Base failure for Metafield domain operations.
 *
 * @api
 */
abstract class MetafieldException extends RuntimeException {}
