# Deploy Too Many Coins on Dokploy

This guide deploys the app on a Dokploy-managed Ubuntu 24.04 VPS, using:
- Dockerfile build for the app container
- PHP 8.3 + Apache (serving HTML/CSS/JavaScript frontend and PHP API)
- A dedicated internal tick worker service from the same image
- MySQL, either as a Dokploy-managed database service (default) or an external host

> Renamed from `DEPLOY_DOKPLOY_HOSTINGER.md`. The database no longer has to live on
> Hostinger — a VPS provider hosting the machine and the domain does not imply
> anything about where MySQL runs. Section 2 covers both arrangements.

## 1. Prerequisites

- Dokploy installed and reachable on your VPS
- Repository connected to Dokploy (GitHub/GitLab)
- A MySQL 8.0 (or MySQL 5.7+/MariaDB 10.6+) database — see section 2

## 2. Database

Pick one arrangement. Everything downstream of this section is identical either
way; only the five `DB_*` values differ.

### Option A: Dokploy database service (recommended)

Dokploy runs the database as a service in the same project, on the same VPS, on
the project's internal Docker network. Nothing is exposed publicly, so there is
no remote-access allowlist to maintain and no cross-internet hop on every query.

1. In your Dokploy project: **Create Service → Database → MySQL** (8.0).
2. Name it (for example `tmc-db`). Note the database name, user, and password
   Dokploy generates or that you set.
3. Do **not** publish a host port unless you specifically need one. The app and
   worker reach it over the internal network.
4. Attach a persistent volume for `/var/lib/mysql` if Dokploy does not do so by
   default. Without it, a redeploy of the database service destroys the world.
5. Read the internal connection details off the service's page. `DB_HOST` is the
   service's internal hostname — **not** `localhost`, and not the VPS public IP.

Then set on both the web and worker services:

```
DB_HOST=<internal service hostname, e.g. tmc-db>
DB_PORT=3306
DB_NAME=<database name>
DB_USER=<database user>
DB_PASS=<database password>
```

If the app cannot resolve `DB_HOST`, the two services are usually not on the
same Docker network — check that they are in the same Dokploy project, and use
the fully-qualified service name Dokploy shows if the short name fails.

**Naming caution.** If the service is named something like `tmc-test-db`, be
certain it is the database the live web service actually points at. The
authority is the `DB_HOST` value on the **web service**, not the service name —
running admin SQL against the wrong instance produces changes nobody can see.

### Option B: External MySQL (Hostinger or any managed host)

1. Create the database, user, and password in the provider's panel.
2. Enable remote MySQL access.
3. Add your VPS public IP to the allowed hosts. This is the step that most often
   causes `Database connection failed` after an otherwise clean deploy — the VPS
   IP changes, or was never added.
4. Use the provider's host and port for `DB_HOST` / `DB_PORT`.

Traffic crosses the public internet on every query, so expect higher and less
predictable latency than Option A, and re-check the IP allowlist whenever the
VPS is rebuilt or migrated.

### Backups

Neither option backs itself up. Take a dump before schema changes and on a
schedule you are willing to lose data down to:

```bash
# Option A: from the database service's terminal (the MySQL image has the client)
mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" > /tmp/tmc-backup.sql
```

The app image does **not** contain a `mysql`/`mysqldump` client — it installs
only the `pdo` and `pdo_mysql` PHP extensions. Run dumps from the database
service's terminal (Option A) or from a MySQL client on the VPS host (Option B).

## 3. Create App in Dokploy

1. Create a new Application in Dokploy.
2. Source: select your Git repository and branch.
3. Build method: Dockerfile.
4. Dockerfile path: `Dockerfile`.
5. Exposed container port: `80`.
6. Public domain: set your domain/subdomain.

Create a second Dokploy service in the same project for ticks:

1. Service type: Application (Dockerfile) using the same repo/branch.
2. Dockerfile path: `Dockerfile`.
3. Do not expose a public port.
4. Set start command/entrypoint override to:

```bash
/app/docker/worker-entrypoint.sh
```

5. Replicas: `1`. More than one replica does not double throughput — the worker
   takes an advisory lock and the extras log `advisory_lock_busy` forever.

`docker-compose.yml` in the repo models this same three-service shape (web,
worker, mysql) for local development. It is a useful reference for what Dokploy
should end up with, but its passwords are development placeholders — never
reuse them on a public host.

## 4. Environment Variables

Set variables per service. Section 4.1 is the minimum to run; 4.2 onward is the
complete reference, so you can tell a variable you must set from one that has a
working default.

Booleans accept `true`/`1`/`on`/`yes`; anything else (including `false`, `0`,
and unset) reads as false.

### 4.1 Required

**Web service:**

| Variable | Value |
|---|---|
| `DB_HOST` | database host (section 2) |
| `DB_PORT` | `3306` |
| `DB_NAME` | database name |
| `DB_USER` | database user |
| `DB_PASS` | database password |
| `TMC_INIT_SECRET` | strong random string — see the note below |

**Worker service:** the same five `DB_*` values, identical to the web service.
The worker connects directly; it does not proxy through the web service.

`TMC_INIT_SECRET` gates the HTTP init and diagnostics endpoints. It is not
optional in the "leave it unset and it's off" sense: when it is unset, the init
endpoint refuses HTTP calls entirely (CLI init still works), which is safe, but
`rate_limit_diagnostics` and `runtime_readiness` then have no usable key. Set it,
keep it out of the repo, and rotate it after initial setup.

### 4.2 Strongly recommended (defaults are wrong for production)

Set these on **both** services unless noted:

| Variable | Set to | Default if unset | Why |
|---|---|---|---|
| `TMC_TICK_REAL_SECONDS` | `5` | `60` | Real seconds per game tick. Must be identical on web and worker. |
| `TMC_TIME_SCALE` | `1` | `1` | Compressed clock for testing. Never anything but `1` in a lane real players use. |
| `TMC_TICK_ON_REQUEST` | `false` | `false` | With a worker running, ticks must not depend on user traffic. |
| `TMC_WORKER_INTERVAL_SECONDS` | `5` | `TMC_TICK_REAL_SECONDS`, else `60` | Worker only. Keep aligned with tick length. |
| `TMC_WORKER_START_DELAY_SECONDS` | `2` | `0` | Worker only. Lets the DB finish coming up on a cold start. |
| `TZ` | `UTC` | container default | Keeps logs and timestamps comparable across services. |

Web service only:

| Variable | Set to | Default if unset | Why |
|---|---|---|---|
| `TMC_AUTO_SQL_MIGRATIONS` | `false` | `true` | See section 4.7 — auto-apply is convenient in test, risky in production. |
| `TMC_TRUST_PROXY_HEADERS` | `false` | `false` | Leave off unless `TMC_TRUSTED_PROXIES` is set. |
| `TMC_TRUSTED_PROXIES` | your proxy's address or range | empty | Required for correct client IPs behind Dokploy's reverse proxy. |

**Client IP correctness matters for the rate limiter.** With no trusted proxies
configured, every request appears to come from the proxy, so anonymous requests
share a single bucket and one noisy client can 429 everyone. `TMC_TRUST_PROXY_HEADERS=true`
without `TMC_TRUSTED_PROXIES` is worse — it trusts `X-Forwarded-For` from anyone,
which lets a client forge its own identity and bypass the limit entirely. If you
do not yet know your proxy IPs, `TMC_TRUST_PROXY_HEADERS=true` is a deliberate
short-term trade during a private test; replace it with the explicit allowlist
before the game is public.

**`TMC_TRUSTED_PROXIES` accepts addresses and CIDR ranges**, comma-separated,
IPv4 and IPv6:

```
TMC_TRUSTED_PROXIES=172.16.0.0/12
TMC_TRUSTED_PROXIES=10.0.5.7, 192.168.0.0/16, 2001:db8::/32
```

Prefer a range on Dokploy. Container addresses are assigned by Docker and are
not stable across a redeploy or a host restart, so a single pinned address
eventually stops matching — which fails safe (back to one shared anonymous
bucket) but silently stops applying the protection you configured. A range
covering the project's Docker network survives that.

Entries that cannot be parsed are dropped rather than honoured, and each one is
named once in the log. A `/0` prefix is refused outright: `0.0.0.0/0` means
"trust every peer", which is `TMC_TRUST_PROXY_HEADERS=true` written to look
like a restriction. Use the flag if that is genuinely what you want.

`tools/proxy_trust_selfcheck.php` covers the matcher — ranges, boundaries,
family mismatches, and every malformed form — and needs no server or database:

```bash
php tools/proxy_trust_selfcheck.php
```

### 4.3 Timing safety

- Keep `TMC_TIME_SCALE=1` in test and live lanes.
- Effective real idle timeout is approximately `900 / TMC_TIME_SCALE` seconds. At
  `TMC_TIME_SCALE=30`, players are marked idle in about 30 seconds.
- Keep `TMC_WORKER_INTERVAL_SECONDS` aligned with `TMC_TICK_REAL_SECONDS` unless
  you intentionally want catch-up behaviour.
- `TMC_TICK_REAL_SECONDS` is the conversion between real durations and stored
  tick counts (season length, blackout, cooldowns and the lock-in floor are all
  derived from it at boot). Changing it mid-season leaves the stored counters
  intact but silently rescales how long they take in wall-clock time. Change it
  between seasons, and change it on both services in the same deploy.

| Variable | Default | Notes |
|---|---|---|
| `TMC_TICK_MAX_CATCHUP_TICKS` | 1800 real seconds' worth | Caps how far one tick pass may catch up after an outage. |
| `TMC_PRESENCE_TOUCH_SECONDS` | `30` (min `5`) | How often presence is refreshed. |
| `TMC_PRESENCE_STALE_OFFLINE_SECONDS` | `120` | Floored at `TMC_PRESENCE_TOUCH_SECONDS`. |

### 4.4 Rate limiting (web service)

| Variable | Default | Notes |
|---|---|---|
| `TMC_RATE_LIMIT_WINDOW_SECONDS` | `60` | |
| `TMC_RATE_LIMIT_ANON_PER_WINDOW` | `120` | Minimum `10`. |
| `TMC_RATE_LIMIT_AUTH_PER_WINDOW` | `300` | Floored at the anon value. |
| `TMC_RATE_LIMIT_DIAGNOSTICS` | `false` | Enables the protected diagnostics endpoint (section 11). |
| `TMC_RATE_LIMIT_TRACE` | `false` | Emits `[rate_limit]` JSON log lines. |
| `TMC_RATE_LIMIT_TRACE_SAMPLE_PCT` | `10` | Clamped to 0–100. |

Per-action throttles (chat, theft, purchases and similar) are defined in code,
not environment — they are a first-pass calibration and may need tuning once you
have real traffic.

### 4.5 Feature flags

| Variable | Default | Effect |
|---|---|---|
| `TMC_SIGIL_FAMILIES_ENABLED` | `true` | Sigil family layer: affinity, wards, market, sight, chronicle. |
| `TMC_PROGRESSION_GATES_ENABLED` | `false` | Progression gating on advanced verbs. |
| `TMC_FORGE_TRANSMUTE_ENABLED` | `false` | Forge transmute. While both forge flags are off, the Forge panel is hidden. |
| `TMC_FORGE_DISTIL_ENABLED` | `false` | Forge distil. |
| `TMC_EVENTS_PUBLIC_TICKER_ENABLED` | `true` | Public event ticker. |
| `TMC_EVENTS_PUBLISH_AMOUNTS` | `false` | Publishes exact amounts in public events. Leaving this off keeps the ticker from becoming a scouting tool. |
| `TMC_STARPRICE_MODEL_VERSION_DEFAULT` | `2` | Star-price model for new seasons. Do not lower it on a running season. |

### 4.6 Email (registration verification)

> **Working outbound mail is now a hard requirement.** An account with no
> confirmed address is refused every action except logout, confirming, asking
> for another link, and reading enough state to be told so. If the confirmation
> mail never arrives, the account is permanently unusable.
>
> `TMC_MAIL_DEV_LOG` defaults to **on**, which logs the link and sends nothing.
> Leaving that default in a lane with real players locks out every new signup.
> Set `TMC_MAIL_DEV_LOG=false`, configure SMTP, and prove it before opening
> registration:
>
> ```bash
> php /app/tools/mail_selftest.php you@example.com
> ```
>
> Every send under dev-log also writes a `[mail-dev] WARNING:` line naming the
> problem, so `grep 'mail-dev.*WARNING'` over the web service log answers
> "is this misconfigured" in one command.
>
> `TMC_PUBLIC_BASE_URL` must be set too, or the confirmation link in the mail
> points nowhere.
>
> Break-glass for an account whose mail never arrived:
> `php /app/tools/admin.php verify <handle>`.

| Variable | Default |
|---|---|
| `TMC_SMTP_HOST` | empty (no SMTP) |
| `TMC_SMTP_PORT` | `587` |
| `TMC_SMTP_USER` | empty |
| `TMC_SMTP_PASS` | empty |
| `TMC_SMTP_SECURE` | `tls` (`tls` \| `ssl` \| `none`) |
| `TMC_SMTP_TIMEOUT_SECONDS` | `10` (min `2`) |
| `TMC_SMTP_FALLBACK_TO_MAIL` | `false` |
| `TMC_MAIL_FROM` | `no-reply@too-many-coins.com` |
| `TMC_MAIL_FROM_NAME` | `Too Many Coins` |
| `TMC_MAIL_DEV_LOG` | `true` |
| `TMC_MAIL_TRACE` | `false` |
| `TMC_PUBLIC_BASE_URL` | empty — set to `https://your-domain` so verification links resolve |
| `TMC_VERIFICATION_TOKEN_MINUTES` | `30` (min `5`) |

### 4.7 Migrations

Automatic SQL migrations on redeploy are enabled by default:

- New `migration_*.sql` files are auto-applied once at runtime startup.
- `_optional.sql` files are excluded from auto-apply (manual only).
- To disable: `TMC_AUTO_SQL_MIGRATIONS=false` (alias: `TMC_AUTO_SQL_HOTFIX`).

**Production recommendation:** set `TMC_AUTO_SQL_MIGRATIONS=false` and apply
migrations as a controlled deployment step, so a syntax-incompatibility failure
does not spam the log on every API request. If a migration fails while
auto-apply is on, it is recorded in `schema_migrations` with `status='failed'`
and not retried — operators must check for failed rows and apply a corrected
replacement migration manually:

```sql
SELECT * FROM schema_migrations WHERE status = 'failed';
```

Migration SQL must be compatible with MySQL 5.7+. Avoid `ADD COLUMN IF NOT EXISTS`;
use `PREPARE`/`EXECUTE` with `INFORMATION_SCHEMA` guards instead (see
`migration_20260329b_*_compat.sql` for the pattern). That form also works with
manual `mysql < file.sql` application.

### 4.8 Diagnostics and fallbacks

| Variable | Default | Notes |
|---|---|---|
| `TMC_TICK_SECRET` (alias `TICK_SECRET`) | empty | Only for the fallback HTTP tick endpoint (section 8). |
| `TMC_WORKER_ERROR_BACKOFF_SECONDS` | `2` | Worker only. |
| `TMC_WORKER_RUN_ONCE` | `false` | Worker runs a single cycle and exits. Debugging only. |
| `TMC_TICK_TRACE` | `false` | Verbose tick logging. |
| `TMC_TICK_SLOW_MS` | `500` (min `50`) | Threshold for slow-tick warnings. |
| `TMC_TICK_LOCK_MISS_LOG_EVERY` | `12` | Log one in N advisory-lock misses. |
| `TMC_AUTH_TRACE` | `false` | Verbose auth logging. Noisy; do not leave on. |
| `TMC_SUPPLY_TRACE` | off | Detailed economy supply trace. Compared strictly against `1`, so `true` does **not** enable it. |

### 4.9 Never set in production

| Variable | Why |
|---|---|
| `TMC_SIMULATION_MODE=fresh-run` | Unlocks `GameTime::setSimulationTick()`, letting code move the game clock arbitrarily. Test harnesses only. |
| `TMC_MINUTE_TO_SECOND_MIGRATION` / `..._DRY_RUN` | One-time historical data migration. Leave off. |

### 4.10 Variable aliases (a real trap)

The five DB values each accept aliases, tried in this order:

```
DB_HOST → MYSQLHOST → MYSQL_HOST → HOSTINGER_DB_HOST
DB_PORT → MYSQLPORT → MYSQL_PORT → HOSTINGER_DB_PORT
DB_NAME → MYSQLDATABASE → MYSQL_DATABASE → HOSTINGER_DB_NAME
DB_USER → MYSQLUSER → MYSQL_USER → HOSTINGER_DB_USER
DB_PASS → MYSQLPASSWORD → MYSQL_PASSWORD → HOSTINGER_DB_PASSWORD
```

An empty value counts as unset and falls through to the next alias. This is
worth knowing with a Dokploy database service: `MYSQL_DATABASE`, `MYSQL_USER`,
and `MYSQL_PASSWORD` are the MySQL image's own variable names, so if any of them
are also present in the app container's environment and your `DB_*` values are
missing or blank, the app will connect using them without complaint. Set the
`DB_*` names explicitly and you never have to reason about it.

## 5. First Deploy

Run deploy in Dokploy. After it is healthy, initialize schema/data once.

### Option A: Run in Dokploy terminal (recommended)

```bash
php /app/init_db.php
```

### Option B: HTTP init endpoint (only if needed)

```text
https://your-domain/api/index.php?action=init_db&secret=YOUR_TMC_INIT_SECRET
```

Do not leave weak init secrets. Rotate or remove access after initialization.

## 6. Health Checks

Use one of these paths in Dokploy:
- `/`
- `/api/index.php?action=game_state`

If your app requires auth for some endpoints, use `/` for health checks.

## 7. DNS and SSL

1. Point your domain A record to the VPS IP.
2. Configure SSL in Dokploy for your domain.
3. Verify HTTPS and API reachability:
   - `https://your-domain/`
   - `https://your-domain/api/index.php?action=game_state`

## 8. Dedicated Tick Worker (Recommended)

The worker service runs:

```bash
/app/docker/worker-entrypoint.sh
```

and internally executes:

```bash
php /app/worker/tick_worker.php
```

This avoids public tick curl traffic and supports sub-minute intervals.

Recommended production values:

- `TMC_TICK_REAL_SECONDS=5`
- `TMC_WORKER_INTERVAL_SECONDS=5`
- `TMC_TICK_ON_REQUEST=false`

Validation checks:

1. Web service is healthy at `/` and `/api/index.php?action=game_state`.
2. Worker service logs show startup line: `[tick-worker] starting ...`.
3. Worker service logs show `time_scale=1` in `[tick-worker] timing (...)`.
4. `server_state.last_tick_processed_at` advances every few seconds.
5. `php /app/tools/runtime_readiness_check.php --observe-seconds=15 --pretty` returns neither `blocked` nor `degraded`.
6. If using remote diagnostics, `runtime_readiness` shows season advancement or explicitly reports expected blackout/zero-participant state. Unlike `rate_limit_diagnostics` it is **not** on the GET-safe allowlist, so it must be POSTed — a GET returns `405 method_not_allowed`:

   ```bash
   curl -X POST "https://your-domain/api/index.php?action=runtime_readiness" \
     -H "X-Init-Secret: $TMC_INIT_SECRET" \
     -H 'Content-Type: application/json' \
     -d '{"observe_seconds":15}'
   ```

   It shares the diagnostics gate: `403` unless **both** `TMC_RATE_LIMIT_DIAGNOSTICS=true` and `TMC_INIT_SECRET` are set. The CLI check in step 5 has neither requirement.

Fallback only if worker service cannot be used:

```text
POST https://your-domain/api/index.php?action=tick
Header: X-Tick-Secret: <TMC_TICK_SECRET>
```

## 9. Operations

Two things cannot be done from inside the game — appointing the first Admin,
and break-glass on the maintenance gate when no Admin can sign in. Both live in
`tools/admin.php`, run from the **web service** terminal in Dokploy:

```bash
php /app/tools/admin.php                          # usage
php /app/tools/admin.php staff                    # who has staff powers
php /app/tools/admin.php show <handle>            # one account's role and state
php /app/tools/admin.php promote <handle>         # grant Admin (or Moderator)
php /app/tools/admin.php demote <handle>          # back to Player
php /app/tools/admin.php gate status|on|off       # maintenance lockdown
```

Every command reports what actually changed, so a typo'd handle prints
`no such handle` instead of looking like it worked, and writes an audit row with
a NULL actor — the honest record of a change made from a shell rather than by a
player. Add `--reason="..."` to say why.

The app image has no `mysql` client (it installs only `pdo`/`pdo_mysql`), which
is why these are PHP rather than SQL. With a Dokploy database service you can
also open that service's terminal, where `mysql` does exist.

### First admin account

Staff powers live in the `players.role` column (`Player` | `Moderator` | `Admin`).
The in-game staff screen calls `admin_role_update`, which requires an existing
Admin — so the *first* Admin has to be made outside the game. Register the
account normally, then promote it once:

```bash
php /app/tools/admin.php promote YourHandle
```

Handles match case-insensitively. Registering an account does **not** grant a
role no matter what it is called — a fresh account is always `Player` until
promoted, so check with `staff` rather than assuming.

### Email confirmation

`staff` flags accounts with no confirmed email address as `unverified`, and
that flag is **load-bearing**. The account signs in, then is refused every
action except logout, confirming, asking for another link, and reading enough
state to be told so — `403` with `reason_code: email_verification_required`.
The gate sits in `api/index.php` ahead of the action switch rather than in
`Auth::login`, and it exempts `login`, which is why sign-in itself still
succeeds while everything after it does not.

**Staff are not exempt.** An `unverified` Admin can sign in and reach nothing,
including the staff screen. Resolve it before you need that screen in a hurry.

There is a self-service flow: registration issues an `EMAIL_VERIFY` token and
mails the link, `email_verify_confirm` consumes it, and `email_verify_resend`
issues another. It is only as good as outbound mail — see section 4.6, and
prove SMTP with `php /app/tools/mail_selftest.php` **before** opening
registration. `TMC_MAIL_DEV_LOG` defaults to on, which sends nothing.

Break-glass for an account whose mail never arrived:

```bash
php /app/tools/admin.php verify <handle>
```

This asserts control on the operator's word — it bypasses the confirmation mail
rather than proving anything — and writes an audit row. Use it when mail is
broken, not instead of fixing mail.

Accounts that predate the confirmation release were grandfathered to confirmed
by `migration_20260731b_email_verification.sql`, so an established lane should
not show `unverified` staff once it has deployed. If one still does, that
migration has not applied — check `schema_migrations` for a `status='failed'`
row rather than assuming the flag is cosmetic.

> This section has now been wrong in both directions — it once said unverified
> accounts could not sign in, then that the flag blocked nothing. Both were
> true of some build; neither is true of this one.

After the first Admin exists, promote everyone else in-game through the staff
screen, which records a real actor. `Permissions::canActOnTarget` requires a
strictly greater rank, so an Admin can act on Moderators and Players but not on
other Admins. Keep the Admin count small and hand out `Moderator`.

Demoting the last Admin is refused, because it leaves nobody able to use the
staff screen — including to appoint a replacement. Promote someone else first,
or pass `--force` if that really is the intent.

### Maintenance gate

The gate is runtime database state, not a deploy-time setting — a redeploy does
not change it. While it is on, non-staff get `503` with
`reason_code: maintenance_lockdown` on every gameplay action; auth and read-only
actions stay open so the construction page renders and staff can sign in and
bypass it.

```bash
php /app/tools/admin.php gate status
php /app/tools/admin.php gate on --reason="pre-test lockdown"
php /app/tools/admin.php gate off
```

### Play-testing a gated build

Staff pass the gate but cannot join a season — `seasonJoin` refuses any role
other than `Player`, so competitive standings never contain a moderator. With
the gate up and every ordinary account blocked, that leaves nobody able to
play-test. `maintenance_access` is the way through: it allows an account past
the gate without granting it any staff power, so a tester stays an ordinary
Player everywhere else and never appears in standings as staff.

```bash
php /app/tools/admin.php tester create playtest1      # throwaway account
php /app/tools/admin.php tester list
php /app/tools/admin.php tester grant <handle>        # allow an existing account
php /app/tools/admin.php tester revoke <handle>
```

`tester create` makes the account pre-verified, because a gated build is
exactly the situation where SMTP may not be configured yet — requiring an email
round trip would make the command useless when it is most needed. It prints a
generated password once; nothing stores it in plaintext.

Revoke access when the test is over. A `maintenance_access` account is
otherwise indistinguishable from any other player, so one left behind is
invisible until the next lockdown, when it can play and nobody else can.

`tools/maintenance_gate_selfcheck.mjs` exercises all of this against a running
server — that a gated player sees the construction page and cannot reach the
app around it, that the staff sign-in escape reaches an actual login form, and
that testers can act while ordinary accounts cannot:

```bash
node tools/maintenance_gate_selfcheck.mjs http://localhost:8080
```

It flips the real gate and restores it on the way out, so do not run it against
a lane with players in it.

Once an Admin exists the staff screen does the same thing via
`staff_server_mode`, with that Admin recorded as the actor. Raw SQL, if you want
it:

```sql
UPDATE server_state SET server_mode = 'MAINTENANCE_LOCKDOWN' WHERE id = 1;  -- gate on
UPDATE server_state SET server_mode = 'NORMAL' WHERE id = 1;                -- gate off
```

## 10. Troubleshooting

- `Database connection failed`:
  - Option A: confirm web/worker and the database are in the same Dokploy project and that `DB_HOST` is the internal service hostname, not `localhost`.
  - Option B: verify the provider allows your VPS public IP.
  - Both: confirm `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, and check section 4.10 for an alias silently supplying a stale value.
- Static frontend works but API fails:
  - Check Dokploy app logs for PHP errors.
  - Confirm env vars are present in runtime, not only build time.
- Init returns forbidden:
  - Set `TMC_INIT_SECRET` and use it in the init URL, or run CLI init.

- Worker not progressing ticks:
  - Confirm worker service start command is `/app/docker/worker-entrypoint.sh`.
  - Confirm worker replicas are `1`.
  - Check worker logs for `[tick-worker] error` lines.
  - Check worker logs for repeated `[tick-worker] advisory_lock_busy` lines (unexpected lock holder — often a second replica).
  - Check worker logs for `[tick-worker] cycle_result` with `progressed=false` or `season_error_count>0`.
  - Confirm DB env vars are present on the worker service, not only the web service.
  - Run `php /app/tools/runtime_readiness_check.php --observe-seconds=15 --pretty` inside the running container.

- Tick endpoint returns forbidden/not configured:
  - This matters only for fallback HTTP scheduler mode.
  - Ensure `X-Tick-Secret` matches `TMC_TICK_SECRET`.

- Runtime looks healthy but economy is still frozen:
  - Compare `server_state.last_tick_processed_at` with each season's `last_processed_tick`.
  - If heartbeat advances but seasons do not, inspect worker/web logs for `Tick processing error for season`.
  - Check `schema_migrations` for `status='failed'`.
  - Confirm `boost_catalog`, `active_boosts`, `active_freezes`, `sigil_drop_log`, and `sigil_drop_tracking` exist.
  - Confirm the current season is not legitimately `Blackout`, `Expired`, or empty of joined participants.

- Everything returns 503 with `maintenance_lockdown`:
  - The maintenance gate is on. See section 9.

- **Client IPs resolve to a private address** (`resolved_ip_is_private: true` with `proxy_trusted: true`):
  - The allowlist is right and the proxy is trusted, but the address it handed
    over is not a player's. Every anonymous request then keys on that one
    constant, so they share a bucket — no better than not trusting the proxy.
  - `client.resolved_from` names the header that was used, and `xff_value` /
    `xri_value` / `cfci_value` show what actually arrived. That distinguishes
    the two causes:
  - **`xff_value` contains a public address but `xri_value` does not.** The
    chain is intact; resolution took `X-Real-IP` first and that header is the
    wrong one for this topology. Fixable in this codebase — open an issue
    rather than reordering blind, since the right order depends on which proxy
    is authoritative in your setup.
  - **Neither header contains a public address.** The proxy never learned the
    client's address, so nothing downstream can recover it. This is upstream
    infrastructure: the connection is reaching Traefik through Docker's
    userland proxy, which rewrites the source. Fix it at the entrypoint —
    publish the port in host mode, or enable PROXY protocol — then re-check.

## 11. Rate-Limit Diagnostics and Rollout Checklist

Protected diagnostics endpoint. Send the secret as a header — this form has no
quoting or encoding traps:

```bash
curl -s "https://your-domain/api/index.php?action=rate_limit_diagnostics" \
  -H "X-Init-Secret: $TMC_INIT_SECRET"
```

The query-string form also works, but only if the secret is URL-encoded:

```text
GET https://your-domain/api/index.php?action=rate_limit_diagnostics&secret=YOUR_TMC_INIT_SECRET
```

A secret containing `&`, `+`, `#` or `%` silently arrives as something else —
`&` starts a new parameter, `+` decodes to a space — and the endpoint answers
`{"error":"Forbidden"}`, which looks exactly like a configuration problem. Pass
it as a header, or `curl --get --data-urlencode "secret=$TMC_INIT_SECRET"`.

### When diagnostics returns `{"error":"Forbidden"}`

Four different causes produce that one response, deliberately — the endpoint
does not say which check failed, because that would let an unauthenticated
caller learn whether a secret is configured. So do not guess from the outside.
Ask the container what it actually has, from the web service terminal:

```bash
php -r 'require "/app/includes/config.php"; printf(
  "diagnostics_enabled=%s  init_secret_set=%s  len=%d\n",
  TMC_RATE_LIMIT_DIAGNOSTICS ? "true" : "false",
  getenv("TMC_INIT_SECRET") ? "yes" : "no",
  strlen((string)getenv("TMC_INIT_SECRET")));'
```

It prints no secret material, only whether one is present and how long it is.

| Reading | Cause | Fix |
|---|---|---|
| `diagnostics_enabled=false` | flag unset, or set but not redeployed | Set `TMC_RATE_LIMIT_DIAGNOSTICS=true` and **redeploy** — Dokploy applies env changes at container start |
| `init_secret_set=no` | `TMC_INIT_SECRET` missing from this service | Set it on the web service specifically |
| Both correct, still `403` | the secret sent ≠ the secret set | Compare `len` against your secret's length; if they match, it is a transport problem — use the header form above |

Staged rollout checklist:

1. Deploy to test lane first with diagnostics enabled.

1. Confirm response headers exist on API calls: `X-RateLimit-Tier`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Window`.

1. Verify diagnostics output includes expected values for `client.proxy_trusted`, `client.resolved_ip`, and `rate_limit.identity_kind` (`session` for authenticated requests).

1. **Check `client.resolved_ip_is_private`.** `proxy_trusted: true` is only half the job — it says the app is willing to believe the proxy, not that the proxy knew who the client was. If `resolved_ip` is a private address (`10.x`, `172.16–31.x`, `192.168.x`) then every anonymous caller resolves to that same constant and they all share one bucket, which is the state trusting the proxy was supposed to fix. See "Client IPs resolve to a private address" in section 10.

1. Check logs for `[rate_limit]` JSON entries and confirm 429 events are isolated rather than synchronized across all users.

1. Verify 401 auth failures are not spiking alongside 429 events.

1. Validate client behavior under forced 429 conditions: users remain logged in and see a temporary busy/retry message instead of forced logout.

1. Keep test running for 24 hours and compare 429 rate, player disconnect complaints, and online churn patterns.

1. Promote to live only after stable metrics and no mass relogin patterns.

Disable diagnostics and/or trace after stabilization:

- `TMC_RATE_LIMIT_DIAGNOSTICS=false`
- `TMC_RATE_LIMIT_TRACE=false`

## 12. Stack Clarification

This deployment uses:
- Dockerfile
- PHP
- MySQL
- HTML/CSS
- JavaScript (frontend client)

If by "JAVA" you intended JVM Java, that is not used by this codebase.
