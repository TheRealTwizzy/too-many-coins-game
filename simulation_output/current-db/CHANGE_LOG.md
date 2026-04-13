# Economy Optimization Change Log

## 2026-04-13 — Convergence Analysis: Structural Constraints Found

### Summary

After 8 prior rejected iterations (verification-v1/v2/v3) and a full multi-scenario
policy sweep campaign, the optimization engine has reached a convergence verdict:
**the three HIGH severity findings (B8, B9, B11) are structurally blocked by
config-only optimization and require game engine code changes.**

### What Was Tried

**Policy Scenarios Added** (12 total in PolicyScenarioCatalog, up from 4):
- `hoarding-sink-minimal-v1` — enable hoarding_sink_enabled=1 with unchanged rates
- `hoarding-sink-conservative-v1` — enable sink + conservative rate increases
- `hoarding-sink-ultra-gentle-v1` — enable sink at half rates (100/250/500 fp/hour)
- `active-ubi-buff-v1` — buff active UBI 30→42
- `combined-sink-ubi-v1` — sink enabled + moderate active UBI buff
- `hoarding-ubi-squeeze-v1` — raise spend target, lower idle UBI factor
- `inflation-tighten-v1` — tighter inflation table at mid-high supply
- `inflation-tighten-plus-ubi-v1` — tighter inflation + active UBI buff

**Agentic Optimizer v5** ran all 10 subsystems (hoarding, blackout, boost,
concentration, sigil, onboarding, star_pricing, lockin_incentives, expiry_pressure,
retention_repeat_season). Only 1 of 10 subsystems produced an accepted candidate.

### Structural Coupling #1: Coin Reduction → Lock-In Regression

**Trigger**: Any change that reduces total coin accumulation in the system
**Effect**: `lock_in_down_but_expiry_dominance_up` regression flag
**Root cause**: Hoarders use accumulated coin reserves to lock in. Reducing coins
(via hoarding sink drain, tighter inflation, or higher spend targets) prevents
lock-in. Naturally expiring becomes comparatively stronger, reversing the
lock-in/expiry balance.

**Affected approaches**:
- Enabling hoarding_sink_enabled=1 (all rate levels tested)
- Tighter inflation_table reducing market UBI
- Raising target_spend_rate_per_tick
- Any hoarding_rate_hourly_fp increases

**Cannot be fixed by config alone.** Fix requires:
> Decouple the lock-in incentive from coin balance — e.g., guarantee minimum
> lock-in conversion even when coin reserves are drained (floor conversion
> regardless of market conditions), OR gate the hoarding sink so it only drains
> coins that were NOT used toward a pending lock-in action.

### Structural Coupling #2: UBI Parameter Changes → Skip-Rejoin Exploit

**Trigger**: Any change that alters the UBI differential between active and idle play
**Effect**: `skip_rejoin_exploit_worsened` regression flag (Sim C multi-season)
**Root cause**: Higher active UBI creates a rational strategy to skip seasons where
a player would be idle. This shows up in Sim C (lifetime simulation) but not Sim B
(single season). The exploit compounds over multiple seasons.

**Affected approaches**:
- `base_ubi_active_per_tick` increases (30→36, 30→42 both failed at ppa=10)
- `base_ubi_idle_factor_fp` decreases (widens active/idle differential)
- Combined sink+UBI scenarios

**Cannot be fixed by config alone.** Fix requires:
> Add a minimum participation threshold per season in game code — e.g., require
> N ticks of active play before receiving that season's UBI. This prevents the
> rational skip-rejoin strategy without changing the UBI amounts themselves.

### What Was Achieved

**One config improvement found by agentic optimizer v5** (only validated at tier1):
- `base_ubi_active_per_tick`: 30 → 34 (+13%) via onboarding_economy subsystem
- Global score delta vs baseline: +1.8524
- Regression flags at tier1: none
- **CAUTION**: Not yet validated at full scale (ppa=10, multi-season Sim C).
  Given that 30→36 failed at ppa=10 across all 3 seeds, 30→34 may also trigger
  skip_rejoin_exploit_worsened at full scale. Recommend targeted verification
  before applying to production.

**Root cause of prior 8 rejections identified**:
All 8 prior tuning packages changed hoarding sink RATES on a DISABLED sink
(hoarding_sink_enabled=0). Rate changes on a disabled sink are no-ops. The actual
regressions were caused by bundled star-pricing changes (market_affordability_bias_fp
and starprice_reactivation_window_ticks) that were included in every package.

### HIGH Severity Findings Status

| Finding | Description | Status | Required Fix |
|---------|-------------|--------|--------------|
| B8 | Hardcore non-viable (28.5% of median) | BLOCKED | Code: minimum participation threshold to prevent skip-rejoin |
| B9 | Boost-Focused non-viable (24.5% of median) | BLOCKED | Code: minimum participation threshold to prevent skip-rejoin |
| B11 | Hoarder dominant (212.3% of regular) | BLOCKED | Code: decouple lock-in from coin balance, OR phase-gated sink |

### MEDIUM Severity Findings Status

B1 (freeze underuse), B2 (theft underuse), B7 (T4+ scarcity), B12 (blackout dead
zone): These are gameplay design issues, not economy balance blockers. Not addressed
by this optimization pass.

B3–B6, B10 (boost ROI, market volatility, retention): Partially structural; the
skip-rejoin and lock-in couplings affect these as well.

---

## Readiness Verdict: CONDITIONAL

**Current economy config is STABLE and safe to run in production** for the existing
player behaviors. No regressions were introduced. The optimization pass confirmed
the baseline is internally consistent.

**BLOCKED on resolving HIGH findings without code changes.** The three HIGH severity
issues (B8, B9, B11) cannot be addressed by seasonal economy parameter tuning alone.

**To unblock:**
1. Implement minimum participation threshold in game engine (fixes skip-rejoin exploit,
   unblocks B8/B9 UBI tuning)
2. Implement phase-gated hoarding sink or lock-in floor guarantee (fixes B11, unblocks
   hoarding sink activation)

**Once code changes land**, re-run this optimization pipeline starting from Task 3
(fast verification sweep) using the scenarios already in PolicyScenarioCatalog:
- `hoarding-sink-minimal-v1` and `hoarding-sink-conservative-v1` for B11
- `active-ubi-buff-v1` and `combined-sink-ubi-v1` for B8/B9

The scenario infrastructure is already built. The next optimization pass should
converge quickly once the structural blocks are removed.

---

## Files Changed This Pass

- `scripts/simulation/PolicyScenarioCatalog.php` — Added 8 new scenarios, added
  `inflation_table` to `boost_related` allowlist
- `simulation_output/current-db/CHANGE_LOG.md` — This file (created)
