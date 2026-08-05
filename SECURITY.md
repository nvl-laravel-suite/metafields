# Security Policy

Security fixes are provided for the current `1.x` release line on PHP 8.3–8.4 and Laravel 12–13.

Report vulnerabilities privately through the repository host's security-advisory feature. Include the field type, schema, owner/reference alias, payload size, authorization behavior, and impact.

Keep APIs disabled by default. When enabled, retain authentication and the
`metafields-management` throttle. Bound structured payloads, authorize owner,
definition, and every referenced-record access, validate reference existence
and reuse, require revisions for existing resource mutations, and never expose
application model classes, model attributes, or arbitrary JSON-path querying.
