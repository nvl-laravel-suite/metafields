<?php

declare(strict_types=1);

namespace Nvl\Metafields\Events;

/** @api
 * @deprecated Use MetafieldSet with the versioned scalar payload; removed no earlier than major 6. */
class_alias(MetafieldSet::class, __NAMESPACE__.'\\MetafieldSetEvent');
