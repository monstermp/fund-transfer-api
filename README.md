# Fund Transfer API

A production-ready Symfony 7 / PHP 8.3 API for transferring funds between accounts, designed to be **reliable under high concurrency**.

> **Time spent:** ~4 hours
> **AI tools used:** GitHub Copilot (Claude Opus) for scaffolding entities, services, and tests. Every line was reviewed and is fully understood by the author.

---

## Architecture at a glance

| Concern | Choice | Why |
|---|---|---|
| Money representation | Integer minor units (`bigint`) + `bcmath` | Avoids float rounding errors — a hard rule in finance |
| Concurrency control | **Pessimistic locking** (`SELECT ... FOR UPDATE`) | Prevents double-spend / negative balances under load |
| Deadlock avoidance | Locks acquired in **sorted account-number order** | A→B and B→A always lock in the same global order |
| Atomicity | Single DB transaction per transfer with rollback on failure | ACID guarantees |
| Idempotency | Redis `SET NX` with TTL keyed by `Idempotency-Key` header | Safe client retries on network failures |
| **Authentication** | **JWT via `lexik/jwt-authentication-bundle` (RS256)** | **Stateless, signed tokens — no DB lookup per request** |
| Validation | Symfony Validator + immutable readonly DTOs | Anti-corruption layer at the HTTP boundary |
| Errors | Central `ApiExceptionListener` → JSON with stable error codes | Machine-readable, predictable API contract |
| Persistence | MySQL 8 via Doctrine ORM | Mature, well-supported, ACID |
| Cache / coordination | Redis 7 (Predis client) | Fast in-memory store for idempotency keys |
| HTTP | Nginx + PHP-FPM | Standard production setup |

---

## Project layout

```
src/
  Command/         # CLI helpers (create accounts)
  Controller/      # Thin HTTP layer
  Dto/             # Validated request payloads
  Entity/          # Account, Transaction (Doctrine)
  EventListener/   # Central JSON error handler
  Exception/       # Domain exceptions with error codes + HTTP status
  Repository/      # Doctrine repos (incl. findAndLock)
  Service/         # TransferService, IdempotencyService
tests/
  Integration/     # WebTestCase end-to-end coverage
docker/            # Nginx + PHP-FPM Dockerfiles
```

---

## Quick start

### 1. Boot the stack

```bash
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php bin/console doctrine:database:create --if-not-exists
docker compose exec app php bin/console doctrine:schema:create
```

### 2. Generate JWT signing keys (one-time)

```bash
docker compose exec app mkdir -p config/jwt
docker compose exec app openssl genpkey -out config/jwt/private.pem -aes256 -algorithm rsa -pkeyopt rsa_keygen_bits:4096 -pass pass:change_me_in_production
docker compose exec app openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout -passin pass:change_me_in_production
```

> The passphrase **must** match `JWT_PASSPHRASE` in `docker-compose.yml`.

### 3. Seed two accounts and an API user

```bash
docker compose exec app php bin/console app:account:create ACC-001 USD 100000
docker compose exec app php bin/console app:account:create ACC-002 USD 0
docker compose exec app php bin/console app:user:create alice@example.com s3cret-password
```

### 4. Authenticate and grab a JWT

```bash
curl -X POST http://localhost:8080/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"alice@example.com","password":"s3cret-password"}'
# => {"token":"eyJ0eXAiOiJKV1QiLCJhbGciOi..."}
```

You can also register a brand-new user (returns a token immediately):

```bash
curl -X POST http://localhost:8080/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{"email":"bob@example.com","password":"another-secret"}'
```

### 5. Hit the API with the token

```bash
TOKEN="eyJ0eXAiOiJKV1QiLCJhbGciOi..."

curl -X POST http://localhost:8080/api/v1/transfers \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: 8c1d4e2a-7b9f-4a0c-9e3d-1b2a3c4d5e6f" \
  -d '{
    "fromAccount": "ACC-001",
    "toAccount":   "ACC-002",
    "amountMinor": "2500",
    "currency":    "USD"
  }'
```

Response:

```json
{
  "transactionId": "0190f9...",
  "status": "completed",
  "fromAccount": "ACC-001",
  "toAccount": "ACC-002",
  "amountMinor": "2500",
  "currency": "USD",
  "completedAt": "2026-05-19T10:00:00+00:00"
}
```

Retry the **exact same** request → you get the same response with `Idempotent-Replay: true` and the balance is **only debited once**.

### 6. Run the tests

```bash
docker compose exec app php bin/console doctrine:database:create --env=test --if-not-exists
docker compose exec app php bin/console doctrine:schema:create --env=test
docker compose exec -e APP_ENV=test app vendor/bin/phpunit
```

---

## API reference

### `POST /api/v1/auth/register`

Body: `{ "email": "...", "password": "min 8 chars" }`
Returns: `{ "userId", "email", "token" }` — `201 Created`

### `POST /api/v1/auth/login`

Body: `{ "email": "...", "password": "..." }`
Returns: `{ "token": "<jwt>" }` — `200 OK` (or `401 Unauthorized`)

### `POST /api/v1/transfers`  *(requires `Authorization: Bearer <jwt>`)*

| Header | Required | Description |
|---|---|---|
| `Authorization: Bearer <jwt>` | yes | JWT obtained from `/auth/login` or `/auth/register` |
| `Content-Type: application/json` | yes | |
| `Idempotency-Key` | optional but **strongly recommended** | Any unique string per logical attempt |

Request body:

```json
{
  "fromAccount": "string",
  "toAccount":   "string",
  "amountMinor": "string (positive integer)",
  "currency":    "ISO-4217 (e.g. USD)"
}
```

Responses:

| Status | Meaning |
|---|---|
| `201 Created` | Transfer completed |
| `401 Unauthorized` | Missing/invalid/expired JWT |
| `403 Forbidden` | Token is valid but lacks required role |
| `409 Conflict` | Another request with the same `Idempotency-Key` is in flight |
| `422 Unprocessable Entity` | Validation, insufficient funds, currency mismatch, same account |
| `404 Not Found` | One of the accounts does not exist |
| `500 Internal Server Error` | Unexpected failure (rolled back) |

Error body shape:

```json
{ "error": "INSUFFICIENT_FUNDS", "message": "..." }
```
