# Effective-access resolver benchmark

Status: threshold registered; benchmark not yet run

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
