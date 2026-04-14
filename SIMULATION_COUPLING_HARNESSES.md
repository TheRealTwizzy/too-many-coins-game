# Simulation Coupling Harnesses

These harnesses exist to catch the known structural economy walls cheaply, before we spend time on full B/C/D/E verification.

Every harness uses the canonical simulation preflight path:

- candidate patches still pass through the effective-config resolver
- unknown, inactive, or disabled keys still fail before execution
- every attempted run still emits `effective_config.json` and `effective_config_audit.md`

## Promotion ladder placement

Use the ladder in this order:

1. `simulate_contracts.php`
2. `simulate_coupling_harnesses.php`
3. `simulate_economy.php`
4. `simulate_lifetime.php`
5. `simulate_policy_sweep.php`
6. `compare_simulation_results.php`

The agentic optimizer also runs the relevant coupling harness families inside tier 1 before any candidate is allowed to promote to tier 2.

## Run commands

Baseline-only smoke:

```powershell
php scripts/simulate_coupling_harnesses.php `
  --seed=harness-smoke `
  --season-config=simulation_output/current-db/export/current_season.json
```

Candidate patch gate:

```powershell
php scripts/simulate_coupling_harnesses.php `
  --seed=harness-candidate `
  --season-config=simulation_output/current-db/export/current_season.json `
  --candidate-patch=tmp/candidate_patch.json
```

Single-family focus:

```powershell
php scripts/simulate_coupling_harnesses.php `
  --seed=harness-star-only `
  --season-config=simulation_output/current-db/export/current_season.json `
  --candidate-patch=tmp/candidate_patch.json `
  --families=star_affordability_pricing_instability
```

PowerShell wrapper:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools/Invoke-TmcSimulationStep.ps1 `
  -Step sim-harness `
  -ConfigPath tools/local/tmc-sim.current.ps1 `
  -Seed current-db `
  -CandidatePatchPath tmp/candidate_patch.json
```

## Output

Each run writes:

- `simulation_output/coupling-harnesses/<seed>/coupling_harness_report.json`
- `simulation_output/coupling-harnesses/<seed>/coupling_harness_report.md`
- tier-1 simulation artifacts under `simulation_output/coupling-harnesses/<seed>/tier1/runs/`

Each family report includes:

- pass/fail status
- threshold definitions
- baseline vs candidate metrics
- directional diagnostics
- blocking regression flags
- the profile cost estimate relative to tier-3 full validation
- links to the underlying reduced B/C artifacts and preflight audits

## Families

### `lock_in_down_but_expiry_dominance_up`

Profile:

- `coupling_lockin_expiry`
- reduced B+C slice
- much cheaper than tier 3 because it uses short seasons, tiny cohorts, and one seed

Pass thresholds:

- `lock_in_total` must not fall
- `expiry_rate_mean` must not rise
- `lock_in_timing_entropy` must not fall
- blocking flag: `lock_in_down_but_expiry_dominance_up`

Proves:

- the candidate did not immediately recreate the known lock-in/expiry wall in the focused expiry harness

Cannot prove:

- long-run retention quality
- full-economy global balance

### `skip_rejoin_exploit_worsened`

Profile:

- `coupling_skip_rejoin`
- reduced C-only repeat-season harness

Pass thresholds:

- `skip_strategy_edge` must not rise
- `repeat_season_viability` must not fall
- `throughput_lock_in_rate` must not fall
- blocking flags: `skip_rejoin_exploit_worsened`, `long_run_concentration_worsened`

Proves:

- repeat participation did not become less attractive in the reduced retention slice

Cannot prove:

- final full-lifecycle concentration outcome by itself

### `hoarding_pressure_imbalance`

Profile:

- `coupling_hoarding`
- reduced B+C anti-safe-strategy slice

Pass thresholds:

- `hoarder_advantage_gap` must not widen
- `dominant_strategy_pressure` must not rise
- `strategic_diversity` must not fall
- blocking flags: `dominant_archetype_shifted`, `archetype_viability_regressed`

Proves:

- the candidate did not obviously strengthen safe hoarding or collapse strategy variety in the local harness

Cannot prove:

- downstream pricing and retention side effects after full composition

### `boost_underperformance`

Profile:

- `coupling_boost`
- reduced B+C boost-focused slice

Pass thresholds:

- `boost_roi` must not fall
- `boost_mid_late_share` must not fall
- `boost_focused_gap` must not widen
- blocking flag: `archetype_viability_regressed`

Proves:

- boost-focused play did not become weaker in the reduced boost harness

Cannot prove:

- boost changes are globally safe after every other subsystem also changes

### `star_affordability_pricing_instability`

Profile:

- `coupling_star_pricing`
- reduced B-only star-market slice

Pass thresholds:

- `star_purchase_density` must not fall
- `first_choice_viability` must not fall
- `star_price_cap_share` must not rise
- `star_price_range_ratio` must not rise
- blocking flags: `dominant_archetype_shifted`, `lock_in_down_but_expiry_dominance_up`

Proves:

- early star access and short-horizon price behavior did not get worse in the focused pricing harness

Cannot prove:

- production star pricing under the full horizon, full cohort, and all subsystems interacting together

## Why these are cheap

All five families reuse tier-1 profiles:

- smaller cohorts than full validation
- shorter season slices
- fewer simulated seasons
- fewer seeds
- focused archetype subsets when possible

That makes them materially faster than the tier-3 full gate while still hitting the exact known failure surfaces we care about first.
