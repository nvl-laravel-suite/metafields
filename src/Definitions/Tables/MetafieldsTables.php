<?php

declare(strict_types=1);

namespace Nvl\Metafields\Definitions\Tables;

use Nvl\Support\Config\PackageStorage;

/**
 * Defines the canonical table names owned by the Metafields package.
 */
final class MetafieldsTables
{
    public const string Metafields = 'nvl_metafields_metafields';

    public const string Definitions = 'nvl_metafields_definitions';

    public const string DefinitionsI18n = 'nvl_metafields_definitions_i18n';

    public const string DefinitionAssignments = 'nvl_metafields_definition_assignments';

    public const string I18n = 'nvl_metafields_i18n';

    public const string TenantGrants = 'nvl_metafields_tenant_grants';

    public const string TenantGrantLocks = 'nvl_metafields_tenant_grant_locks';

    public const string TenantAdoptionCopies = 'nvl_metafields_tenant_adoption_copies';

    public const string METAFIELDS = self::Metafields;

    public const string METAFIELDS_DEFINITIONS = self::Definitions;

    public const string METAFIELDS_DEFINITIONS_I18N = self::DefinitionsI18n;

    public const string METAFIELD_DEFINITION_ASSIGNMENTS = self::DefinitionAssignments;

    public const string METAFIELDS_I18N = self::I18n;

    public const string METAFIELD_DEFINITION_TENANT_GRANTS = self::TenantGrants;

    /** Return one configured logical or historical package table. */
    public static function get(string $key): string
    {
        return PackageStorage::resolveTable('metafields', $key);
    }

    private function __construct() {}
}
