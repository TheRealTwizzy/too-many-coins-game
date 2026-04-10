# Plan: Economy Tuning via Simulation Evidence

**Baseline simulation → diagnosis → candidate tuning → verification → controlled test-server promotion.** Five phases (A–E), each independently verifiable, strictly isolated from production.

---

## Goal

Use the lifecycle-runtime-simulator to run baseline simulations against the current play-testing economy, diagnose balance issues, produce candidate tuning packages backed by simulation evidence, verify them, and promote verified changes to the test server under controlled conditions.

## Scope

- Run baseline batch simulations (Sim B + C) against current play-testing economy config
- Systematically detect economy balance problems from simulation outputs
- Generate candidate economy tuning packages (config-driven, not logic rewrites)
- Verify candidates via simulation reruns with side-by-side comparison
- Promote verified changes to test server with rollback capability

## Non-Goals

- Production/live deployment
- Runtime logic rewrites (unless simulation proves config tuning is insufficient)
- New game features or mechanics
- Simulator parity improvements (tracked separately)
- Fresh-run lifecycle changes

---

## Milestones

### Milestone 1 — Baseline Batch (Phase A)
Run reproducible baseline simulations across multiple seeds and cohort sizes against the current play-testing economy.

### Milestone 2 — Diagnosis Report (Phase B)
Produce a structured findings document identifying economy balance issues from baseline data.

### Milestone 3 — Candidate Tuning Packages (Phase C)
Generate 1–3 ranked candidate tuning packages (conservative / balanced / aggressive) with per-change justification.

### Milestone 4 — Verification Runs (Phase D)
Rerun simulations with each candidate package and produce side-by-side comparison reports with pass/fail assessment.

### Milestone 5 — Test Server Promotion (Phase E)
Package the best verified candidate as a migration + config update, deploy to test server with rollback plan.

---

## Detailed Steps

### Phase A — Baseline Batch Simulation

**A1. Export current play-testing season config** *(blocking)*
- Run `export` step via `Invoke-TmcSimulationStep.ps1` with `tmc-sim.current.ps1`
- Output: `simulation_output/current-db/export/current_season.json`
- This captures the live season's inflation_table, starprice_table, vault_config, hoarding params, and all per-season economy columns

**A2. Create baseline batch runner** *(depends A1)*
- New file: `scripts/run_baseline_batch.php`
- SHALL run Sim B (single-season) across seeds: `baseline-econ-s1` through `baseline-econ-s5` (5 seeds)
- SHALL run Sim C (lifetime, 12 seasons) across seeds: `baseline-econ-l1` through `baseline-econ-l3` (3 seeds)
- Cohort sizes: ppa=5, ppa=10, ppa=20 for Sim B; ppa=5, ppa=10 for Sim C
- All runs use `--season-config` pointing to the exported current_season.json
- Total: 15 Sim B runs + 6 Sim C runs = 21 baseline runs
- Output: `simulation_output/current-db/baseline-batch/`

**A3. Contract validation** *(parallel with A2)*
- `php scripts/simulate_contracts.php --seed=baseline-econ-contracts --output=simulation_output/current-db/baseline-batch/`
- MUST pass all 12 gates before proceeding

**A4. Execute baseline batch** *(depends A2+A3)*
- Run all 21 simulations
- Verify all complete without errors
- Spot-check: at least 1 JSON output per simulator has valid schema_version and non-empty archetypes array

**A5. Create baseline analysis aggregator** *(depends A4)*
- New file: `scripts/analyze_baseline.php`
- SHALL read all baseline batch outputs and produce a single `baseline_analysis_report.json` containing:
  - **Lock-in vs expiry**: aggregate lock_in_rate and natural_expiry_rate across all runs/seeds, per archetype and overall
  - **Star accumulation curves**: mean/median seasonal_stars by phase and archetype; global_stars_gained distribution
  - **Sigil tier distribution**: acquisition counts by tier (T1–T6); T6 sources (drop/combine/theft); T6 availability windows
  - **Boost usage and ROI**: boost activations by tier vs. score outcome correlation; boost_focused archetype payoff vs. others
  - **Ranking concentration**: top-10% share of total score; Gini-like spread; per-archetype rank distribution
  - **Archetype outcome spread**: score variance across archetypes; dominant vs. non-viable archetypes (>2σ from mean)
  - **Final standing distribution**: histogram of final_effective_score; locked-in vs. expired score gap
  - **Dominant strategies**: identify archetype+phase combos with outsized returns (>1.5× median)
  - **Resource hoarding vs spending**: coin reserve ratios by archetype; hoarding_sink_total magnitude; spend_window utilization
  - **Phase-by-phase behavior**: action volumes by phase (EARLY/MID/LATE_ACTIVE/BLACKOUT); engagement rate shifts
  - **Cross-seed stability**: coefficient of variation for key metrics across seeds (flags: CV > 0.15 = unstable)

---

### Phase B — Findings and Problem Detection

**B1. Create diagnosis engine** *(depends A5)*
- New file: `scripts/diagnose_economy.php`
- Input: `baseline_analysis_report.json`
- SHALL apply detection rules for each problem class below
- Output: `diagnosis_report.json` with findings array, each containing: `{id, severity, category, metric_evidence, description, affected_archetypes, affected_phases}`

**Detection rules (implemented as threshold checks):**

| Problem Class | Detection Logic | Severity |
|---|---|---|
| Underused mechanics | Any action type with <5% usage rate across all archetypes | MEDIUM |
| Overpowered mechanics | Any single mechanic contributing >40% of top-quartile score delta | HIGH |
| Weak progression pacing | Median score at phase boundary <20% of final median | MEDIUM |
| Concentrated wealth | Top-10% share >35% of total score | HIGH |
| Lock-in timing pathologies | >60% of lock-ins in single phase OR <15% lock-in rate overall | HIGH |
| Excessive expiry | Natural expiry rate >50% across all archetypes | HIGH |
| Insufficient expiry | Natural expiry rate <10% (no pressure to lock in) | MEDIUM |
| Sigil scarcity | Median T4+ acquisition <1.0 per player per season | MEDIUM |
| Sigil overabundance | Median total sigil inventory >20 per player (cap is 25) | MEDIUM |
| Star pricing issues | Star price CV >0.3 across seeds OR price stuck at cap/floor >50% of ticks | MEDIUM |
| Non-viable archetype | Any archetype with median final score <50% of overall median | HIGH |
| Dominant archetype | Any archetype with median final score >200% of overall median | HIGH |
| Bad player experience | mostly_idle or casual archetype with <5% lock-in rate | HIGH |
| Boost ROI imbalance | boost_focused payoff <80% of regular archetype payoff | MEDIUM |
| Hoarding advantage | hoarder final score >150% of regular final score | HIGH |
| Phase dead zones | Any phase with <10% of total actions | MEDIUM |
| Cross-seed instability | Any key metric with CV >0.20 | LOW |

**B2. Generate human-readable diagnosis summary** *(depends B1)*
- Extend `diagnose_economy.php` to also output `diagnosis_summary.md` (Markdown)
- SHALL rank findings by severity (HIGH → MEDIUM → LOW)
- SHALL include the specific metric values that triggered each finding

---

### Phase C — Candidate Tuning Packages

**C1. Create tuning recommendation engine** *(depends B1)*
- New file: `scripts/generate_tuning_candidates.php`
- Input: `diagnosis_report.json`
- Output: `tuning_candidates.json` containing 1–3 ranked packages

**C2. Each candidate package SHALL contain:**
- `package_name`: conservative / balanced / aggressive
- `changes[]`: array of individual changes, each with:
  - `change_id`: unique identifier
  - `finding_id`: links to diagnosis finding
  - `type`: `config_value` | `drop_rate` | `pricing` | `timer` | `cap` | `reward` | `pacing` | `sink_source`
  - `target`: specific config key or season column name
  - `current_value`: baseline value
  - `proposed_value`: new value
  - `reason`: why this change addresses the finding
  - `impacted_mechanic`: which game mechanic is affected
  - `expected_player_effect`: what players should notice
  - `expected_economy_effect`: what changes in simulation metrics
  - `risk_level`: LOW / MEDIUM / HIGH
  - `simulation_test`: how to verify (scenario name + expected metric direction)

**C3. Package ranking rules:**
- **Conservative**: only HIGH-severity findings; smallest value changes; risk_level all LOW
- **Balanced**: HIGH + MEDIUM findings; moderate value changes; risk up to MEDIUM
- **Aggressive**: all findings; larger value changes; may include MEDIUM-HIGH risk

**C4. Register policy scenarios from candidates** *(depends C2)*
- For each package, generate a named policy scenario compatible with `PolicyScenarioCatalog`
- New scenarios registered in `scripts/simulation/PolicyScenarioCatalog.php` with proper category allowlists
- Scenario naming: `tuning-conservative-v1`, `tuning-balanced-v1`, `tuning-aggressive-v1`

**C5. Escalation gate** *(depends C2)*
- If diagnosis finds issues that CANNOT be addressed by config/data tuning alone, the engine SHALL:
  - Flag them as `requires_logic_change`
  - Document the specific runtime code path involved
  - Separate them into a `parity_bug_fixes` or `logic_changes` section
  - These are tracked but NOT included in tuning packages

---

### Phase D — Verification Runs

**D1. Run policy sweep with candidate scenarios** *(depends C4)*
- Use existing `simulate_policy_sweep.php`:
  ```
  php scripts/simulate_policy_sweep.php \
    --seed=tuning-verify-v1 \
    --scenarios=tuning-conservative-v1,tuning-balanced-v1,tuning-aggressive-v1 \
    --include-baseline=1 \
    --simulators=B,C \
    --players-per-archetype=10 \
    --seasons=12
  ```
- Run across 3 seeds for statistical confidence: `tuning-verify-v1`, `tuning-verify-v2`, `tuning-verify-v3`

**D2. Run comparison** *(depends D1)*
- Use existing `compare_simulation_results.php` for each seed's sweep manifest
- Output: disposition per scenario per seed

**D3. Create verification summary report** *(new, depends D2)*
- New file: `scripts/summarize_verification.php`
- Input: all 3 comparison outputs
- Output: `verification_summary.json` + `verification_summary.md`
- SHALL contain:
  - Per-package: wins, losses, mixed, regression flags — aggregated across seeds
  - Side-by-side metric deltas for each finding that was targeted
  - Per-finding: did the proposed change improve the target metric? (pass/fail)
  - Overall disposition per package: VERIFIED / PARTIAL / REJECTED
  - Cross-package comparison: did any package improve one area while damaging another?

**D4. Pass/fail thresholds:**
- **VERIFIED**: disposition = "candidate for production tuning" on ≥2/3 seeds AND zero regression flags across all seeds
- **PARTIAL**: disposition = "mixed / revisit" on any seed OR 1 regression flag that doesn't affect a HIGH-severity finding
- **REJECTED**: disposition = "reject" on any seed OR regression flag affecting a HIGH-severity finding

**D5. Approval gate** *(blocking before Phase E)*
- Verification summary MUST be reviewed by operator before proceeding
- The plan SHALL output a clear recommendation but SHALL NOT auto-promote

---

### Phase E — Test Server Promotion

**E1. Package verified changes** *(depends D5 approval)*
- Generate a dated SQL migration file: `migration_YYYYMMDD_sim_tuning_{package_name}.sql`
- Migration SHALL update only the `seasons` table columns and/or add new seed data
- Migration SHALL be idempotent (UPDATE with WHERE clauses, not blind INSERT)
- If `config.php` constants need changing, generate a diff showing exact constant changes

**E2. Version tag the economy config**
- Create a lightweight tag: `economy-tuning-v{N}-{package_name}`
- Tag message SHALL reference the `verification_summary.json`

**E3. Pre-deploy backup**
- Export current season config via `tools/export-season-config.php` to `simulation_output/pre-deploy-backup/`
- Alternatively:
  ```sql
  SELECT * FROM seasons WHERE id = (SELECT MAX(id) FROM seasons)
  ```

**E4. Deploy to test server only**
- Push migration file to `source/dev` branch
- Push to test repo (`TheRealTwizzy/too-many-coins-game`)
- Auto-migration picks it up on next Dokploy redeploy of `too-many-coins-test`
- MUST NOT push to live repo

**E5. Post-deploy verification**
- Run `export` step against test server DB to confirm new values applied
- Run a quick Sim B (single seed, ppa=5) against the new export to confirm simulation still matches expectations
- Monitor test server for 1+ play-test sessions
- Collect player feedback on affected mechanics

**E6. Rollback plan**
- If issues found: create `migration_YYYYMMDD_revert_sim_tuning_{package_name}.sql`
- Revert migration restores previous column values (captured in E3 backup)
- Push revert migration to test repo
- Auto-migration applies on next redeploy

---

## Relevant Files

### Economy Config (read + potential modification)
- `includes/config.php` — All economy constants (sigil drop rates, phase weights, boost floors, theft caps, pacing params)
- `includes/boost_catalog.php` — Per-tier boost definitions (duration, power, extension, stack limits, vault discounts)
- `includes/economy.php` — Core economy engine (UBI calc, star pricing, hoarding sink, sigil power, rate breakdown)
- `schema.sql` — Season table columns define per-season tunable economy (inflation_table, starprice_table, vault_config, hoarding params)
- `seed_data.sql` — Cosmetic pricing tiers

### Simulator (read + extend)
- `scripts/simulation/PolicyScenarioCatalog.php` — Register new tuning scenarios; existing: mostly-idle-pressure-v1, star-focused-friction-v1, boost-payoff-relief-v1, hoarder-pressure-v1
- `scripts/simulation/ResultComparator.php` — 9 delta dimensions, 5+ regression flags; may need additional dimensions for new tuning targets
- `scripts/simulation/MetricsCollector.php` — Season/lifetime JSON+CSV output; extend if new metrics needed
- `scripts/simulation/Archetypes.php` — 10 archetypes with phase behaviors (read-only for this phase)
- `scripts/simulation/SimulationSeason.php` — Season config builder with override support
- `scripts/simulation/PolicySweepRunner.php` — Orchestrates sweep runs; already supports baseline inclusion
- `scripts/simulate_policy_sweep.php` — CLI entry for Sim D
- `scripts/compare_simulation_results.php` — CLI entry for Sim E
- `scripts/simulate_economy.php` — CLI entry for Sim B
- `scripts/simulate_lifetime.php` — CLI entry for Sim C

### New Files (to be created)
- `scripts/run_baseline_batch.php` — Batch orchestrator for multiple seeds/cohort sizes
- `scripts/analyze_baseline.php` — Aggregation across baseline runs
- `scripts/diagnose_economy.php` — Threshold-based problem detection
- `scripts/generate_tuning_candidates.php` — Candidate tuning package generator
- `scripts/summarize_verification.php` — Cross-seed verification aggregator
- `migration_YYYYMMDD_sim_tuning_*.sql` — Generated migration for test server

### Deployment (read-only reference)
- `tools/Invoke-TmcSimulationStep.ps1` — PowerShell orchestration CLI
- `tools/local/tmc-sim.current.ps1` — Current DB connection config
- `docker-compose.yml` — Local dev environment
- `DEPLOY_DOKPLOY_HOSTINGER.md` — Deployment instructions
- `includes/database.php` — Auto-migration logic (applyPendingMigrations)

---

## Verification

1. **Phase A gate**: All 21 baseline runs produce valid JSON with schema_version `tmc-sim-phase1.v1`; contract validation passes; `baseline_analysis_report.json` has all 11 metric categories populated
2. **Phase B gate**: `diagnosis_report.json` has ≥1 finding; all findings have valid severity, category, and metric_evidence; `diagnosis_summary.md` is human-readable and matches JSON
3. **Phase C gate**: `tuning_candidates.json` has 1–3 packages; every change links to a finding_id that exists in diagnosis; all proposed scenarios register in PolicyScenarioCatalog without error; `php scripts/simulate_contracts.php` still passes with candidate overrides active
4. **Phase D gate**: All verification sweep runs complete; comparison outputs have valid dispositions; verification_summary aggregates correctly across seeds; at least one package achieves VERIFIED or PARTIAL status
5. **Phase E gate**: Migration SQL is syntactically valid; test server export after deploy matches proposed values; quick Sim B against new config produces expected metric direction changes
6. **Rollback gate**: Revert migration SQL restores pre-deploy values when applied

---

## Risks / Safeguards

| Risk | Mitigation |
|---|---|
| Simulation doesn't model all runtime mechanics (parity gaps) | ParityLedger tracks unmodeled mechanics; diagnosis SHALL flag low-confidence findings where parity gaps overlap |
| Candidate tuning improves one metric but damages another | ResultComparator regression flags catch this; verification requires ≥2/3 seeds pass; cross-package comparison required |
| Test server economy diverges from simulation predictions | Post-deploy verification includes re-export + re-simulate; play-test monitoring covers behavioral gaps |
| Accidental production deployment | AGENTS.md rules enforced: only push to test repo; migration naming convention includes `sim_tuning`; no live repo changes in this phase |
| Over-tuning from limited seed coverage | 3–5 seeds per phase; cross-seed CV check flags unstable metrics |
| Auto-migration applies before review | Operator manually pushes migration file to repo; migration only applied on next Dokploy redeploy |

---

## Outputs / Deliverables

1. `simulation_output/current-db/baseline-batch/` — All baseline run artifacts (JSON + CSV)
2. `simulation_output/current-db/baseline-batch/baseline_analysis_report.json` — Aggregated baseline metrics
3. `simulation_output/current-db/diagnosis/diagnosis_report.json` — Structured findings
4. `simulation_output/current-db/diagnosis/diagnosis_summary.md` — Human-readable findings
5. `simulation_output/current-db/tuning/tuning_candidates.json` — Ranked candidate packages
6. `simulation_output/current-db/verification/verification_summary.json` — Cross-seed verification
7. `simulation_output/current-db/verification/verification_summary.md` — Human-readable verification
8. New policy scenarios in `PolicyScenarioCatalog.php`
9. Migration SQL file for test server (if verified candidate exists)
10. Economy version tag

---

## Open Questions

1. **Cohort size for Sim C lifetime runs**: ppa=10 across 12 seasons is 120 players × 12 seasons = significant compute. SHOULD we cap at ppa=5 for lifetime or is ppa=10 acceptable? **Recommendation**: Start with ppa=5, escalate to ppa=10 only if cross-seed variance is high.

2. **Number of seeds**: 5 seeds for Sim B and 3 for Sim C is a balance of coverage vs. compute. Are these sufficient? **Recommendation**: Yes for initial pass; add seeds only if CV check flags instability.

3. **Existing 4 scenarios**: The existing scenarios (mostly-idle-pressure-v1, star-focused-friction-v1, boost-payoff-relief-v1, hoarder-pressure-v1) partially overlap with tuning concerns. SHOULD we include them in the baseline sweep for comparison? **Recommendation**: Yes — run them alongside baseline to see if any existing scenario already addresses a diagnosed problem, avoiding redundant work.

4. **ResultComparator dimensions**: Current 9 dimensions may not cover all Phase B detection categories (e.g., star pricing pacing, phase dead zones). SHOULD we extend the comparator? **Recommendation**: Extend only if a diagnosed finding cannot be verified with existing dimensions.

5. **Approval mechanism**: How should the Phase D→E approval gate work? Git PR review? Manual confirmation? **Recommendation**: Codex outputs the verification summary; operator reviews and explicitly approves before E1 begins.

---

## Final Recommendation

Execute phases sequentially: A → B → C → D → E. Phases A and B are safe read-only analysis. Phase C produces candidates but changes no runtime code. Phase D is simulation-only verification. Phase E is the only phase that touches the test server, gated behind explicit approval.

---

## Recommended Milestone Breakdown

- **Milestone 1 (Phase A)**: ~2 commits (batch runner + analysis aggregator)
- **Milestone 2 (Phase B)**: ~1 commit (diagnosis engine)
- **Milestone 3 (Phase C)**: ~1–2 commits (tuning generator + scenario registration)
- **Milestone 4 (Phase D)**: ~1 commit (verification summary)
- **Milestone 5 (Phase E)**: ~1 commit (migration + tag)

## Files/Scripts to Inspect First

1. `includes/config.php` — all economy constants
2. `scripts/simulation/PolicyScenarioCatalog.php` — existing scenarios & category allowlists
3. `scripts/simulation/ResultComparator.php` — comparison dimensions
4. `scripts/simulation/MetricsCollector.php` — current output metrics
5. `simulation_output/current-db/` — any existing baseline outputs

## Suggested Execution Order

1. A1 (export) → A3 (contracts) → A2 (batch runner script) → A4 (run batch) → A5 (analysis aggregator)
2. B1 (diagnosis engine) → B2 (summary)
3. C1–C5 (tuning candidates + scenarios)
4. D1–D4 (verification sweep + comparison + summary)
5. D5 (approval gate) → E1–E6 (promotion)

## First Action Codex Should Take Now

Run the `export` step to capture the current play-testing season config, then create `scripts/run_baseline_batch.php` to orchestrate the 21 baseline simulation runs.

---

## Change Categories (kept separate per constraint)

- **economy_tuning_changes**: All Phase C candidate packages (config values, drop rates, pricing, timers, caps, rewards)
- **parity_bug_fixes**: Any Phase C escalations flagged as `requires_logic_change` due to simulation parity gaps
- **simulation_reporting_improvements**: New scripts (batch runner, analysis aggregator, diagnosis engine, verification summary) and any MetricsCollector/ResultComparator extensions
