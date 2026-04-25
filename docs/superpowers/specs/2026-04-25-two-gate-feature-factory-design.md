# Two-Gate Feature Factory Design

## Purpose

Too Many Coins needs near-automation for conceiving, planning, designing, balancing, and implementing features and mechanics without letting automated work skip balance evidence or touch player-facing code prematurely.

The v1 system will create a guarded feature factory. It will turn a proposed feature or mechanic into simulation/config/mechanic scaffolding, balance reports, candidate templates, and implementation planning artifacts. It will not change player-facing runtime, API, frontend, deployment, or live-environment files until a mechanic-specific approval manifest explicitly allows that work.

## Goals

- Convert new feature and mechanic ideas into structured, reviewable artifacts.
- Require every mechanic to declare intended strategies, affected archetypes, counterplay, risks, tunable parameters, and success metrics.
- Generate simulation/config/mechanic scaffolding before runtime or UI changes.
- Use the existing canonical config, candidate validation, effective-config preflight, parity, playability, and promotion tooling.
- Block generated patches that touch player-facing paths before explicit approval.
- Balance pre-existing and newly-created mechanics together for equitable playability and multi-strategic competition.

## Non-Goals

- v1 will not add an in-game feature factory UI.
- v1 will not bypass existing simulation config integrity rules.
- v1 will not auto-promote changes to the sandbox or live repos.
- v1 will not add proposed new config keys to optimizer search until runtime parity exists.
- v1 will not change init or database bootstrap behavior unless a later approved feature explicitly requires it.

## Architecture

The v1 system is a Two-Gate Feature Factory that sits on top of the existing simulation and promotion tooling.

1. Conception Gate
   Normalize a mechanic idea into a machine-readable brief. The brief captures player fantasy, affected systems, intended strategies, counter-strategies, tunable parameters, expected risks, and success metrics.

2. Scaffolding Gate
   Generate only non-player-facing artifacts: simulation config notes, mechanic declarations, candidate patch templates, archetype expectations, balance gate definitions, parity fixture stubs, and implementation plan drafts.

3. Approval Gate
   Require an explicit approval artifact before touching player-facing runtime or UI files such as `includes/*.php`, `api/index.php`, `public/js/app.js`, `public/css/style.css`, or `public/index.html`.

4. Implementation Gate
   After approval, generate or apply runtime/API/frontend changes under the existing parity, candidate validation, playability, and promotion rules.

The core boundary is enforced by path classification. Scaffolding automation may write under automation output, docs, simulation/config planning surfaces, and generated bundle directories. It must fail if a generated patch touches player-facing PHP, API, JS, CSS, HTML, deployment, or live-environment files before approval.

## Components

### FeatureFactory

Owns the end-to-end workflow for a single proposed feature or mechanic. It reads a proposal, resolves defaults, validates scope, calls the scaffolder, classifies generated paths, and writes an automation bundle.

### MechanicBrief

A normalized JSON and Markdown artifact describing one mechanic. It includes:

- `mechanic_id`
- player-facing fantasy
- affected systems
- primary strategy served
- secondary strategies affected
- counterplay
- failure modes
- tunable parameters
- proposed new config keys, if any
- affected metrics
- balance risks
- approval requirements

### MechanicScaffolder

Generates simulation/config/mechanic-only artifacts first:

- candidate patch templates
- canonical schema notes
- parity fixture stubs
- balance gate definitions
- archetype impact expectations
- mechanic contract checklist
- implementation plan draft
- approval manifest example

### FeaturePatchClassifier

Inspects generated file paths and classifies each path as one of:

- `scaffolding`
- `simulation_config`
- `mechanic_contract`
- `runtime`
- `api`
- `frontend`
- `database`
- `deployment`
- `unknown`

Before approval, it blocks `runtime`, `api`, `frontend`, `deployment`, and `unknown`. Database changes are blocked by default in v1 unless a later implementation plan adds a stricter migration-specific approval path.

### ApprovalManifest

A JSON artifact that explicitly authorizes player-facing work for a specific mechanic. It includes:

- `mechanic_id`
- generated bundle hash
- allowed path classes
- allowed paths or path prefixes
- approval reason
- approver
- approval timestamp

Approval can allow runtime only, API only, frontend only, or a named combination. An approval for one mechanic or bundle hash does not authorize another.

### BalanceImpactModel

Connects the proposed mechanic to existing archetypes and metrics. It evaluates the mechanic against current competition goals and existing automation signals, including:

- `archetype_viability_min_ratio`
- `dominant_strategy_pressure`
- `strategic_diversity`
- `concentration_top10_share`
- `concentration_top1_share`
- `lock_in_timing_entropy`
- `boost_roi`
- `skip_strategy_edge`
- `repeat_season_viability`
- mechanic-specific metrics declared by the brief

## Data Flow

1. A proposal file describes a feature or mechanic idea.
2. `FeatureFactory` validates and normalizes the proposal into `mechanic_brief.json` and `mechanic_brief.md`.
3. `BalanceImpactModel` maps the mechanic to archetypes, strategy risks, and required metrics.
4. `MechanicScaffolder` writes the scaffolding bundle.
5. `FeaturePatchClassifier` classifies all generated or planned patch paths.
6. Pre-approval validation fails if blocked path classes are present.
7. Candidate templates are validated against the canonical economic candidate surface where possible.
8. Simulation runs, when invoked by later implementation work, must use the canonical effective-config resolver and emit `effective_config.json` and `effective_config_audit.md`.
9. Runtime/API/frontend implementation requires a valid `ApprovalManifest`.
10. After approval, later implementation work can generate guarded runtime/API/frontend plans or patches and must pass parity, playability, candidate validation, and promotion gates.

## Balance Rules

Every feature or mechanic must declare its strategic footprint before any patch is generated:

- Primary strategy served: the playstyle the mechanic is meant to strengthen.
- Secondary strategies affected: playstyles that may benefit accidentally.
- Counterplay: what other players can do in response.
- Failure modes: dominant strategy risk, dead mechanic risk, runaway leader risk, hoarding abuse, lock-in abuse, skip/rejoin abuse, onboarding harm, or UI confusion.
- Required metrics: at least one viability metric, one concentration or diversity metric, and one mechanic-specific metric.
- Archetype expectations: expected impact direction for existing archetypes such as hoarder, mostly idle, regular, hardcore, boost focused, star focused, early locker, late deployer, casual, and aggressive sigil user.

The scaffolding gate passes only if:

- The mechanic has a normalized brief.
- Every tunable key is known to the canonical config contract or explicitly marked as a proposed new key.
- Proposed new keys are excluded from optimizer search until runtime parity exists.
- Candidate patch templates lint cleanly against `EconomicCandidateValidator` when they only use existing patchable keys.
- Generated patch paths stay inside the allowed scaffolding/config/simulation surface.
- The bundle includes a balance-risk report.

## Approval Rules

Before an approval manifest exists, automation must not write or apply patches to:

- `includes/*.php`
- `api/index.php`
- `public/js/*.js`
- `public/css/*.css`
- `public/*.html`
- deployment files
- live/sandbox environment files
- migrations or schema files
- unknown path classes

The approval manifest must match the mechanic id and generated bundle hash. It must name the allowed path classes and, for player-facing code, the exact path prefixes or files. If the generated patch changes outside the approved surface, validation fails.

## Bundle Output

Each v1 run should produce a feature automation bundle under:

`simulation_output/feature_factory/<mechanic_id>/`

The bundle should contain:

- `mechanic_brief.json`
- `mechanic_brief.md`
- `balance_impact_report.json`
- `balance_impact_report.md`
- `candidate_patch_template.json`
- `mechanic_contract_checklist.md`
- `approval_manifest.example.json`
- `implementation_plan_draft.md`
- `patch_classification.json`

## Error Handling

- Missing required mechanic declarations fail brief validation.
- Unknown config keys fail unless explicitly declared as proposed new keys.
- Proposed new keys fail optimizer-search eligibility until parity scaffolding and runtime approval exist.
- Pre-approval path classification fails on runtime, API, frontend, deployment, database, or unknown path classes.
- Candidate templates using existing keys fail if `EconomicCandidateValidator` rejects them.
- Simulation commands must fail if they do not produce the required effective-config audit artifacts.
- Approval manifests fail if the mechanic id, bundle hash, allowed class, or allowed path does not match the generated work.

## Testing Strategy

Focused tests should cover:

- Brief validation rejects missing strategy, counterplay, metrics, or risk declarations.
- Patch classification blocks runtime/API/frontend/deployment paths before approval.
- Unknown config keys fail unless marked as proposed new keys.
- Proposed new keys cannot enter optimizer search without parity scaffolding.
- Approval manifests are mechanic-specific and bundle-hash-specific.
- Generated existing-key candidate templates pass canonical candidate validation before simulation.
- Any simulation command used by the workflow preserves the effective-config audit requirement.

## Integration With Existing Rules

The feature factory must preserve the existing repo and release model:

- Feature work starts in source/dev.
- Approved builds go to test first.
- Live promotion happens only after tested approval.
- Deployment changes remain minimal.
- Sandbox and live environment values are not mixed.
- Simulation runs must pass through the canonical effective-config resolver.
- Unknown config keys are errors, not warnings.
- Candidate patches must be validated against the canonical schema before execution.

## First Implementation Boundary

The first implementation should be CLI-oriented. It should take a proposal JSON file and generate the bundle. Browser/UI automation can be added later after the file-based workflow is trustworthy.

The first implementation plan should avoid changing player-facing runtime/API/frontend code. It should create the feature factory scaffolding engine, tests, and documentation needed to make later mechanic work safer and more automatic.
