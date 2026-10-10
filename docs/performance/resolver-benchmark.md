# Effective-access resolver benchmark

Status: complete; cross-request cache not required

## Purpose

This benchmark decides whether GateTrail needs a cross-request authorization
cache. It does not publish a general performance guarantee. Results are valid
only for the recorded runtime, database image and machine or CI runner.

The threshold in this document is committed before the first benchmark run, as
required by ADR 0013. It must not be changed in response to the measured result.

## Registered decision threshold

Cross-request caching is retained for further design only when the live MySQL
benchmark fails either representative-workload threshold:

- cold resolution p95 exceeds **25 milliseconds**; or
- a warm request-local memo hit p95 exceeds **1 millisecond** or executes any
  database statement.

The cold query budget remains the resolver contract: two selects with no
effective role, three selects for 1–900 effective roles, and
`2 + ceil(roles / 900)` above that boundary. A query-budget failure is a
correctness regression, not evidence in favour of adding another cache.

## Workloads

The reproducible harness records two role-derived permission datasets:

| Workload | Effective roles | Effective permissions | Use |
|---|---:|---:|---|
| Representative | 10 | 100 | Cache decision threshold |
| Heavy | 50 | 500 | Informational scaling evidence |

For each workload the harness records the database driver and version, PHP and
Laravel versions, sample counts, role and permission counts, cold and warm p50
and p95 latency, and observed statement counts. Fixture creation and inserts
are excluded from timed samples.

The representative workload uses 30 independent cold samples. Each cold sample
clears the request-local memo before resolving. Warm latency uses 30 batches of
100 memoized resolutions and reports per-operation latency; the statement probe
must observe zero statements across the whole warm window.

## Decision rule

- When the representative live MySQL workload satisfies every registered
  threshold, Phase 8 records **no cross-request cache required**. Cache ADR,
  invalidation, TTL, stampede and after-commit cache work close as not required.
- When it fails a registered threshold, GateTrail may draft a cache-strategy ADR.
  No cache implementation starts before that ADR is accepted.

SQLite may be used to validate the harness. It cannot decide the cache question.
Targeted Oracle evidence may be added later, but the pre-alpha cache decision
uses the disposable live MySQL environment already maintained by the project.

## Reproduction

```bash
docker compose -f compose.mysql.yml up -d mysql
composer benchmark:mysql
```

The isolated harness prints one JSON record per workload and fails when the
representative workload exceeds a registered threshold or a warm run executes
a statement. Fixture creation and inserts remain outside the timed samples.

## Accepted result — 2026-10-10

```text
MySQL       8.0.45, disposable Docker service
PHP         8.3.31
Laravel     13.23.0
Kernel      Linux 6.18.40.1-microsoft-standard-WSL2
CPU         13th Gen Intel Core i7-13700H, 20 logical CPUs
Docker      29.7.2
```

| Workload | Cold p50 | Cold p95 | Warm p50 | Warm p95 | Cold selects | Warm statements |
|---|---:|---:|---:|---:|---:|---:|
| Representative | 5.495 ms | 6.069 ms | 0.008 ms | 0.009 ms | 3 | 0 |
| Heavy | 12.606 ms | 16.082 ms | 0.010 ms | 0.012 ms | 3 | 0 |

The representative result passes both pre-registered thresholds. GateTrail
therefore retains request-local memoization as its only cache-like behaviour and
does not design or implement a cross-request cache for the internal alpha.
Cache-store selection, TTL, stampede control, fan-out and after-commit
cross-request invalidation are closed as not required, not claimed as
implemented.

A full quality-gate rerun independently reproduced the decision: representative
cold/warm p95 measured 6.914/0.010 ms and the heavy workload measured 12.884 ms
cold p95, again with three cold selects and zero warm statements.
