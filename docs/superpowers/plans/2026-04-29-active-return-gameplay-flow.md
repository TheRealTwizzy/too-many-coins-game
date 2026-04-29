# Active Return Gameplay Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a bounded return pulse and active dry-spell pulse so returning and continuously active players get reliable low-tier participation fuel without globally flooding the economy.

**Architecture:** Add a small `ParticipationPacing` helper that owns eligibility, cap checks, pulse application, and meaningful-event bookkeeping. Store pacing knobs on `seasons` so simulations, exports, candidate patches, and play-test runtime all use the same canonical effective-config path. Wire runtime entry points (`Auth`, `Actions`, `TickEngine`) and simulator entry points (`SimulationPlayer`, `MetricsCollector`) through the helper.

**Tech Stack:** PHP 8, PHPUnit 11, existing MySQL schema/migrations, canonical simulation preflight, existing batch/diagnosis scripts.

---

## File Structure

- Create `includes/participation_pacing.php`: central runtime helper for return pulse eligibility, active dry-spell eligibility, pulse grants, and meaningful-event updates.
- Modify `includes/actions.php`: grant return pulses on season join/rejoin and idle acknowledgement; mark meaningful events for star purchases, boosts, combine, freeze, melt, theft, lock-in.
- Modify `includes/auth.php`: grant return pulses when a session/login brings an offline non-idle player back online.
- Modify `includes/tick_engine.php`: mark sigil drops as meaningful events and grant active dry-spell pulses during active non-blackout ticks.
- Modify `includes/game_time.php`: insert new pacing season config values for new seasons and legacy rebalance paths.
- Modify `schema.sql`: add season pacing config columns and participation pacing state columns.
- Create `migration_20260429_active_return_gameplay_flow.sql`: idempotently add and backfill the new columns without resetting gameplay state.
- Modify `scripts/simulation/SimulationSeason.php`: add pacing keys to the simulation season surface and defaults.
- Modify `scripts/simulation/CanonicalEconomyConfigContract.php`: make pacing knobs canonical patchable season keys.
- Modify `scripts/simulation/EconomicCandidateValidator.php`: add pacing keys to the validator allowlist under `sigil_drop_tier_combine`.
- Modify `scripts/simulation/SimulationConfigPreflight.php`: mark pacing keys as active referenced season keys.
- Modify `scripts/simulation/SimulationPlayer.php`: model return and active pulses, update dry-spell timers, and export pulse metrics.
- Modify `scripts/simulation/MetricsCollector.php`: aggregate pulse metrics and active dry-spell diagnostics.
- Modify `scripts/analyze_baseline.php`: include pulse/dry-spell analysis in `baseline_analysis_report.json`.
- Modify `scripts/diagnose_economy.php`: report active dry-spell regressions as gameplay-flow findings.
- Create `tests/ParticipationPacingTest.php`: pure helper behavior tests.
- Modify `tests/SimulationConfigPreflightTest.php`: canonical audit tests for pacing keys.
- Modify `tests/SimulationExportImportTest.php`: season default/export round-trip tests for pacing keys.
- Create `tests/SimulationParticipationPacingTest.php`: simulator return and dry-spell pulse tests.

---

### Task 1: Red Tests For Pacing Helper And Canonical Config

**Files:**
- Create: `tests/ParticipationPacingTest.php`
- Modify: `tests/SimulationConfigPreflightTest.php`
- Modify: `tests/SimulationExportImportTest.php`

- [ ] **Step 1: Write failing helper tests**

Create `tests/ParticipationPacingTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/economy.php';
require_once __DIR__ . '/../includes/participation_pacing.php';

class ParticipationPacingTest extends TestCase
{
    private function season(array $overrides = []): array
    {
        return array_replace([
            'return_pulse_min_gap_ticks' => 5,
            'return_pulse_cooldown_ticks' => 30,
            'active_dry_spell_ticks' => 5,
            'participation_pulse_reward_tier' => 1,
            'blackout_time' => 1000,
            'end_time' => 1200,
        ], $overrides);
    }

    private function player(array $overrides = []): array
    {
        return array_replace([
            'player_id' => 10,
            'joined_season_id' => 1,
            'participation_enabled' => 1,
            'activity_state' => 'Active',
            'idle_modal_active' => 0,
            'idle_since_tick' => 10,
            'last_activity_tick' => 10,
            'online_current' => 1,
        ], $overrides);
    }

    private function participation(array $overrides = []): array
    {
        return array_replace([
            'player_id' => 10,
            'season_id' => 1,
            'sigils_t1' => 0,
            'sigils_t2' => 0,
            'sigils_t3' => 0,
            'sigils_t4' => 0,
            'sigils_t5' => 0,
            'sigils_t6' => 0,
            'sigil_drops_total' => 0,
            'last_meaningful_economy_tick' => 10,
            'last_return_pulse_tick' => 0,
            'last_active_pulse_tick' => 0,
            'return_pulses_total' => 0,
            'active_pulses_total' => 0,
        ], $overrides);
    }

    public function testReturnPulseEligibleAfterIdleGap(): void
    {
        $decision = ParticipationPacing::returnPulseDecision(
            $this->season(),
            $this->player(['idle_since_tick' => 10]),
            $this->participation(),
            16
        );

        $this->assertTrue($decision['eligible']);
        $this->assertSame('eligible', $decision['reason_code']);
        $this->assertSame(1, $decision['reward_tier']);
    }

    public function testReturnPulseCooldownBlocksRepeatGrant(): void
    {
        $decision = ParticipationPacing::returnPulseDecision(
            $this->season(),
            $this->player(['idle_since_tick' => 10]),
            $this->participation(['last_return_pulse_tick' => 15]),
            20
        );

        $this->assertFalse($decision['eligible']);
        $this->assertSame('return_pulse_cooldown', $decision['reason_code']);
    }

    public function testActiveDrySpellEligibleAfterQuietActiveWindow(): void
    {
        $decision = ParticipationPacing::activePulseDecision(
            $this->season(),
            $this->player(),
            $this->participation(['last_meaningful_economy_tick' => 20]),
            25,
            'Active'
        );

        $this->assertTrue($decision['eligible']);
        $this->assertSame('eligible', $decision['reason_code']);
        $this->assertSame(1, $decision['reward_tier']);
    }

    public function testActivePulseBlocksAtSigilCapacity(): void
    {
        $decision = ParticipationPacing::activePulseDecision(
            $this->season(),
            $this->player(),
            $this->participation(['sigils_t1' => 25, 'last_meaningful_economy_tick' => 20]),
            25,
            'Active'
        );

        $this->assertFalse($decision['eligible']);
        $this->assertSame('sigil_capacity', $decision['reason_code']);
    }

    public function testApplyingReturnPulseMutatesParticipationCounters(): void
    {
        $participation = $this->participation();
        $applied = ParticipationPacing::applyPulseToParticipation(
            $participation,
            $this->season(),
            ParticipationPacing::SOURCE_RETURN,
            16
        );

        $this->assertTrue($applied);
        $this->assertSame(1, $participation['sigils_t1']);
        $this->assertSame(1, $participation['sigil_drops_total']);
        $this->assertSame(16, $participation['last_meaningful_economy_tick']);
        $this->assertSame(16, $participation['last_return_pulse_tick']);
        $this->assertSame(1, $participation['return_pulses_total']);
    }
}
```

- [ ] **Step 2: Add failing preflight tests**

Append these methods to `tests/SimulationConfigPreflightTest.php`:

```php
    public function testParticipationPacingCandidatePatchIsActiveAndAudited(): void
    {
        $resolved = SimulationConfigPreflight::resolve($this->options([
            'candidate_patch' => [
                'return_pulse_min_gap_ticks' => 7,
                'return_pulse_cooldown_ticks' => 42,
                'active_dry_spell_ticks' => 6,
                'participation_pulse_reward_tier' => 1,
            ],
        ]));

        $this->assertSame('pass', $resolved['report']['status']);
        $season = $resolved['report']['effective_config']['season'];
        $this->assertSame(7, $season['return_pulse_min_gap_ticks']);
        $this->assertSame(42, $season['return_pulse_cooldown_ticks']);
        $this->assertSame(6, $season['active_dry_spell_ticks']);
        $this->assertSame(1, $season['participation_pulse_reward_tier']);

        $paths = array_column($resolved['report']['requested_candidate_changes'], 'path');
        $this->assertContains('season.return_pulse_min_gap_ticks', $paths);
        $this->assertContains('season.return_pulse_cooldown_ticks', $paths);
        $this->assertContains('season.active_dry_spell_ticks', $paths);
        $this->assertContains('season.participation_pulse_reward_tier', $paths);
    }

    public function testUnknownParticipationPacingCandidateKeyFailsPreflight(): void
    {
        $this->expectException(SimulationConfigPreflightException::class);

        SimulationConfigPreflight::resolve($this->options([
            'candidate_patch' => ['participation_pulse_reward_tier_99' => 1],
        ]));
    }
```

- [ ] **Step 3: Add failing export/default tests**

Append these methods to `tests/SimulationExportImportTest.php`:

```php
    public function testDefaultSeasonIncludesParticipationPacingDefaults(): void
    {
        $season = SimulationSeason::build(1, 'participation-pacing-defaults');

        $this->assertSame(ticks_from_real_seconds(300), (int)$season['return_pulse_min_gap_ticks']);
        $this->assertSame(ticks_from_real_seconds(1800), (int)$season['return_pulse_cooldown_ticks']);
        $this->assertSame(ticks_from_real_seconds(300), (int)$season['active_dry_spell_ticks']);
        $this->assertSame(1, (int)$season['participation_pulse_reward_tier']);
    }

    public function testParticipationPacingKeysRoundTripThroughExport(): void
    {
        $season = SimulationSeason::build(1, 'participation-pacing-export', [
            'return_pulse_min_gap_ticks' => 9,
            'return_pulse_cooldown_ticks' => 45,
            'active_dry_spell_ticks' => 8,
            'participation_pulse_reward_tier' => 1,
        ]);
        $exportFile = $this->writeExportFile($season, 'participation_pacing_roundtrip.json');

        $imported = SimulationSeason::fromJsonFile($exportFile, 1, 'participation-pacing-export');

        $this->assertSame(9, (int)$imported['return_pulse_min_gap_ticks']);
        $this->assertSame(45, (int)$imported['return_pulse_cooldown_ticks']);
        $this->assertSame(8, (int)$imported['active_dry_spell_ticks']);
        $this->assertSame(1, (int)$imported['participation_pulse_reward_tier']);
    }
```

- [ ] **Step 4: Verify red**

Run:

```powershell
php vendor\bin\phpunit tests\ParticipationPacingTest.php tests\SimulationConfigPreflightTest.php tests\SimulationExportImportTest.php --filter "ParticipationPacing|participation|ReturnPulse|ActiveDrySpell" --no-coverage
```

Expected: FAIL because `includes/participation_pacing.php` does not exist and the canonical season keys are not defined.

---

### Task 2: Add Canonical Pacing Config Surface

**Files:**
- Modify: `schema.sql`
- Create: `migration_20260429_active_return_gameplay_flow.sql`
- Modify: `includes/game_time.php`
- Modify: `scripts/simulation/SimulationSeason.php`
- Modify: `scripts/simulation/CanonicalEconomyConfigContract.php`
- Modify: `scripts/simulation/EconomicCandidateValidator.php`
- Modify: `scripts/simulation/SimulationConfigPreflight.php`

- [ ] **Step 1: Add season config columns to `schema.sql`**

Add these columns after `market_affordability_bias_fp` in the `seasons` table:

```sql
    -- Participation pacing config
    return_pulse_min_gap_ticks BIGINT NOT NULL DEFAULT 5,
    return_pulse_cooldown_ticks BIGINT NOT NULL DEFAULT 30,
    active_dry_spell_ticks BIGINT NOT NULL DEFAULT 5,
    participation_pulse_reward_tier TINYINT NOT NULL DEFAULT 1,
```

- [ ] **Step 2: Add participation state columns to `schema.sql`**

Add these columns after `reactivation_start_tick` in `season_participation`:

```sql
    -- Participation pacing state
    last_meaningful_economy_tick BIGINT NOT NULL DEFAULT 0,
    last_return_pulse_tick BIGINT NOT NULL DEFAULT 0,
    last_active_pulse_tick BIGINT NOT NULL DEFAULT 0,
    return_pulses_total INT NOT NULL DEFAULT 0,
    active_pulses_total INT NOT NULL DEFAULT 0,
```

- [ ] **Step 3: Create the migration**

Create `migration_20260429_active_return_gameplay_flow.sql`:

```sql
-- Migration: active return gameplay flow pacing
-- Scope: add bounded return and active dry-spell pacing state without resetting gameplay.

ALTER TABLE seasons
    ADD COLUMN IF NOT EXISTS return_pulse_min_gap_ticks BIGINT NOT NULL DEFAULT 5 AFTER market_affordability_bias_fp,
    ADD COLUMN IF NOT EXISTS return_pulse_cooldown_ticks BIGINT NOT NULL DEFAULT 30 AFTER return_pulse_min_gap_ticks,
    ADD COLUMN IF NOT EXISTS active_dry_spell_ticks BIGINT NOT NULL DEFAULT 5 AFTER return_pulse_cooldown_ticks,
    ADD COLUMN IF NOT EXISTS participation_pulse_reward_tier TINYINT NOT NULL DEFAULT 1 AFTER active_dry_spell_ticks;

ALTER TABLE season_participation
    ADD COLUMN IF NOT EXISTS last_meaningful_economy_tick BIGINT NOT NULL DEFAULT 0 AFTER reactivation_start_tick,
    ADD COLUMN IF NOT EXISTS last_return_pulse_tick BIGINT NOT NULL DEFAULT 0 AFTER last_meaningful_economy_tick,
    ADD COLUMN IF NOT EXISTS last_active_pulse_tick BIGINT NOT NULL DEFAULT 0 AFTER last_return_pulse_tick,
    ADD COLUMN IF NOT EXISTS return_pulses_total INT NOT NULL DEFAULT 0 AFTER last_active_pulse_tick,
    ADD COLUMN IF NOT EXISTS active_pulses_total INT NOT NULL DEFAULT 0 AFTER return_pulses_total;

UPDATE seasons
SET
    return_pulse_min_gap_ticks = GREATEST(1, CEIL(300 * GREATEST(1, end_time - start_time) / 1209600)),
    return_pulse_cooldown_ticks = GREATEST(1, CEIL(1800 * GREATEST(1, end_time - start_time) / 1209600)),
    active_dry_spell_ticks = GREATEST(1, CEIL(300 * GREATEST(1, end_time - start_time) / 1209600)),
    participation_pulse_reward_tier = 1
WHERE status IN ('Scheduled', 'Active', 'Blackout')
  AND participation_pulse_reward_tier = 1;

UPDATE season_participation sp
JOIN seasons s ON s.season_id = sp.season_id
SET sp.last_meaningful_economy_tick = COALESCE(NULLIF(sp.last_meaningful_economy_tick, 0), COALESCE(sp.first_joined_at, s.start_time))
WHERE sp.last_meaningful_economy_tick = 0;

SELECT 'active_return_gameplay_flow_complete' AS status;
```

- [ ] **Step 4: Add new-season defaults in `includes/game_time.php`**

In the `GameTime::ensureSeasons()` insert column list, add:

```php
                     return_pulse_min_gap_ticks, return_pulse_cooldown_ticks,
                     active_dry_spell_ticks, participation_pulse_reward_tier,
```

In the matching `VALUES` list after `market_affordability_bias_fp, market_anchor_price`, add:

```php
                     ?, ?, ?, 1,
```

In the insert parameter array after `STARPRICE_REACTIVATION_WINDOW_TICKS_DEFAULT, $starpriceDemandTable`, add:

```php
                     ticks_from_real_seconds(300), ticks_from_real_seconds(1800), ticks_from_real_seconds(300),
```

- [ ] **Step 5: Add simulation season keys and defaults**

In `scripts/simulation/SimulationSeason.php`, add these keys to `SEASON_ECONOMY_COLUMNS` after `market_affordability_bias_fp`:

```php
        'return_pulse_min_gap_ticks',
        'return_pulse_cooldown_ticks',
        'active_dry_spell_ticks',
        'participation_pulse_reward_tier',
```

Add these defaults after `market_affordability_bias_fp` in `build()`:

```php
            'return_pulse_min_gap_ticks' => ticks_from_real_seconds(300),
            'return_pulse_cooldown_ticks' => ticks_from_real_seconds(1800),
            'active_dry_spell_ticks' => ticks_from_real_seconds(300),
            'participation_pulse_reward_tier' => 1,
```

- [ ] **Step 6: Add canonical contract schema entries**

In `scripts/simulation/CanonicalEconomyConfigContract.php`, add to `PATCHABLE_PARAMETER_SCHEMA` after `market_affordability_bias_fp`:

```php
        'return_pulse_min_gap_ticks' => [
            'type' => 'int',
            'subsystem' => 'sigil_drop_tier_combine',
            'units' => 'ticks',
            'min' => 1,
            'max_from_context' => 'season_duration_ticks',
            'description' => 'Minimum inactive gap before a player can receive a return participation pulse.',
        ],
        'return_pulse_cooldown_ticks' => [
            'type' => 'int',
            'subsystem' => 'sigil_drop_tier_combine',
            'units' => 'ticks',
            'min' => 1,
            'max_from_context' => 'season_duration_ticks',
            'description' => 'Cooldown between return participation pulse grants for a player in one season.',
        ],
        'active_dry_spell_ticks' => [
            'type' => 'int',
            'subsystem' => 'sigil_drop_tier_combine',
            'units' => 'ticks',
            'min' => 1,
            'max_from_context' => 'season_duration_ticks',
            'description' => 'Maximum active quiet window before a low-tier participation pulse can grant fuel.',
        ],
        'participation_pulse_reward_tier' => [
            'type' => 'int',
            'subsystem' => 'sigil_drop_tier_combine',
            'units' => 'sigil_tier',
            'min' => 1,
            'max' => 1,
            'description' => 'Sigil tier granted by participation pulses. This pass allows only Tier 1.',
        ],
```

- [ ] **Step 7: Add validator allowlist entries**

In `scripts/simulation/EconomicCandidateValidator.php`, change the `sigil_drop_tier_combine` allowlist from an empty array to:

```php
        'sigil_drop_tier_combine' => [
            'return_pulse_min_gap_ticks',
            'return_pulse_cooldown_ticks',
            'active_dry_spell_ticks',
            'participation_pulse_reward_tier',
        ],
```

- [ ] **Step 8: Mark preflight keys active**

In `scripts/simulation/SimulationConfigPreflight.php`, add these entries to `SEASON_KEY_META` after `market_affordability_bias_fp`:

```php
        'return_pulse_min_gap_ticks' => ['candidate_scope' => true, 'referenced' => true],
        'return_pulse_cooldown_ticks' => ['candidate_scope' => true, 'referenced' => true],
        'active_dry_spell_ticks' => ['candidate_scope' => true, 'referenced' => true],
        'participation_pulse_reward_tier' => ['candidate_scope' => true, 'referenced' => true],
```

- [ ] **Step 9: Verify config green**

Run:

```powershell
php vendor\bin\phpunit tests\SimulationConfigPreflightTest.php tests\SimulationExportImportTest.php --filter "ParticipationPacing|participation" --no-coverage
```

Expected: PASS for config/export tests. `tests/ParticipationPacingTest.php` still fails until Task 3 creates the helper.

---

### Task 3: Implement ParticipationPacing Helper

**Files:**
- Create: `includes/participation_pacing.php`
- Test: `tests/ParticipationPacingTest.php`

- [ ] **Step 1: Create helper with pure decisions and mutation**

Create `includes/participation_pacing.php`:

```php
<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/economy.php';

class ParticipationPacing
{
    public const SOURCE_RETURN = 'return_pulse';
    public const SOURCE_ACTIVE = 'active_dry_spell_pulse';

    public static function returnPulseDecision(array $season, array $player, array $participation, int $tick): array
    {
        return self::pulseDecision($season, $player, $participation, $tick, self::SOURCE_RETURN, 'Active');
    }

    public static function activePulseDecision(array $season, array $player, array $participation, int $tick, string $presenceState): array
    {
        return self::pulseDecision($season, $player, $participation, $tick, self::SOURCE_ACTIVE, $presenceState);
    }

    public static function applyPulseToParticipation(array &$participation, array $season, string $source, int $tick): bool
    {
        $tier = self::rewardTier($season);
        if (!Economy::canReceiveSigilTier($participation, $tier, 1, 0)) {
            return false;
        }

        $col = 'sigils_t' . $tier;
        $participation[$col] = max(0, (int)($participation[$col] ?? 0)) + 1;
        $participation['sigil_drops_total'] = max(0, (int)($participation['sigil_drops_total'] ?? 0)) + 1;
        $participation['last_meaningful_economy_tick'] = $tick;

        if ($source === self::SOURCE_RETURN) {
            $participation['last_return_pulse_tick'] = $tick;
            $participation['return_pulses_total'] = max(0, (int)($participation['return_pulses_total'] ?? 0)) + 1;
        } elseif ($source === self::SOURCE_ACTIVE) {
            $participation['last_active_pulse_tick'] = $tick;
            $participation['active_pulses_total'] = max(0, (int)($participation['active_pulses_total'] ?? 0)) + 1;
        }

        return true;
    }

    public static function rewardTier(array $season): int
    {
        return max(1, min(1, (int)($season['participation_pulse_reward_tier'] ?? 1)));
    }

    public static function markMeaningfulEconomyEventInArray(array &$participation, int $tick): void
    {
        $participation['last_meaningful_economy_tick'] = max(0, $tick);
    }

    private static function pulseDecision(array $season, array $player, array $participation, int $tick, string $source, string $presenceState): array
    {
        $tick = max(0, $tick);
        $tier = self::rewardTier($season);
        if ((int)($season['blackout_time'] ?? PHP_INT_MAX) <= $tick) {
            return self::decision(false, 'blackout_blocked', $tier);
        }
        if ($presenceState !== 'Active') {
            return self::decision(false, 'not_active', $tier);
        }
        if (!Economy::canReceiveSigilTier($participation, $tier, 1, 0)) {
            return self::decision(false, 'sigil_capacity', $tier);
        }

        if ($source === self::SOURCE_RETURN) {
            $lastReturn = max(0, (int)($participation['last_return_pulse_tick'] ?? 0));
            if ($lastReturn > 0 && ($tick - $lastReturn) < self::returnPulseCooldownTicks($season)) {
                return self::decision(false, 'return_pulse_cooldown', $tier);
            }

            $inactiveSince = self::inactiveSinceTick($player);
            if ($inactiveSince <= 0 || ($tick - $inactiveSince) < self::returnPulseMinGapTicks($season)) {
                return self::decision(false, 'return_gap_too_short', $tier);
            }

            return self::decision(true, 'eligible', $tier);
        }

        $lastMeaningful = max(0, (int)($participation['last_meaningful_economy_tick'] ?? 0));
        $lastActivePulse = max(0, (int)($participation['last_active_pulse_tick'] ?? 0));
        $referenceTick = max($lastMeaningful, $lastActivePulse);
        if ($referenceTick <= 0) {
            $referenceTick = max(0, (int)($player['last_activity_tick'] ?? 0));
        }
        if (($tick - $referenceTick) < self::activeDrySpellTicks($season)) {
            return self::decision(false, 'active_gap_too_short', $tier);
        }

        return self::decision(true, 'eligible', $tier);
    }

    private static function returnPulseMinGapTicks(array $season): int
    {
        return max(1, (int)($season['return_pulse_min_gap_ticks'] ?? ticks_from_real_seconds(300)));
    }

    private static function returnPulseCooldownTicks(array $season): int
    {
        return max(1, (int)($season['return_pulse_cooldown_ticks'] ?? ticks_from_real_seconds(1800)));
    }

    private static function activeDrySpellTicks(array $season): int
    {
        return max(1, (int)($season['active_dry_spell_ticks'] ?? ticks_from_real_seconds(300)));
    }

    private static function inactiveSinceTick(array $player): int
    {
        $idleSince = isset($player['idle_since_tick']) ? (int)$player['idle_since_tick'] : 0;
        if ($idleSince > 0) {
            return $idleSince;
        }

        return max(0, (int)($player['last_activity_tick'] ?? 0));
    }

    private static function decision(bool $eligible, string $reasonCode, int $rewardTier): array
    {
        return [
            'eligible' => $eligible,
            'reason_code' => $reasonCode,
            'reward_tier' => $rewardTier,
        ];
    }
}
```

- [ ] **Step 2: Verify helper tests pass**

Run:

```powershell
php vendor\bin\phpunit tests\ParticipationPacingTest.php --no-coverage
```

Expected: PASS.

- [ ] **Step 3: Commit helper/config**

Run:

```powershell
git add includes/participation_pacing.php schema.sql migration_20260429_active_return_gameplay_flow.sql includes/game_time.php scripts/simulation/SimulationSeason.php scripts/simulation/CanonicalEconomyConfigContract.php scripts/simulation/EconomicCandidateValidator.php scripts/simulation/SimulationConfigPreflight.php tests/ParticipationPacingTest.php tests/SimulationConfigPreflightTest.php tests/SimulationExportImportTest.php
git commit -m "Add participation pacing config surface"
```

---

### Task 4: Wire Runtime Return And Active Pulses

**Files:**
- Modify: `includes/participation_pacing.php`
- Modify: `includes/actions.php`
- Modify: `includes/auth.php`
- Modify: `includes/tick_engine.php`

- [ ] **Step 1: Add database helpers to `ParticipationPacing`**

Add these methods before the private helper methods:

```php
    public static function markMeaningfulEconomyEvent($db, int $playerId, int $seasonId, int $tick): void
    {
        if ($playerId <= 0 || $seasonId <= 0) {
            return;
        }

        $db->query(
            "UPDATE season_participation
             SET last_meaningful_economy_tick = ?
             WHERE player_id = ? AND season_id = ?",
            [max(0, $tick), $playerId, $seasonId]
        );
    }

    public static function grantReturnPulseForPlayer($db, int $playerId, int $seasonId, int $tick): array
    {
        return self::grantPulseForPlayer($db, $playerId, $seasonId, $tick, self::SOURCE_RETURN);
    }

    public static function grantActivePulseForPlayer($db, int $playerId, int $seasonId, int $tick, string $presenceState): array
    {
        return self::grantPulseForPlayer($db, $playerId, $seasonId, $tick, self::SOURCE_ACTIVE, $presenceState);
    }

    private static function grantPulseForPlayer($db, int $playerId, int $seasonId, int $tick, string $source, string $presenceState = 'Active'): array
    {
        if ($playerId <= 0 || $seasonId <= 0) {
            return self::grantResult(false, 'invalid_context', 0);
        }

        $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);
        $participation = $db->fetch(
            "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
            [$playerId, $seasonId]
        );
        if (!$season || !$player || !$participation) {
            return self::grantResult(false, 'missing_state', 0);
        }

        $decision = ($source === self::SOURCE_RETURN)
            ? self::returnPulseDecision($season, $player, $participation, $tick)
            : self::activePulseDecision($season, $player, $participation, $tick, $presenceState);

        if (!$decision['eligible']) {
            return self::grantResult(false, (string)$decision['reason_code'], (int)$decision['reward_tier']);
        }

        $tier = (int)$decision['reward_tier'];
        $sigilCol = 'sigils_t' . $tier;
        $pulseTickColumn = $source === self::SOURCE_RETURN ? 'last_return_pulse_tick' : 'last_active_pulse_tick';
        $pulseTotalColumn = $source === self::SOURCE_RETURN ? 'return_pulses_total' : 'active_pulses_total';

        $db->query(
            "UPDATE season_participation
             SET {$sigilCol} = {$sigilCol} + 1,
                 sigil_drops_total = sigil_drops_total + 1,
                 last_meaningful_economy_tick = ?,
                 {$pulseTickColumn} = ?,
                 {$pulseTotalColumn} = {$pulseTotalColumn} + 1
             WHERE player_id = ? AND season_id = ?",
            [$tick, $tick, $playerId, $seasonId]
        );

        $db->query(
            "INSERT INTO sigil_drop_log (player_id, season_id, drop_tick, tier, source)
             VALUES (?, ?, ?, ?, ?)",
            [$playerId, $seasonId, $tick, $tier, $source]
        );

        return self::grantResult(true, 'granted', $tier);
    }

    private static function grantResult(bool $granted, string $reasonCode, int $tier): array
    {
        return [
            'granted' => $granted,
            'reason_code' => $reasonCode,
            'tier' => $tier,
        ];
    }
```

- [ ] **Step 2: Require helper in runtime files**

Add:

```php
require_once __DIR__ . '/participation_pacing.php';
```

to:

- `includes/actions.php`
- `includes/auth.php`
- `includes/tick_engine.php`

- [ ] **Step 3: Grant return pulse on season join/rejoin**

In `Actions::seasonJoin()`, after the player update and before `$db->commit()`, add:

```php
            ParticipationPacing::markMeaningfulEconomyEvent($db, $playerId, $seasonId, $gameTime);
            $pulse = ParticipationPacing::grantReturnPulseForPlayer($db, $playerId, $seasonId, $gameTime);
```

Add the pulse result to the returned success payload:

```php
            return [
                'success' => true,
                'message' => 'Joined season successfully',
                'participation_pulse' => $pulse,
            ];
```

- [ ] **Step 4: Grant return pulse on idle acknowledgement**

In `Actions::idleAck()`, after the player update and before `Notifications::create(...)`, add:

```php
        $pulse = ['granted' => false, 'reason_code' => 'not_participating', 'tier' => 0];
        if ($joinedSeasonId > 0) {
            $pulse = ParticipationPacing::grantReturnPulseForPlayer($db, $playerId, $joinedSeasonId, $gameTime);
        }
```

Return it in the payload:

```php
        return [
            'success' => true,
            'message' => 'Welcome back! You are now Active.',
            'participation_pulse' => $pulse,
        ];
```

- [ ] **Step 5: Grant return pulse on login/session restoration from offline**

In `Auth::login()`, before the update, capture:

```php
        $wasOffline = empty($player['online_current']);
        $joinedSeasonId = (int)($player['joined_season_id'] ?? 0);
```

After the update, add:

```php
        $pulse = ['granted' => false, 'reason_code' => 'not_offline_return', 'tier' => 0];
        if ($wasOffline && $joinedSeasonId > 0 && empty($player['idle_modal_active'])) {
            require_once __DIR__ . '/participation_pacing.php';
            $pulse = ParticipationPacing::grantReturnPulseForPlayer($db, (int)$player['player_id'], $joinedSeasonId, GameTime::now());
        }
```

Add `participation_pulse` to the login result.

In `Auth::touchPresence()`, before the update, capture:

```php
        $shouldTryReturnPulse = is_array($player)
            && empty($player['online_current'])
            && empty($player['idle_modal_active'])
            && (int)($player['joined_season_id'] ?? 0) > 0;
```

After the update query, add:

```php
        if ($shouldTryReturnPulse) {
            require_once __DIR__ . '/participation_pacing.php';
            ParticipationPacing::grantReturnPulseForPlayer($db, $playerId, (int)$player['joined_season_id'], GameTime::now());
        }
```

- [ ] **Step 6: Mark meaningful events in action methods**

In every successful transaction after the state change and before commit, call:

```php
ParticipationPacing::markMeaningfulEconomyEvent($db, $playerId, $seasonId, GameTime::now());
```

Apply this to:

- `purchaseStars()`
- `lockIn()`
- `purchaseBoost()`
- `combineSigils()`
- `freezePlayerUbi()`
- `selfMeltFreeze()`
- `attemptSigilTheft()`

For methods already storing `$nowTick` or `$gameTime`, pass that value instead of calling `GameTime::now()` again.

- [ ] **Step 7: Update sigil drop source normalization**

In `TickEngine::awardSigilDrop()`, replace:

```php
        $sourceNormalized = strtoupper((string)$source) === 'PITY' ? 'pity' : 'rng';
```

with:

```php
        $sourceNormalized = strtolower(trim((string)$source));
        if ($sourceNormalized === '') {
            $sourceNormalized = 'rng';
        }
```

Then after the `sigil_drop_tracking` update, add:

```php
        ParticipationPacing::markMeaningfulEconomyEvent($db, (int)$playerId, (int)$seasonId, (int)$dropTick);
```

- [ ] **Step 8: Grant active dry-spell pulse in tick processing**

In `TickEngine::processSeasonTick()`, after `self::processSigilDrops(...)` and before UBI accrual, add:

```php
                if (!$isBlackout && !$isLastValid && !$isExpiration && $presenceState === 'Active') {
                    ParticipationPacing::grantActivePulseForPlayer($db, (int)$playerId, (int)$seasonId, (int)$gameTime, $presenceState);
                }
```

- [ ] **Step 9: Keep fresh-start rejoin from farming pulses**

In `resetSeasonParticipationForFreshStart()`, add these assignments:

```sql
             last_meaningful_economy_tick = ?,
             last_active_pulse_tick = 0,
```

Pass `$gameTime` for the new query parameter. Do not reset `last_return_pulse_tick` or `return_pulses_total`.

- [ ] **Step 10: Run syntax checks**

Run:

```powershell
php -l includes\participation_pacing.php
php -l includes\actions.php
php -l includes\auth.php
php -l includes\tick_engine.php
```

Expected: each command prints `No syntax errors detected`.

---

### Task 5: Add Simulation Pulse Modeling And Tests

**Files:**
- Create: `tests/SimulationParticipationPacingTest.php`
- Modify: `scripts/simulation/SimulationPlayer.php`
- Modify: `scripts/simulation/MetricsCollector.php`

- [ ] **Step 1: Write failing simulation tests**

Create `tests/SimulationParticipationPacingTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/Archetypes.php';
require_once __DIR__ . '/../scripts/simulation/SimulationPlayer.php';
require_once __DIR__ . '/../scripts/simulation/SimulationSeason.php';

class SimulationParticipationPacingTest extends TestCase
{
    private function season(array $overrides = []): array
    {
        return SimulationSeason::build(1, 'simulation-participation-pacing', array_replace([
            'return_pulse_min_gap_ticks' => 5,
            'return_pulse_cooldown_ticks' => 30,
            'active_dry_spell_ticks' => 5,
            'participation_pulse_reward_tier' => 1,
        ], $overrides));
    }

    public function testSimulatorGrantsReturnPulseWhenPlayerBecomesActiveAfterIdleGap(): void
    {
        $player = new SimulationPlayer(1, 'casual', Archetypes::get('casual'), 'return-pulse-sim', 1);
        $season = $this->season();

        $player->setPresenceState('Idle', 10, $season);
        $player->setPresenceState('Active', 16, $season);

        $snapshot = $player->snapshot();
        $this->assertSame(1, (int)$snapshot['participation']['sigils_t1']);
        $this->assertSame(1, (int)$snapshot['participation']['return_pulses_total']);
        $this->assertSame(1, (int)$snapshot['metrics']['participation_pulses_by_source']['return_pulse']);
    }

    public function testSimulatorGrantsActiveDrySpellPulseAfterQuietActiveWindow(): void
    {
        $player = new SimulationPlayer(1, 'casual', Archetypes::get('casual'), 'active-pulse-sim', 1);
        $season = $this->season();
        $player->setPresenceState('Active', 1, $season);

        $player->processParticipationPacing($season, 'EARLY', 6);

        $snapshot = $player->snapshot();
        $this->assertSame(1, (int)$snapshot['participation']['sigils_t1']);
        $this->assertSame(1, (int)$snapshot['participation']['active_pulses_total']);
        $this->assertSame(1, (int)$snapshot['metrics']['participation_pulses_by_source']['active_dry_spell_pulse']);
        $this->assertSame(0, (int)$snapshot['metrics']['active_dry_spell_violations']);
    }

    public function testSimulatorMeaningfulActionResetsDrySpellTimer(): void
    {
        $player = new SimulationPlayer(1, 'casual', Archetypes::get('casual'), 'active-pulse-reset-sim', 1);
        $season = $this->season();
        $this->seedParticipation($player, ['last_meaningful_economy_tick' => 5]);

        $player->processParticipationPacing($season, 'EARLY', 9);

        $snapshot = $player->snapshot();
        $this->assertSame(0, (int)$snapshot['participation']['sigils_t1']);
        $this->assertSame(0, (int)$snapshot['participation']['active_pulses_total']);
    }

    private function seedParticipation(SimulationPlayer $player, array $overrides): void
    {
        $mutator = function () use ($overrides): void {
            $this->participation = array_replace($this->participation, $overrides);
        };
        $bound = \Closure::bind($mutator, $player, SimulationPlayer::class);
        $bound();
    }
}
```

- [ ] **Step 2: Verify simulation tests fail**

Run:

```powershell
php vendor\bin\phpunit tests\SimulationParticipationPacingTest.php --no-coverage
```

Expected: FAIL because `SimulationPlayer::setPresenceState()` does not accept a season argument and `processParticipationPacing()` does not exist.

- [ ] **Step 3: Require helper and initialize participation state**

In `scripts/simulation/SimulationPlayer.php`, add:

```php
require_once __DIR__ . '/../../includes/participation_pacing.php';
```

Add these fields to the constructor participation array:

```php
            'last_meaningful_economy_tick' => 0,
            'last_return_pulse_tick' => 0,
            'last_active_pulse_tick' => 0,
            'return_pulses_total' => 0,
            'active_pulses_total' => 0,
```

Add these metrics:

```php
            'participation_pulses_by_source' => ['return_pulse' => 0, 'active_dry_spell_pulse' => 0],
            'sigils_acquired_by_source' => ['drop' => 0, 'return_pulse' => 0, 'active_dry_spell_pulse' => 0, 'combine' => 0, 'theft' => 0],
            'max_active_dry_spell_ticks' => 0,
            'active_dry_spell_violations' => 0,
```

- [ ] **Step 4: Update `setPresenceState()` and add pulse processing**

Change the signature to:

```php
    public function setPresenceState(string $presenceState, int $tick, ?array $season = null): void
```

Capture previous state at the start:

```php
        $previousPresence = (string)($this->player['economic_presence_state'] ?? 'Active');
```

At the end of `setPresenceState()`, add:

```php
        if ($season !== null && $presenceState === 'Active' && $previousPresence !== 'Active') {
            $this->grantPulse($season, ParticipationPacing::SOURCE_RETURN, $tick);
        }
```

Add this public method:

```php
    public function processParticipationPacing(array $season, string $phase, int $tick): void
    {
        if (!$this->isParticipating() || $this->currentPresence() !== 'Active') {
            return;
        }
        if ((string)$phase === 'BLACKOUT') {
            return;
        }

        $lastMeaningful = max(0, (int)($this->participation['last_meaningful_economy_tick'] ?? 0));
        if ($lastMeaningful > 0) {
            $this->metrics['max_active_dry_spell_ticks'] = max(
                (int)$this->metrics['max_active_dry_spell_ticks'],
                max(0, $tick - $lastMeaningful)
            );
        }

        $decision = ParticipationPacing::activePulseDecision($season, $this->player, $this->participation, $tick, 'Active');
        if ($decision['eligible']) {
            $this->grantPulse($season, ParticipationPacing::SOURCE_ACTIVE, $tick);
        } elseif (($decision['reason_code'] ?? '') === 'active_gap_too_short') {
            return;
        } elseif (($decision['reason_code'] ?? '') !== 'sigil_capacity') {
            $this->metrics['active_dry_spell_violations']++;
        }
    }
```

Add this private method:

```php
    private function grantPulse(array $season, string $source, int $tick): void
    {
        $applied = ParticipationPacing::applyPulseToParticipation($this->participation, $season, $source, $tick);
        if (!$applied) {
            return;
        }

        $tier = ParticipationPacing::rewardTier($season);
        $this->metrics['sigils_acquired_by_tier'][(string)$tier]++;
        $this->metrics['participation_pulses_by_source'][$source]++;
        $this->metrics['sigils_acquired_by_source'][$source]++;
    }
```

- [ ] **Step 5: Mark meaningful simulator events**

After successful sigil drop in `processSigilDrop()`, add:

```php
        ParticipationPacing::markMeaningfulEconomyEventInArray($this->participation, $tick);
        $this->metrics['sigils_acquired_by_source']['drop']++;
```

After successful `purchaseStars()`, `combineSigils()`, `purchaseBoost()`, `freezeTarget()`, `attemptTheft()` when the spend happens, and `lockIn()`, add:

```php
        ParticipationPacing::markMeaningfulEconomyEventInArray($this->participation, $tick);
```

In `combineSigils()` when the produced tier is counted, also add:

```php
        $this->metrics['sigils_acquired_by_source']['combine']++;
```

In `attemptTheft()` when a sigil is transferred successfully, add:

```php
        $this->metrics['sigils_acquired_by_source']['theft']++;
```

- [ ] **Step 6: Call pulse processing from population simulation**

In `scripts/simulation/SimulationPopulationSeason.php`, change:

```php
                $player->setPresenceState($presence, $tick);
```

to:

```php
                $player->setPresenceState($presence, $tick, $season);
```

After `processSigilDrop()` and before `accrue()`, add:

```php
                $player->processParticipationPacing($season, $phase, $tick);
```

- [ ] **Step 7: Aggregate metrics**

In `scripts/simulation/MetricsCollector.php`, add archetype totals for:

```php
            $participationPulsesBySource = ['return_pulse' => 0, 'active_dry_spell_pulse' => 0];
            $sigilsAcquiredBySource = ['drop' => 0, 'return_pulse' => 0, 'active_dry_spell_pulse' => 0, 'combine' => 0, 'theft' => 0];
            $maxActiveDrySpellTicks = 0;
            $activeDrySpellViolations = 0;
```

Inside the player row loop, accumulate:

```php
                foreach ($participationPulsesBySource as $source => $_) {
                    $participationPulsesBySource[$source] += (int)($row['metrics']['participation_pulses_by_source'][$source] ?? 0);
                }
                foreach ($sigilsAcquiredBySource as $source => $_) {
                    $sigilsAcquiredBySource[$source] += (int)($row['metrics']['sigils_acquired_by_source'][$source] ?? 0);
                }
                $maxActiveDrySpellTicks = max($maxActiveDrySpellTicks, (int)($row['metrics']['max_active_dry_spell_ticks'] ?? 0));
                $activeDrySpellViolations += (int)($row['metrics']['active_dry_spell_violations'] ?? 0);
```

Add these keys to each archetype metrics row:

```php
                'participation_pulses_by_source' => $participationPulsesBySource,
                'sigils_acquired_by_source' => $sigilsAcquiredBySource,
                'max_active_dry_spell_ticks' => $maxActiveDrySpellTicks,
                'active_dry_spell_violations' => $activeDrySpellViolations,
```

Add matching overall diagnostics:

```php
            'participation_pulses_by_source' => ['return_pulse' => 0, 'active_dry_spell_pulse' => 0],
            'sigils_acquired_by_source' => ['drop' => 0, 'return_pulse' => 0, 'active_dry_spell_pulse' => 0, 'combine' => 0, 'theft' => 0],
            'max_active_dry_spell_ticks' => 0,
            'active_dry_spell_violations' => 0,
```

- [ ] **Step 8: Verify simulation green**

Run:

```powershell
php vendor\bin\phpunit tests\SimulationParticipationPacingTest.php --no-coverage
```

Expected: PASS.

---

### Task 6: Add Analysis And Diagnosis Flow Metrics

**Files:**
- Modify: `scripts/analyze_baseline.php`
- Modify: `scripts/diagnose_economy.php`
- Test through existing simulation smoke and diagnosis commands.

- [ ] **Step 1: Add baseline analysis section**

In `scripts/analyze_baseline.php`, add a helper:

```php
function analyzeParticipationPacing(array $simBPayloads): array {
    $totals = [
        'return_pulse' => 0,
        'active_dry_spell_pulse' => 0,
    ];
    $sigilSources = [
        'drop' => 0,
        'return_pulse' => 0,
        'active_dry_spell_pulse' => 0,
        'combine' => 0,
        'theft' => 0,
    ];
    $maxGap = 0;
    $violations = 0;

    foreach ($simBPayloads as $entry) {
        $diag = (array)($entry['data']['diagnostics'] ?? []);
        foreach ($totals as $source => $_) {
            $totals[$source] += (int)($diag['participation_pulses_by_source'][$source] ?? 0);
        }
        foreach ($sigilSources as $source => $_) {
            $sigilSources[$source] += (int)($diag['sigils_acquired_by_source'][$source] ?? 0);
        }
        $maxGap = max($maxGap, (int)($diag['max_active_dry_spell_ticks'] ?? 0));
        $violations += (int)($diag['active_dry_spell_violations'] ?? 0);
    }

    return [
        'available' => true,
        'participation_pulses_by_source' => $totals,
        'sigils_acquired_by_source' => $sigilSources,
        'max_active_dry_spell_ticks' => $maxGap,
        'active_dry_spell_violations' => $violations,
    ];
}
```

Add the result to the final report under key `participation_pacing`.

- [ ] **Step 2: Add diagnosis rule**

In `scripts/diagnose_economy.php`, after underused mechanics, add:

```php
echo "Checking: Participation pacing...\n";
$pacing = $report['participation_pacing'] ?? [];
if (!empty($pacing['available'])) {
    $violations = (int)($pacing['active_dry_spell_violations'] ?? 0);
    if ($violations > 0) {
        $findings[] = finding(
            $findingId,
            'HIGH',
            'active_play_dead_zones',
            "Active play produced {$violations} dry-spell pacing violations.",
            [
                'active_dry_spell_violations' => $violations,
                'max_active_dry_spell_ticks' => (int)($pacing['max_active_dry_spell_ticks'] ?? 0),
            ],
            [],
            [],
            'active dry-spell violations > 0',
            0,
            $violations,
            'HIGH'
        );
    }
} else {
    $unsupported[] = [
        'rule' => 'participation_pacing',
        'reason' => 'Participation pacing metrics are not available.',
    ];
}
```

- [ ] **Step 3: Run syntax checks**

Run:

```powershell
php -l scripts\analyze_baseline.php
php -l scripts\diagnose_economy.php
```

Expected: both commands print `No syntax errors detected`.

---

### Task 7: Focused Verification

**Files:**
- All modified source and tests.

- [ ] **Step 1: Run focused PHPUnit suite**

Run:

```powershell
php vendor\bin\phpunit tests\ParticipationPacingTest.php tests\SimulationParticipationPacingTest.php tests\SimulationConfigPreflightTest.php tests\SimulationExportImportTest.php --filter "ParticipationPacing|participation|ReturnPulse|ActiveDrySpell" --no-coverage
```

Expected: PASS.

- [ ] **Step 2: Run syntax checks for touched PHP files**

Run:

```powershell
php -l includes\participation_pacing.php
php -l includes\actions.php
php -l includes\auth.php
php -l includes\tick_engine.php
php -l includes\game_time.php
php -l scripts\simulation\SimulationSeason.php
php -l scripts\simulation\CanonicalEconomyConfigContract.php
php -l scripts\simulation\EconomicCandidateValidator.php
php -l scripts\simulation\SimulationConfigPreflight.php
php -l scripts\simulation\SimulationPlayer.php
php -l scripts\simulation\MetricsCollector.php
php -l scripts\analyze_baseline.php
php -l scripts\diagnose_economy.php
```

Expected: every command prints `No syntax errors detected`.

- [ ] **Step 3: Run diff whitespace check**

Run:

```powershell
git diff --check
```

Expected: no output and exit code 0.

---

### Task 8: Canonical Simulation Validation

**Files:**
- Create generated candidate patch under `simulation_output/current-db/active-return-gameplay-flow-candidate.patch.json`
- Create generated simulation output directories under `simulation_output/current-db/active-return-gameplay-flow-*`

- [ ] **Step 1: Create candidate patch**

Create `simulation_output/current-db/active-return-gameplay-flow-candidate.patch.json` with:

```json
{
  "return_pulse_min_gap_ticks": 60,
  "return_pulse_cooldown_ticks": 360,
  "active_dry_spell_ticks": 60,
  "participation_pulse_reward_tier": 1
}
```

Use these values for public-test cadence evidence where `TMC_TICK_REAL_SECONDS=5`: 60 ticks is 5 real minutes and 360 ticks is 30 real minutes.

- [ ] **Step 2: Run smoke simulation**

Run:

```powershell
php scripts\simulate_economy.php --seed=active-return-flow-smoke --players-per-archetype=10 --season-config=simulation_output\current-db\export\current_season_economy_only.json --candidate-patch=simulation_output\current-db\active-return-gameplay-flow-candidate.patch.json --output=simulation_output\current-db\active-return-gameplay-flow-smoke-20260429
```

Expected: simulation succeeds, audit artifacts exist, and `effective_config.json` reports the four participation pacing keys from `candidate_patch`.

- [ ] **Step 3: Run canonical batch**

Run:

```powershell
php scripts\run_baseline_batch.php --season-config=simulation_output\current-db\export\current_season_economy_only.json --candidate-patch=simulation_output\current-db\active-return-gameplay-flow-candidate.patch.json --output=simulation_output\current-db\active-return-gameplay-flow-batch-20260429
```

Expected: 21 completed, 0 failed, and every run has `effective_config.json` plus `effective_config_audit.md`.

- [ ] **Step 4: Analyze and diagnose**

Run:

```powershell
php scripts\analyze_baseline.php --manifest=simulation_output\current-db\active-return-gameplay-flow-batch-20260429\batch_manifest.json
php scripts\diagnose_economy.php --report=simulation_output\current-db\active-return-gameplay-flow-batch-20260429\baseline_analysis_report.json --output=simulation_output\current-db\active-return-gameplay-flow-diagnosis-20260429
```

Expected:

- no HIGH severity findings
- active dry-spell violations are 0
- return and active pulse counts are present in `baseline_analysis_report.json`
- boost top-quartile coin delta share remains below 40%

---

### Task 9: Final Commit

**Files:**
- Source/test/docs/migration only. Do not stage generated simulation output unless the user explicitly asks for artifacts to be tracked.

- [ ] **Step 1: Inspect status**

Run:

```powershell
git status --short
```

Expected: source, tests, schema, and migration files are modified; generated simulation output is untracked or ignored.

- [ ] **Step 2: Stage source changes**

Run:

```powershell
git add includes/participation_pacing.php includes/actions.php includes/auth.php includes/tick_engine.php includes/game_time.php schema.sql migration_20260429_active_return_gameplay_flow.sql scripts/simulation/SimulationSeason.php scripts/simulation/CanonicalEconomyConfigContract.php scripts/simulation/EconomicCandidateValidator.php scripts/simulation/SimulationConfigPreflight.php scripts/simulation/SimulationPlayer.php scripts/simulation/MetricsCollector.php scripts/analyze_baseline.php scripts/diagnose_economy.php tests/ParticipationPacingTest.php tests/SimulationParticipationPacingTest.php tests/SimulationConfigPreflightTest.php tests/SimulationExportImportTest.php
```

- [ ] **Step 3: Commit implementation**

Run:

```powershell
git commit -m "Add active return gameplay pacing"
```

Expected: commit succeeds on `source/dev`.

- [ ] **Step 4: Report verification evidence**

Include:

- focused PHPUnit result
- syntax check result
- `git diff --check` result
- smoke/batch/diagnosis result, or the exact blocker if a long simulation could not run

---

## Self-Review

- Spec coverage: return pulse is covered by Tasks 1, 3, 4, and 5; active dry-spell pulse is covered by Tasks 1, 3, 4, 5, and 6; canonical config/audit is covered by Tasks 1, 2, and 8; tests and validation are covered by Tasks 7 and 8.
- Banned-token scan: clean. Commands and expected outcomes are explicit.
- Type consistency: the plan uses the same key names throughout: `return_pulse_min_gap_ticks`, `return_pulse_cooldown_ticks`, `active_dry_spell_ticks`, `participation_pulse_reward_tier`, `last_meaningful_economy_tick`, `last_return_pulse_tick`, `last_active_pulse_tick`, `return_pulses_total`, and `active_pulses_total`.
