# Security Policy

Submit reports through [this package's private vulnerability reporting form](https://github.com/nvl-laravel-suite/metafields/security/advisories/new).

Security fixes are provided for the published `5.x` release line. Composer declares PHP `^8.4` and Laravel `^12.0|^13.0`. The local Dagger release gate verifies PHP 8.4/Laravel 13 with MySQL 8.4 and PostgreSQL 17 persistence contracts; PHP 8.5, Laravel 12 and MariaDB require separate compatibility evidence. Upstream security lifecycle limits still apply.

Report vulnerabilities privately through the repository host's security-advisory feature. Include the field type, schema, owner/reference alias, payload size, authorization behavior, and impact.

Keep APIs disabled by default. When enabled, retain authentication and the
`metafields-management` throttle. Bound structured payloads, authorize owner,
definition, and every referenced-record access, validate reference existence
and reuse, require revisions for existing resource mutations, and never expose
application model classes, model attributes, or arbitrary JSON-path querying.
