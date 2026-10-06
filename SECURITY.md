# Security Policy

Submit reports through [this package's private vulnerability reporting form](https://github.com/nvl-laravel-suite/metafields/security/advisories/new).

The prepared `5.x` release line targets PHP 8.4–8.5 and Laravel 12–13. This compatibility matrix remains candidate/unverified until same-source execution evidence is reviewed; upstream security lifecycle limits still apply.

Report vulnerabilities privately through the repository host's security-advisory feature. Include the field type, schema, owner/reference alias, payload size, authorization behavior, and impact.

Keep APIs disabled by default. When enabled, retain authentication and the
`metafields-management` throttle. Bound structured payloads, authorize owner,
definition, and every referenced-record access, validate reference existence
and reuse, require revisions for existing resource mutations, and never expose
application model classes, model attributes, or arbitrary JSON-path querying.
