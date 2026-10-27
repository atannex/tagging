# Security reporting

Report suspected vulnerabilities privately through [GitHub security advisories](https://github.com/atannex/tagging/security/advisories/new) or by e-mail to **atangageihlekecollins@gmail.com** before publishing a working exploit. Include the affected version, PHP/framework/database versions, configuration, expected boundary, and a minimal reproduction with credentials, tokens, tenant data, and private metadata removed.

Security fixes are released for the latest 1.x version. There is no promised patch SLA. Laravel 11 compatibility is legacy testing, not a promise of framework security support. Use a maintained framework/dependency set for production and review dependency audit output for the actual consuming application.

Tenant selection is not authorization. Authorize application actions, select spaces from trusted context, and configure fail-closed ownership/query restrictions when required. Raw model/SQL writes bypass package invariants. After-commit callbacks are not durable delivery. Provenance can contain application metadata and needs appropriate access, retention, and redaction controls.

Do not send database dumps, real credentials, or personal data with an initial report. Agree a sanitized reproduction and disclosure timeline with the maintainer.
