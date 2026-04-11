# Plan: Fresh Lifecycle Runtime Simulation (Locked v1)

Build a new fresh-run simulator that executes against a disposable local database and reuses production runtime paths (Actions + TickEngine + Economy) with a simulation-controlled clock so a full season lifecycle can run quickly, deterministically, and without touching live/test VPS data.

## Objective

Create a one-season, parity-focused fresh-run simulator that:
- uses a disposable local database
- reuses production runtime logic wherever possible
- is deterministic under a fixed seed
- produces lifecycle metrics and stakeholder-readable summaries
- cannot accidentally touch production, staging, or shared test infrastructure

## Scope

### Included in v1
- one-season full lifecycle simulation
- disposable local DB bootstrap/reset/teardown
- simulation-controlled clock
- explicit tick-driven execution path
- deterministic synthetic cohort generation
- deterministic seeded behavior variation inside archetype policies
- reuse of production Economy, Actions, TickEngine, and settlement logic wherever possible
- technical outputs and stakeholder summaries
- smoke, determinism, safety, and parity contract validation

### Excluded from v1
- economy rebalance or tuning work
- coarse fast-forward mechanics
- multi-season simulation
- player migration/carryover between seasons
- policy sweeps or parameter search tooling
- broad production refactors beyond minimal parity-preserving seams
- live third-party integration calls unless explicitly isolated and required for parity validation

## Execution Phases

### Phase 1 - Isolation and safety rails
1. Add a dedicated simulation mode flag and fail-closed fresh-run safety boundary.
2. Add DB safety validation that aborts before any write if target coordinates/config do not satisfy fresh-run requirements.
3. Require explicit machine-enforced safety flags for simulation mode and destructive reset.
4. Require a safe DB-name allowlist pattern for fresh-run databases.
5. Reject known protected environments and unsafe/non-local DB targets.
6. Add a disposable database bootstrap flow that creates a clean schema/state from schema + seed + migrations, independent from current export workflow and independent from current production/test lane DB.
7. Add optional teardown/cleanup for disposable DB artifacts and output directories for repeatable reruns.
8. Add a safety test that proves fresh-run aborts on unsafe DB targets before writes occur.

### Phase 2 - Runtime parity foundation
9. Introduce a simulation clock adapter so GameTime can be driven by explicit tick input during CLI simulation while preserving default production behavior when simulation mode is off.
10. Add a TickEngine entrypoint that accepts explicit game tick input and routes through the same season processing path used in production.
11. Validate no production behavior drift by proving the default code path still uses the existing time source and existing scheduler endpoint semantics when simulation mode is off.
12. Mock or stub external dependencies by default in fresh-run mode. Only isolated, non-destructive test endpoints may be used, and only when explicitly required for parity validation.

### Phase 3 - Fresh lifecycle orchestrator
13. Add a new CLI script for fresh lifecycle simulation that orchestrates: bootstrap clean DB, create synthetic players, season join, per-tick action scheduling, tick progression, season completion, and result export.
14. Add a new orchestration engine that owns bootstrap, cohorts, actions, ticks, finalization, and export flow.
15. Reuse existing archetype and policy behavior layers for decision generation, but execute real action handlers for purchases, lock-in, boosts, sigil combine, freeze/theft/melt where available.
16. If any action path is too tightly coupled to reusable fresh-run execution, isolate only that path behind a minimal adapter and record it in run metadata under `adapted_paths` and/or `unmodeled_mechanics`.
17. Add support for deterministic cohort generation using existing deterministic seed utilities and include requested cohorts: Casual, Regular, Hardcore, Hoarder, Early Locker, Late Deployer, Boost-Focused, Star-Focused, Aggressive Sigil User, Mostly Idle.
18. Keep synthetic behavior deterministic and seed-driven. Archetype policies define the primary behavior envelope, and bounded stochastic variation may be used only when sourced from the deterministic simulation RNG.
19. Implement season-end finalization capture using runtime expiry/settlement outputs and global star outcomes from persisted DB records.

### Phase 4 - Outputs and CLI integration
20. Extend simulation output payloads to include full lifecycle metrics and stakeholder-readable summaries.
21. Add a dedicated CLI command path in tools orchestration for fresh-run mode separate from export/snapshot modes, with explicit naming to avoid confusion.
22. Update the simulation runbook with the isolated fresh-run workflow, explicit non-production DB requirements, output interpretation guidance, and common failure guidance.
23. Add a parity ledger artifact format for tracking discrepancies, classification, severity, ownership, and resolution.

### Phase 5 - Validation and determinism
24. Add smoke test coverage for clean bootstrap, player join/progression, full season completion, and deterministic fixed-seed repeatability for fresh-run mode.
25. Add a parity-focused regression test set for selected core economy contracts, including star pricing progression, lock-in semantics, expiry settlement, and effective score ordering.
26. Run existing simulation tests to confirm export/snapshot modes are unchanged.
27. Integrate fresh-run smoke, determinism, and parity contract validation into the regular testing pipeline so simulator drift is detected as production mechanics evolve.

## Relevant Files

- `c:\Users\trent\Documents\webgame too-many-coins\too-many-coins-game\includes\game_time.php`  
  Add simulation clock override boundary while preserving the default runtime path.

- `c:\Users\trent\Documents\webgame too-many-coins\too-many-coins-game\includes\tick_engine.php`  
  Expose explicit tick-driven processing entrypoint for simulation.

- `c:\Users\trent\Documents\webgame too-many-coins\too-many-coins-game\includes\actions.php`  
  Reuse `seasonJoin`, `purchaseStars`, `lockIn`, `combine`, `freezePlayerUbi`, `selfMeltFreeze`, `attemptSigilTheft` in the orchestrated run loop.

- `c:\Users\trent\Documents\webgame too-many-coins\too-many-coins-game\scripts\simulation\Archetypes.php`  
  Reuse existing cohort definitions and traits.

- `c:\Users\trent\Documents\webgame too-many-coins\too-many-coins-game\scripts\simulation\PolicyBehavior.php`  
  Reuse behavior policy decisions for action scheduling.

- `c:\Users\trent\Documents\webgame too-many-coins\too-many-coins-game\scripts\simulation\SimulationRandom.php`  
  Reuse deterministic seeded randomness.

- `c:\Users\trent\Documents\webgame too-many-coins\too-many-coins-game\scripts\simulation\MetricsCollector.php`  
  Extend for fresh-run lifecycle output schema.

- `c:\Users\trent\Documents\webgame too-many-coins\too-many-coins-game\scripts\simulate_fresh_lifecycle.php`  
  New CLI entrypoint for fresh-run mode.

- `c:\Users\trent\Documents\webgame too-many-coins\too-many-coins-game\scripts\simulation\FreshLifecycleRunner.php`  
  New orchestration engine.

- `c:\Users\trent\Documents\webgame too-many-coins\too-many-coins-game\tools\Invoke-TmcSimulationStep.ps1`  
  Add fresh-run step command wiring.

- `c:\Users\trent\Documents\webgame too-many-coins\too-many-coins-game\tools\tmc-sim.config.example.ps1`  
  Add isolated local fresh-run config keys.

- `c:\Users\trent\Documents\webgame too-many-coins\too-many-coins-game\SIMULATION_RUNBOOK.md`  
  Document fresh-run lifecycle workflow and safeguards.

- `c:\Users\trent\Documents\webgame too-many-coins\too-many-coins-game\tests\FreshLifecycleSmokeTest.php`  
  New end-to-end smoke tests.

- `c:\Users\trent\Documents\webgame too-many-coins\too-many-coins-game\tests\FreshLifecycleDeterminismTest.php`  
  New fixed-seed determinism tests.

## Verification

1. Run fresh lifecycle CLI twice with identical seed and cohort size; assert lifecycle metrics and ranking outputs are byte-identical except for explicitly allowed generated-at fields.
2. Run smoke test asserting fresh bootstrap creates season + players, players can join, at least one lock-in and one natural expiry occur, and season finalization emits global star outcomes.
3. Run safety test proving fresh-run aborts on unsafe DB coordinates/config and does not execute writes.
4. Run existing simulation tests to confirm export/snapshot modes are unchanged.
5. Run selected economy contract tests around lock-in, expiry settlement, star pricing, and effective score ordering to confirm parity paths remain intact.

## Locked Decisions

- Isolation model: disposable local DB is preferred over in-memory state because it allows maximal reuse of production DB-coupled logic and action handlers without touching live/test DBs.
- Reuse strategy: use production Economy, Actions, TickEngine, and settlement logic directly wherever possible; keep adapters limited to clock control, safety boundaries, external stubs, and orchestration glue only.
- Scope included: one-season full lifecycle fresh run first, with deterministic cohorts and required metrics/outputs.
- Scope excluded in v1: policy sweep integration, economy value tuning, fast-forward mechanics, and multi-season support.
- Fresh-run v1 SHALL prioritize runtime parity over performance optimizations.
- Tick progression SHALL execute tick-by-tick in v1.
- No coarse fast-forward mechanics SHALL be introduced until parity validation passes and a later phase explicitly approves them.
- The default validation cohort size SHALL be 100 synthetic players.
- Smaller cohorts MAY be used for smoke/debug runs.
- Larger cohorts MAY be used for stress and representativeness testing after baseline parity is established.
- Production runtime paths SHALL be reused unchanged wherever possible against a disposable local DB.
- Any non-reusable path SHALL be isolated only behind a minimal adapter.
- Every adapted path SHALL be recorded in run metadata under `adapted_paths` and/or `unmodeled_mechanics`.
- Fresh-run mode SHALL require explicit machine-enforced safety guards, including simulation mode flags, destructive-reset flags, safe DB-name allowlist checks, protected-environment rejection rules, and simulator ownership/sentinel checks where applicable.
- Host/port validation alone is insufficient.
- Synthetic player behavior SHALL remain deterministic and seed-driven.
- Archetype policies SHALL define the primary behavior envelope.
- Bounded stochastic variation MAY be used only when sourced from the deterministic simulation RNG.
- External dependencies and third-party integrations SHALL be mocked or stubbed by default in fresh-run mode.
- Real external test endpoints SHALL only be used when they are isolated, non-destructive, and specifically required for parity validation.
- Validation discrepancies SHALL be classified into:
  - `unmodeled_mechanics` for minor or explicitly approximated behavior
  - `parity_bugs` for discrepancies that materially affect economy behavior, settlement, ranking, lock-in semantics, expiry outcomes, or other core contracts
- Parity discrepancies SHALL be tracked in a shared parity ledger with structured fields for reproduction context, severity, classification, ownership, and resolution status.
- The simulator SHALL be maintained as a first-class parity surface.
- Production mechanic changes that affect shared runtime behavior SHALL trigger simulator validation updates and, where necessary, simulator implementation updates.
- Fresh-run smoke, determinism, and parity contract validation SHALL be integrated into the regular testing pipeline so simulator drift is detected as production mechanics evolve.
- Performance monitoring SHALL capture runtime by phase, per-tick processing cost, bootstrap/reset cost, memory growth, and DB/query hotspots.
- Optimization SHALL not take precedence over parity in v1.
- Fresh-run outputs SHALL include both technical metrics and stakeholder-friendly summaries suitable for developers, analysts, and designers.
- Discrepancies between fresh-run and production outcomes SHALL follow a formal investigation flow and SHALL not be treated as documentation-only limitations when they materially affect interpretation.
- Fresh-run usability SHALL be supported by a runbook, example commands, output glossary, and interpretation guidance for non-technical users.
- Future enhancements such as multi-season simulation, migration modeling, richer behavior modeling, and optional fast-forward modes SHALL remain explicitly post-v1 unless needed to preserve core lifecycle fidelity.

## Additional Required Outputs

- action attempt/success/failure counts by action type and by archetype
- phase-by-phase source/sink breakdown for major economy resources
- lock-in timing distribution by tick/phase, not only final lock-in vs expiry totals
- archetype ROI across stars, boosts, sigils, and final standings
- ranking churn over time (top-N movement by phase)
- concentration metrics for key resources and outcomes (top 1%, 5%, 10%)
- runtime/performance metrics per run and per tick batch
- adapted-path and unmodeled-mechanics counts
- determinism fingerprint / reproducibility hash

## Stakeholder Outputs

Provide:
- concise run summary
- economy phase summary
- lock-in vs expiry summary
- archetype comparison table
- final outcome distribution summary
- known limitations / parity status section

## Report Interpretation Notes

Each fresh-run report MUST include:
- simulator version
- production commit/build reference
- deterministic seed
- cohort definition and size
- adapted paths
- unmodeled mechanics
- assumptions
- parity status
- interpretation constraints

Mechanics SHALL be labeled as one of:
- Modeled faithfully
- Adapted
- Approximated
- Not modeled

Assumptions and constraints MUST be clearly documented, especially where adapted or unmodeled mechanics exist. Interpretation of results MUST consider these factors, especially when comparing to production outcomes or making tuning decisions.

## Parity Bug Tracking

Each identified discrepancy SHOULD be recorded with:
- parity_issue_id
- discovered_at
- simulator_version
- production_commit
- seed
- cohort_size / cohort_mix
- mechanic_area
- expected_behavior
- observed_behavior
- severity
- classification (`parity_bug` or `unmodeled_mechanic`)
- owner
- status
- linked_fix

Parity bugs SHALL be highlighted with severity levels:
- Critical: materially affects core economy behavior, settlement, ranking, lock-in semantics, or expiry outcomes
- Major: affects secondary economy behavior or archetype outcomes but not core mechanics
- Minor: affects non-core behavior or metrics but not overall economy dynamics or player incentives

## Performance Monitoring

Capture at minimum:
- bootstrap duration
- total run duration
- per-phase duration
- per-tick processing duration
- action scheduling duration
- finalization/export duration
- peak memory usage
- DB query counts and slowest query groups

## Milestones and Acceptance Gates

### Milestone 1 - Safety + bootstrap
Deliver:
- simulation mode flag
- fail-closed DB safety validation
- required simulation/destructive-reset flags
- DB-name allowlist
- disposable DB bootstrap/reset
- optional teardown
- safety test coverage

Done when:
- fresh-run aborts on unsafe DB coordinates/config
- fresh-run aborts when required safety flags are missing
- fresh-run can bootstrap a clean local DB from schema + seed + migrations
- rerun from scratch works without touching protected environments

### Milestone 2 - Runtime parity foundation
Deliver:
- simulation clock adapter in `includes/game_time.php`
- explicit tick-driven entrypoint in `includes/tick_engine.php`
- proof that production behavior is unchanged when simulation mode is off
- default external dependency stubbing in fresh-run mode

Done when:
- simulator can drive tick explicitly
- production scheduler behavior remains unchanged with simulation mode off
- no default runtime regression is introduced

### Milestone 3 - Fresh lifecycle runner
Deliver:
- `scripts/simulate_fresh_lifecycle.php`
- `scripts/simulation/FreshLifecycleRunner.php`
- deterministic cohort generation
- deterministic policy-driven action scheduling
- season completion and finalization capture

Done when:
- one CLI command runs a full one-season lifecycle end to end
- fixed seed produces repeatable cohort generation and behavior
- smoke coverage can produce at least one lock-in and one expiry

### Milestone 4 - Metrics + reports
Deliver:
- extended `MetricsCollector.php`
- technical lifecycle outputs
- stakeholder summary outputs
- interpretation notes
- adapted/unmodeled metadata
- reproducibility hash

Done when:
- outputs include required lifecycle metrics
- outputs include stakeholder summary fields
- outputs record adapted paths and unmodeled mechanics
- outputs include simulator version, build ref, seed, cohort, and parity status

### Milestone 5 - Validation + CI
Deliver:
- smoke tests
- determinism tests
- parity contract tests
- backward compatibility checks for existing simulation modes
- CI integration for fresh-run validation

Done when:
- identical seed produces byte-identical outputs except explicitly allowed timestamp fields
- selected core economy contracts pass
- existing export/snapshot simulation modes remain unchanged
- simulator validation is part of the regular testing pipeline

## Non-Goals for Codex v1

Codex MUST NOT:
- add coarse fast-forward mechanics
- rebalance economy values
- change live scheduler semantics
- invent new player mechanics
- build multi-season support
- wire real third-party endpoints unless explicitly isolated/test-scoped
- perform broad production refactors beyond minimal parity-preserving seams

## First Execution Package for Codex

Codex should implement Milestone 1 first.

### Milestone 1 Scope
- add a dedicated simulation mode flag
- add fail-closed DB safety validation for fresh-run mode
- require explicit simulation/destructive-reset flags
- require a safe DB-name allowlist pattern for fresh-run DBs
- reject unsafe/non-local DB targets
- add disposable DB bootstrap/reset flow from schema + seed + migrations
- add optional teardown for repeatable reruns
- add tests proving unsafe DB targets abort before writes

### Milestone 1 Constraints
- preserve all current production/test behavior when simulation mode is off
- do not change existing export/snapshot simulation modes
- do not add fast-forward or economy tuning
- keep adapters minimal and localized
- fail closed on any ambiguous safety condition

### First Smoke Scenario
- fixed seed
- cohort size: 25
- mixed archetypes
- one season
- requires at least one join, one purchase, one lock-in, one expiry, and one finalization output

## Phase 6 — Simulation Optimization and Expansion

### 6.1 Output intelligence
- Add run-to-run delta comparison summaries
- Add automated imbalance detection heuristics
- Add recommendation generation based on target economy health metrics
- Add stakeholder summaries for tuning decisions

### 6.2 Safe performance optimization
- Introduce selective fast-forward for low-interaction phases only
- Add action-density-aware tick skipping safeguards
- Profile and reduce slow query groups and per-tick overhead
- Preserve full-fidelity simulation for lock-in, expiry, blackout, and finalization windows

### 6.3 Behavior model expansion
- Add adaptive archetype decision logic based on economy state, rankings, scarcity, and timing
- Add bounded deterministic stochastic variation within archetype envelopes
- Add configurable policy-interaction layers for aggression, hoarding, deployment timing, and risk response

### 6.4 Batch balancing workflows
- Add parameter sweep orchestration for economy tuning experiments
- Add A/B/C scenario comparison output
- Add ranked recommendations against declared balance goals
- Export machine-readable and stakeholder-friendly comparison reports

### 6.5 Extended lifecycle modeling
- Add optional multi-season simulation with carryover effects
- Add migration/retention modeling between seasons
- Add longitudinal economy health metrics across repeated seasons

### 6.6 Drift prevention and discrepancy automation
- Add CI parity gates for shared runtime surfaces
- Add automated discrepancy triage/report generation
- Add simulator drift alarms tied to production mechanic changes

### Future Implementations
- Add support for optional fast-forward mechanics in low-interaction phases while preserving full fidelity during critical windows.
- Expand behavior modeling with adaptive archetype logic and bounded stochastic variation.
-  Build parameter sweep orchestration for systematic economy tuning experiments.
- Add multi-season simulation support with migration and retention modeling.
- Implement CI parity gates and automated discrepancy triage to prevent simulator drift.
- Exploration of additional use cases for the fresh lifecycle simulator beyond tuning, such as design space exploration for new mechanics, player behavior research, and educational purposes, while maintaining a focus on parity and safety.
- Continuous refinement of outputs and interpretation guidance to maximize actionable insights for economy tuning and design decisions, while clearly communicating assumptions, limitations, and parity status.


## Final Note

This plan is intentionally limited to a robust, parity-focused fresh lifecycle simulator foundation. The initial scope is constrained so high-fidelity, deterministic outcomes can be established before adding complexity.
