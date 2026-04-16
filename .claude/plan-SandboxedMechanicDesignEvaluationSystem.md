# Sandboxed Mechanic Design and Evaluation System — Master Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan phase-by-phase. Steps use checkbox (`- [ ]`) syntax for tracking. Each phase has its own sub-agent assignment and exit gate — do NOT advance past an exit gate without explicit user confirmation.

**Goal:** Evolve the Too-Many-Coins Simulation Suite from a parameter-tuning optimizer into a framework-driven sandbox system that can design, simulate, evaluate, and prepare mechanics for approval-driven promotion — without ever touching the live economy.

**Architecture:** A sandboxed evaluation layer wraps existing simulation infrastructure (Sims B/C, 10-stage promotion pipeline stages 1-5) through strict isolation boundaries. Mechanics are composed from an approved framework registry. A 7-dimension evaluation model scores all candidates. User approval with an explicit token is the only path to promotion.

**Tech Stack:** PHP (existing codebase), JSON Schema (framework/mechanic/approval definitions), PHPUnit (existing test framework), existing `AgenticOptimizationUtils` helpers, existing `CanonicalEconomyConfigContract` as the patchable surface authority.

---

## 1. Executive Summary

### Overall Strategy

The Simulation Suite has reached diagnostic stability: it can simulate the economy, validate candidates, and detect structural imbalances. The next evolution is not more aggressive parameter search — it is **mechanic design capability**. The system needs to safely explore mechanics that do not yet exist in the economy, evaluate them rigorously, and surface them for approval without any risk of contaminating the live game.

The upgrade is structured as a **layered build**. The sandbox isolation boundary is built and proven first. Everything else depends on it. No mechanic evaluation, no framework registry, no approval workflow can proceed until the isolation contract is proven by a test suite.

The upgrade works **around** the existing open blockers (SV1, SV2, OQ1, OQ2, RP1) rather than depending on their resolution. The sandbox does not use the agentic optimizer, does not depend on `market_affordability_bias_fp` being wired, and does not require any candidate to pass official qualification. The recovery plan and the sandbox upgrade can proceed in parallel after Phase 0 confirms that the simulation engine itself is stable.

### Key Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| Sandbox output bleeds into live promotion pipeline | CRITICAL | Layer 1-5 isolation enforcement + `SandboxIsolationContractTest` as Phase 0 exit gate |
| Framework defined with inactive/unreferenced knob | HIGH | `FrameworkValidator` cross-references `CanonicalEconomyConfigContract::patchableParameters()` |
| Mechanic approved and promoted without user consent | CRITICAL | `ApprovalPromotionAgent` hard-requires explicit token file |
| Existing blockers SV1/SV2 corrupt sandbox evaluation | MEDIUM | Sandbox uses its own 7-dimension model, not the agentic optimizer; SV2 causes a graceful stage-2 fail |
| Scope drift across multiple sessions | HIGH | Phased execution with explicit exit gates; each phase produces a signed session manifest |
| Mechanic composition produces dominant meta-strategy | HIGH | `BalanceScorer` cap at 0.2 if any archetype exceeds 200% of median |

### Key Design Principles

1. **Isolation before function** — the sandbox boundary is proven to hold before any mechanic evaluation is attempted
2. **Framework-first** — no mechanic can be composed without an approved framework; no ad-hoc invention
3. **Equal-weight balance/pace** — `balance` and `competitive_pace` each carry 2.0× weight in the aggregate score
4. **Approval is a tripwire, not a checkbox** — promotion requires a physical token file; no token = hard exception, not a prompt
5. **Additive, not replacing** — all new machinery wraps existing simulation infrastructure; nothing in `CandidatePromotionPipeline`, `SimulationPopulationSeason`, or `includes/economy.php` is modified
6. **Blocker-agnostic design** — the sandbox system is independently useful even if recovery blockers remain open

---

## 2. System Readiness Assessment

### What Is Ready

- **Simulation engine** (`SimulationPopulationSeason`, `SimulationPopulationLifetime`): Stable, deterministic, seeded. No crashes in normal operation.
- **10-stage promotion pipeline** (`CandidatePromotionPipeline`): Fully operational for stages 1-6; stages 7-10 require live system context.
- **Canonical config contract** (`CanonicalEconomyConfigContract`): Complete patchable parameter schema with no-op protection.
- **Archetype system** (`Archetypes.php`): 10 archetypes with well-defined behavior profiles.
- **Mechanic integration contract** (`docs/SIMULATION_MECHANIC_INTEGRATION_CONTRACT.md`): Formalized — defines the 8-step process for integrating new mechanics into runtime.
- **Simulation runbook** (`SIMULATION_RUNBOOK.md`): Complete, covers all 5 simulators and the full promotion ladder.
- **Play-test parity certification** (Stage 8): Defined across 7 domains.
- **Utility infrastructure** (`AgenticOptimizationUtils`): `writeJson`, `ensureDir`, `jsonHash`, `percentile`, `median`, `entropyNormalized` all available for reuse.

### What Is Not Ready (Open Blockers)

| Blocker | Status | Impact on Sandbox Upgrade |
|---------|--------|--------------------------|
| **SV1**: Agentic optimizer crashes before completing (tier3_full = 34.5M iterations) | OPEN | **No impact** — sandbox uses its own evaluation model |
| **SV2**: `market_affordability_bias_fp` not wired into `Economy::calculateStarPrice()` | OPEN | **Graceful degradation** — sandbox mechanics using this knob will fail at stage 2 preflight with `inactive_unreferenced`; stage 2 result will be reported accurately |
| **OQ1**: 3 subsystems have zero owned_parameters | OPEN | **No impact** — sandbox doesn't use subsystem decomposition |
| **OQ2**: No candidate passes official qualification | OPEN | **No impact** — sandbox stops at stage 5; stages 6-10 are explicitly blocked in sandbox |
| **RP1**: No `final_integration_report.json` (same root as SV1) | OPEN | **No impact** — sandbox produces its own `session_report.json` |

### Mandatory Prerequisites Before Phase 0

Before any phase of the sandbox upgrade begins, a one-time **simulation engine health check** must be run:

```bash
php artisan test --testsuite=SimulationContractSmoke
php artisan test --testsuite=RuntimeParityCertification
```

Both must pass. If either fails, the engine has regressed and the sandbox system cannot be built on top of it. The recovery plan must be addressed first.

**No prerequisite dependency on recovery blockers SV1, SV2, OQ1, OQ2, RP1.** Those blockers affect the optimizer, not the simulation engine itself.

---

## 3. Phase-by-Phase Implementation Plan

---

### Phase 0 — Readiness Audit and Sandbox Foundation

**Objective:** Confirm the simulation engine is healthy, confirm the isolation boundary can be enforced, and prove that the sandbox produces zero artifacts outside its own directory.

**Sub-agents:** Baseline Protection Agent (read-only), Sandbox Boundary Agent

**Exact Tasks:**

- [ ] Run `SimulationContractSmoke` and `RuntimeParityCertification` test suites. Record results. If either fails, STOP — file an issue and do not proceed.
- [ ] Run Sim B (single-season) against `simulation_output/current-db/export/current_season_economy_only.json` using qualification profile. Confirm it completes without timeout.
- [ ] Run Sim C (lifetime) using qualification profile. Confirm it completes without timeout.
- [ ] Create `scripts/sandbox/SandboxRegistry.php` — session lifecycle: `startSession(id, options): manifest`, `endSession(id): void`, `getSession(id): manifest`. Session states: `created → running → complete → approved`.
- [ ] Create `scripts/sandbox/SandboxBoundaryEnforcer.php` — static checks: `assertSandboxOutputDir(path, sessionId)`, `assertNoCandidateLiveCoupling(candidatePatch)`, `assertSandboxStageAllowed(stageIndex)`.
- [ ] Create `scripts/sandbox/SandboxConfigResolver.php` — wraps `SimulationConfigPreflight::resolve()`, hard-codes `artifact_dir` to session sandbox directory, strips unknown knobs, asserts `debug_allow_inactive_candidate = false`.
- [ ] Create `scripts/sandbox/SandboxArtifactWriter.php` — only permitted file writer in sandbox sessions. Enforces path prefix constraint via `realpath()`. Throws `SandboxBoundaryViolationException` on traversal attempt.
- [ ] Create `scripts/sandbox/SandboxSessionManifest.php` — session metadata, provenance, timestamps, baseline snapshot hash.
- [ ] Create `tests/SandboxBoundaryEnforcerTest.php` — tests: stage 6 throws violation, path outside sandbox throws violation, live candidate coupling throws violation.
- [ ] Create `tests/SandboxIsolationContractTest.php` — runs a minimal sandbox session (start + stop, no mechanic), asserts every path in the session manifest has prefix `simulation_output/sandbox/sessions/<id>/`, asserts no files written to `simulation_output/season/`, `simulation_output/lifetime/`, or `simulation_output/promotion/`.
- [ ] Run both test suites. All must pass.

**Deliverables:**
- `scripts/sandbox/SandboxRegistry.php`
- `scripts/sandbox/SandboxBoundaryEnforcer.php`
- `scripts/sandbox/SandboxConfigResolver.php`
- `scripts/sandbox/SandboxArtifactWriter.php`
- `scripts/sandbox/SandboxSessionManifest.php`
- `tests/SandboxBoundaryEnforcerTest.php` (passing)
- `tests/SandboxIsolationContractTest.php` (passing)

**Dependencies:** None (this is the root phase)

**Risks:**
- Sim B or Sim C timeouts would indicate engine instability requiring recovery plan work first
- Path enforcement via `realpath()` may behave differently on Windows dev vs VPS Linux — test on both

**Success Criteria:**
- All 5 required test suites pass from the recovery plan's Task 1
- `SandboxIsolationContractTest` passes (zero artifacts written outside sandbox directory)
- `SandboxBoundaryEnforcerTest` passes (stage 6 blocks, path traversal blocks)

**Exit Gate:** `SandboxIsolationContractTest` MUST pass. No advancement until this test exists and passes. Commit: `feat: add sandbox isolation boundary (Phase 0)`.

---

### Phase 1 — Baseline Freeze

**Objective:** Lock a known-good simulation baseline that all mechanic evaluations will be compared against. Establish regression floors.

**Sub-agents:** Baseline Protection Agent

**Exact Tasks:**

- [ ] Create `scripts/agents/BaselineProtectionAgent.php` with:
  - `snapshot(options): array` — runs Sim B + Sim C against official season config, records archetype metrics, computes SHA-256 of season surface, stores to `baseline_snapshot.json`
  - `verify(sessionManifest): bool` — recomputes hash, compares
  - `loadFrozen(sessionDir): array` — read-only load of snapshot
  - Class constant `SCHEMA_VERSION = 'tmc-sandbox-baseline.v1'`
- [ ] Baseline snapshot format must capture: `season_surface_hash`, per-archetype `global_stars_earned_mean`, `final_score_mean`, `action_volume_by_phase`, coupling harness results (5 families), concentration metrics (top-10 share, HHI).
- [ ] Create `tests/BaselineProtectionAgentTest.php` with:
  - `testBaselineHashIsDeterministic()` — call `snapshot()` twice with same inputs, assert hashes match
  - `testVerifyFailsOnMutatedBaseline()` — mutate one score in snapshot, assert `verify()` returns false
  - `testLoadFrozenIsReadOnly()` — assert `loadFrozen()` result has no filesystem writes
- [ ] Run snapshot against the official baseline: `simulation_output/current-db/export/current_season_economy_only.json`
- [ ] Record the known imbalance (hoarder at ~212% of median) as a reference data point in the baseline snapshot under `known_issues`.
- [ ] Run tests. All must pass.
- [ ] Commit: `feat: add baseline protection agent (Phase 1)`.

**Deliverables:**
- `scripts/agents/BaselineProtectionAgent.php`
- `simulation_output/sandbox/baseline/baseline_snapshot.json` (one-time output, committed)
- `tests/BaselineProtectionAgentTest.php` (passing)

**Dependencies:** Phase 0 (sandbox output directory must exist, `SandboxArtifactWriter` must be available)

**Risks:**
- Baseline snapshot captures a known-imbalanced economy — this is intentional; it establishes the floor
- If Sim B runs slowly, use tier1_smoke profile for baseline to maintain reasonable session times

**Success Criteria:**
- `BaselineProtectionAgentTest` passes
- Baseline snapshot file exists and contains all required fields
- SHA-256 hash is reproducible (deterministic seed confirmed)

**Exit Gate:** `BaselineProtectionAgentTest` passes. Baseline snapshot committed. Commit hash recorded in session manifest template.

---

### Phase 2 — Sandbox Boundary Formalization

**Objective:** Add the DB write tripwire and static state reset mechanisms that complete the 5-layer isolation strategy. Proves sandbox cannot touch live state even through side channels.

**Sub-agents:** Sandbox Boundary Agent

**Exact Tasks:**

- [ ] Add `SandboxBoundaryEnforcer::installQueryInterceptor()` — wraps PDO to detect any `INSERT`/`UPDATE`/`DELETE` query during a sandbox session and throw `SandboxBoundaryViolationException`. This is a tripwire, not a filter.
- [ ] Add `SandboxRegistry::startSession()` static state reset calls:
  - `PolicyScenarioCatalog::resetExtra()` — requires adding `resetExtra(): void` to existing `PolicyScenarioCatalog` (adds static state reset, zero behavior change otherwise)
  - Any other classes with mutable static state discovered by `grep -r 'static \$' scripts/simulation/`
- [ ] Add isolation layer test: `SandboxIsolationContractTest::testNoDatabaseWritesDuringSession()` — installs query interceptor, runs a sandbox session, confirms no DML queries fired.
- [ ] Add isolation layer test: `SandboxIsolationContractTest::testStaticStateResetBetweenSessions()` — run two sessions back-to-back, assert that state from session 1 doesn't leak into session 2 via mutable statics.
- [ ] Run all isolation tests. All must pass.
- [ ] Commit: `feat: complete 5-layer sandbox isolation (Phase 2)`.

**Deliverables:**
- Modified `scripts/sandbox/SandboxBoundaryEnforcer.php` (query interceptor added)
- Modified `scripts/sandbox/SandboxRegistry.php` (static state resets in `startSession`)
- Modified `scripts/simulation/CandidatePromotionPipeline.php` or `PolicyScenarioCatalog.php` only to add `resetExtra()` — no behavior changes
- Extended `tests/SandboxIsolationContractTest.php` (two new tests)

**Dependencies:** Phase 0

**Risks:**
- `PolicyScenarioCatalog::resetExtra()` modification must be additive-only — verify no existing tests break
- On Windows dev environment, PDO interceptor behavior may differ from VPS — run integration test on VPS

**Success Criteria:**
- Full `SandboxIsolationContractTest` suite (all 4 tests) passes
- No existing tests in `tests/` break after adding `resetExtra()`

**Exit Gate:** Full isolation contract test suite passes. No test regressions.

---

### Phase 3 — Framework Registry

**Objective:** Define the framework schema, build the validator, seed the first 6 built-in frameworks, and build the registry agent.

**Sub-agents:** Framework Registry Agent

**Exact Tasks:**

- [ ] Create `frameworks/schemas/framework.schema.json` — full JSON Schema (see Appendix A). Required fields: `framework_id`, `schema_version`, `label`, `ability_family` (enum: Offensive/Defensive/Utility), `mechanic_domain` (enum: 8 values), `slots` array, `simulation_knobs`, `balance_surface`, `evaluation_targets`, `integration_requirements`.
- [ ] Create `frameworks/schemas/mechanic.schema.json` — schema for composed mechanic definitions.
- [ ] Create `frameworks/schemas/approval_package.schema.json` — schema for approval packages.
- [ ] Create `scripts/mechanic/FrameworkValidator.php` — validates a framework definition against `framework.schema.json`. Cross-references every `simulation_knobs` value against `CanonicalEconomyConfigContract::patchableParameters()`. Throws `FrameworkRegistryException` with reason `unknown_runtime_path` on mismatch.
- [ ] Create `scripts/mechanic/FrameworkRegistry.php` — loads all `.json` from `frameworks/definitions/`, validates each, indexes by `framework_id`, `ability_family`, and `mechanic_domain`.
- [ ] Create `scripts/agents/FrameworkRegistryAgent.php` — `load(options)`, `register(frameworkDef, options)`, `get(frameworkId)`, `listByFamily(family)`, `validate(frameworkDef)`.
- [ ] Create 6 seed framework definitions:
  - `frameworks/definitions/offensive/sigil_theft_amplifier.json`
  - `frameworks/definitions/offensive/freeze_chain_escalator.json`
  - `frameworks/definitions/defensive/hoarding_shield_decay.json`
  - `frameworks/definitions/defensive/lock_in_early_bonus.json`
  - `frameworks/definitions/utility/coin_surge_window.json`
  - `frameworks/definitions/utility/ubi_participation_boost.json`
  - Each must have `integration_requirements.sandbox_eligible: true` and `requires_runtime_code_change: false` to be usable without the full integration contract process.
  - Each must declare only knobs present in `CanonicalEconomyConfigContract::patchableParameters()`.
- [ ] Create `scripts/mechanic/AbilityFamilyRegistry.php` — defines the three families and their permitted mechanic domains.
- [ ] Create `tests/FrameworkValidatorTest.php`:
  - `testAllBuiltInFrameworksPassSchema()` — loads every framework JSON and asserts validation passes
  - `testUnknownRuntimePathIsRejected()` — registers a framework with `simulation_knobs: {"slot": "nonexistent_knob"}`, asserts `FrameworkRegistryException`
  - `testSandboxIneligibleFrameworkIsRejected()` — registers a framework with `sandbox_eligible: false`, asserts it's not returned from `listByFamily()`
- [ ] Create `tests/FrameworkRegistryTest.php`:
  - `testRegistryLoadsAllBuiltInFrameworks()` — asserts count matches expected number of seed files
  - `testGetReturnsCorrectFramework()` — retrieves a known framework by ID and asserts fields
- [ ] Run tests. All must pass.
- [ ] Commit: `feat: add framework registry with 6 seed frameworks (Phase 3)`.

**Deliverables:**
- `frameworks/schemas/framework.schema.json`
- `frameworks/schemas/mechanic.schema.json`
- `frameworks/schemas/approval_package.schema.json`
- `frameworks/definitions/offensive/*.json` (2 files)
- `frameworks/definitions/defensive/*.json` (2 files)
- `frameworks/definitions/utility/*.json` (2 files)
- `scripts/mechanic/FrameworkRegistry.php`
- `scripts/mechanic/FrameworkValidator.php`
- `scripts/mechanic/AbilityFamilyRegistry.php`
- `scripts/agents/FrameworkRegistryAgent.php`
- `tests/FrameworkValidatorTest.php` (passing)
- `tests/FrameworkRegistryTest.php` (passing)

**Dependencies:** Phase 0 (needs `CanonicalEconomyConfigContract` confirmed readable), Phase 2 (sandbox session must be established before framework registration)

**Risks:**
- Seed frameworks must map to knobs that actually exist in the patchable schema — verify each against `CanonicalEconomyConfigContract::candidateSearchParameters()` before writing
- `sandbox_eligible: false` frameworks should still be definable (they just go through the full mechanic integration contract instead) — don't reject them on load, just filter from sandbox lists

**Success Criteria:**
- All 6 seed frameworks pass schema validation
- `FrameworkValidatorTest` and `FrameworkRegistryTest` pass
- Unknown runtime path is rejected with correct exception

**Exit Gate:** All framework tests pass. 6 seed frameworks committed and validated. No existing tests break.

---

### Phase 4 — Mechanic Composition System

**Objective:** Build the ability to compose a mechanic from a framework, bind slots, resolve simulation knobs, and produce a validated candidate patch.

**Sub-agents:** Mechanic Composition Agent

**Exact Tasks:**

- [ ] Create `scripts/mechanic/MechanicComposer.php` — `compose(frameworkId, slotBindings, paramOverrides, options): array`. 5-step pipeline:
  1. **Framework resolution** — load from `FrameworkRegistryAgent`, assert `sandbox_eligible: true` and `requires_runtime_code_change: false`. If either fails, emit `mechanic_integration_request.json` (not a `mechanic_definition.json`) and halt.
  2. **Slot binding validation** — required slots must have values; `magnitude_fp` in [500000, 2000000]; `probability` in [0.0, 1.0]; `duration_ticks` ≤ `season_duration_ticks`.
  3. **Simulation knob resolution** — map slot bindings to canonical season keys via `simulation_knobs`. Apply `mode: multiply/add/set` per framework declaration.
  4. **Collision detection** — if any resolved knob key already exists in another mechanic in the same session, enqueue a warning for `InteractionSystemAgent`.
  5. **Mechanic definition assembly** — produce `mechanic_definition.json` with deterministic `mechanic_id` (SHA-256 of `framework_id + slot_bindings`).
- [ ] Create `scripts/mechanic/MechanicValidator.php` — validates composed mechanic: no live-coupling keys, all knobs in patchable schema, no cross-session state references.
- [ ] Create `scripts/mechanic/MechanicFidelityBridge.php` — converts mechanic's resolved `candidate_patch` into the exact format expected by `SimulationConfigPreflight::resolve()`.
- [ ] Create `scripts/agents/MechanicCompositionAgent.php` — `compose(frameworkId, slotBindings, paramOverrides, options)`, `resolveSimulationKnobs(mechanicDef)`, `mechanicId(mechanicDef)`.
- [ ] Create `tests/MechanicComposerTest.php`:
  - `testComposedPatchPassesEconomicCandidateValidator()` — compose mechanic from built-in framework, pass resolved patch to `EconomicCandidateValidator::validate()`, assert no failures
  - `testMissingRequiredSlotThrows()` — compose without required slot binding, assert `MechanicCompositionException`
  - `testMagnitudeOutOfRangeThrows()` — magnitude_fp = 3000000, assert exception
  - `testSandboxIneligibleFrameworkProducesIntegrationRequest()` — use a framework with `sandbox_eligible: false`, assert output is `mechanic_integration_request.json` not `mechanic_definition.json`
- [ ] Create `tests/MechanicFidelityBridgeTest.php`:
  - `testAllResolvedKnotsInPatchableSchema()` — for every built-in framework, compose mechanic, assert every resolved patch key is in `CanonicalEconomyConfigContract::patchableParameters()`
- [ ] Run tests. All must pass.
- [ ] Commit: `feat: add mechanic composition system (Phase 4)`.

**Deliverables:**
- `scripts/mechanic/MechanicComposer.php`
- `scripts/mechanic/MechanicValidator.php`
- `scripts/mechanic/MechanicFidelityBridge.php`
- `scripts/agents/MechanicCompositionAgent.php`
- `tests/MechanicComposerTest.php` (passing)
- `tests/MechanicFidelityBridgeTest.php` (passing)

**Dependencies:** Phase 3 (framework registry must exist and have seed frameworks)

**Risks:**
- The FP (fixed-point) scale 1,000,000 must be applied consistently in `applyBinding()` for `multiply` mode — unit test with known values
- `mechanic_id` must be deterministic across PHP versions — use `hash('sha256', json_encode(..., JSON_SORT_KEYS))` to ensure key ordering

**Success Criteria:**
- Composed patch from any seed framework passes `EconomicCandidateValidator`
- All composed knobs present in `CanonicalEconomyConfigContract::patchableParameters()`
- All mechanic composer tests pass

**Exit Gate:** All composition tests pass. At least one mechanic composed from each seed framework successfully. Commit.

---

### Phase 5 — Fidelity Pipeline

**Objective:** Run sandbox-safe simulation (stages 1-5 of the promotion pipeline) against composed mechanics. Hard-block stages 6-10.

**Sub-agents:** Fidelity Pipeline Agent

**Exact Tasks:**

- [ ] Create `scripts/agents/FidelityPipelineAgent.php`:
  - Class constants: `SANDBOX_STAGES = [1, 2, 3, 4, 5]`, `BLOCKED_STAGES = [6, 7, 8, 9, 10]`
  - `run(mechanicDef, sessionManifest, options): array` — orchestrates stages 1-5 for a composed mechanic
  - `runStage(stageIndex, context): array` — dispatches to existing pipeline stage implementations
  - `assertSandboxStage(stageIndex)` — throws `SandboxBoundaryViolationException` if stage not in `SANDBOX_STAGES`
  - Does NOT subclass `CandidatePromotionPipeline` — imports same stage files but wraps each call with `SandboxBoundaryAgent::assertIsolated()` and `SandboxArtifactWriter`
- [ ] Create `scripts/sandbox/SandboxArtifactWriter.php` (if not already finalized in Phase 0) — all sandbox output routing.
- [ ] Stage mapping:
  - Stage 1 = `EconomicCandidateValidator::validate()` on mechanic's `resolved_candidate_patch`
  - Stage 2 = `SandboxConfigResolver::resolve()` (sandbox-wrapped preflight)
  - Stage 3 = `AgenticCouplingHarnessCatalog` targeted harnesses for mechanic's affected subsystems (use tier1_smoke profile in sandbox)
  - Stage 4 = `SimulationPopulationSeason::run()` with sandbox session config and artifact dir
  - Stage 5 = `SimulationPopulationLifetime::run()` with sandbox session config and artifact dir
- [ ] All stage output written to `simulation_output/sandbox/sessions/<id>/mechanics/<mechanic-id>/` via `SandboxArtifactWriter`.
- [ ] Create `tests/FidelityPipelineAgentTest.php`:
  - `testStages1Through5CompleteWithValidOutput()` — run pipeline for a valid mechanic, assert each stage returns `status: 'pass'` and produces artifact files in sandbox dir
  - `testStage6ThrowsSandboxViolation()` — attempt to call stage 6 via `FidelityPipelineAgent`, assert `SandboxBoundaryViolationException`
  - `testNoArtifactInLivePromotionDir()` — after running stages 1-5, assert `simulation_output/promotion/` contains no new dirs from this session
  - `testSV2GracefulFail()` — compose a mechanic using `market_affordability_bias_fp` as a knob. If SV2 is still open (knob is `inactive_unreferenced`), assert stage 2 returns `status: 'fail'` with reason `inactive_unreferenced` — not an exception or crash.
- [ ] Run tests. All must pass.
- [ ] Commit: `feat: add sandbox fidelity pipeline (Phase 5)`.

**Deliverables:**
- `scripts/agents/FidelityPipelineAgent.php`
- `tests/FidelityPipelineAgentTest.php` (passing)

**Dependencies:** Phase 0 (isolation boundary), Phase 4 (mechanic definitions must exist), Phase 3 (framework registry for subsystem targeting in stage 3)

**Risks:**
- Stage 3 harness targeting requires knowing which subsystems a mechanic affects — derive this from the framework's `mechanic_domain` field mapped to `AgenticEconomyDecomposition::subsystems()`
- Stages 4-5 (Sim B/C) can be slow on full profiles — use tier1_smoke in sandbox for speed; full profile only on approved mechanics before promotion

**Success Criteria:**
- Valid mechanic passes stages 1-5 in sandbox
- Stage 6 hard-blocked
- No artifacts outside sandbox directory
- Graceful handling of SV2 inactive knob (fail with reason, not crash)

**Exit Gate:** `FidelityPipelineAgentTest` passes including stage-6 block and SV2 graceful fail tests. No artifacts outside sandbox directory confirmed.

---

### Phase 6 — Evaluation Model

**Objective:** Build the 7-dimension scoring engine that evaluates sandbox simulation results against the frozen baseline.

**Sub-agents:** Evaluation Model Agent

**Exact Tasks:**

- [ ] Create `scripts/evaluation/EvaluationModel.php` — orchestrates all 7 scorers, passes results to `EvaluationAggregator`.
- [ ] Create `scripts/evaluation/BalanceScorer.php`:
  - Inputs: `archetype_metrics[*].global_stars_earned_mean`, `archetype_metrics[*].final_score_mean`
  - `viability_ratio = min(archetype_scores) / median(archetype_scores)`
  - `gini = entropyNormalized(archetype_scores)` (proxy; use existing util)
  - `score = 0.6 * clamp(viability_ratio / 0.40, 0, 1) + 0.4 * clamp(gini, 0, 1)`
  - **Dominant meta cap**: if any archetype > 200% of median, cap score at 0.2
- [ ] Create `scripts/evaluation/CompetitivePaceScorer.php`:
  - Inputs: `overall_diagnostics.lock_in_timing` distribution, `overall_diagnostics.action_volume_by_phase`
  - `lock_in_entropy = entropyNormalized(lock_in_timing_distribution)`
  - `action_density = mean(LATE_ACTIVE + BLACKOUT actions) / mean(EARLY + MID actions)`
  - `score = 0.5 * lock_in_entropy + 0.5 * clamp(action_density / 1.5, 0, 1)`
- [ ] Create `scripts/evaluation/ConcentrationScorer.php`:
  - Inputs: Lifetime `concentration_drift`, Sim B `final_score` distribution
  - `top10_share = sum(top 10% scores) / sum(all scores)`
  - `hhi = sum((archetype_score / total)^2)`
  - `score = 0.5 * clamp((0.35 - top10_share) / 0.35, 0, 1) + 0.5 * clamp((0.20 - hhi) / 0.20, 0, 1)`
- [ ] Create `scripts/evaluation/StrategyDiversityScorer.php`:
  - `viable_count = count(archetypes where score >= median * 0.35)`
  - `diversity_entropy = entropyNormalized(archetype_scores)`
  - `score = 0.4 * clamp(viable_count / 8, 0, 1) + 0.6 * diversity_entropy`
- [ ] Create `scripts/evaluation/ExploitRiskScorer.php`:
  - Inputs: regression flags from `ResultComparator`, coupling harness results
  - `score = clamp(1.0 - (flag_count * 0.25) - (coupling_violations * 0.20), 0, 1)`
  - Hard-zero if: `flag_count >= 3` OR `coupling_violations >= 2`
- [ ] Create `scripts/evaluation/CounterplayScorer.php`:
  - `counterplay_ratio = (freeze + theft actions) / total_action_volume`
  - `counterplay_archetypes = count(archetypes with theft_probability > 0.01 OR freeze_probability > 0.02)`
  - `score = 0.5 * clamp(counterplay_ratio / 0.15, 0, 1) + 0.5 * clamp(counterplay_archetypes / 4, 0, 1)`
- [ ] Create `scripts/evaluation/ComplexityScorer.php` (inverse — higher complexity reduces score):
  - `complexity_index = knob_count * 0.3 + adjacent_subsystem_count * 0.2 + balance_risk_count * 0.5`
  - `score = clamp(1.0 - (complexity_index / 8.0), 0, 1)`
- [ ] Create `scripts/evaluation/EvaluationAggregator.php`:
  - Default weights: `balance=2.0`, `competitive_pace=2.0`, `concentration=1.0`, `strategy_diversity=1.0`, `exploit_risk=1.0`, `counterplay=1.0`, `complexity=1.0`
  - Framework `weight_overrides` multiply (not replace) defaults
  - Aggregate = weighted mean of all dimension scores
  - Recommendation logic: `approve` (all >= threshold AND aggregate >= 0.55), `revise` (required >= 0.4 AND aggregate >= 0.45), `reject` (any required < 0.4 OR exploit_risk hard-zero)
- [ ] Create `scripts/agents/EvaluationModelAgent.php` — `evaluate(fidelityResults, baselineSnapshot, mechanicDef, options)`, `detectDominantMeta(archetypeMetrics)`, `recommend(evaluationReport)`.
- [ ] Create `tests/EvaluationModelTest.php`:
  - `testBaselineEconomyScoresBelowBalanceThreshold()` — run evaluation against known baseline (hoarder at 212%), assert `balance` dimension < 0.5
  - `testEqualWeightForBalanceAndCompetitivePace()` — assert `DEFAULT_WEIGHTS['balance'] === DEFAULT_WEIGHTS['competitive_pace'] === 2.0`
  - `testDominantMetaCapApplied()` — mock archetype metrics where one archetype is 250% of median, assert balance score capped at 0.2
  - `testExploitRiskHardZeroOnThreeFlags()` — mock 3 regression flags, assert exploit_risk score = 0.0 and recommendation = 'reject'
- [ ] Create `tests/BalanceScorerTest.php` — unit tests for each balance formula component with known inputs/outputs.
- [ ] Create `tests/CompetitivePaceScorerTest.php` — unit tests for entropy and action_density components.
- [ ] Run all tests. All must pass.
- [ ] Commit: `feat: add 7-dimension evaluation model (Phase 6)`.

**Deliverables:**
- `scripts/evaluation/EvaluationModel.php`
- `scripts/evaluation/BalanceScorer.php`
- `scripts/evaluation/CompetitivePaceScorer.php`
- `scripts/evaluation/ConcentrationScorer.php`
- `scripts/evaluation/StrategyDiversityScorer.php`
- `scripts/evaluation/ExploitRiskScorer.php`
- `scripts/evaluation/CounterplayScorer.php`
- `scripts/evaluation/ComplexityScorer.php`
- `scripts/evaluation/EvaluationAggregator.php`
- `scripts/agents/EvaluationModelAgent.php`
- `tests/EvaluationModelTest.php` (passing)
- `tests/BalanceScorerTest.php` (passing)
- `tests/CompetitivePaceScorerTest.php` (passing)

**Dependencies:** Phase 5 (fidelity pipeline results are the evaluation model's inputs), Phase 1 (baseline snapshot is the comparison anchor)

**Risks:**
- `entropyNormalized()` from `AgenticOptimizationUtils` must handle uniform distributions (all archetypes equal) without returning NaN — test with uniform input
- FP scale values from sim output must be normalized before passing to scorers (e.g., convert fp values to floats by dividing by 1,000,000)
- The "hoarder at 212%" test requires loading real baseline data — if real data isn't available in test, use a fixture that matches the known ratio

**Success Criteria:**
- All evaluation tests pass
- Baseline economy scores below `balance` threshold (confirming scorer is sensitive to known problem)
- `balance` and `competitive_pace` weights equal in aggregator

**Exit Gate:** All evaluation model and scorer tests pass. Dominant meta cap confirmed working. Commit.

---

### Phase 7 — Interaction System

**Objective:** Detect coupling conflicts and synergy exploits between mechanics evaluated within the same session.

**Sub-agents:** Interaction System Agent

**Exact Tasks:**

- [ ] Create `scripts/agents/InteractionSystemAgent.php`:
  - `analyzeAll(sessionMechanics, sessionManifest, options): array` — runs pairwise analysis for all mechanic pairs in session
  - `analyzePair(mechA, mechB, sessionManifest): array` — creates combined `candidate_patch` (merge of both mechanics' knobs), runs Sim B (short profile), compares combined result vs individual results
  - `detectSynergyExploit(pairResult, individualResults): bool` — returns true if combined dimension score delta > sum of individual deltas by more than 10% (emergent amplification)
  - `detectCounterplaySuppression(pairResult, mechA, mechB): bool` — returns true if a mechanic's counterplay effectiveness drops by >30% when combined with another
- [ ] Interaction matrix output format:
  ```json
  {
    "schema_version": "tmc-sandbox-interaction-matrix.v1",
    "session_id": "<id>",
    "mechanic_pairs": [
      {
        "mechanic_a": "<id>",
        "mechanic_b": "<id>",
        "synergy_exploit_detected": false,
        "counterplay_suppression_detected": false,
        "combined_knob_conflicts": ["starprice_max_upstep_fp"],
        "combined_evaluation_delta": { ... }
      }
    ]
  }
  ```
- [ ] Create `tests/InteractionSystemAgentTest.php`:
  - `testSynergyExploitIsDetected()` — create two mechanics that both increase `starprice_max_upstep_fp`, verify the combined effect exceeds sum of individual effects by >10%, assert `synergy_exploit_detected: true`
  - `testNoInteractionOnOrthogonalMechanics()` — mechanics in completely different domains, assert no conflicts
  - `testKnobCollisionIsReported()` — two mechanics sharing the same knob key, assert `combined_knob_conflicts` is non-empty
- [ ] Run tests. All must pass.
- [ ] Commit: `feat: add mechanic interaction detection system (Phase 7)`.

**Deliverables:**
- `scripts/agents/InteractionSystemAgent.php`
- `tests/InteractionSystemAgentTest.php` (passing)

**Dependencies:** Phase 5 (fidelity pipeline must produce per-mechanic results), Phase 6 (evaluation model must produce dimension scores for comparison)

**Risks:**
- Sessions with only one mechanic produce no pairs — `analyzeAll()` must return an empty interaction matrix gracefully, not fail
- Combined `candidate_patch` may include conflicting knob values — use the higher-impact value (not average) and flag the conflict in the report

**Success Criteria:**
- Synergy exploit detection fires on known amplifying mechanic pairs
- Orthogonal mechanics produce no interaction flags
- Empty interaction matrix (single mechanic session) handled gracefully

**Exit Gate:** All interaction system tests pass. Synergy exploit detection confirmed working.

---

### Phase 8 — Approval and Promotion Gate

**Objective:** Assemble the approval package, generate human-readable artifacts, and enforce the approval token gate.

**Sub-agents:** Approval & Promotion Agent, Reporting & Artifact Agent

**Exact Tasks:**

- [ ] Create `scripts/agents/ApprovalPromotionAgent.php`:
  - `assemblePackage(sessionId, options): array` — assembles `approval_package.json` from all session artifacts (mechanic definitions, evaluation reports, interaction matrix)
  - `promoteApproved(sessionId, approvalToken, options): array` — verifies token file, then and ONLY then executes promotion
  - `verifyApprovalToken(sessionDir, token)` — reads `approval/approval_token.txt`, compares, throws `SandboxApprovalRequiredException` if absent or mismatched
  - Class constant `APPROVAL_TOKEN_FILE = 'approval/approval_token.txt'`
- [ ] Approval package must include:
  - `schema_version: 'tmc-sandbox-approval-package.v1'`
  - `generated_at`, `session_id`, `baseline_snapshot_hash`
  - Per-mechanic: evaluation summary, dimension scores, delta vs baseline, resolved candidate patch, artifact paths
  - `interaction_matrix_path`, `interaction_conflicts` array
  - `promotion_patch_plan` (read-only): `status: 'plan_only'`, explicit warning text, `required_season_changes`, `promotion_command`
  - `approval_status: 'pending_user_approval'`
  - `approval_token_file` path
- [ ] Create `scripts/agents/ReportingArtifactAgent.php`:
  - `buildSessionReport(sessionId, options): array` — session-level comparison table, all mechanics ranked by aggregate score
  - `buildMechanicReport(evaluationReport, mechanicDef): string` — per-mechanic Markdown report with plain-English description of changes, affected archetypes, next-step instructions
  - `buildApprovalSummary(approvalPackage): string` — Markdown approval summary with explicit promotion instructions
  - Outputs both `.json` (machine-readable) and `.md` (operator-facing) for every report
- [ ] Create `tests/ApprovalPromotionAgentTest.php`:
  - `testPromoteApprovedWithoutTokenThrows()` — call `promoteApproved()` with no token file, assert `SandboxApprovalRequiredException`
  - `testApprovalPackageMatchesSchema()` — assemble package, validate against `approval_package.schema.json`, assert zero violations
  - `testPromotionPatchPlanIsReadOnly()` — assert `promotion_patch_plan.status === 'plan_only'` and that `promoteApproved()` is NOT called by `assemblePackage()` under any circumstances
- [ ] Create `tests/ReportingArtifactAgentTest.php`:
  - `testSessionReportContainsAllMechanics()` — session with 2 mechanics, assert both in report
  - `testApprovalSummaryContainsPromotionInstructions()` — assert `buildApprovalSummary()` output contains the literal string `approval_token.txt`
- [ ] Run tests. All must pass.
- [ ] Commit: `feat: add approval and promotion gate with reporting (Phase 8)`.

**Deliverables:**
- `scripts/agents/ApprovalPromotionAgent.php`
- `scripts/agents/ReportingArtifactAgent.php`
- `tests/ApprovalPromotionAgentTest.php` (passing)
- `tests/ReportingArtifactAgentTest.php` (passing)

**Dependencies:** Phases 6, 7 (evaluation and interaction results are inputs to the approval package)

**Risks:**
- The `promotion_patch_plan` in the approval package must be absolutely clear it cannot be auto-executed — the warning text must be explicit and must appear in the summary report
- `promoteApproved()` must refuse to run if any mechanic in the session has `recommendation: 'reject'` — add a pre-promotion check

**Success Criteria:**
- `promoteApproved()` without token file throws — confirmed by test
- Approval package validates against schema
- Promotion patch plan status is `plan_only` and contains explicit warning text

**Exit Gate:** All approval and reporting tests pass. Token gate confirmed. Commit.

---

### Phase 9 — First Controlled Trial (CLI Entry Points + End-to-End)

**Objective:** Build the four CLI entry points and run the complete workflow end-to-end with a real mechanic from a seed framework.

**Sub-agents:** All agents (orchestrated by CLI scripts)

**Exact Tasks:**

- [ ] Create `scripts/sandbox_design_session.php` — CLI: `--session-id=<id>`, `--season-config=<path>`. Starts session, runs `BaselineProtectionAgent::snapshot()`, emits `session_manifest.json`.
- [ ] Create `scripts/sandbox_evaluate_mechanic.php` — CLI: `--session-id=<id>`, `--framework=<id>`, `--slot-<slot_id>=<value>` (repeatable). Composes mechanic, runs `FidelityPipelineAgent::run()`, runs `EvaluationModelAgent::evaluate()`, writes all artifacts to session dir, prints evaluation summary.
- [ ] Create `scripts/sandbox_generate_approval.php` — CLI: `--session-id=<id>`. Runs `InteractionSystemAgent::analyzeAll()`, then `ApprovalPromotionAgent::assemblePackage()`, then `ReportingArtifactAgent::buildSessionReport()`. Emits all approval artifacts.
- [ ] Create `scripts/sandbox_promote_approved.php` — CLI: `--session-id=<id>`, `--approval-token=<token>`. Calls `ApprovalPromotionAgent::promoteApproved()`. Requires token file to exist at `simulation_output/sandbox/sessions/<id>/approval/approval_token.txt`.
- [ ] Create `tests/SandboxEndToEndTest.php`:
  - `testFullSessionWithOneMechanic()`:
    1. Start session with `sandbox_design_session.php` arguments
    2. Evaluate `ubi_participation_boost` framework with `--slot-boost_magnitude=1080000`
    3. Generate approval package
    4. Assert `approval_package.json` exists and is valid JSON
    5. Assert `promotion_patch_plan.json` has `status: 'plan_only'`
    6. Attempt promotion without token file — assert non-zero exit code
    7. Assert ALL output files are within `simulation_output/sandbox/sessions/<id>/`
    8. Assert zero files written to `simulation_output/season/`, `simulation_output/lifetime/`, `simulation_output/promotion/`
  - `testSessionWithTwoMechanicsProducesInteractionMatrix()`:
    1. Evaluate two different frameworks in same session
    2. Generate approval package
    3. Assert `interaction_matrix.json` exists with both mechanic IDs
- [ ] Run end-to-end test. Must pass.
- [ ] Run full manual trial with real CLI:
  ```bash
  php scripts/sandbox_design_session.php --session-id=trial-001 --season-config=simulation_output/current-db/export/current_season_economy_only.json
  php scripts/sandbox_evaluate_mechanic.php --session-id=trial-001 --framework=ubi_participation_boost --slot-boost_magnitude=1080000
  php scripts/sandbox_generate_approval.php --session-id=trial-001
  ```
- [ ] Review `simulation_output/sandbox/sessions/trial-001/approval/approval_summary.md` and confirm it is human-readable and actionable.
- [ ] Commit: `feat: complete sandbox system with CLI and end-to-end trial (Phase 9)`.

**Deliverables:**
- `scripts/sandbox_design_session.php`
- `scripts/sandbox_evaluate_mechanic.php`
- `scripts/sandbox_generate_approval.php`
- `scripts/sandbox_promote_approved.php`
- `tests/SandboxEndToEndTest.php` (passing)
- `simulation_output/sandbox/sessions/trial-001/` (trial artifacts, not committed to git)

**Dependencies:** All phases 0-8 complete

**Risks:**
- CLI scripts running on Windows dev must work identically on VPS Linux — use absolute paths via `__DIR__`, never relative paths
- Sim B/C in stage 4-5 of a trial session may be slow with full profile — use tier1_smoke profile in trial; document this in CLI help text

**Success Criteria:**
- `SandboxEndToEndTest` passes
- Trial session completes without crashes
- Approval summary is readable by a human (subjective check by user)
- Zero files outside sandbox directory

**Exit Gate:** End-to-end test passes. Manual trial completes. User reviews `approval_summary.md` and confirms it is actionable. Commit.

---

## 4. Sub-Agent Architecture

### Agent Roster

| Agent | File | Phase Introduced | Responsibility |
|-------|------|-----------------|----------------|
| Baseline Protection Agent | `scripts/agents/BaselineProtectionAgent.php` | 1 | Snapshot, freeze, and verify the known-good economy baseline |
| Sandbox Boundary Agent | `scripts/agents/SandboxBoundaryAgent.php` | 0 | Enforce isolation at every agent call boundary; maintain boundary log |
| Framework Registry Agent | `scripts/agents/FrameworkRegistryAgent.php` | 3 | Framework CRUD, validation, versioning, family indexing |
| Mechanic Composition Agent | `scripts/agents/MechanicCompositionAgent.php` | 4 | Compose mechanics from frameworks; validate; resolve simulation knobs |
| Fidelity Pipeline Agent | `scripts/agents/FidelityPipelineAgent.php` | 5 | Run sandbox-safe pipeline stages 1-5; hard-block stages 6-10 |
| Evaluation Model Agent | `scripts/agents/EvaluationModelAgent.php` | 6 | Score mechanics across 7 dimensions; compare vs baseline; recommend |
| Interaction System Agent | `scripts/agents/InteractionSystemAgent.php` | 7 | Detect synergy exploits and counterplay suppression between mechanic pairs |
| Approval & Promotion Agent | `scripts/agents/ApprovalPromotionAgent.php` | 8 | Assemble approval packages; enforce token gate; generate promotion plan |
| Reporting & Artifact Agent | `scripts/agents/ReportingArtifactAgent.php` | 8 | Generate session reports, mechanic reports, approval summaries in JSON + Markdown |

### Data Flow

```
CLI: sandbox_design_session.php
        ↓
BaselineProtectionAgent::snapshot()
        → [baseline_snapshot.json]
        ↓
CLI: sandbox_evaluate_mechanic.php
        ↓
FrameworkRegistryAgent::get(frameworkId)
        → [framework definition]
        ↓
MechanicCompositionAgent::compose(...)
        → [mechanic_definition.json]
        ↓ ← [mechanic_definition.json]
FidelityPipelineAgent::run(...)
  Wraps: SandboxBoundaryAgent::assertIsolated() on each stage
  Stage 1: EconomicCandidateValidator
  Stage 2: SandboxConfigResolver (wraps SimulationConfigPreflight)
  Stage 3: AgenticCouplingHarnessCatalog (tier1_smoke)
  Stage 4: SimulationPopulationSeason
  Stage 5: SimulationPopulationLifetime
        → [sim_b_results.json, sim_c_results.json, stage_results/]
        ↓ ← [fidelity results, baseline_snapshot.json, mechanic_definition.json]
EvaluationModelAgent::evaluate(...)
  BalanceScorer → CompetitivePaceScorer → ConcentrationScorer → ...
  EvaluationAggregator
        → [evaluation_report.json]
        ↓
CLI: sandbox_generate_approval.php
        ↓ ← [all mechanic definitions, evaluation reports]
InteractionSystemAgent::analyzeAll(...)
        → [interaction_matrix.json]
        ↓ ← [all artifacts]
ApprovalPromotionAgent::assemblePackage(...)
        → [approval_package.json, promotion_patch_plan.json (read-only)]
        ↓ ← [approval_package.json]
ReportingArtifactAgent::buildSessionReport(...)
        → [session_report.json, session_report.md, approval_summary.md]
        ↓
[User reviews approval_summary.md]
[User writes approval token to approval/approval_token.txt]
        ↓
CLI: sandbox_promote_approved.php (manual, after user writes token)
        ↓ ← [session_id, approval_token from token file]
ApprovalPromotionAgent::promoteApproved(...)
  verifyApprovalToken() ← throws SandboxApprovalRequiredException if absent
        → [begins live promotion process via existing CandidatePromotionPipeline stages 6-10]
```

### Agent Interaction Boundaries

- **No agent writes directly to `simulation_output/` outside its session sandbox.** All writes go through `SandboxArtifactWriter`.
- **No agent modifies live DB.** `SandboxBoundaryEnforcer::installQueryInterceptor()` tripwires all DML.
- **No agent calls `CandidatePromotionPipeline` stages 6-10.** `FidelityPipelineAgent::assertSandboxStage()` blocks all attempts.
- **`ApprovalPromotionAgent::assemblePackage()` never calls `promoteApproved()`.** These are two separate methods, and the CLI scripts call them separately.
- **Baseline Protection Agent is read-only after Phase 1.** Its snapshot is never overwritten within a session.

---

## 5. Dependency Graph

```
Phase 0: Readiness Audit + Sandbox Isolation Foundation
   │
   ├── Phase 1: Baseline Freeze ──────────────────────────────────┐
   │                                                               │
   ├── Phase 2: Sandbox Boundary Formalization (DB/static)         │
   │      │                                                        │
   │      └── Phase 3: Framework Registry ─────────────────────── │
   │                     │                                         │
   │                     └── Phase 4: Mechanic Composition         │
   │                                   │                           │
   │                                   └── Phase 5: Fidelity Pipeline
   │                                                 │           │
   │                                    ─────────────┘           │ (baseline)
   │                                    │                         │
   │                               Phase 6: Evaluation Model ←───┘
   │                                    │
   │                               Phase 7: Interaction System
   │                                    │
   │                               Phase 8: Approval + Reporting
   │                                    │
   └──────────────────────────── Phase 9: CLI + End-to-End Trial
```

**Critical path:** 0 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9

**Can parallelize:**
- Phases 1 and 3 are independent of each other (both depend only on Phase 0)
- Phases 7 and 8 can begin development concurrently once Phase 6 defines the evaluation report schema

**Blocking relationships:**
- Phase 0's `SandboxIsolationContractTest` must pass before ANY other phase begins — no exceptions
- Phase 5's `FidelityPipelineAgent` cannot run without at least one seed framework (Phase 3) and one composed mechanic (Phase 4)
- Phase 7's interaction detection cannot run without per-mechanic evaluation reports (Phase 6)
- Phase 9's CLI cannot function without all 9 agents being complete (Phases 0-8)

---

## 6. Verification Strategy

| Phase | Primary Verification | Gate |
|-------|---------------------|------|
| 0 | `SandboxIsolationContractTest`: zero artifacts outside sandbox dir | Must pass before anything else |
| 1 | `BaselineProtectionAgentTest`: hash deterministic, verify fails on mutation | Must pass before evaluation |
| 2 | Extended isolation tests: no DML, no static leak between sessions | Must not introduce regressions |
| 3 | `FrameworkValidatorTest`: all seed frameworks pass schema; unknown knob rejected | All 6 seeds validated |
| 4 | `MechanicComposerTest`: composed patch passes `EconomicCandidateValidator` | Composition must produce valid candidate |
| 5 | `FidelityPipelineAgentTest`: stages 1-5 complete; stage 6 blocked; SV2 graceful fail | Pipeline runs without live promotion |
| 6 | `EvaluationModelTest`: baseline scores below balance threshold; dominant meta cap works | Scorer is sensitive to known imbalance |
| 7 | `InteractionSystemAgentTest`: synergy exploit detected; orthogonal mechanics clear | Exploit detection fires correctly |
| 8 | `ApprovalPromotionAgentTest`: no-token throws; package matches schema | Token gate is hard |
| 9 | `SandboxEndToEndTest`: full session with zero artifacts outside sandbox | System works as designed |

**Regression prevention:** Every phase's test suite runs on top of all previous phases' tests. No phase can be committed if prior phase tests break.

**Performance budgets:**
- Phase 0/1 (baseline snapshot with tier1_smoke): ≤ 3 minutes
- Phase 5 stages 4-5 per mechanic (tier1_smoke): ≤ 4 minutes per mechanic
- Phase 7 interaction pair analysis (short Sim B): ≤ 90 seconds per pair
- Phase 9 end-to-end trial: ≤ 12 minutes total (one mechanic, tier1_smoke)

---

## 7. Failure Modes and Safeguards

| Failure Mode | How It Manifests | Safeguard |
|-------------|-----------------|-----------|
| Sandbox writes to live promotion dir | Files appear in `simulation_output/promotion/` | `SandboxArtifactWriter` path enforcement + `SandboxIsolationContractTest` |
| Framework defines inactive knob | Mechanic passes composition but fails stage 2 | `FrameworkValidator` cross-references `patchableParameters()` at registration time; stage 2 reports graceful fail |
| Dominant meta-strategy approved | Mechanic with one archetype at 250% of median gets `recommend: approve` | `BalanceScorer` hard-cap at 0.2 when dominant archetype detected |
| Session static state leaks between sessions | Second session inherits scenario registrations from first | `SandboxRegistry::startSession()` resets all mutable statics |
| Promotion triggered without approval | `promoteApproved()` executes without user consent | Token file required; hard exception without it |
| Recovery blocker SV2 corrupts sandbox | `market_affordability_bias_fp` is inactive; sandbox silently uses wrong value | `SandboxConfigResolver` passes knob to preflight unchanged; stage 2 returns `inactive_unreferenced` fail rather than silent passthrough |
| Mechanic with `requires_runtime_code_change: true` enters pipeline | Framework is sandbox-ineligible but composed anyway | `MechanicComposer` step 1 emits `mechanic_integration_request.json` and halts — no `mechanic_definition.json` produced |
| Session ID collision between runs | Two sessions share same directory | `SandboxRegistry::startSession()` checks for existing session and throws if ID already in use |
| Synergy exploit goes undetected | Two mechanics amplify each other past scoring threshold | `InteractionSystemAgent::detectSynergyExploit()` runs pair analysis; `ApprovalPromotionAgent::assemblePackage()` blocks package if any pair has `synergy_exploit_detected: true` |
| Scope drift across implementation sessions | Phase 4 accidentally modifies Phase 0 isolation boundary | Each phase has an explicit exit gate commit; later phases may only ADD files, not modify sandbox boundary files without explicit review |

---

## 8. Execution Strategy

### How to Structure Future Prompts

Each phase should be executed in its own dedicated session, or in a small group of related phases (0+1 together, 3+4 together). The prompt for each session should include:

1. The phase number and objective from this plan
2. The exact files to create (from this plan's deliverables list)
3. The test that constitutes the exit gate
4. The instruction: "Do not proceed beyond the exit gate without user confirmation"
5. The relevant section of this plan document as context

Example session structure:
- **Session 1:** Execute Phase 0 (Readiness Audit + Sandbox Foundation)
- **Session 2:** Execute Phase 1 (Baseline Freeze) + Phase 2 (Boundary Formalization)
- **Session 3:** Execute Phase 3 (Framework Registry) — includes framework design decisions that may require user input
- **Session 4:** Execute Phase 4 (Mechanic Composition)
- **Session 5:** Execute Phase 5 (Fidelity Pipeline)
- **Session 6:** Execute Phase 6 (Evaluation Model) — most complex phase; allocate full session
- **Session 7:** Execute Phase 7 (Interaction System) + Phase 8 (Approval/Reporting)
- **Session 8:** Execute Phase 9 (CLI + End-to-End Trial) — includes manual trial review

### How to Avoid Scope Drift

- **One phase per session.** Do not start the next phase unless the current phase's exit gate has been passed and committed.
- **Exit gates are commitments, not suggestions.** If a test doesn't pass, the implementation session is not done.
- **No speculative work.** Do not build Phase 4 infrastructure while in a Phase 3 session, even if it seems natural.
- **Framework definitions require user review.** Seed frameworks in Phase 3 should be presented to the user for review before committing — they define what kinds of mechanics the system can generate.
- **Evaluation thresholds require user input.** The values in Phase 6 (e.g., `TARGET_MIN_RATIO = 0.40`, aggregate `>= 0.55`) should be confirmed with the user before being coded.

### Recovery Plan Relationship

This master plan and the recovery plan (`2026-04-15-simulation-suite-recovery.md`) are **parallel workstreams**. They can proceed independently:

- Recovery plan fixes the agentic optimizer (SV1/RP1) and wires the affordability knob (SV2/OQ2)
- This plan builds the sandbox system which works around those blockers

When SV2 is fixed, sandbox mechanics using `market_affordability_bias_fp` will automatically start passing stage 2 (no sandbox changes required). When SV1/RP1 are fixed, the agentic optimizer can eventually be used alongside the sandbox (not a dependency — an enhancement).

### Commit Convention

Each phase should end with a commit following the pattern:
```
feat: <phase description> (Phase N)

Closes Phase N exit gate:
- [list of passing tests]

No modification to: [list of isolation-boundary files if unchanged]
```

This makes it easy to identify when each phase completed and to roll back if needed.

---

## Appendix A — Framework JSON Schema (Canonical)

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "title": "TmcMechanicFramework",
  "type": "object",
  "required": [
    "framework_id", "schema_version", "label", "ability_family",
    "mechanic_domain", "slots", "simulation_knobs", "balance_surface",
    "evaluation_targets", "integration_requirements"
  ],
  "properties": {
    "framework_id": { "type": "string", "pattern": "^[a-z0-9_-]+$" },
    "schema_version": { "type": "string", "const": "tmc-mechanic-framework.v1" },
    "label": { "type": "string" },
    "ability_family": { "type": "string", "enum": ["Offensive", "Defensive", "Utility"] },
    "mechanic_domain": {
      "type": "string",
      "enum": [
        "star_conversion_pricing", "hoarding_preservation_pressure",
        "boost_related", "lock_in_expiry_incentives", "sigil_loop",
        "onboarding_economy", "concentration_control", "pvp_counterplay"
      ]
    },
    "slots": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["slot_id", "label", "required", "accepted_types"],
        "properties": {
          "slot_id": { "type": "string" },
          "label": { "type": "string" },
          "required": { "type": "boolean" },
          "accepted_types": {
            "type": "array",
            "items": {
              "type": "string",
              "enum": ["archetype_target", "phase_window", "magnitude_fp",
                       "duration_ticks", "probability", "tier_selector", "flag"]
            }
          },
          "default": {}
        }
      }
    },
    "simulation_knobs": {
      "type": "object",
      "description": "Map from slot_id to canonical season key. All values must exist in CanonicalEconomyConfigContract::patchableParameters().",
      "additionalProperties": { "type": "string" }
    },
    "balance_surface": {
      "type": "object",
      "required": ["affected_archetypes", "affected_metrics", "balance_risks"],
      "properties": {
        "affected_archetypes": {
          "type": "array",
          "items": {
            "type": "string",
            "enum": ["casual", "regular", "hardcore", "hoarder", "early_locker",
                     "late_deployer", "boost_focused", "star_focused",
                     "aggressive_sigil_user", "mostly_idle"]
          }
        },
        "affected_metrics": { "type": "array", "items": { "type": "string" } },
        "balance_risks": { "type": "array", "items": { "type": "string" } }
      }
    },
    "evaluation_targets": {
      "type": "object",
      "required": ["primary_dimension", "weight_overrides"],
      "properties": {
        "primary_dimension": {
          "type": "string",
          "enum": ["balance", "competitive_pace", "concentration",
                   "strategy_diversity", "exploit_risk", "counterplay", "complexity"]
        },
        "weight_overrides": {
          "type": "object",
          "additionalProperties": { "type": "number", "minimum": 0.0, "maximum": 3.0 }
        },
        "pass_thresholds": {
          "type": "object",
          "additionalProperties": { "type": "number", "minimum": 0.0, "maximum": 1.0 }
        }
      }
    },
    "integration_requirements": {
      "type": "object",
      "required": ["requires_runtime_code_change", "requires_parity_fixture", "sandbox_eligible"],
      "properties": {
        "requires_runtime_code_change": { "type": "boolean" },
        "requires_parity_fixture": { "type": "boolean" },
        "sandbox_eligible": { "type": "boolean" },
        "feature_flag": { "type": ["string", "null"] },
        "parity_domain": { "type": ["string", "null"] }
      }
    }
  }
}
```

---

## Appendix B — Critical File Reference

**Must not modify (isolation boundary):**
- `scripts/sandbox/SandboxBoundaryEnforcer.php` (after Phase 0)
- `scripts/sandbox/SandboxArtifactWriter.php` (after Phase 0)
- `simulation_output/sandbox/baseline/baseline_snapshot.json` (after Phase 1)

**Must read before implementing each phase:**
- `scripts/simulation/CanonicalEconomyConfigContract.php` — patchable parameter surface
- `scripts/simulation/CandidatePromotionPipeline.php` — stage implementations to wrap
- `scripts/simulation/SimulationConfigPreflight.php` — preflight to sandbox-wrap in stage 2
- `scripts/optimization/AgenticOptimization.php` — `AgenticOptimizationUtils` utilities to reuse
- `scripts/optimization/AgenticOptimization.php` — `AgenticCouplingHarnessCatalog` for stage 3 targeting
- `simulation_output/current-db/export/current_season_economy_only.json` — official baseline config
- `docs/SIMULATION_MECHANIC_INTEGRATION_CONTRACT.md` — defines what `sandbox_eligible: false` means

**Must check before each seed framework definition (Phase 3):**
- `CanonicalEconomyConfigContract::patchableParameters()` — every `simulation_knobs` value must be in this list
- `CanonicalEconomyConfigContract::CANDIDATE_SEARCH_SURFACE_REMOVALS` — verify framework knobs are NOT in this list
