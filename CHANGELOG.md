## [0.2.0] - 2026-11-17
- Recover queued runs when a queue push fails or a message disappears before execution
- Preserve retry delays when duplicate messages reach a worker
- Settle invalid handler results using the run type's retry policy
- Allow hosts to reject conflicting requests with a server-generated fingerprint
- Cover durable queue execution and competing HTTP requests on MySQL

## [0.1.0] - 2026-11-17
- Runs record a unit of work for a polymorphic owner: what ran, what it ran against, what triggered it, who asked, its input and its result
- Three ways to execute a run: uploaded by whoever did the work (such as CI), claimed over HTTP by a machine, or run by the Laravel queue
- Every try at a run is its own attempt, so retries keep their history and a machine that lost its lease cannot settle the run
- Leases are proved by a token on every runner call; only a hash of the token is stored
- Artifacts are stored per attempt with checksums, and log lines can be resent without duplicating
- Retries follow each run type's rules with a backoff; cancellation asks a running machine to stop
- A sweep command expires abandoned attempts and times out runs past their deadline
- Run types, the runner authorizer, models, the store request, resources, the response envelope and controllers can be replaced by the host
