# Economy Config Compatibility Contract

Source of truth:

- Schema and mapper: `scripts/simulation/CanonicalEconomyConfigContract.php`
- Candidate validation surface: `scripts/simulation/EconomicCandidateValidator.php`
- Promotion-stage compatibility artifact: `play_test_repo_compatibility.json` and `play_test_repo_compatibility.md`

## Purpose

This contract defines the patchable economy surface that is allowed to move between:

- simulator-side candidate patches and scenario overrides
- play-test-side season config rows and exported JSON

The canonical representation is lossless and typed:

- scalar knobs stay native integers
- structured knobs are canonical arrays in the contract
- play-test storage for structured knobs is JSON text

The mapper must reject:

- missing mappings
- unit mismatches
- incompatible ranges
- unsupported keys
- lossy conversions

## Mapping Rules

| Canonical type | Simulator patch form | Play-test form | Mapping |
|---|---|---|---|
| `int` | integer | integer | identity |
| `bool_int` | integer `0` or `1` | integer `0` or `1` | identity |
| `inflation_table` | array or JSON string | JSON string | decode to canonical array, encode back to JSON |
| `starprice_table` | array or JSON string | JSON string | decode to canonical array, encode back to JSON |
| `starprice_demand_table` | array or JSON string | JSON string | decode to canonical array, encode back to JSON |
| `vault_config` | array or JSON string | JSON string | decode to canonical array, encode back to JSON |

## Patchable Keys

Defaults below are the season defaults from `SimulationSeason::build()` at the repo default cadence.

| Key | Type | Units | Range | Default | Feature flag | Play-test key |
|---|---|---|---|---|---|---|
| `base_ubi_active_per_tick` | `int` | `coins_per_tick` | `0..1000000` | `30` | none | `base_ubi_active_per_tick` |
| `base_ubi_idle_factor_fp` | `int` | `fp_1e6` | `0..1000000` | `250000` | none | `base_ubi_idle_factor_fp` |
| `ubi_min_per_tick` | `int` | `coins_per_tick` | `0..1000000` | `1` | none | `ubi_min_per_tick` |
| `inflation_table` | `inflation_table` | `table[x:coins_supply,factor_fp:fp_1e6]` | monotonic `x`, `0 <= factor_fp <= 1000000` | `inflation_table.default_v1` | none | `inflation_table` |
| `hoarding_window_ticks` | `int` | `ticks` | `1..season_duration_ticks` | `1440` | none | `hoarding_window_ticks` |
| `target_spend_rate_per_tick` | `int` | `coins_per_tick` | `1..1000000` | `50` | none | `target_spend_rate_per_tick` |
| `hoarding_min_factor_fp` | `int` | `fp_1e6` | `0..1000000` | `100000` | none | `hoarding_min_factor_fp` |
| `hoarding_sink_enabled` | `bool_int` | `bool_int` | `0 or 1` | `1` | none | `hoarding_sink_enabled` |
| `hoarding_safe_hours` | `int` | `hours` | `0..2160` | `12` | `season.hoarding_sink_enabled` | `hoarding_safe_hours` |
| `hoarding_safe_min_coins` | `int` | `coins` | `0..1000000000` | `20000` | `season.hoarding_sink_enabled` | `hoarding_safe_min_coins` |
| `hoarding_tier1_excess_cap` | `int` | `coins` | `0..1000000000` | `50000` | `season.hoarding_sink_enabled` | `hoarding_tier1_excess_cap` |
| `hoarding_tier2_excess_cap` | `int` | `coins` | `0..1000000000` | `200000` | `season.hoarding_sink_enabled` | `hoarding_tier2_excess_cap` |
| `hoarding_tier1_rate_hourly_fp` | `int` | `fp_1e6_per_hour` | `0..5000000` | `200` | `season.hoarding_sink_enabled` | `hoarding_tier1_rate_hourly_fp` |
| `hoarding_tier2_rate_hourly_fp` | `int` | `fp_1e6_per_hour` | `0..5000000` | `500` | `season.hoarding_sink_enabled` | `hoarding_tier2_rate_hourly_fp` |
| `hoarding_tier3_rate_hourly_fp` | `int` | `fp_1e6_per_hour` | `0..5000000` | `1000` | `season.hoarding_sink_enabled` | `hoarding_tier3_rate_hourly_fp` |
| `hoarding_sink_cap_ratio_fp` | `int` | `fp_1e6` | `0..1000000` | `350000` | `season.hoarding_sink_enabled` | `hoarding_sink_cap_ratio_fp` |
| `hoarding_idle_multiplier_fp` | `int` | `fp_1e6` | `0..5000000` | `1250000` | `season.hoarding_sink_enabled` | `hoarding_idle_multiplier_fp` |
| `starprice_table` | `starprice_table` | `table[m:effective_price_supply_coins,price:coins_per_star]` | monotonic `m`, `price >= 1` | `starprice_table.default_v1` | none | `starprice_table` |
| `star_price_cap` | `int` | `coins_per_star` | `1..1000000000` | `10000` | none | `star_price_cap` |
| `starprice_idle_weight_fp` | `int` | `fp_1e6` | `0..1000000` | `250000` | none | `starprice_idle_weight_fp` |
| `starprice_active_only` | `bool_int` | `bool_int` | `0 or 1` | `0` | none | `starprice_active_only` |
| `starprice_max_upstep_fp` | `int` | `fp_1e6` | `1..1000000` | `2000` | none | `starprice_max_upstep_fp` |
| `starprice_max_downstep_fp` | `int` | `fp_1e6` | `1..1000000` | `10000` | none | `starprice_max_downstep_fp` |
| `starprice_reactivation_window_ticks` | `int` | `ticks` | `1..season_duration_ticks` | `75` | none | `starprice_reactivation_window_ticks` |
| `starprice_demand_table` | `starprice_demand_table` | `table[ratio_fp:fp_1e6,multiplier_fp:fp_1e6]` | monotonic `ratio_fp`, `0 < multiplier_fp <= 5000000` | `starprice_demand_table.default_v1` | none | `starprice_demand_table` |
| `market_affordability_bias_fp` | `int` | `fp_1e6` | `1..5000000` | `1000000` | none | `market_affordability_bias_fp` |
| `vault_config` | `vault_config` | `table[tier:int,supply:count,cost_table:table[remaining:count,cost:stars]]` | unique tiers, positive supply, non-empty cost tables | `vault_config.default_v1` | none | `vault_config` |

## Structured Defaults

`inflation_table.default_v1`

```json
[
  {"x":0,"factor_fp":1000000},
  {"x":50000,"factor_fp":620000},
  {"x":200000,"factor_fp":280000},
  {"x":800000,"factor_fp":110000},
  {"x":3000000,"factor_fp":50000}
]
```

`starprice_table.default_v1`

```json
[
  {"m":0,"price":100},
  {"m":25000,"price":220},
  {"m":100000,"price":520},
  {"m":500000,"price":1600},
  {"m":2000000,"price":4200}
]
```

`starprice_demand_table.default_v1`

```json
[
  {"ratio_fp":850000,"multiplier_fp":900000},
  {"ratio_fp":1000000,"multiplier_fp":1000000},
  {"ratio_fp":1150000,"multiplier_fp":1080000},
  {"ratio_fp":1300000,"multiplier_fp":1120000}
]
```

`vault_config.default_v1`

```json
[
  {"tier":1,"supply":500,"cost_table":[{"remaining":1,"cost":50}]},
  {"tier":2,"supply":250,"cost_table":[{"remaining":1,"cost":250}]},
  {"tier":3,"supply":125,"cost_table":[{"remaining":1,"cost":1000}]}
]
```

## Compatibility Report

Promotion stage 7 now emits a contract-based compatibility report that includes:

- canonical patch
- mapped play-test patch
- round-trip canonical patch
- issue list with rejection codes
- export/import surface hashes
- sweep manifest path and run count

Passing the report means the patch surface is covered, unit-compatible, range-compatible, and round-trips without semantic drift.

## Promotion Patch Bundles

Promotion-eligible canonical configs can now be converted directly into a review-first play-test repo patch bundle with:

```powershell
php scripts/generate_promotion_patch.php `
  --promotion-report=simulation_output/promotion/<candidate-id>/promotion_report.json `
  --output=simulation_output/promotion-bundles `
  --dry-run
```

Bundle outputs:

- staged repo file additions under `repo_files/`
- `repo_patch.diff` unified diff preview
- `promotion_bundle.json` metadata with touched-file hashes and changed canonical keys
- `patched_play_test_season.json` for post-patch schema/import validation

Safety rules enforced by the generator:

- only approved root `migration_*.sql` files may be staged
- existing repo files are never overwritten
- only canonical config deltas become play-test assignments
- the patched play-test config must round-trip through `SimulationSeason` and match the canonical config exactly
