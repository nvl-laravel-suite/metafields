<?php

declare(strict_types=1);

namespace Nvl\Metafields\Definitions\Tables;

/**
 * Defines the canonical table names owned by the Metafields package.
 */
final class MetafieldsTables
{
    public const string Metafields = 'metafields';

    public const string Definitions = 'metafields_definitions';

    public const string DefinitionsI18n = 'metafields_definitions_i18n';

    public const string DefinitionAssignments = 'metafield_definition_assignments';

    public const string I18n = 'metafields_i18n';

    public const string TenantGrants = 'metafield_definition_tenant_grants';

    public const string TenantGrantLocks = 'metafield_definition_tenant_grant_locks';

    public const string TenantAdoptionCopies = 'metafield_definition_tenant_adoption_copies';

    public const string METAFIELDS = self::Metafields;

    public const string METAFIELDS_DEFINITIONS = self::Definitions;

    public const string METAFIELDS_DEFINITIONS_I18N = self::DefinitionsI18n;

    public const string METAFIELD_DEFINITION_ASSIGNMENTS = self::DefinitionAssignments;

    public const string METAFIELDS_I18N = self::I18n;

    public const string METAFIELD_DEFINITION_TENANT_GRANTS = self::TenantGrants;

    private function __construct() {}
}
