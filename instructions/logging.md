# Logging Standard

- Use `Psr\Log\LoggerInterface` for application logging.
- Do not use `error_log()` in application runtime code (`src/`).
- Runtime logs must be structured JSON entries (Monolog to `php://stderr`).
- Every log entry should include a stable `event` key in context.
- Exceptions must be logged via logger context using the `exception` key.
- Keep one event per log line to stay compatible with container log pipelines.
