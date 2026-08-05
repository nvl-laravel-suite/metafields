<?php

declare(strict_types=1);

namespace Nvl\Metafields\Enums;

/**
 * Stable authorization capabilities exposed by the optional management API.
 */
enum MetafieldAbility: string
{
    case ListOwners = 'list_owners';
    case ViewOwner = 'view_owner';
    case MutateOwner = 'mutate_owner';
    case DeleteOwnerValue = 'delete_owner_value';
    case ListDefinitions = 'list_definitions';
    case ViewDefinition = 'view_definition';
    case CreateDefinition = 'create_definition';
    case UpdateDefinition = 'update_definition';
    case DeleteDefinition = 'delete_definition';
}
