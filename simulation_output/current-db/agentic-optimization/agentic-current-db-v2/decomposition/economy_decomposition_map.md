# Economy Decomposition Map

Schema: tmc-agentic-decomposition.v1
Generated: 2026-04-12T07:42:50+00:00

## Subsystems

### Hoarding pressure and anti-safe-strategy controls (`hoarding_pressure`)

- Priority: 1
- Local profile: `tier1_hoarding`
- Adjacent systems: blackout_lockin, concentration_control, star_pricing
- Promotion gates: Tier1 score >= 0.25, Tier2 global delta >= -0.15, Tier3 global delta >= 0
- Owned parameters:
  - `hoarding_tier2_rate_hourly_fp` (multiply)
  - `hoarding_tier3_rate_hourly_fp` (multiply)
  - `hoarding_idle_multiplier_fp` (multiply)
  - `hoarding_sink_cap_ratio_fp` (multiply)
- Local metrics:
  - `hoarder_advantage_gap` direction=down weight=2
  - `dominant_strategy_pressure` direction=down weight=1.5
  - `strategic_diversity` direction=up weight=1

### Blackout PvP pressure and lock-in timing logic (`blackout_lockin`)

- Priority: 2
- Local profile: `tier1_blackout`
- Adjacent systems: hoarding_pressure, lockin_incentives, expiry_pressure
- Promotion gates: Tier1 score >= 0.2, Tier2 global delta >= -0.1, Tier3 global delta >= 0
- Owned parameters:
  - `starprice_reactivation_window_ticks` (multiply)
  - `hoarding_window_ticks` (multiply)
  - `market_affordability_bias_fp` (multiply)
- Local metrics:
  - `blackout_action_density` direction=up weight=1.8
  - `lock_in_timing_entropy` direction=up weight=1.2
  - `expiry_rate_mean` direction=down weight=1.3

### Boost viability and deployment timing (`boost_viability`)

- Priority: 3
- Local profile: `tier1_boost`
- Adjacent systems: onboarding_economy, star_pricing, hoarding_pressure
- Promotion gates: Tier1 score >= 0.18, Tier2 global delta >= -0.08, Tier3 global delta >= 0
- Owned parameters:
  - `target_spend_rate_per_tick` (multiply)
  - `hoarding_min_factor_fp` (multiply)
  - `base_ubi_active_per_tick` (multiply)
- Local metrics:
  - `boost_roi` direction=up weight=1.7
  - `boost_mid_late_share` direction=up weight=1.1
  - `boost_focused_gap` direction=down weight=1

### Concentration and runaway leader control (`concentration_control`)

- Priority: 4
- Local profile: `tier1_concentration`
- Adjacent systems: hoarding_pressure, blackout_lockin, retention_repeat_season
- Promotion gates: Tier1 score >= 0.22, Tier2 global delta >= -0.08, Tier3 global delta >= 0
- Owned parameters:
  - `hoarding_sink_cap_ratio_fp` (multiply)
  - `hoarding_tier3_rate_hourly_fp` (multiply)
  - `market_affordability_bias_fp` (multiply)
- Local metrics:
  - `concentration_top10_share` direction=down weight=2
  - `concentration_top1_share` direction=down weight=1.5
  - `archetype_viability_min_ratio` direction=up weight=1.2

### Sigil acquisition/combine/theft/melt/denial loop (`sigil_loop`)

- Priority: 5
- Local profile: `tier1_sigil`
- Adjacent systems: blackout_lockin, boost_viability, concentration_control
- Promotion gates: Tier1 score >= 0.16, Tier2 global delta >= -0.08, Tier3 global delta >= 0
- Owned parameters:
  - `vault_config` (vault_supply_cost)
  - `hoarding_window_ticks` (multiply)
- Local metrics:
  - `sigil_counterplay_density` direction=up weight=1.7
  - `t6_theft_share` direction=up weight=1.1
  - `dead_mechanic_penalty` direction=down weight=1.3

### Onboarding and early acquisition economy (`onboarding_economy`)

- Priority: 6
- Local profile: `tier1_onboarding`
- Adjacent systems: star_pricing, boost_viability, lockin_incentives
- Promotion gates: Tier1 score >= 0.16, Tier2 global delta >= -0.12, Tier3 global delta >= 0
- Owned parameters:
  - `base_ubi_active_per_tick` (multiply)
  - `base_ubi_idle_factor_fp` (multiply)
  - `market_affordability_bias_fp` (multiply)
- Local metrics:
  - `onboarding_liquidity` direction=up weight=1.8
  - `first_choice_viability` direction=up weight=1.2
  - `archetype_viability_min_ratio` direction=up weight=1

### Star pricing and purchasing economy (`star_pricing`)

- Priority: 7
- Local profile: `tier1_star_pricing`
- Adjacent systems: onboarding_economy, lockin_incentives, blackout_lockin
- Promotion gates: Tier1 score >= 0.16, Tier2 global delta >= -0.1, Tier3 global delta >= 0
- Owned parameters:
  - `starprice_idle_weight_fp` (multiply)
  - `starprice_max_upstep_fp` (multiply)
  - `starprice_max_downstep_fp` (multiply)
  - `market_affordability_bias_fp` (multiply)
- Local metrics:
  - `star_purchase_density` direction=up weight=1.6
  - `lock_in_total` direction=up weight=1
  - `expiry_rate_mean` direction=down weight=1.1

### Lock-in incentives and timing (`lockin_incentives`)

- Priority: 8
- Local profile: `tier1_lockin`
- Adjacent systems: blackout_lockin, expiry_pressure, star_pricing
- Promotion gates: Tier1 score >= 0.2, Tier2 global delta >= -0.08, Tier3 global delta >= 0
- Owned parameters:
  - `starprice_reactivation_window_ticks` (multiply)
  - `market_affordability_bias_fp` (multiply)
  - `target_spend_rate_per_tick` (multiply)
- Local metrics:
  - `lock_in_total` direction=up weight=1.8
  - `lock_in_timing_entropy` direction=up weight=1.2
  - `expiry_rate_mean` direction=down weight=1.3

### Expiry pressure and punishment/reward gradients (`expiry_pressure`)

- Priority: 9
- Local profile: `tier1_expiry`
- Adjacent systems: lockin_incentives, retention_repeat_season, hoarding_pressure
- Promotion gates: Tier1 score >= 0.18, Tier2 global delta >= -0.08, Tier3 global delta >= 0
- Owned parameters:
  - `starprice_max_downstep_fp` (multiply)
  - `target_spend_rate_per_tick` (multiply)
  - `hoarding_min_factor_fp` (multiply)
- Local metrics:
  - `expiry_rate_mean` direction=down weight=1.8
  - `lock_in_total` direction=up weight=1.2
  - `repeat_season_viability` direction=up weight=1

### Repeated-season carryover/retention/adaptation (`retention_repeat_season`)

- Priority: 10
- Local profile: `tier1_retention`
- Adjacent systems: concentration_control, expiry_pressure, onboarding_economy
- Promotion gates: Tier1 score >= 0.18, Tier2 global delta >= -0.08, Tier3 global delta >= 0
- Owned parameters:
  - `hoarding_min_factor_fp` (multiply)
  - `starprice_reactivation_window_ticks` (multiply)
  - `base_ubi_active_per_tick` (multiply)
- Local metrics:
  - `repeat_season_viability` direction=up weight=1.7
  - `skip_strategy_edge` direction=down weight=1.6
  - `concentration_top10_share` direction=down weight=1.2

## Profiles

- `tier1_hoarding` (tier1): Cheap local screening for anti-safe-strategy pressure.
- `tier1_blackout` (tier1): Blackout and lock-in pressure micro-harness.
- `tier1_boost` (tier1): Boost-focused local screening with reduced cohorts.
- `tier1_concentration` (tier1): Short lifecycle concentration stress harness.
- `tier1_sigil` (tier1): Sigil acquisition/combine/theft local harness.
- `tier1_onboarding` (tier1): Onboarding-only phase-limited harness.
- `tier1_star_pricing` (tier1): Star market and purchasing local harness.
- `tier1_expiry` (tier1): Expiry pressure and lock-in gradient harness.
- `tier1_lockin` (tier1): Lock-in incentive and timing harness.
- `tier1_retention` (tier1): Repeated-season retention proxy harness.
- `tier2_integration` (tier2): Cross-subsystem integration validation.
- `tier3_full` (tier3): Full lifecycle acceptance gate.
