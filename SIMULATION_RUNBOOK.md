# Simulation Suite Runbook

Five simulators. Run them in order to go from contract verification to a production rebalancing candidate.

The coupling harness suite is an additional early gate that runs between contracts and the broader B/C/D/E promotion ladder.

---

## Quick reference

| Sim | Script | Purpose |
|-----|--------|---------|
| A | `simulate_contracts.php` | Contract/invariant verification |
| B | `simulate_economy.php` | Single-season population simulation |
| C | `simulate_lifetime.php` | Multi-season lifetime population simulation |
| D | `simulate_policy_sweep.php` | Multi-scenario policy sweep runner |
| E | `compare_simulation_results.php` | Result comparator and disposition engine |

All scripts live in `scripts/`. Run from the repo root.  
All output goes to `simulation_output/` by default.

---

## Effective Config Preflight

Simulation B, C, and D now run a mandatory preflight audit before execution begins.

Canonical resolution chain:

- Season scope: `SimulationSeason::build()` defaults -> optional base season JSON/import -> candidate patch -> scenario override
- Runtime scope: code defaults in `includes/config.php` -> environment variables
- Feature activity: evaluated from the final resolved config, not from the requested change list

Mandatory artifacts per attempted run:

- `<run>.audit/effective_config.json`
- `<run>.audit/effective_config_audit.md`

Hard gate behavior:

- any inactive candidate key aborts the run before simulation unless explicitly bypassed
- debug-only bypass: `--allow-inactive-candidate-config` or env `TMC_SIMULATION_AUDIT_BYPASS=1`

Strict candidate validation now runs before the inactive-key audit. Candidate ingestion rejects:

- `candidate_unknown_key`
- `candidate_deprecated_key`
- `candidate_out_of_surface`
- `candidate_disabled_subsystem`
- `candidate_type_mismatch`
- `candidate_out_of_range`

Inactive reason codes:

- `inactive_feature_disabled`
- `inactive_shadowed`
- `inactive_unreferenced`
- `inactive_invalid_path`
- `inactive_out_of_scope`

Notes:

- unknown keys are not silently ignored
- unreferenced knobs now fail fast instead of generating misleading candidate runs
- the audit reflects the actual B/C simulator code paths, including known unreferenced season fields such as `vault_config`

### Candidate lint command

Lint candidate package JSON without running any simulation:

```bash
php scripts/lint_candidate_packages.php --input=simulation_output/current-db/tuning/tuning_candidates_v3.json
```

Optional base season context:

```bash
php scripts/lint_candidate_packages.php --input=simulation_output/current-db/tuning/tuning_candidates_v3.json --season-config=simulation_output/current-db/export/current_season.json
```

---

## Candidate Promotion Ladder

`CandidatePromotionPipeline` is now the canonical patch-ready gate for individual simulation candidates.

Fixed stage order:

1. `candidate_schema_validation`
2. `effective_config_preflight`
3. `targeted_subsystem_harnesses`
4. `full_single_season_validation`
5. `multi_season_exploit_regression_validation`
6. `patch_serialization_validation`
7. `play_test_repo_compatibility_validation`
8. `promotion_eligibility_marking`

Rules:

- stage order is mandatory in normal workflows
- a failed required stage blocks later required stages
- developer-only bypasses are explicit and always keep the candidate ineligible for patch-ready promotion
- promotion eligibility is only marked after stages 1-7 all return `pass`
- stage 7 now validates the shared simulator/play-test schema contract in `CanonicalEconomyConfigContract`
- stage 7 writes both `play_test_repo_compatibility.json` and `play_test_repo_compatibility.md`

Reference:

- [ECONOMY_CONFIG_COMPATIBILITY.md](ECONOMY_CONFIG_COMPATIBILITY.md)

CLI:

```powershell
php scripts/promote_simulation_candidate.php `
  --candidate=tmp/candidate_patch.json `
  --candidate-id=hoarding-safe-v1 `
  --season-config=simulation_output/current-db/export/current_season.json `
  --output=simulation_output/promotion `
  --players-per-archetype=1 `
  --season-count=4
```

Developer-only bypass example:

```powershell
php scripts/promote_simulation_candidate.php `
  --candidate=tmp/candidate_patch.json `
  --debug-bypass-stages=candidate_schema_validation,effective_config_preflight
```

Per-candidate artifacts:

- `simulation_output/promotion/<candidate-id>/promotion_state.json`
- `simulation_output/promotion/<candidate-id>/promotion_report.json`
- `simulation_output/promotion/<candidate-id>/promotion_report.md`

Machine-readable state contract:

- `status`: `eligible`, `ineligible`, or `debug_only`
- `patch_ready`: final boolean patch-ready flag
- `promotion_eligible`: final boolean eligibility flag
- `debug_bypass_used`: whether any stage was bypassed
- `stages[]`: ordered stage records with `status`, `summary`, `warnings`, `artifacts`, and structured `details`

Report semantics:

- `promotion_state.json` is the compact automation-facing record
- `promotion_report.json` is the full machine-readable report
- `promotion_report.md` is the operator-facing summary

### Promotion Patch Generator

After a candidate is marked `promotion_eligible=true`, generate the play-test repo patch bundle directly from the canonical config:

```powershell
php scripts/generate_promotion_patch.php `
  --promotion-report=simulation_output/promotion/hoarding-safe-v1/promotion_report.json `
  --output=simulation_output/promotion-bundles `
  --dry-run
```

Direct canonical-config input is also supported when you already have the verified season JSON:

```powershell
php scripts/generate_promotion_patch.php `
  --canonical-config=simulation_output/promotion/hoarding-safe-v1/07_play_test_repo_compatibility_validation/candidate_effective_season.json `
  --base-season-config=simulation_output/current-db/export/current_season.json `
  --candidate-id=hoarding-safe-v1 `
  --output=simulation_output/promotion-bundles `
  --dry-run
```

Bundle guarantees:

- stages exactly one approved root `migration_*.sql` repo file
- emits a unified dry-run diff in `repo_patch.diff`
- writes `promotion_bundle.json` with exact file metadata and hashes
- validates that the patched play-test config re-imports cleanly and matches the canonical config exactly

### Phase C staged candidate generation

`generate_tuning_candidates.php` now emits staged experiment candidates instead of first-pass bundled packages:

- `stage_1_single_knob`: exactly one knob per candidate
- `stage_2_pairwise`: only knobs validated for promotion in stage 1 may pair
- `stage_3_constrained_bundle`: only knobs proven in eligible stage-2 pairs may bundle
- `stage_4_full_confirmation`: only promoted stage-3 bundles advance to full confirmation

Every package and scenario includes:

- `stage`
- `stage_order`
- `lineage.parent_candidate_ids`
- `lineage.ancestor_candidate_ids`
- `lineage.validated_candidate_ids`

Advancement controls are explicit in `stage_controls`. Knobs flagged as low-signal or unstable remain available for isolated stage-1 learning, but they do not auto-advance into pairwise or bundled candidates.

---

## Simulation A — Contract Simulator

Verifies that economy invariants hold under the canonical season config. Run first.

```
php scripts/simulate_contracts.php [--seed=VALUE] [--output=DIR]
```

**Key arguments**
- `--seed` — run identifier (default: `phase1`)
- `--output` — output directory

**Output**
- `simulation_output/contracts/contract_<seed>.json` — pass/fail for each invariant check

**Example**
```
php scripts/simulate_contracts.php --seed=verify-20260408
```

---

## Coupling Harness Gate

Run this after contracts and before broader economy promotion. It targets the known structural coupling walls with reduced-cost profiles.

```
php scripts/simulate_coupling_harnesses.php [--seed=VALUE] [--season-config=FILE] [--candidate-patch=FILE] [--families=A,B]
```

**Key outputs**
- `simulation_output/coupling-harnesses/<seed>/coupling_harness_report.json`
- `simulation_output/coupling-harnesses/<seed>/coupling_harness_report.md`

**Family ids**
- `lock_in_down_but_expiry_dominance_up`
- `skip_rejoin_exploit_worsened`
- `hoarding_pressure_imbalance`
- `boost_underperformance`
- `star_affordability_pricing_instability`

**Example**
```powershell
php scripts/simulate_coupling_harnesses.php `
  --seed=harness-verify `
  --season-config=simulation_output/current-db/export/current_season.json `
  --candidate-patch=tmp/candidate_patch.json
```

See [SIMULATION_COUPLING_HARNESSES.md](SIMULATION_COUPLING_HARNESSES.md) for thresholds, what each harness proves, and what it cannot prove.

---

## Simulation B — Single-Season Population Simulator

Simulates one season for a full 10-archetype population cohort.

```
php scripts/simulate_economy.php [--seed=VALUE] [--players-per-archetype=N] [--output=DIR] [--season-config=FILE]
```

**Key arguments**
- `--seed` — run identifier
- `--players-per-archetype` — cohort size per archetype (default: 5)
- `--season-config` — JSON file with season config overrides; accepts output of `tools/export-season-config.php`

**Output**
- `simulation_output/season/season_<seed>_ppa<N>.json` — full archetype metrics  
- `simulation_output/season/season_<seed>_ppa<N>.csv` — per-archetype CSV

**Example**
```
php scripts/simulate_economy.php --seed=run1 --players-per-archetype=5
```

**With live config**
```
php tools/export-season-config.php --output=simulation_output/live_season.json
php scripts/simulate_economy.php --seed=live-test --season-config=simulation_output/live_season.json
```

---

## Simulation C — Lifetime Overlapping-Season Simulator

Simulates N overlapping seasons, tracking every player across their full lifetime.

```
$env:TMC_TICK_REAL_SECONDS=3600
php scripts/simulate_lifetime.php [--seed=VALUE] [--players-per-archetype=N] [--seasons=N] [--output=DIR] [--season-config=FILE]
```

**Key arguments**
- `--seed` — run identifier
- `--players-per-archetype` — cohort size (default: 5)
- `--seasons` — number of seasons (default: 12, min 2)
- `--season-config` — JSON file applied to every season in the lifetime run
- `TMC_TICK_REAL_SECONDS=3600` — required env var for realistic speed; each real second counts as one game tick

**Output**
- `simulation_output/lifetime/lifetime_<seed>_s<N>_ppa<N>.json` — full player/archetype/concentration data  
- `simulation_output/lifetime/lifetime_<seed>_s<N>_ppa<N>.csv` — per-player CSV

**Example**
```
$env:TMC_TICK_REAL_SECONDS=3600
php scripts/simulate_lifetime.php --seed=run1 --players-per-archetype=5 --seasons=12
Remove-Item Env:TMC_TICK_REAL_SECONDS
```

**With live config**
```
php tools/export-season-config.php --output=simulation_output/live_season.json
$env:TMC_TICK_REAL_SECONDS=3600
php scripts/simulate_lifetime.php --seed=live-test --season-config=simulation_output/live_season.json
```

---

## Simulation D — Policy Sweep Runner

Runs named policy scenarios against Simulation B and/or C without changing production values.

```
$env:TMC_TICK_REAL_SECONDS=3600
php scripts/simulate_policy_sweep.php [OPTIONS]
```

**Key arguments**
- `--seed` — sweep run identifier
- `--players-per-archetype` — cohort size (default: 5)
- `--seasons` — season count for C runs (default: 12)
- `--simulators` — comma-separated `B,C` (default: both)
- `--scenario=NAME` — add one scenario (repeatable); or `--scenarios=A,B,C`
- `--include-baseline=0|1` — include no-override baseline run (default: 1)
- `--season-config=FILE` — JSON file used as base config for all runs (scenario overrides layer on top)
- `--list-scenarios` — print available scenario names and exit

**Output**
- `simulation_output/sweep/runs/<run>.json` — one file per simulator × scenario combination
- `simulation_output/sweep/policy_sweep_<seed>_ppa<N>_s<N>.json` — sweep manifest (input to Simulation E)

**Example — all scenarios**
```
$env:TMC_TICK_REAL_SECONDS=3600
php scripts/simulate_policy_sweep.php `
  --seed=sweep-20260408 `
  --players-per-archetype=5 `
  --seasons=12 `
  --simulators=B,C `
  --scenarios=mostly-idle-pressure-v1,star-focused-friction-v1,boost-payoff-relief-v1,hoarder-pressure-v1 `
  --include-baseline=1
Remove-Item Env:TMC_TICK_REAL_SECONDS
```

**Example — with live config as base**
```
php tools/export-season-config.php --output=simulation_output/live_season.json
$env:TMC_TICK_REAL_SECONDS=3600
php scripts/simulate_policy_sweep.php --seed=sweep-live --season-config=simulation_output/live_season.json --scenarios=hoarder-pressure-v1
```

---

## Simulation E — Result Comparator

Ingests a sweep manifest (and optional standalone baselines) and produces win/loss comparisons with regression flags and dispositions.

```
php scripts/compare_simulation_results.php --sweep-manifest=FILE [OPTIONS]
```

**Key arguments**
- `--sweep-manifest=FILE` — manifest JSON from Simulation D (required)
- `--seed` — artifact identifier
- `--baseline-b=FILE` — additional standalone Sim B baseline (repeatable)
- `--baseline-c=FILE` — additional standalone Sim C baseline (repeatable)
- `--output` — output directory

**Output**
- `simulation_output/comparator/comparison_<seed>.json` — per-scenario comparison with:
  - `wins`, `losses`, `mixed_tradeoffs`
  - `delta_flags` — numeric deltas on 11 dimensions per simulator
  - `regression_flags` — named failure patterns
  - `recommended_disposition` — `reject` / `mixed / revisit` / `candidate for production tuning`

**Dispositions**
| Disposition | Meaning |
|-------------|---------|
| `candidate for production tuning` | wins ≥ losses+2, no regression flags — eligible for production consideration |
| `mixed / revisit` | no regression flags but not clearly winning |
| `reject` | one or more regression flags present |

**Example**
```
php scripts/compare_simulation_results.php `
  --seed=sweep-20260408 `
  --sweep-manifest=simulation_output/sweep/policy_sweep_sweep-20260408_ppa5_s12.json
```

---

## Full workflow

```
# 1. Verify contracts
php scripts/simulate_contracts.php --seed=pre-run

# 2. Run coupling harnesses against the candidate patch
php scripts/simulate_coupling_harnesses.php `
  --seed=pre-run-harness `
  --season-config=simulation_output/current-db/export/current_season.json `
  --candidate-patch=tmp/candidate_patch.json

# 3. Run sweep (all scenarios, both simulators)
$env:TMC_TICK_REAL_SECONDS=3600
php scripts/simulate_policy_sweep.php `
  --seed=sweep-v1 --players-per-archetype=5 --seasons=12 `
  --simulators=B,C `
  --scenarios=mostly-idle-pressure-v1,star-focused-friction-v1,boost-payoff-relief-v1,hoarder-pressure-v1 `
  --include-baseline=1
Remove-Item Env:TMC_TICK_REAL_SECONDS

# 4. Compare
php scripts/compare_simulation_results.php `
  --seed=sweep-v1 `
  --sweep-manifest=simulation_output/sweep/policy_sweep_sweep-v1_ppa5_s12.json

# 5. Review output
#    simulation_output/comparator/comparison_sweep-v1.json
#    — scenarios with disposition "candidate for production tuning" are eligible for
#      promotion to an actual balance change
```

---

## Agentic Hierarchical Optimization Workflow

Use this when broad monolithic package sweeps are not converging. It runs a staged subsystem-first search:

- Tier 1: cheap local subsystem harness screening
- Tier 1a: explicit coupling-family harness gates for known structural walls
- Tier 2: cross-subsystem integration validation
- Tier 3: full-lifecycle acceptance validation on promoted candidates only

### Direct CLI

```powershell
php scripts/agentic_optimize_economy.php \
  --seed=agentic-current-db \
  --season-config=simulation_output/current-db/export/current_season.json \
  --output=simulation_output/current-db/agentic-optimization
```

### Via existing PowerShell wrapper

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools/Invoke-TmcSimulationStep.ps1 \
  -Step agentic-opt \
  -ConfigPath tools/local/tmc-sim.current.ps1 \
  -Seed agentic-current-db
```

### Key outputs

- `simulation_output/current-db/agentic-optimization/<run-id>/decomposition/economy_decomposition_map.json`
- `simulation_output/current-db/agentic-optimization/<run-id>/audit/rejected_iteration_audit.json`
- `simulation_output/current-db/agentic-optimization/<run-id>/search-memory/run_cache_index.json`
- `simulation_output/current-db/agentic-optimization/<run-id>/reports/final_integration_report.json`
- `simulation_output/current-db/agentic-optimization/<run-id>/best_composed_config.json`

---

## Available scenarios

```
php scripts/simulate_policy_sweep.php --list-scenarios
```

| Scenario | Keys changed |
|----------|-------------|
| `mostly-idle-pressure-v1` | `starprice_idle_weight_fp`, `hoarding_idle_multiplier_fp`, `hoarding_safe_min_coins` |
| `star-focused-friction-v1` | `market_affordability_bias_fp`, `starprice_reactivation_window_ticks`, `starprice_max_upstep_fp` |
| `boost-payoff-relief-v1` | `target_spend_rate_per_tick`, `hoarding_min_factor_fp`, `hoarding_safe_hours` |
| `hoarder-pressure-v1` | `hoarding_tier1/2/3_rate_hourly_fp`, `hoarding_sink_cap_ratio_fp` |

---

## Export/import path

Use `tools/export-season-config.php` to export the currently active live season config to JSON.  
That file can be fed to **any** of B, C, or D via `--season-config=FILE`.

```
# Export (requires live DB connection)
php tools/export-season-config.php --output=simulation_output/live_season.json

# B
php scripts/simulate_economy.php --seed=live-b --season-config=simulation_output/live_season.json

# C
$env:TMC_TICK_REAL_SECONDS=3600
php scripts/simulate_lifetime.php --seed=live-c --season-config=simulation_output/live_season.json

# D (season config becomes base; scenario overrides layer on top)
$env:TMC_TICK_REAL_SECONDS=3600
php scripts/simulate_policy_sweep.php --seed=live-d --season-config=simulation_output/live_season.json --scenarios=hoarder-pressure-v1
```

---

## Determinism

All five simulators are fully deterministic for a given seed. Re-running with the same seed always produces identical economic outputs. Only `generated_at` timestamps change between runs.

`TMC_TICK_REAL_SECONDS` affects wall-clock speed only; it does not affect simulation outcome or determinism.

---

## Artifact locations

| Simulator | Default output |
|-----------|---------------|
| A | `simulation_output/contracts/` |
| B | `simulation_output/season/` |
| C | `simulation_output/lifetime/` |
| D | `simulation_output/sweep/` and `simulation_output/sweep/runs/` |
| E | `simulation_output/comparator/` |

---

## Windows + VS Code + Hostinger VPS workflow

This workflow runs the existing export and simulation scripts from VS Code on Windows against the current Hostinger-backed database through an SSH tunnel.

### Prerequisites

- Windows OpenSSH client available as `ssh.exe`
- PHP CLI available in the VS Code integrated terminal
- SSH key-based access to the current VPS endpoint
- SSH host key already trusted in your `known_hosts` file (strict host key checking is enforced)
- The current database host, port, name, user, and password from Hostinger
- A local secret config file at `tools/local/tmc-sim.current.ps1`

### Local config file

Copy `tools/tmc-sim.config.example.ps1` to `tools/local/tmc-sim.current.ps1` and fill in the real values locally.

Expected variables:

- `TmcSshHost`
- `TmcSshPort`
- `TmcSshUser`
- `TmcSshIdentityFile` (preferred; full private key file path, not just `.ssh` folder)
- `TmcSshKeyPath` (legacy alias still supported)
- `TmcSshConnectTimeoutSeconds` (optional, default `10`)
- `TmcSshKnownHostsPath` (optional explicit `known_hosts` file path)
- `TmcRemoteDbHost`
- `TmcRemoteDbPort`
- `TmcDbName`
- `TmcDbUser`
- `TmcDbPass`
- `TmcLocalForwardPort`

The tunnel script runs SSH in non-interactive batch mode and uses key-only auth. It fails fast instead of prompting for passwords.

### Verify SSH auth before running export

Run this once to confirm non-interactive key auth is working for your configured endpoint:

```powershell
ssh.exe -i C:\Users\YOUR_USER\.ssh\id_ed25519 -p 22 -o BatchMode=yes -o PreferredAuthentications=publickey -o NumberOfPasswordPrompts=0 -o StrictHostKeyChecking=yes root@YOUR_HOST exit
```

Expected result: command exits with code 0 and does not prompt for a password.

The local config file is ignored by Git and should never be committed.

### VS Code tasks

The workspace provides these tasks:

- `Tunnel Current DB`
- `Export Current DB`
- `Sim B Current DB`
- `Sim C Current DB`
- `Sim D Current DB`
- `Sim E Current DB`

`Tunnel Current DB` keeps an SSH port forward open in its own terminal, using `TmcSshIdentityFile` (or legacy alias `TmcSshKeyPath`):

- `127.0.0.1:<LocalForwardPort>` on your machine
- forwarded to `<RemoteDbHost>:<RemoteDbPort>` through `<SshUser>@<SshHost>`

Keep that tunnel terminal running while export and simulation tasks execute.

The tunnel command intentionally uses non-interactive SSH options (`BatchMode=yes`, key-only authentication, zero password prompts). If auth is missing or invalid, it fails immediately.

### Usage flow in VS Code

1. Run `Tasks: Run Task` -> `Tunnel Current DB`.
2. Leave that terminal open after the tunnel connects.
3. Run `Export Current DB` to write `simulation_output/current-db/export/current_season.json`.
4. Run `Sim B Current DB` using the seed and cohort size you want.
5. Run `Sim C Current DB` using the same seed, cohort size, and season count you want.
6. Run `Sim D Current DB` using the same seed, cohort size, and season count, plus the scenario list you want.
7. Run `Sim E Current DB` with the same seed, cohort size, and season count so it can read the sweep manifest from Sim D.

For `Sim C Current DB` and `Sim D Current DB`, the PowerShell wrapper sets `TMC_TICK_REAL_SECONDS=3600` automatically for the current process only.

### Output locations

- Export: `simulation_output/current-db/export/current_season.json`
- Sim B: `simulation_output/current-db/season/`
- Sim C: `simulation_output/current-db/lifetime/`
- Sim D: `simulation_output/current-db/sweep/`
- Sim E: `simulation_output/current-db/comparator/`

Sim E expects the Sim D manifest at:

- `simulation_output/current-db/sweep/policy_sweep_<seed>_ppa<players-per-archetype>_s<seasons>.json`

Keep the `seed`, `players-per-archetype`, and `seasons` inputs aligned between Sim D and Sim E.

### Troubleshooting

- `Config file not found`: create `tools/local/tmc-sim.current.ps1` from the example file first.
- `Missing required config value`: fill in the missing SSH or DB value in the local config file.
- `SSH identity file not found`: verify `TmcSshIdentityFile` (or `TmcSshKeyPath`) points to a readable private key file.
- `SSH preflight authentication check failed ... Permission denied`: the key was rejected. Confirm the private key is correct and the corresponding public key is installed for `TmcSshUser` on `TmcSshHost`.
- `SSH preflight authentication check failed ... strict host key verification`: trust/update the server host key in `known_hosts` (or set `TmcSshKnownHostsPath` to the correct file) and retry.
- `SSH preflight authentication check failed ... connectivity`: verify `TmcSshHost`, `TmcSshPort`, DNS, and firewall/network access.
- `SSH tunnel startup failed ...`: SSH connected but could not establish the tunnel; verify remote DB host/port reachability from the VPS.
- `Database connection failed`: confirm the tunnel is still running and that `LocalForwardPort`, `DbName`, `DbUser`, and `DbPass` match the current database.
- `Expected sweep manifest not found`: run Sim D first, or re-run Sim E with the same seed, player count, and season count used for Sim D.
