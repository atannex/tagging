# Production checklist

The October 27, 2026 review covered tenant boundaries, queued identity, transaction retries and after-commit notifications, import bounds, migrations, package distribution, and operational diagnostics. Verification commands and results are recorded in [verification.md](verification.md).

The CLI import validates settings before reading and bounds the actual read, including when a file grows after its metadata check.

Before application deployment:

1. Rehearse the [upgrade](upgrading.md) on a copy of the application's data, including a backup restore. Pause writes while MySQL schema changes run.
2. Run `tagging:health` for each tenant/taxonomy in use. Resolve missing migrations, indexes, morph types, and vocabulary integrity errors.
3. Select tenants from trusted application state. For tenant-owned subjects, implement `SubjectAccess` and enable strict tenants after auditing existing data and draining legacy jobs.
4. Verify application authorization, morph mappings, queue workers, after-commit integrations, and retention schedules. Direct SQL and model writes bypass package rules.
5. Run the compatibility workflow for the intended PHP/Laravel combination before tagging a release. Review the verification record rather than treating a configured job as a pass.

Verification of the library does not verify the consuming application's policies or deployment.
