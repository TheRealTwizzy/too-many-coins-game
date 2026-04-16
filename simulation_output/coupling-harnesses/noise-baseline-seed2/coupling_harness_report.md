# Coupling Harness Report

Generated: 2026-04-15T23:05:58+00:00
Seed: `noise-baseline-seed2`
Overall status: `pass`
Selected families: hoarding_pressure_imbalance, skip_rejoin_exploit_worsened

## Promotion Ladder

- This gate runs before tier2/tier3 promotion in the agentic ladder.
- A failing family is an early reject even if the local objective score looks good.

## Hoarding pressure imbalance (`hoarding_pressure_imbalance`)

- Status: `pass`
- Harness profile: `coupling_hoarding`
- Simulators: B, C
- Estimated speedup vs tier3 full: 40x
- Proves: The cheap anti-hoarding harness did not show the candidate worsening safe-strategy dominance.
- Cannot prove: Does not prove cross-subsystem pricing or retention interactions remain healthy at full scale.
- Directional diagnostics:
  - `hoarder_advantage_gap` pass | baseline=0 candidate=0 delta=0 | hoarder_advantage_gap held by 0; goal-facing improvement=0 (need >= 0).
  - `dominant_strategy_pressure` pass | baseline=0.66144414168937 candidate=0.66144414168937 delta=0 | dominant_strategy_pressure held by 0; goal-facing improvement=0 (need >= 0).
  - `strategic_diversity` pass | baseline=0.76947224114677 candidate=0.76947224114677 delta=0 | strategic_diversity held by 0; goal-facing improvement=0 (need >= 0).

## Skip/rejoin exploit worsened (`skip_rejoin_exploit_worsened`)

- Status: `pass`
- Harness profile: `coupling_skip_rejoin`
- Simulators: C
- Estimated speedup vs tier3 full: 80x
- Proves: Short lifecycle retention dynamics did not make the skip/rejoin exploit materially stronger.
- Cannot prove: Does not prove final long-run concentration is globally acceptable outside this reduced harness.
- Directional diagnostics:
  - `skip_strategy_edge` pass | baseline=5910 candidate=5910 delta=0 | skip_strategy_edge held by 0; goal-facing improvement=0 (need >= 0).
  - `repeat_season_viability` pass | baseline=0.33333333333333 candidate=0.33333333333333 delta=0 | repeat_season_viability held by 0; goal-facing improvement=0 (need >= 0).
  - `throughput_lock_in_rate` pass | baseline=1 candidate=1 delta=0 | throughput_lock_in_rate held by 0; goal-facing improvement=0 (need >= 0).
