# Economy bugs on test: root cause and fix plan (room task E1)

Reported on test.too-many-coins.com (runs `main`), 2026-11-14:

1. The next-payout timer counts down and plays the payout effect, but the coin
   balance and season progress never change.
2. Sigils never drop.
3. Players cannot join the active season, so they cannot buy stars.

Investigated on `main` at `bde1df9`. `source/dev` is behind `main` (#85, #90,
#92 are main-only), so it cannot reproduce this.

## Root cause (one cause, three symptoms)

PR #90 (active-return pacing, `bde1df9`) added code that reads and writes
columns created by
`migrations/migration_20260429_active_return_gameplay_flow.sql`:

- `season_participation`: `last_meaningful_economy_tick`,
  `last_return_pulse_tick`, `last_active_pulse_tick`, `return_pulses_total`,
  `active_pulses_total`
- `seasons`: `return_pulse_min_gap_ticks`, `return_pulse_cooldown_ticks`,
  `active_dry_spell_ticks`, `participation_pulse_reward_tier`
- `sigil_drop_log.source` ENUM widened with `RETURN_PULSE`,
  `ACTIVE_DRY_SPELL_PULSE`

None of those uses is guarded by `Database::columnExists()`, which
`includes/database.php` documents as required for exactly this reason. The
deploy templates (`deploy/dokploy/web.env.example`, `worker.env.example`) set
`TMC_AUTO_SQL_MIGRATIONS=false`, so unless someone ran `php tools/migrate.php`
on test, the migration is pending and every hot path that touches pacing
throws `SQLSTATE[42S22] Unknown column`:

| Symptom | Path that throws |
| --- | --- |
| All three, globally | `TickEngine::processTicks` calls `GameTime::ensureSeasons()` first (`includes/tick_engine.php:40`). When a new season slot is due, its `INSERT INTO seasons` (`includes/game_time.php:274-306`) names `return_pulse_min_gap_ticks`, `return_pulse_cooldown_ticks`, `active_dry_spell_ticks`, `participation_pulse_reward_tier` and throws. That aborts the whole cycle before any season is processed (the worker logs `[tick-worker] error: SQLSTATE[42S22]...`), and no new season is ever created, so once the current one ends there is nothing to join. |
| Rejoin | `Actions::resetSeasonParticipationForFreshStart` (`includes/actions.php:86`) resets `last_meaningful_economy_tick` / `last_active_pulse_tick`, so a player rejoining a season they were in before fails too. (Spotted by `@mokemuzik617.claude` in review.) |
| 3. Cannot join season | `Actions::seasonJoin` (`includes/actions.php:385`) calls `ParticipationPacing::markMeaningfulEconomyEvent` inside its transaction; the catch rolls back and returns `Failed to join season: SQLSTATE[42S22]...`. Any season-join then also blocks star purchase, which needs a participation row. |
| 1. No coins / progress | `TickEngine::processSeasonTick` (`includes/tick_engine.php:251-446`) runs all participants in ONE transaction. For an Active player it calls `ParticipationPacing::grantActivePulseForPlayer` (line 289). On an unmigrated row the dry-spell decision sees `last_meaningful_economy_tick` as 0, so the pulse is eligible, and the grant path throws (pulse insert into `sigil_drop_log` with an unwidened ENUM, then the `last_meaningful_economy_tick` write). The catch (line 446) rolls back the whole season batch: UBI, participation ticks, supply, star price and `last_processed_tick` for every player. The next cycle retries the same batch and fails again. |
| 2. No sigils | Same rollback discards any RNG/pity drop in the batch. Independently, `TickEngine::awardSigilDrop` ends with `markMeaningfulEconomyEvent` (line 701), so any drop throws on its own. |

Why it "appears" to grant UBI: since #92 the client fires the payout pulse and
re-polls on each tick boundary from the server's published schedule
(`public/js/main.js` ~1993-2020). It plays whether or not the server batch
committed, so the UI looks like a payout happened.

Evidence:

- Code reading above (all line numbers at `bde1df9`).
- `tools/schema_pending_selfcheck.php` (added here) drives
  `ParticipationPacing` against `tools/lib/fake_db.php` with the migration's
  columns missing. On `main` it fails 5 of 7: `markMeaningfulEconomyEvent`
  throws `Unknown column 'last_meaningful_economy_tick'`, and both pulse grants
  decide "eligible" and go on to award a sigil instead of standing down.
- NOT yet confirmed against the test database (no access from here). Confirm
  with, in the test web or worker container:
  - `php tools/migration_status.php` - expect
    `migration_20260429_active_return_gameplay_flow.sql` PENDING (or failed)
  - worker logs - expect `[tick-worker] error: SQLSTATE[42S22]` (season
    INSERT) and/or `Tick processing error for season N: SQLSTATE[42S22]` with
    `[tick] cycle_degraded`, every cycle.

### Other suspects, ruled out or secondary

- #92 (timer deadlines): client-side countdown and worker alignment only. Does
  not touch balances. Explains the misleading payout effect (fix task E5/E6),
  not the missing coins.
- #85 (stop rewriting season economy config on boot): only stops overwriting
  existing season config rows. Does not explain a join failure or a rollback.
  Revisit only if the bugs persist after the migration is applied.
- PR #87 (sigil drop odds) was closed unmerged; drop odds on main are the
  pre-#87 values. Not a cause of zero drops, since drops are rolled back.

## Immediate unblock (owner, no code change)

Apply the pending migration on test: `php tools/migrate.php` in the test web
container (one runner; it takes the `tmc_migrations` lock). This is a schema
change on a shared DB, so it needs owner approval. After it runs, ticks should
commit on the next cycle and joins should succeed. The code fixes below stop
this class of outage from recurring.

## Fix tasks (parallel, disjoint files)

| Task | Owns (only these files) | Done when |
| --- | --- | --- |
| E2 ops: apply migration on test (owner) | none (ops) | `migration_status.php` shows nothing pending; a test account joins the active season and gains coins over two ticks. |
| E3 pacing: degrade when schema pending | `includes/participation_pacing.php`, `includes/game_time.php` (season INSERT only), `includes/actions.php` (`resetSeasonParticipationForFreshStart` only), `tools/schema_pending_selfcheck.php`, `.github/workflows/selfcheck.yml` | Every pacing read/write checks `columnExists`; missing columns make `markMeaningfulEconomyEvent` a no-op and pulses return `granted=false, reason_code=schema_pending` before any write. The season INSERT and the participation reset include the pacing columns only when they exist. `schema_pending_selfcheck` passes (extended to cover the INSERT and reset) and is in CI. `participation_pacing_selfcheck` still passes. |
| E4 tick: one bad player must not roll back the season | `includes/tick_engine.php` | Optional side effects (pulse grant) in the tick loop run under a SAVEPOINT or a per-call try/catch so their failure is logged and skipped, not a whole-season rollback; core UBI/participation writes unchanged. `awardSigilDrop` tolerates a pending `sigil_drop_log.source` ENUM for pulse sources (falls back or skips the pulse). DB-free selfcheck using `tools/lib/fake_db.php` covers it. |
| E5 api: publish tick health | `api/index.php` (game_state only) | `game_state.timing.tick_health = { season_last_processed_tick, lag_ticks, degraded }` for the player's season, `degraded=true` when the season's `last_processed_tick` is more than 2 ticks behind game time. Field contract fixed here so E6 can build in parallel. |
| E6 client: payout effect only on real payout | `public/js/main.js`, `public/js/core/clock.js`, `public/js/patch-notes.js` | Payout pulse plays only when the polled balance or `tick_health.season_last_processed_tick` actually advanced; when `tick_health.degraded` is true the HUD shows a "payouts delayed" state instead of the pulse. `client_core_selfcheck` covers both. |
| E7 deploy: fail loudly on pending migrations | `includes/runtime_readiness.php`, `tools/runtime_readiness_check.php`, `worker/tick_worker.php` (startup log only), `DEPLOY_DOKPLOY.md` | With auto-migrations off and a migration pending, readiness reports red with the file names, and the worker logs `[tick-worker] pending_migrations` at start. Deploy doc says to run `tools/migrate.php` before promoting. |

Ordering: E2 unblocks players now and depends on nothing. E3-E7 are
independent of each other (E6 builds against the E5 contract above). All code
PRs follow release discipline: branch from `main` is acceptable here because
`source/dev` lacks #90; target `source/dev` once it is synced with `main`,
otherwise `main`, per owner call.

## Selfcheck harness

`tools/lib/fake_db.php` is a recording stand-in for `Database`: canned
`fetch`/`fetchAll` rows by regex, `statementsMatching()` to assert writes, and
`missingColumns` to make any statement naming a pending column throw the real
MySQL error while `columnExists()` reports false. Extend it rather than writing
a new fake per selfcheck.
