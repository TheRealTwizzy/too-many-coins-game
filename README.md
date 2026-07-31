# Too Many Coins

A deterministic economic competition game where every coin tells a story of sacrifice, strategy, and timing. Built with HTML, CSS, PHP, MySQL, and JavaScript.

## Game Overview

Too Many Coins is a season-based multiplayer economic strategy game. Players join 14-day competitive seasons, earn Coins through Universal Basic Income (UBI), convert them into Stars to climb the leaderboard, collect Sigils through random drops, activate Boosts to increase their income, and ultimately decide when to Lock-In and convert their Seasonal Stars to permanent Global Stars.

## Features

| Feature | Description |
|---------|-------------|
| **Universal Basic Income** | Dynamic UBI system with activity bonuses, inflation curves, and hoarding penalties |
| **Star Purchasing** | Convert Coins to Seasonal Stars at dynamic prices based on total coin supply |
| **6-Tier Sigil System** | Deterministic per-tick drop model; available tiers are gated by season phase |
| **Boost Activation** | Consume Sigils to activate temporary UBI modifiers (self only) |
| **Lock-In Mechanism** | Exit early to convert Seasonal Stars to Global Stars at 65% |
| **Sigil Theft** | Spend Tier 3/4/5 Sigils for a chance to steal Sigils from other season participants |
| **Season Leaderboard** | Ranked by Seasonal Stars with placement bonuses |
| **Global Leaderboard** | Ranked by lifetime Global Stars **earned**, so spending on cosmetics does not lower your rank |
| **Cosmetics Shop** | 24 cosmetic items across 5 categories, purchasable with Global Stars |
| **Chat System** | Global and per-season chat channels |
| **Idle Detection** | Activity tracking with idle acknowledgment system |

## Boost Catalog

Values come from `includes/boost_catalog.php`, which overwrites whatever the
`boost_catalog` table holds — the table's tuning columns are not authoritative.

Opening a boost (no boost currently active):

| Sigil spent | Power | Initial duration |
|---|---|---|
| Tier I | +5% UBI | 4 hours |
| Tier II | +10% UBI | 3 hours |
| Tier III | +25% UBI | 2 hours |
| Tier IV | +50% UBI | 1 hour |
| Tier V | +100% UBI | 30 minutes |

Spending into an already-active boost adds power, or adds time:

| Sigil spent | Power added | Time added |
|---|---|---|
| Tier I | +5% | 5 minutes |
| Tier II | +10% | 10 minutes |
| Tier III | +25% | 30 minutes |
| Tier IV | +50% | 1 hour |
| Tier V | +100% | 2 hours |

Note the inversion: a Tier I sigil buys the **longest** opening window, so
opening with a low tier and topping up is not a mistake. All boosts are `SELF`
scope — season-wide boosts were removed by
`migration_20260403c_disable_global_boosts_single_self.sql`. Only one boost row
is active per player, so the +500% combined cap is unreachable; the real
maximum is +100%.

Guaranteed floor policy (hybrid scaling):

- +1 whole Coin per tick per 10% effective boost modifier
- Applied after percent boost math and before fixed-point mint split
- Capped at `BOOST_GUARANTEED_FLOOR_CAP_COINS = 5`

## Sigil Drop System

The live model is `Economy::evaluateSigilDropForTick`. One drop attempt per
tick per player.

- **Base gate chance**: `SIGIL_DROP_CHANCE_FP = 3500` — **0.35% per tick**
- **Eligibility**: Offline earns nothing. Idle rolls at 0.5x the Active rate
- **Inventory pressure**: at or below 10 sigils held the rate is unmodified; it
  then ramps linearly to **zero** at the 25-sigil cap
- **Boost pressure**: an active boost multiplies the gate by
  `1 - 0.1 x ceil(boost% / 10)`, floored at 0.10 — a +100% boost cuts your drop
  rate to a **tenth**
- **Phase gating**: which tiers can drop depends on season phase —
  EARLY `T1-T3`, MID `T1-T5`, LATE `T1-T6`, BLACKOUT no drops at all
- **Tier odds**: phase-dependent, see `SIGIL_PHASE_TIER_WEIGHTS` in
  `includes/config.php`

**There is no pity timer and no drop throttle.** `SIGIL_PITY_TICKS` is read
only to populate a Sight-reveal display field,
`eligible_ticks_since_last_drop` is never incremented, and
`SIGIL_MAX_DROPS_WINDOW` is referenced nowhere. Earlier revisions of this
document described both as live mechanics; they are not implemented.

**Combine recipes** (`SIGIL_COMBINE_RECIPES`): 5xT1 to T2, 5xT2 to T3,
3xT3 to T4, 3xT4 to T5, 2xT5 to T6.

## Tech Stack

| Component | Technology |
|-----------|-----------|
| Frontend | HTML5, CSS3, JavaScript (Vanilla SPA) |
| Backend | PHP 8.x (REST API) |
| Database | MySQL 8.x |
| Architecture | Tick-based deterministic game engine |

## Project Structure

```
too-many-coins/
├── public/                      # Web-accessible files
│   ├── index.html              # Main HTML page (SPA)
│   ├── css/style.css           # Complete stylesheet (dark theme, gold accents)
│   ├── js/app.js               # Game client JavaScript
│   └── wiki/                   # Wiki pages (served at /wiki)
│       ├── index.html
│       ├── getting-started/
│       ├── game-systems/
│       ├── deployment/
│       └── assets/wiki.css
├── api/
│   └── index.php               # API router (all endpoints)
├── includes/
│   ├── config.php              # Configuration constants
│   ├── database.php            # Database connection class
│   ├── game_time.php           # Game time and season management
│   ├── economy.php             # UBI, pricing, trading, sigil drops
│   ├── tick_engine.php         # Tick processing engine
│   ├── actions.php             # Player action handlers
│   └── auth.php                # Authentication helpers
├── schema.sql                  # Database schema
├── seed_data.sql               # Initial data (cosmetics)
├── migration_boosts_drops.sql  # Boost and drop tables
├── migration_boost_duration_hotfix.sql # One-time live DB boost duration/description fixes
├── router.php                  # PHP dev server router
├── setup.sh                    # Production deployment script
├── tools/                      # Runtime readiness, reset, and cutover utilities
└── README.md                   # This file
```

## Automatic DB Migrations

Runtime migration application is enabled by default and runs on redeploy/startup:

- Any repo-root file matching `migration_*.sql` is auto-applied once.
- Applied files are tracked in `schema_migrations` with filename + checksum.
- Files ending with `_optional.sql` remain manual-only.
- `migration_boosts_drops.sql` remains init/setup-only bootstrap and is excluded from runtime auto-apply.
- `migration_20260413b_tick_runtime_compat.sql` backfills bootstrap-only tick tables for environments that skipped the original boost/drop bootstrap.

Guidelines:

- Add new DB changes as new `migration_*.sql` files (do not edit already-applied files).
- Keep auto-applied migrations idempotent where possible.
- Write migrations using MySQL 5.7+ compatible syntax. Avoid `ADD COLUMN IF NOT EXISTS` (supported only on some MySQL 8.x variants). Use `PREPARE/EXECUTE` with `INFORMATION_SCHEMA` guards instead (see existing `migration_20260329b_*_compat.sql` files for the pattern). This approach works with both runtime PDO execution and manual `mysql < file.sql` application.

### Failure-loop guard

When a migration fails, the runtime records it in `schema_migrations` with `status='failed'`.
Subsequent requests skip that migration entry, preventing repeated log-spam and interference with economy/tick paths.
**To remediate a failed migration:** create a new migration file with a corrected SQL approach; do not edit the original file (checksum immutability).

### Production recommendation

**Recommended for production:** run migrations as a one-time controlled step during deployment (not on every API request):

```
TMC_AUTO_SQL_MIGRATIONS=false
```

Then apply pending migrations manually:

```bash
mysql -u USER -p DB_NAME < migration_YYYYMMDD_name.sql
```

Or use the init script for a full re-run:

```bash
php init_db.php
```

Leave `TMC_AUTO_SQL_MIGRATIONS=true` (default) only in dev/staging environments where convenience outweighs caution. In production, a failed migration with `true` will be silently recorded as failed; operators must check `schema_migrations` for `status='failed'` rows.

Disable auto-migrations only if needed:

- `TMC_AUTO_SQL_MIGRATIONS=false`

Backward-compatibility alias still works:

- `TMC_AUTO_SQL_HOTFIX=false`

## Account, Social, and Moderation Operations

Account verification emails, staff deletion confirmation, staff chat, user social controls, and staff/admin moderation use the schema in `migration_20260428_social_account_moderation.sql`.

Email verification uses these environment variables:

- `TMC_PUBLIC_BASE_URL`: public base URL used for verification links.
- `TMC_MAIL_FROM`: sender address for account/security emails.
- `TMC_MAIL_FROM_NAME`: sender display name.
- `TMC_MAIL_DEV_LOG`: when true, verification emails are logged instead of sent.
- `TMC_VERIFICATION_TOKEN_MINUTES`: verification token lifetime.

Player accounts support editable profile metadata, current-password-verified password changes, friend/block lists, and self-deletion by emailed verification link. Staff and admin account deletion requests send the confirmation link to the acting staff/admin email, notify the target player, and audit the action.

Staff and admin actions are role-gated by `players.role`. Staff can moderate chat, mute users, manage eligible player accounts, open dedicated Staff chat threads, and send custom notifications to individual players or all players. Admins inherit staff scope and can also update roles and run day-0 economy reset controls.

Admin reset controls are intended to clear persisted economic play state while preserving account/auth, social, staff chat, notifications, and moderation audit data. They must not be used to change economy configuration, tick logic, pricing, rewards, simulation behavior, or season cadence.

## Season Reset (Preserve Accounts/Auth)

To rebuild season timelines/state without removing player accounts or authentication data,
use the season reset utilities under `tools/`.

What this reset preserves:

- `players` identity/auth columns (email/password/session)
- `handle_registry`
- `handle_history`

What this reset clears/rebuilds:

- season-bound runtime tables (`seasons`, `season_participation`, vault/theft/ledger/action tables)
- season pointers/participation flags on `players`
- `server_state`/`yearly_state` bootstrap timing rows

Preferred runner (no `mysql` CLI required):

```bash
php tools/run-season-reset.php
```

Runner modes:

- Reset + verify (default): `php tools/run-season-reset.php`
- Verify only: `php tools/run-season-reset.php --verify-only`
- Reset only: `php tools/run-season-reset.php --no-verify`

Raw SQL alternatives:

```bash
mysql -u USER -p DB_NAME < tools/reinitialize-seasons-preserve-accounts.sql
mysql -u USER -p DB_NAME < tools/verify-season-timing.sql
```

After reset, trigger one normal API request or one tick call so bootstrap recreates
`server_state`, `yearly_state`, and fresh season rows.

## Global Economic Reset (Preserve Accounts/Auth)

To rebuild the game to a day-0 economy while keeping player accounts and authentication
data intact, use the global economic reset utilities under `tools/`.

What this reset preserves:

- `players` identity/auth columns (email/password/session)
- `handle_registry`
- `handle_history`

What this reset clears/rebuilds:

- everything from the season reset
- `players.global_stars`
- `player_cosmetics`

Preferred runner (no `mysql` CLI required):

```bash
php tools/run-global-economic-reset.php
```

Runner modes:

- Reset + verify (default): `php tools/run-global-economic-reset.php`
- Verify only: `php tools/run-global-economic-reset.php --verify-only`
- Reset only: `php tools/run-global-economic-reset.php --no-verify`

Raw SQL alternative:

```bash
mysql -u USER -p DB_NAME < tools/reinitialize-global-economy-preserve-accounts.sql
```

After reset, trigger one normal API request or one tick call so bootstrap recreates
`server_state`, `yearly_state`, and fresh season rows.

## Wiki (In-Repo, Same Domain)

The project now includes an isolated wiki surface served under:

- `/wiki/`

Routing behavior is configured so `/wiki/*` does not fall into the SPA fallback:

- Dev server: `router.php`
- Nginx container: `docker/nginx.conf`
- Apache container: `docker/apache-vhost.conf`

This keeps game navigation and API routes unchanged while allowing static wiki deep links.

Current wiki routes:

- `/wiki/`
- `/wiki/getting-started/`
- `/wiki/game-systems/`
- `/wiki/competition/`
- `/wiki/social/`
- `/wiki/strategy/`
- `/wiki/search/`

Implementation notes:

- Full chapter/section content is rendered from `public/wiki/assets/wiki-data.js`.
- The shared renderer `public/wiki/assets/wiki-render.js` handles category page rendering and client-side search.

## Quick Start (Development)

```bash
# 1. Install PHP and MySQL
sudo apt-get install -y php php-mysql mysql-server

# 2. Start MySQL and create database
sudo service mysql start
sudo mysql -e "CREATE DATABASE too_many_coins;"

# 3. Load schema and data
sudo mysql -e "USE too_many_coins; SOURCE schema.sql;"
sudo mysql -e "USE too_many_coins; SOURCE seed_data.sql;"
sudo mysql -e "USE too_many_coins; SOURCE migration_boosts_drops.sql;"
sudo mysql too_many_coins -e "ALTER TABLE season_participation ADD COLUMN sigil_drops_total INT NOT NULL DEFAULT 0, ADD COLUMN eligible_ticks_since_last_drop BIGINT NOT NULL DEFAULT 0;"

# 4. Start the development server
php -S 0.0.0.0:8080 router.php

# 5. Open http://localhost:8080 in your browser
```

## Production Deployment

```bash
# Automated setup on a fresh Ubuntu server:
sudo TMC_DOMAIN=yourdomain.com ./setup.sh

# The script will:
# - Install Apache, PHP, MySQL
# - Create the database and user
# - Load all schema and data
# - Configure Apache virtual host
# - Set up cron for tick processing
# - Output the database credentials

# For SSL:
sudo apt-get install certbot python3-certbot-apache
sudo certbot --apache -d yourdomain.com
```

## Dokploy Deployment

For Ubuntu 24.04 VPS deployments with Dokploy — using either a Dokploy-managed
MySQL service or an external host — follow:

- `DEPLOY_DOKPLOY.md` (full environment-variable reference, first-admin
  bootstrap, and the maintenance gate)

## API Endpoints

All endpoints are accessed via `POST /api/index.php?action=<action>` with JSON body.

### Public Endpoints

| Action | Description |
|--------|-------------|
| `game_state` | Full game state (seasons, player data, boosts, drops) |
| `register` | Create account (handle, email, password) |
| `login` | Login (email, password) |
| `season_detail` | Season details (season_id) |
| `season_leaderboard` | Season rankings (season_id) |
| `global_leaderboard` | Global rankings |
| `cosmetics_catalog` | Cosmetic items catalog |
| `chat_history` | Chat messages (channel, season_id) |
| `tick` | Runs one server tick pulse (requires `X-Tick-Secret`) |

## Tick Processing in Production

The server supports two production-safe tick models:

1. Dedicated internal worker (recommended for Dokploy)
2. Scheduler endpoint (fallback)

### Dedicated Internal Worker (Recommended)

Run a separate worker process/service from the same image:

```bash
/app/docker/worker-entrypoint.sh
```

This executes `php /app/worker/tick_worker.php`, which calls `TickEngine::processTicks()` directly on an interval and does not require public HTTP calls.

Recommended env for worker-based processing:

- `TMC_TICK_ON_REQUEST=false`
- `TMC_TICK_REAL_SECONDS=5` (or your target cadence)
- `TMC_WORKER_INTERVAL_SECONDS=5`
- `TMC_WORKER_START_DELAY_SECONDS=2` (optional)
- `TMC_WORKER_ERROR_BACKOFF_SECONDS=2` (optional)

Worker safety:

- Uses MySQL advisory lock `GET_LOCK('tmc_tick_worker', 0)` to avoid concurrent tick execution if more than one worker replica is accidentally started.
- Worker logs now emit explicit `advisory_lock_busy` messages on repeated lock misses and `cycle_result` summaries when progression is not advancing cleanly.

### 1 Tick/Second Cutover (Safe Procedure)

When standardizing to 1 tick/second from legacy minute-scale data, use this sequence:

1. Precheck current stored tick scale:

```bash
php tools/precheck_tick_cadence.php
```

1. Dry-run migration telemetry (no writes):

- `TMC_TICK_REAL_SECONDS=1`
- `TMC_TIME_SCALE=1`
- `TMC_MINUTE_TO_SECOND_MIGRATION_DRY_RUN=1`
- `TMC_MINUTE_TO_SECOND_MIGRATION=0`

1. Cutover run (single controlled startup):

- `TMC_TICK_REAL_SECONDS=1`
- `TMC_TIME_SCALE=1`
- `TMC_MINUTE_TO_SECOND_MIGRATION_DRY_RUN=0`
- `TMC_MINUTE_TO_SECOND_MIGRATION=1`

1. Immediately disable migration flag after successful conversion:

- `TMC_MINUTE_TO_SECOND_MIGRATION=0`

Notes:

- The runtime migration path only executes when cadence is 1s/tick and minute-scale season duration is detected.
- Keep `TMC_TICK_ON_REQUEST=false` during cutover; use worker or scheduler endpoint only.

### Scheduler Endpoint (Fallback)

If you cannot run a worker service, use the dedicated scheduler endpoint:

- `POST /api/index.php?action=tick`
- Header: `X-Tick-Secret: <TMC_TICK_SECRET>`

Recommended environment variables:

- `TMC_TICK_SECRET=<strong-random-secret>`
- `TMC_TICK_ON_REQUEST=false`
- `TMC_TICK_REAL_SECONDS=60`
- `TMC_TIME_SCALE=1`

Then schedule a request every 1 minute (Dokploy schedule or external cron):

```bash
curl -sS -X POST "https://your-domain/api/index.php?action=tick" \
	-H "X-Tick-Secret: $TMC_TICK_SECRET"
```

### Runtime Readiness Gate

Before declaring a deployed environment healthy, run the runtime readiness checks:

```bash
php tools/runtime_readiness_check.php --pretty
php tools/runtime_readiness_check.php --observe-seconds=15 --pretty
```

Protected remote check:

```text
GET /api/index.php?action=runtime_readiness&secret=<TMC_INIT_SECRET>
GET /api/index.php?action=runtime_readiness&secret=<TMC_INIT_SECRET>&observe_seconds=15
```

What this validates:

- required tick-runtime tables exist (`boost_catalog`, `active_boosts`, `active_freezes`, `sigil_drop_log`, `sigil_drop_tracking`, `player_notifications`)
- failed `schema_migrations` are surfaced
- current season state is distinguished between `Active`, `Blackout`, `Expired`, and zero-participant states
- joinable season count is reported
- optional observation mode detects the dangerous case where `server_state.last_tick_processed_at` advances but no season `last_processed_tick` advances

Treat `blocked` or `degraded` results as a failed runtime gate even if simulations still pass.

### Authenticated Endpoints (require `X-Session-Token` header)

| Action | Description |
|--------|-------------|
| `season_join` | Join a season (season_id) |
| `idle_ack` | Acknowledge idle status |
| `purchase_stars` | Buy stars by quantity (stars_requested) |
| `purchase_boost` | Activate a boost (boost_id) |
| `lock_in` | Lock-in and exit season |
| `self_melt_freeze` | Spend Tier 5 or Tier 6 to reduce your active Freeze |
| `sigil_theft_preview` | Preview a sigil theft attempt |
| `sigil_theft_attempt` | Execute a sigil theft attempt |
| `chat_send` | Send message (channel, content, season_id) |
| `buy_cosmetic` | Purchase cosmetic (cosmetic_id) |
| `boost_catalog` | Get available boosts |
| `active_boosts` | Get active boosts |
| `sigil_drops` | Get recent sigil drops |

## Season Lifecycle

1. **Scheduled**: Season created, waiting for start time
2. **Active**: Players can join, earn UBI, buy stars, attempt sigil theft, collect sigils, activate boosts
3. **Blackout**: Final 72 hours. No new joins, star price frozen, no combining,
   and **all UBI accrual and all sigil drops stop**. Settlement only, plus a
   last chance to Lock-In.
4. **Expired**: Season ended, final standings calculated, Global Stars awarded

## Self-Checks

Dependency-free guards, matching the existing `tools/` convention. None of them
need a database except the concurrency harness.

```bash
php  tools/integrity_selfcheck.php        # every balance/sigil decrement is guarded + rowCount-checked
php  tools/star_price_selfcheck.php       # price tracks supply; legacy v1 seasons still behave
php  tools/hoarding_sink_selfcheck.php    # sink ramps, ceiling holds, idle not punished harder
php  tools/proxy_trust_selfcheck.php      # who may name the client IP: ranges, boundaries, malformed entries
node tools/client_security_selfcheck.js   # escapeHtml is attribute-safe; no client cookie writes
node tools/ui_smoke.mjs                   # real Chromium: console errors, overflow, NaN in output

# Needs a running server + database:
php  tools/concurrency_selfcheck.php     --base=http://localhost:8080
php  tools/server_security_selfcheck.php --base=http://localhost:8080
node tools/e2e_season_loop.mjs           http://localhost:8080
node tools/maintenance_gate_selfcheck.mjs http://localhost:8080  # flips the real gate
php  tools/email_verification_selfcheck.php --base=http://localhost:8080
```

## License

This project is based on the Too Many Coins game design documentation.
