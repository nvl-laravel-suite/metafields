<?php

declare(strict_types=1);

namespace Nvl\Metafields\Events;

/** @api
 * @deprecated Use MetafieldsSynced with the versioned scalar payload; removed no earlier than major 6. */
class_alias(MetafieldsSynced::class, __NAMESPACE__.'\\MetafieldsSyncedEvent');
