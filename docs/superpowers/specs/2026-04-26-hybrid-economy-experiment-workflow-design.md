# Hybrid Economy Experiment Workflow Design

## Purpose

Too Many Coins needs a fast, safe workflow for creating, simulating, tuning, removing, and lightly implementing economy mechanics while preserving a fun, balanced, competitive, multi-strategy, playable end-to-end economy.

The workflow should let small ideas move quickly as bite-sized capsules, while still supporting larger changes through a split economy and experience process. It must preserve the existing simulation config integrity rules, feature factory approval model, release discipline, and deployment separation.

## Goals

- Treat new mechanics, removals, nerfs, config-only rebalances, and tiny quality-of-life gameplay/UI changes as first-class experiment types.
- Keep the default loop small, fast, and reviewable.
- Allow a capsule to carry one or a few tiny gameplay/UI implementation changes when they improve quality of life and do not destabilize core loops.
- Support larger work through coordinated economy and experience lanes.
- Require economy evidence and experience evidence before player-facing changes proceed.
- Preserve canonical config validation, effective-config audit artifacts, simulation preflight, parity gates, feature-factory path classification, approval manifests, and promotion gates.
- Keep optimization in mind without letting optimizer search include proposed, inactive, unproven, or no-op keys.

## Non-Goals

- This design does not bypass the existing feature factory or simulation promotion pipeline.
- This design does not permit casual edits to runtime, API, frontend, database, deployment, sandbox, or live environment files.
- This design does not auto-promote work to test or live.
- This design does not change init or database bootstrap behavior.
- This design does not make large UI or gameplay features eligible for capsule mode.

## Workflow Modes

### Capsule Mode

Capsule Mode is the default. Every experiment starts here.

A capsule may include:

- one config-only rebalance
- one small mechanic addition
- one mechanic removal, disable, or nerf
- one economy change plus one to three tiny quality-of-life gameplay/UI changes
- one cleanup of a damaging old mechanic plus evidence that strategy diversity is not harmed

Capsules should produce compact, reviewable artifacts:

- experiment brief
- mechanic or removal brief
- candidate config patch
- removal or deprecation notes, when relevant
- balance impact report
- simulation gate plan and results
- tiny player-facing patch plan, when approved
- rollback notes
- final decision record

### Dual-Lane Mode

Dual-Lane Mode is for larger or more coupled work. A capsule graduates to dual-lane when the change touches multiple core loops, needs multiple UI surfaces, introduces a new resource/sink/source family, changes onboarding, or requires runtime/API/frontend/database work that cannot be reviewed as a tiny patch.

Dual-lane work splits into:

- Economy Lane: config, simulation, candidate validation, parity, balance, promotion readiness.
- Experience Lane: gameplay feel, UI clarity, quality-of-life changes, interaction polish, screenshots or manual QA where needed.

The lanes may proceed in parallel, but they merge only at a shared checkpoint after both evidence sets pass.

## Core Rule

No player-facing change ships unless the economy evidence and experience evidence both pass.

For capsule mode, this means the small player-facing change must remain tiny, reversible, quality-of-life oriented, explicitly approved, and supported by passing economy gates.

For dual-lane mode, this means the economy lane and experience lane both need their own pass evidence before the work can be considered ready for promotion.

## Components

### Experiment Brief

The experiment brief extends the current feature-factory mechanic brief so it can describe:

- new mechanics
- removals
- nerfs
- disables
- config-only rebalances
- tiny quality-of-life gameplay/UI changes
- combined economy and experience capsules

The brief declares:

- experiment id
- mode preference
- player-facing intent
- economy hypothesis
- affected systems
- primary strategy served
- secondary strategies affected
- counterplay
- failure modes
- tunable parameters
- proposed new config keys
- removal or deprecation target, if any
- affected archetypes
- required metrics
- player-facing path request, if any
- rollback notes

### Capsule Runner

The capsule runner is the fast path. It generates the feature-factory bundle, validates candidate keys, runs the required simulation gates, records evidence, and decides whether the capsule passes, fails, needs revision, graduates to dual-lane, or should be abandoned.

The runner must use the existing canonical config and effective-config preflight behavior. It must not run simulations that fail to produce `effective_config.json` and `effective_config_audit.md`.

### Dual-Lane Coordinator

The dual-lane coordinator splits a larger experiment into economy and experience lanes, tracks their evidence separately, and blocks merge until both lanes pass.

The coordinator does not weaken capsule rules. It exists to keep larger work organized when capsule mode would hide too much coupling.

### Experience Gate

The experience gate guards tiny gameplay/UI work. It checks that the player-facing changes:

- are small enough for capsule mode or explicitly routed to dual-lane mode
- improve quality of life, clarity, engagement, or flow
- do not change core loops unless declared and approved
- do not alter economy behavior without an economy hypothesis
- are reversible
- have focused QA or test evidence

### Shared Final Gate

The shared final gate blocks readiness unless all required evidence exists:

- candidate config validation passed
- effective-config audit artifacts were generated
- simulation gates passed or produced an accepted revise/split decision
- parity requirements are satisfied for any new or changed mechanic
- player-facing path approval exists when needed
- experience evidence passed
- rollback notes exist
- promotion discipline is preserved

## Data Flow

1. The idea enters as an experiment brief.
2. The workflow starts in Capsule Mode.
3. The brief is normalized into mechanic, removal, config, and experience declarations.
4. The feature factory generates scaffolding artifacts.
5. Candidate config patches are validated against the canonical schema.
6. Unknown, deprecated, disabled, inactive, shadowed, or out-of-surface keys fail hard.
7. Simulations run only through the canonical effective-config resolver.
8. Every simulation run must emit `effective_config.json` and `effective_config_audit.md`.
9. Tiny gameplay/UI changes require explicit approval for player-facing paths.
10. If the capsule is too broad or too coupled, it graduates to Dual-Lane Mode.
11. Dual-lane economy and experience work merge only at the shared final gate.
12. Approved builds go to test first. Live promotion remains separate and requires tested approval.

## Removal Handling

Removals are first-class experiments.

Removing, disabling, hiding, or nerfing an old mechanic can destabilize the economy just as much as adding one. Every removal must declare:

- target mechanic or config key
- reason for removal
- suspected harm
- affected strategies
- affected archetypes
- replacement behavior, if any
- expected balance impact
- rollback path
- simulation evidence

If a removal touches a no-op, inactive, shadowed, or disabled key, the experiment should record that finding explicitly and avoid pretending the removal changed the live economy.

## Chunk Size Rules

Capsule mode remains valid only when the work is small enough to review and test quickly.

Capsule-friendly work:

- one mechanic
- one removal
- one config patch
- one to three tiny gameplay/UI changes
- one small user-facing clarity or flow improvement

Dual-lane required:

- multiple core loop changes
- multiple UI surfaces
- new resource family
- new sink/source family
- onboarding changes
- database or migration work
- multi-file runtime/API/frontend implementation
- unclear economy impact
- unclear player-facing risk

## Safety Rules

- Core loops cannot change without explicit approval and parity evidence.
- Old mechanics are removed through removal capsules, not casual cleanup.
- Unknown config keys are errors, not warnings.
- Candidate patches must validate against the canonical schema before execution.
- Simulations must pass through the canonical effective-config resolver.
- Missing effective-config artifacts invalidate the run.
- Proposed new keys stay out of optimizer search until runtime/simulation parity exists.
- Quality-of-life changes cannot secretly become economy changes.
- UI/gameplay changes must be reversible and easy to test.
- Rollback notes are required before implementation proceeds.
- Sandbox and live environment values must not mix.
- Deployment changes remain minimal and separate.

## Error Handling

The workflow fails early for:

- unknown config keys
- deprecated config keys
- disabled subsystem keys
- inactive candidate keys
- shadowed candidate keys
- out-of-surface candidate keys
- type mismatches
- range violations
- candidate patches that do not validate
- simulations missing effective-config artifacts
- player-facing file changes without approval
- quality-of-life changes that alter economy behavior without declaration
- removal work with no rollback notes
- removal work with no strategy-impact evidence

Failures should produce a compact decision record with one of:

- `pass`
- `fail`
- `revise`
- `split_to_dual_lane`
- `abandon`

## Testing Strategy

Testing scales with risk.

Config-only capsule:

- candidate lint
- simulation preflight
- focused simulation or comparator output
- effective-config audit artifact check

Mechanic addition or removal:

- candidate lint
- simulation preflight
- parity tests
- targeted subsystem harnesses
- promotion gate checks
- balance impact report

Tiny gameplay/UI quality-of-life change:

- path approval
- focused browser or manual QA
- existing frontend tests, when available
- basic usability evidence

Dual-lane feature:

- all relevant economy-lane evidence
- all relevant experience-lane evidence
- shared final gate decision

## Integration With Existing Systems

This workflow builds on the existing feature factory and simulation suite.

It relies on:

- `scripts/generate_feature_factory_bundle.php`
- `scripts/feature_factory/*`
- `scripts/simulation/CanonicalEconomyConfigContract.php`
- `scripts/simulation/EconomicCandidateValidator.php`
- `scripts/simulation/SimulationConfigPreflight.php`
- `scripts/simulation/CandidatePromotionPipeline.php`
- `scripts/simulation/RuntimeParityCertification.php`
- `docs/SIMULATION_MECHANIC_INTEGRATION_CONTRACT.md`
- `SIMULATION_RUNBOOK.md`
- `ECONOMY_CONFIG_COMPATIBILITY.md`

The existing release discipline remains authoritative:

- feature work starts in source/dev
- approved builds go to test first
- live promotion happens only after tested approval
- deployment changes stay minimal
- init/db behavior is preserved unless explicitly approved
- sandbox and live environment values are not mixed

## First Implementation Boundary

The first implementation should be CLI-first and documentation-first.

It should add experiment-brief validation, capsule mode orchestration, a small experience gate, dual-lane split metadata, and decision-record output. It should not immediately change runtime/API/frontend behavior.

The first implementation may update feature-factory scaffolding so generated bundles can represent both new mechanics and removals, plus tiny approved player-facing QoL plans.

Runtime, API, frontend, database, migration, deployment, sandbox, and live changes remain blocked until a later approved implementation plan creates the required approval and validation path.

## Success Criteria

The workflow succeeds when the team can quickly move from idea to evidence to small approved implementation without weakening economy safety.

A successful experiment should answer:

- What are we changing?
- Why should this improve fun, balance, competition, or clarity?
- Which strategies and archetypes are affected?
- What could go wrong?
- Which config keys are touched?
- Did canonical validation pass?
- Did effective-config artifacts exist?
- Did simulation evidence support the change?
- Did the player-facing experience remain small, clear, and reversible?
- Should this pass, fail, revise, split, or be abandoned?
