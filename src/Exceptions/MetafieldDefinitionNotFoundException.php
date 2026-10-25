<?php

declare(strict_types=1);

namespace Nvl\Metafields\Exceptions;

use InvalidArgumentException;

/** Requested definition handle was not registered. @api */
final class MetafieldDefinitionNotFoundException extends InvalidArgumentException {}
