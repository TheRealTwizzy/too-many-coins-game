# Game Editor — Design Proposal

Status: **proposal, for approval or rejection**. Nothing here is implemented.
No migrations, no schema changes, no code changes accompany this document.

Scope: a staff-facing authoring surface over Too Many Coins' economy —
parameters, season scheduling, feature flags, player inspection, balance
simulation.

---

## 0. What I read

`includes/config.php` (611 lines, 169 `define()`s), `includes/tick_engine.php`,
`includes/economy.php`, `includes/game_time.php`, `includes/audit.php`,
`includes/admin.php`, `includes/permissions.php`, `includes/boost_catalog.php`,
`schema.sql` (seasons table), `tools/admin.php`, `tools/balance_selfcheck.php`,
`worker/tick_worker.php`, the staff endpoints in `api/index.php`, and canon
chapters 3, 5, 12 and 15 in `too-many-coins-api/project_canon_docs`.

Claims below cite file and line. Where I am inferring rather than reading, I say
so explicitly.

---

## 1. The headline

**The brief's blocking prerequisite is roughly half-built already, and the built
half is broken in a way that matters more than the missing half.**

Three things are true at once:

1. `seasons` already carries a per-season economy parameter block. `schema.sql:74`
   labels it, in the schema itself, `-- Immutable per-season economy config`.
   Canon `chapter_05 §5.5` mandates exactly this: *"The per-season economy
   configuration MUST be immutable once the season starts. Any change requires a
   new season."* The design the brief asks for is not new — it is specified, and
   partly implemented.

2. That immutability is violated on **every application boot**.
   `GameTime::rebalanceExistingSeasons()` (`game_time.php:696-761`) runs from
   `ensureSeasons()`, which runs from `TickEngine::processTicks()`
   (`tick_engine.php:39`), which the worker runs every tick
   (`worker/tick_worker.php`). It issues a bare `UPDATE seasons SET ...` with
   twenty-odd hardcoded values and **no status predicate** — it will rewrite an
   `Active` season's UBI rate, hoarding table and star-price table mid-flight.
   It is currently gated only by `WHERE base_ubi_active_per_tick = 100 AND
   target_spend_rate_per_tick = 50`, which is a data-shaped accident, not a
   guard.

3. A large fraction of the parameters that *do* exist are wired to nothing.
   **27 of the 169 constants in `config.php` have zero use sites outside their own
   definition.** Six `seasons` columns are written and never read. The
   player-facing drop-odds API is computed from a code path the tick engine does
   not use.

So the risk is not "we might build an editor on top of a system that can't take
it." The risk is **an editor would make the existing breakage exploitable at
speed, and would expose a control surface where a meaningful share of the
controls are already disconnected from the game.**

That reframes the first work item. It is not "move constants to data." It is
**"establish that a tunable means exactly one thing, then move the ones that
survive."**

---

## 2. Findings

### F1 — The determinism story is much better than feared

This is the most important structural finding, and it is good news.

The drop RNG is **stateless and content-addressed**
(`economy.php:1368-1378`):

```php
$input = pack('J', $seasonId) . pack('J', $playerId) . pack('J', $tickIndex)
       . $seasonSeed . $streamTag;
return unpack('N', substr(hash('sha256', $input, true), 0, 4))[1];
```

There is no PRNG state to desynchronise. Every roll for every (season, player,
tick, stream) is recomputable forever from the seed. Separate `streamTag` values
(`'sigil_gate'`, `'sigil_tier'`) keep the streams independent, so consuming a
roll in one stream cannot shift another.

Critically, **parameters move the threshold, not the roll**
(`economy.php:1230-1241`):

```php
$gateRoll      = self::deterministicSigilRollU32(...);       // parameter-free
$gateThreshold = intdiv($effectiveDropChanceFp * $u32Range, FP_SCALE);  // all parameters here
if ($gateRoll >= $gateThreshold) return null;
```

The consequence is worth stating plainly, because it changes the risk
calculation for the whole proposal:

> Changing an economy parameter mid-season does **not** corrupt replayability.
> Every past roll remains recomputable and identical. What changes is the
> comparison applied to future rolls. A parameter change is a *discontinuity in
> policy*, not a *break in determinism*.

The `Legion` swarm event already relies on this property and documents it
(`economy.php:1211-1225`): it multiplies the gate threshold for a tick window
and explicitly notes *"The deterministic roll inputs below are never touched, so
history replays identically."* The pattern for safe, time-bounded parameter
change is therefore already in the codebase and already reasoned about.

**The determinism constraint is real but narrower than the brief assumes.** It
binds on one thing, below.

### F2 — Catch-up replay is the actual determinism hazard

`processSeasonTick` reads the season row **once** (`tick_engine.php:56`), then
replays up to `TICK_MAX_CATCHUP_TICKS` ticks in a loop
(`tick_engine.php:503-561`, default 1800 ticks / 30 real minutes at shipped
cadence, `config.php:154`). Season columns are therefore already snapshotted for
the batch — good. But `define()` constants are read at their use sites *inside*
the loop, on every iteration.

Within one PHP process a `define()` cannot change, so today's exposure is
limited to a redeploy landing between the moment ticks elapsed and the moment
they are processed. That window exists but is small and requires a deploy.

If parameters become runtime-editable data, **the window becomes every save**.
Ticks 1000–1500 elapsed under parameter set A; the worker processes them at
wall-clock T under parameter set B; the game awards drops for historical ticks
using thresholds that were never in force when those ticks occurred. The rolls
replay identically — but the outcomes do not match either parameter set's
intent, and nothing errors.

This is the single constraint the design must actually defend against, and it is
defensible: resolve parameters **once per batch, keyed to the season**, exactly
as the season row already is.

### F3 — 27 constants are dead

Zero use sites outside `config.php`:

```
COSMETIC_PRICE_TIERS                       SIGIL_INVENTORY_ADJ_MAX_STEPS
EVENTS_PUBLISH_AMOUNTS                     SIGIL_INVENTORY_ADJ_STEP_FP
FREEZE_BLACKOUT_BASE_DURATION_TICKS        SIGIL_INVENTORY_ADJ_THRESHOLD
FREEZE_BLACKOUT_STACK_EXTENSION_TICKS      SIGIL_INVENTORY_UPLIFT_MAX_STEPS
FREEZE_STACK_EXTENSION_TICKS               SIGIL_INVENTORY_UPLIFT_STEP_FP
HANDLE_COOLDOWN_DAYS                       SIGIL_INVENTORY_UPLIFT_THRESHOLD
SEASON_ANCHOR                              SIGIL_PACING_ENABLED
SIGIL_AFFINITY_REPICK_PHASE                SIGIL_PACING_JITTER_MAX_FP
SIGIL_BOOST_DROP_RATE_CEILING              SIGIL_PACING_JITTER_MIN_FP
SIGIL_BOOST_DROP_RATE_FLOOR                SIGIL_SEASON_PHASE_LATE_BLACKOUT
SIGIL_BOOST_DROP_RATE_MAX_PENALTY          SIGIL_TIER_DROP_RATES
SIGIL_BOOST_DROP_RATE_STEP_FP              SIGIL_TIER_ODDS_MAX
SIGIL_FREEZE_BLACKOUT_STACK_EXTENSION_TICKS_BY_TIER   SIGIL_TIER_ODDS_MIN
SIGIL_FREEZE_STACK_EXTENSION_TICKS_BY_TIER
```

Several carry long, confident explanatory comments describing mechanics that do
not run — `SIGIL_INVENTORY_ADJ_*` and `SIGIL_INVENTORY_UPLIFT_*` have ~30 lines
between them (`config.php:244-284`) documenting a dampening/uplift system with
no reader. `SIGIL_TIER_ODDS_MIN`/`MAX` document a monotonic-ordering guarantee
("T1 >= T2 >= ... is never violated") that nothing enforces.

An editor that surfaced `config.php` wholesale would present 27 controls that do
nothing, each with authoritative documentation claiming otherwise.

### F4 — The published drop odds describe a pipeline the engine does not run

This is a live, player-visible defect and the clearest argument for the
proposal's central rule.

There are two complete sigil-drop implementations in `economy.php`:

| | Legacy | Live |
|---|---|---|
| Entry point | `Economy::processSigilDrop()` (`:1094`) | `Economy::evaluateSigilDropForTick()` (`:1185`) |
| Callers | **none** | `tick_engine.php:540` |
| Gate | Bernoulli `1 in SIGIL_DROP_RATE` (=8, 12.5%) | `SIGIL_DROP_CHANCE_FP` (=3500, **0.35%/tick**) × activity × inventory × boost pressure |
| Tier odds | `SIGIL_TIER_ODDS` (flat, T1–T5) | `SIGIL_PHASE_TIER_WEIGHTS` (per-phase, T1–T6) |

`Economy::processSigilDrop()`, `sampleSigilTier()` and `calculateSigilPower()`
have zero callers. But `sigilDropRateForPower()` and `adjustedSigilTierOdds()`
— both part of the legacy path — are still called from
`api/index.php:1265-1267`, and `computePerPlayerSigilDropConfig()` (which
returns `SIGIL_DROP_RATE` and `SIGIL_TIER_ODDS` verbatim, `economy.php:1075-1080`)
from `api/index.php:1790`.

Those feed `season.sigil_drop_rates` — the odds shown to players.

So the client is told: *base 1-in-8, ~12.5%; T1 70% / T2 20% / T3 8% / T4 1.5% /
T5 0.5%; no T6.* The engine actually rolls 0.35% per tick before pressure
multipliers, with phase-dependent weights that include T6 in `LATE_ACTIVE`. The
published numbers are not approximations of the live ones — they are a different
model. `README.md:63-80` documents the live model correctly; the API does not.

Notably `README.md:80-85` claims *"There is no pity timer and no drop
throttle"* — that is now stale in the other direction: `tick_engine.php:515-537`
implements both, and `balance_selfcheck.php:348-356` greps the tick engine to
assert it. Two surfaces describing the drop system, both wrong, in opposite
directions.

**Rule this generates:** a value is only a tunable if exactly one code path
reads it and that path is the authoritative one. Anything read by a display
surface and an engine surface separately is a bug waiting for an editor to
accelerate it.

### F5 — Six season columns are write-only

Written by `ensureSeasons()`/`rebalanceExistingSeasons()`, read by nothing:

- `starprice_demand_table` (JSON, populated with a four-point demand curve)
- `market_pressure_fp`
- `star_burn_ema_fp`
- `net_mint_ema_fp`
- `market_anchor_price`
- `vault_config` (JSON) — already documented as dead at `economy.php:1060-1064`

`pending_star_burn_coins` is incremented at `actions.php:571` and read nowhere.

`economy.php:988-997` records the same failure mode being found and fixed once
already: a configurable star-price floor read `$season['star_price_minimum_absolute']`,
*"a column that exists in no schema and no migration — so the `?? 1` fallback was
the only path that ever ran and the 'configurable floor' was a fiction."*

### F6 — DB-backed content is overwritten from code at read time

`boost_catalog` is a real table, but `BoostCatalog::normalize()`
(`boost_catalog.php:216-256`) overwrites `name`, `scope`, `duration_ticks`,
`modifier_fp` (base), `max_stack`, `icon` and `sigil_cost` from the PHP
`DEFINITIONS` const on every read. Editing the table changes nothing except the
per-row runtime `modifier_fp` on `active_boosts`.

Three instances of the same anti-pattern now: dead constants, write-only
columns, and data-shaped storage overwritten by code. **The failure mode this
codebase actually has is not "values are hard to change" — it is "values are
stored in places nothing reads."** An editor is a machine for multiplying that
failure mode unless the first increment is a resolver with exactly one answer.

### F7 — One float path in an int64 economy

`Economy::BOOST_RATE_BONUS_BREAKPOINTS` (`economy.php:434-446`) and
`grossRateBonusFromBoostPct()` use PHP floats:

```php
$t = ($x - $x1) / $width;
return $y1 + $t * ($y2 - $y1);
```

IEEE-754 is deterministic for these operations on a fixed platform, so this
replays identically on the same binary — I am **inferring** rather than
verifying that no PHP version or libm difference perturbs it. It is nonetheless
the one place the file-header canon of *"int64 arithmetic with floor-after-each-step"*
(`economy.php:5`) is not held. If this table becomes editable, it should be
converted to the integer `piecewiseLinear()` already used for the inflation and
star-price tables (`economy.php:566-590`) first.

### F8 — The audit table has no path that can surface a parameter change

`Audit::record()` (`audit.php:49`) takes `(actorId, targetId, actionType,
reason, before, after)` and already does the right things: it redacts
`season_seed`, `password_hash` and `session_token` on the way in *and* on the
way out (`audit.php:20-28`, `88-96`).

But the only read path is `Audit::recentForTarget(int $targetId)`
(`audit.php:69`), which filters `WHERE target_player_id = ?`.

A parameter change has no player target. `staff_server_mode` and `cli_gate`
already write rows with `target_player_id = NULL` (`api/index.php:1006`,
`admin.php:330`) — **those rows are already unreadable through any existing
interface.** Adding parameter-change auditing without a non-player-scoped read
path means writing records nobody can retrieve, which is worse than not
claiming to audit.

Canon `chapter_15 §15.5.3` names the required record shape for this class
directly — an immutable record in `Lifecycle / Configuration Events` carrying
`toggle_id`, `old_value`, `new_value`, `effective_global_tick_index`.

### F9 — Canon forbids most of what the brief asks the staff console to do

`chapter_12`, hard constraints, lines 9–10:

> 1. Administrators and Moderators MUST NOT have **any control over the economy**
>    (balances, inventories, boosts, **prices**, rewards, placements, outcomes).
> 2. Administrators and Moderators MUST NOT have **any control over seasonal
>    timing/pace** (season length, expiration timing, blackout windows,
>    joins/exits legality windows, forced end/extend).

And `§12.4.1`: *"Staff MUST NOT change season timing, pacing, schedules, or
blackout windows."*

The brief's sequencing item 3 puts "economy tuning bound to versioned
parameters" and "season scheduling" behind the staff screen. **As written that
is prohibited by canon**, and `staff_server_mode` is not the precedent it looks
like — it toggles the maintenance gate, which is service safety, not economy.

I do not read this as a blocker, but it does change the design. The resolution
is in §4.4: parameter authority is not a staff role, and must not be reachable
from a `Moderator`/`Admin` session.

---

## 3. Which constants become data

Four buckets. The classification rule is mechanical: **what is the blast radius
of a wrong value, and can the wrongness be detected?**

### Bucket A — Delete, do not migrate (27 constants)

Every constant in F3. These are not tuning surface; they are archaeology.
Deleting them is the cheapest correctness work available and it shrinks the
editor's surface by 16% before a line of editor code exists.

Two need a decision rather than a delete: `SIGIL_TIER_ODDS_MIN`/`MAX` describe a
monotonicity invariant that is genuinely desirable. Either implement the clamp in
the live path or delete the constants — but the comment claiming it is enforced
must not survive either way.

### Bucket B — Stays in code, permanently (not negotiable)

| Constant(s) | Why |
|---|---|
| `TICK_REAL_SECONDS`, `TIME_SCALE` | Every rate in the economy is divided by `ticksPerRealMinute()`/`ticksPerRealHour()` (`economy.php:615-625`). Changing these mid-life rescales every stored tick value; the codebase carries **two** full migrations to recover from having done it (`maybeMigrateLegacyTickScale`, `maybeMigrateMinuteTickScaleToSecond`, ~270 lines). This is deployment topology, not tuning. |
| `FP_SCALE` | The unit of the entire fixed-point system, 84 use sites. |
| `SEASON_DURATION`, `SEASON_CADENCE`, `BLACKOUT_DURATION`, `SIGIL_BLACKOUT_DURATION_TICKS` | Season geometry. Canon `§12` hard constraint 2 forbids staff control; changing them retroactively moves phase boundaries under in-flight seasons. Schedule *instances*, not the shape (see §4.3). |
| `TICK_MAX_CATCHUP_TICKS`, `TMC_TICK_*`, `TMC_RATE_LIMIT_*`, `TMC_TRUST_PROXY_*`, `TMC_SMTP_*`, `DB_*` | Operational/infrastructural. Belongs in env, where it is. |
| `SIGIL_DROP_ALGORITHM_VERSION`, `STARPRICE_MODEL_V1/V2` | Identifiers for code branches. A version tag you can edit is not a version tag. |
| `STAR_PRICE_ABSOLUTE_FLOOR` | `config.php:137` states the reasoning: a zero star price mints unbounded score. Structural invariant. |

### Bucket C — Per-season parameters (the editable set)

These are the ones that become versioned data. Two groups:

**C1 — already per-season columns, already read.** Nothing to migrate; they need
validation, versioning and an immutability guard:

`base_ubi_active_per_tick`, `base_ubi_idle_factor_fp`, `ubi_min_per_tick`,
`inflation_table`, `hoarding_min_factor_fp`, `target_spend_rate_per_tick`,
`hoarding_window_ticks`, `hoarding_sink_enabled`, `hoarding_safe_hours`,
`hoarding_safe_min_coins`, `hoarding_tier{1,2}_excess_cap`,
`hoarding_tier{1,2,3}_rate_hourly_fp`, `hoarding_sink_cap_ratio_fp`,
`hoarding_idle_multiplier_fp`, `starprice_table`, `star_price_cap`,
`starprice_idle_weight_fp`, `starprice_active_only`,
`starprice_max_{up,down}step_fp`, `market_affordability_bias_fp`.

This set maps closely onto canon `§5.5.2`'s required field list. The two canon
fields with no column — `trade_fee_tiers`, `modifier_component_clamps` — should
be noted as gaps, not invented here.

**C2 — currently `define()`s, promote to per-season parameters:**

| Group | Constants |
|---|---|
| Drop gate | `SIGIL_DROP_CHANCE_FP`, `SIGIL_ACTIVITY_MULTIPLIER_FP`, `SIGIL_BOOST_DROP_PRESSURE_{STEP_FP,STEP_PENALTY_FP,MIN_FP}`, `SIGIL_INVENTORY_DROP_PRESSURE_{START,FULL}` |
| Tier distribution | `SIGIL_PHASE_TIER_WEIGHTS`, `SIGIL_PHASE_AVAILABLE_TIERS`, `SIGIL_EARLY_PHASE_FRACTION_FP` |
| Pity / throttle | `SIGIL_PITY_TICKS`, `SIGIL_MAX_DROPS_WINDOW`, `SIGIL_DROP_WINDOW_TICKS` |
| Inventory | `SIGIL_INVENTORY_TOTAL_CAP`, `SIGIL_INVENTORY_TIER_CAPS`, `SIGIL_COMBINE_RECIPES` |
| Valuation | `SIGIL_REFERENCE_STARS_BY_TIER`, `SIGIL_UTILITY_VALUE_BY_TIER` |
| Theft | `SIGIL_THEFT_SUCCESS_CAP_FP`, `SIGIL_THEFT_VALUE_PRESSURE_MULTIPLIER`, `SIGIL_THEFT_{COOLDOWN,PROTECTION}_TICKS`, `SIGIL_THEFT_{SPEND,TARGET}_TIERS` |
| Freeze / melt | `SIGIL_FREEZE_DURATION_TICKS_BY_TIER`, `SIGIL_FREEZE_{COOLDOWN,PROTECTION}_TICKS`, `SIGIL_{FREEZE,MELT}_SPEND_TIERS`, `SIGIL_MELT_REDUCTION_TICKS_BY_TIER` |
| Boost | `BOOST_GUARANTEED_FLOOR_{STEP_PERCENT,STEP_COINS,CAP_COINS}`, `BOOST_RATE_BONUS_BREAKPOINTS` (after F7 integer conversion) |
| Families | `SIGIL_FAMILY_WEIGHTS_FP`, `SIGIL_AFFINITY_{BONUS,PENALTY}_PP`, `SIGIL_SIGHT_TRICKLE_CHANCE_FP`, `WARD_UNITS_X100_BY_TIER`, `WARD_DEFLECT_TIER`, `WARD_MAX_FRACTION_OF_REMAINING_FP`, `CAPS_{PER_FAMILY_HOLDING,MODIFIER_CEILING_PCT}` |
| Legion | `LEGION_CRITICAL_MASS_COUNT`, `LEGION_EVENT_UNITS_X100_BY_TIER`, `LEGION_{SWARM_DROP,FORESIGHT_SIGHT}_MULTIPLIER_FP`, `LEGION_FRENZY_TIMING_DIVISOR` |
| Market | `MARKET_RATE_HOURS_PER_VP_FP`, `MARKET_MAX_DISCOUNT_FRACTION_FP`, `MARKET_WINDOW_TICKS` |
| Settlement | `PARTICIPATION_BONUS_{DIVISOR,CAP}`, `PLACEMENT_BONUS`, `STARTER_GRANT_{TIER,COUNT}`, `MIN_SEASONAL_LOCK_IN_TICKS`, `MIN_PARTICIPATION_TICKS` |

Roughly 55 parameters. That is a curated set with a defensible boundary, not
"edit anything."

Note the derived constants (`SIGIL_THEFT_BLACKOUT_*`, `SIGIL_FREEZE_BLACKOUT_*`,
`FREEZE_BASE_DURATION_TICKS`, `ABILITY_UNIT_DURATION_TICKS`) — these are
computed from their parents in `config.php`. They should stay **derived**, in
code, from the parameter values. Exposing a parent and its derivative as
independent controls is how you get a blackout cooldown longer than the active
one.

### Bucket D — Global feature flags (not per-season)

`TMC_SIGIL_FAMILIES_ENABLED`, `TMC_PROGRESSION_GATES_ENABLED`,
`FORGE_TRANSMUTE_ENABLED`, `FORGE_DISTIL_ENABLED`,
`EVENTS_PUBLIC_TICKER_ENABLED`.

These are canon feature gates (`chapter_15 §15.10.2`): *"Feature-gate flips MUST
become effective only at the next tick boundary and MUST be fully audited."*
They are genuinely global, genuinely runtime, and the canon contract is already
written. They do **not** need the parameter-versioning machinery — they need a
`server_state`-style row, a tick-boundary read, and an audit record.

This is why they can ship well before the parameter work, and why I move them
earlier in the sequencing (§6).

---

## 4. Design

### 4.1 Shape

Two tables. (Described for evaluation — **not proposed for implementation this
session**.)

```
economy_parameter_set
  parameter_set_id   PK
  label              human name ("conservative-v3")
  status             draft | validated | published | withdrawn
  payload            JSON, the full parameter document
  payload_hash       SHA-256 of canonical-form payload
  parent_set_id      lineage
  created_by         actor (nullable — CLI changes have no player actor)
  created_at
  validated_at, validation_report JSON

seasons
  + parameter_set_id  FK, NOT NULL, set at creation, never updated
```

**Whole documents, not rows.** A parameter set is one immutable JSON blob with a
content hash. This matters: economy parameters are not independent. `star_price_cap`
must dominate `max(starprice_table.price)`; theft's success cap and pressure
multiplier are only jointly meaningful; the settle/utility ladders must stay
proportional across all six tiers (`balance_selfcheck.php:238-242`). Row-level
edits let a validated combination be reached through unvalidated intermediates.
Sets are validated, published and pinned as units.

**`payload_hash` is the identity.** Two sets with the same hash are the same
parameters. This makes "did anything actually change?" a comparison rather than
a diff review, and makes rollback provably exact.

### 4.2 Resolution: one function, once per season, at creation

```php
EconomyParameters::forSeason(int $seasonId): ParameterSet   // memoised per request
```

Rules:

1. **A season resolves its parameters at creation and stores the id.** Never
   looked up by "current published set" at tick time. This is what makes the
   catch-up hazard (F2) structurally impossible rather than merely unlikely: a
   replay of tick 1200 reads the set the season was born with, whatever the
   wall clock says.

2. **Every reader goes through it.** No `define()` fallback, no `?? $default` at
   a use site. The pattern in F4 and F5 — a second reader with its own defaults —
   is what the resolver exists to prevent. A missing key is a hard error at
   season creation, never a silent default at tick time. This is the codebase's
   own stated rule (`AGENTS.md`: *"Unknown config keys are errors, not warnings"*).

3. **The published set only affects seasons created after publication.** There is
   no "apply to running season" verb. Canon `§5.5.1` already says so; the design
   just needs to stop violating it.

### 4.3 How a running season is protected

Four layers, in order of strength:

1. **Structural.** `seasons.parameter_set_id` is written once at INSERT. The
   parameter tables have no UPDATE path from the console at all — publishing
   creates a new set, it never mutates one. An in-flight season cannot see a new
   value because nothing has a way to point it at one.

2. **`rebalanceExistingSeasons()` must be deleted or hard-gated.** This is
   non-optional and is the first fix, before any editor work. As it stands it is
   an unaudited mutation of live economy config running every tick. Its purpose —
   repairing seasons created with legacy defaults — is a migration, and should
   run as one: once, explicitly, with a `status = 'Scheduled'` predicate, from
   `tools/`, with an audit record. Not from the tick loop.

3. **Immutability guard.** A DB trigger (or, if triggers are unwanted, an
   assertion in the resolver comparing the season's parameter payload hash
   against the hash recorded at creation) that refuses writes to economy config
   columns on seasons whose status is not `Scheduled`. Cheap, and it catches the
   next `rebalanceExistingSeasons()` before it ships.

4. **Feature gates are the one exception, and are bounded.** Bucket D flips do
   affect running seasons — that is their point. Canon constrains them to
   next-tick-boundary effect and full audit. They can only turn whole systems on
   or off, never re-tune a live one, and every gated system already degrades
   safely (`SigilFamilies::active()` additionally requires the family schema, so
   a flag flip on an unmigrated DB behaves like the pre-family build,
   `config.php:516-520`).

**Deliberately not included: a mid-season parameter override, in any form.**
Even scheduled at a tick boundary, even audited. Per F1 it would be technically
safe for replay — and I still would not build it, because §5.5.1 is a promise to
players about the season they are in, and a mechanism that exists will be used
under pressure at exactly the moment judgement is worst.

### 4.4 Who holds the authority

Per F9, canon forbids `Admin`/`Moderator` from economy and season-timing
control, and `chapter_12 §12.1.1` closes the role set to three immutable values —
so a fourth "Designer" role is not available.

Proposal: **parameter authority is not an in-game role, and is not reachable
from an authenticated player session.** It lives where `tools/admin.php` already
puts operator-level authority — behind shell access, recorded with a NULL actor,
which that file describes precisely as *"a change made by whoever had shell
access, not by a player"* (`tools/admin.php:22-23`).

This is not a workaround. It is the honest model: the person tuning the economy
is the game's author, not a moderator, and the authority they hold is
deployment-shaped. It is also what makes the canon constraint hold without
weakening it — the in-game staff screen gains no economy lever at all.

Consequence for the console (§6): the browser UI is **read-only for economy
parameters** — inspect sets, diff them, view simulation results, read audit
history. Authoring and publishing stay on the CLI. A staff account can see what
the parameters are and what changed; it cannot change them.

### 4.5 Validation

Three gates, all before `status = 'validated'`:

1. **Schema/range** — canon `§5.5.3` specifies these and they can be lifted
   almost verbatim: tables non-empty, keys strictly increasing, fp bounds in
   `[0, 1_000_000]`, `star_price_cap >= max(starprice_table.price) >= 1`,
   `ubi_min_per_tick >= 1`, `target_spend_rate > 0`.

2. **Relational** — `tools/balance_selfcheck.php` already encodes these; the
   brief says 88 assertions. Today it reads `config.php` constants directly at
   `:32`. It should be refactored to take a parameter set as input, which turns
   it from a build-time check into a **candidate validator**. That is a small
   change with disproportionate value: it means every candidate set is checked
   against the same properties that guard the shipped one (theft has a positive
   swing, punching down does not pay, spending beats settling at every spendable
   tier, refunds monotonic in tier, pity is a backstop not a faucet).

3. **Simulation** — §5.

Note `balance_selfcheck.php:321-334` already scopes some bounds to the shipped
cadence and skips them otherwise. That precedent — *"this assertion is only
meaningful at this cadence"* — should carry into the validator rather than being
reinvented.

### 4.6 Audit and player visibility

**Audit.** Reuse `Audit::record()` — it already redacts correctly and already
supports NULL targets. What is missing is the read path (F8). Needs:

- a non-player-scoped query (by `action_type`, by date range), and
- `action_type` values matching canon `§15.5.3`'s `Lifecycle / Configuration
  Events` class, carrying `old_value`, `new_value`, `effective_global_tick_index`.

`before_json`/`after_json` should carry **payload hashes plus the changed keys**,
not both full documents. Full payloads make every row enormous and the actual
change unreadable.

`season_seed` is already in `REDACTED_KEYS` (`audit.php:27`) — important, since
parameter sets sit next to seasons and canon `§15.5.2` forbids logging or
exposing the seed.

**What a player can see.** Two things, and I would draw the line hard:

- *Publish the parameters their season is running under.* The season already
  publishes `sigil_drop_rates`; the fix in F4 is to compute it from the same
  resolver the engine uses. That closes a live divergence and makes the odds
  honest. Under §4.3 a player's parameters cannot change mid-season, so this is
  a stable, checkable claim.
- *Do not publish the parameter-set history, labels, or lineage.* The
  economy's tuning trajectory is design information, and canon `§15.11.3.3`
  deliberately gives players only an audit *tail pointer*, not audit contents.

The strongest player-facing property is not disclosure — it is that **the numbers
cannot move under them.** Publishing per-season parameters and guaranteeing
immutability is worth more than a changelog.

---

## 5. Simulation sandbox

The brief is right that this is the highest value per unit of risk, and the
seeds are real but thinner than they look.

**What exists:** `GameTime::setSimulationTick()` (`game_time.php:79`) and
`TickEngine::processTickAt()` (`tick_engine.php:132`), both gated on
`TMC_SIMULATION_MODE=fresh-run`. `Economy::presenceIsStale()` already derives
"now" from the simulated clock (`economy.php:665-671`). `ensureSeasons()`
already no-ops under a simulation clock (`game_time.php:181-184`).

**What does not exist: any caller.** `processTickAt()` and `setSimulationTick()`
have zero callers anywhere in the repo. The hooks were built and never wired.
`AGENTS.md` describes a simulation discipline — canonical effective-config
resolver, `effective_config.json`, `effective_config_audit.md`, preflight
failure on unknown keys — for which **no implementing code exists in this
repository.** I flag that as an inconsistency to resolve before building: either
that machinery lives somewhere I was not given, or the rules describe an
intended design rather than a current one.

**What a runner needs:**

- A disposable database (schema load + direct season INSERT, matching the
  `season_setup_direct_insert` path `game_time.php:177` already anticipates).
- N synthetic players with a presence/activity script — this is the real design
  work, and the honest constraint on fidelity. Drop rates depend on
  `activity_state`, `online_current` and `last_seen_at` (`economy.php:1161-1173`),
  so simulated player *behaviour* determines results as much as parameters do.
  A model that assumes everyone is Active all season will validate parameters
  against a population that does not exist.
- The tick loop: `for ($t = $start; $t <= $end; $t++) TickEngine::processTickAt($t)`.
- Report: supply curve, star price, per-tier drop counts, sink totals, Gini or
  similar spread across players, plus the `balance_selfcheck` assertion results.

**Feasibility, honestly.** A 14-day season at 60s/tick is 20,160 ticks. Per tick
per player the engine issues several queries (`tick_engine.php:321`, `340`,
`479`, `496`, `566`, plus per-drop writes). At 50 synthetic players that is
on the order of 5–10 million statements. "In seconds" is optimistic against a
real MySQL; **minutes** is realistic, and worth designing for rather than
discovering. Two mitigations, both worth considering at design time:

- Run at `TMC_TICK_REAL_SECONDS=60` and let batch processing collapse the
  per-tick loop — but note the drop loop (`tick_engine.php:503`) iterates per
  tick regardless of batching, deliberately, so the sigil path does not
  collapse.
- Accept it and make the runner a background job with a report artefact, rather
  than an interactive control in a browser.

The property that makes this worth building anyway: **it touches nothing live**,
and per F1 the deterministic RNG means a simulation with a given seed is exactly
reproducible — the same candidate set can be re-run against the same synthetic
population and give the same answer.

---

## 6. Sequencing

I largely agree with the brief's ordering, with two changes I would defend.

### 0. Correctness prerequisites (new — before anything else)

None of this is editor work. All of it is work an editor makes dangerous to skip.

- **Delete `rebalanceExistingSeasons()` from the tick path.** Unaudited mutation
  of live economy config, every tick. (F1/§4.3)
- **Delete the 27 dead constants**, and the comments claiming they do things.
- **Fix `season.sigil_drop_rates`** to compute from the live drop path. Players
  are currently shown odds from a different model. (F4)
- **Delete or wire the write-only season columns.** (F5)
- **Reconcile `README.md` §Sigil Drop System** with the tick engine (pity and
  throttle are live).

Small, independently valuable, each shippable alone, and together they establish
the one-reader-per-value rule the parameter design depends on.

### 1. `tools/admin.php` extensions (moved up)

The brief calls these "cheap wins to note separately." **The analysis says they
should be first**, and not because they are cheap — because §4.4 concludes the
CLI is the *permanent, correct* home for parameter authority under canon, not a
stopgap until a UI exists.

- `season list|show` — read-only, immediately useful, needs nothing new.
- `player inspect` — read-only; canon `§12` constraint 3 limits staff to
  publicly-visible state, so this needs a defined field allowlist, not
  `SELECT *`.
- `flags list|set` — Bucket D. Independent of all parameter work, needs only a
  `server_state`-style row, a tick-boundary read and an audit record. This is
  the item most likely to deliver "easier development toggles" soonest, and it
  is why I promote it above the parameter work.
- `economy show` — dump the effective parameter set for a season. Buildable
  *before* the parameter tables exist, reading `config.php` + the `seasons` row,
  and it is the resolver's first consumer.

`season create|advance` — **not here.** See §7.

### 2. Constants to versioned data

As designed in §4. Prerequisite for tuning, no user-visible payoff, highest
risk — the brief has this right. But it is materially cheaper after step 0,
because a third of the surface no longer exists and the ownership rule is
already established.

### 3. Simulation sandbox

The brief puts this second. I put it third, for one reason: **a simulation
harness that reads `config.php` constants directly can only ever test the
shipped configuration.** Its value is testing *candidates*, and a candidate is a
parameter set. Built before step 2 it needs its own ad-hoc override mechanism —
a second config path, which is F4's failure mode with extra steps.

If step 2 slips, the fallback is to refactor `balance_selfcheck.php` to take a
parameter set as input (§4.5.2) and ship that alone. It is a fraction of the
work and catches the class of error — a settle ladder that inverts, theft that
goes arithmetically dead — that a full season sim would also catch.

### 4. Read-only console

Extend the existing staff screen. Per §4.4 this is **inspection only** for
economy parameters: view the set a season is pinned to, diff two sets, read
simulation reports, read configuration audit history, inspect a player against
the allowlist. Feature-flag toggles are the one write, and only because canon
explicitly contemplates them.

### 5. Event injection — test lanes only

Trigger a Legion event, force a drop, advance a season phase. Hard-gated on
`TMC_SIMULATION_MODE`, refusing to run against a database with real accounts.
Last, lowest value, highest blast radius: this is the only item that writes
directly to player-visible season state, and canon `§12.4.2` puts most of its
natural extensions in the forbidden list.

---

## 7. What I would explicitly not build

| | Why |
|---|---|
| **Mid-season parameter override** — even tick-aligned, even audited | Canon `§5.5.1`. Technically safe for replay (F1), which is exactly why it is tempting; it would still break a promise to players mid-season. The mechanism's existence is the risk. |
| **`season create` / `advance` / `extend` from the console or CLI** | Canon `§12` constraint 2 and `§12.4.1` both forbid staff control of season timing. Seasons are derived from `SEASON_ANCHOR`/`SEASON_CADENCE` (`game_time.php:132`) by design. Scheduling a season is a deployment act; forcing a phase is a canon violation. A *read-only* `season list` is fine; `create`/`advance` are not, outside a `TMC_SIMULATION_MODE` test lane. |
| **Per-player economy edits, in any form** | Canon `§12.4.2`. `AdminService::playerEconomyReset` already exists behind a confirmation phrase and is the furthest this should ever go. Not extended, not softened, not given a UI. |
| **A general `config.php` editor** | The point of a curated set with validated ranges is the boundary. A generic key/value editor is the same product with the boundary deleted. |
| **Editing `TICK_REAL_SECONDS`/`TIME_SCALE` from anywhere but env** | Two 130-line migrations exist because this was changed once. |
| **Row-level parameter editing** | §4.1: the invariants are relational; a valid set is not reachable through independently-valid edits. |
| **Publishing parameter-set history to players** | Canon `§15.11.3.3` gives players an audit tail pointer, not contents. Immutability is the guarantee worth making; disclosure is not. |
| **A second config resolution path for simulation** | The whole failure mode in F4/F5/F6. If simulation cannot use the production resolver, the resolver is wrong. |
| **`GET_LOCK`-free parallel simulation** | Not now. The worker's single-lock model (`tick_worker.php:44`) is load-bearing; parallel sim runs need separate databases, not shared-DB concurrency. |

---

## 8. Open questions

1. **`AGENTS.md`'s simulation rules reference machinery that does not exist in
   this repository** — canonical effective-config resolver, `effective_config.json`,
   `effective_config_audit.md`, candidate-patch schema validation. Do these live
   elsewhere, or are they a specification for work not yet done? The answer
   changes §5 substantially; if that resolver is specified, the parameter
   resolver in §4.2 should be it rather than a parallel design.

2. **Canon `§5.5.2` requires `trade_fee_tiers` and `modifier_component_clamps`;
   neither has a `seasons` column.** Is trading out of scope for the current
   build, or is this a schema gap? I did not read the trade paths closely enough
   to say.

3. **The `staff_audit_log` read path** (F8) — is a non-player-scoped audit view
   acceptable to canon `§12`? `§15` line 307 says *"Admins: full audit access"*,
   which suggests yes, but the interaction with `§12`'s staff-authority limits
   is worth confirming before building it.

4. **Synthetic player behaviour model** (§5) — this is the largest unspecified
   piece and it determines whether simulation results mean anything. Worth its
   own decision before the runner is built.

---

## 9. Summary

- The prerequisite is **partly built and partly broken**. `seasons` already
  carries per-season economy config that the schema calls immutable, canon
  mandates, and a boot-time function rewrites on live seasons every tick.
- **Determinism is safer than feared.** The RNG is stateless and content-addressed;
  parameters move thresholds, not rolls. The real hazard is catch-up replay, and
  pinning parameters per season at creation makes it structurally impossible.
- **The dominant risk is not "wrong value" but "value nobody reads."** 27 dead
  constants, 6 write-only columns, a DB table overwritten from code, and a
  player-facing odds display computed from a dead pipeline. An editor multiplies
  this class of bug unless one-reader-per-value is established first.
- **Canon forbids staff economy and season-timing control.** Parameter authority
  belongs on the CLI with a NULL actor, as `tools/admin.php` already models it.
  The console is read-only for economy; only feature gates are writable, and
  canon already specifies their contract.
- **Sequencing:** correctness fixes → CLI (inspection + feature flags) →
  parameters as versioned data → simulation → read-only console → gated event
  injection.
