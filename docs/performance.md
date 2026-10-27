# Performance and measured limits

Run a reproducible local benchmark using the package's development dependencies:

```sh
php benchmarks/run.php 500 1000
```

Arguments are tag count and subject count. The fixture assigns five tags per subject in a tree with branching factor five. Output records runtime/database versions, CPU information, dataset size, elapsed milliseconds, query counts, peak process memory, and a SQLite matching-query plan. Fixtures use direct inserts; their setup speed is not a measure of domain mutation throughput.

Use disposable databases only if selecting an external test connection: the test runtime can drop all tables. Follow [CONTRIBUTING.md](../CONTRIBUTING.md) for environment variables. Benchmark runs must not overlap test runs against the same database.

Writers in the same tenant/taxonomy serialize on one namespace lock. Measure contention using your own workload before choosing capacity targets. Eager loading batches assignment and ownership reads; descendant traversal and facets depend on vocabulary size and the application's candidate query. Input limits bound individual operations, not total retained vocabulary or history.

No throughput or latency service level is promised. Keep benchmark output with the exact environment and dataset when comparing changes; SQLite results do not establish MySQL/PostgreSQL concurrency performance.
