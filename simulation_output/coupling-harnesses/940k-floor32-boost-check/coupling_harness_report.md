# Coupling Harness Report

Generated: 2026-04-15T23:14:07+00:00
Seed: `940k-floor32-boost-check`
Overall status: `fail`
Selected families: boost_underperformance

## Promotion Ladder

- This gate runs before tier2/tier3 promotion in the agentic ladder.
- A failing family is an early reject even if the local objective score looks good.

## Boost underperformance (`boost_underperformance`)

- Status: `fail`
- Harness profile: `coupling_boost`
- Simulators: B
- Estimated speedup vs tier3 full: 320x
- Proves: The reduced boost harness did not show the candidate weakening boost payoff or deployment timing.
- Cannot prove: Does not prove boost changes remain neutral once all other subsystems mutate together.
- Directional diagnostics:
  - `boost_roi` fail | baseline=18.41000091416 candidate=18.403967455892 delta=-0.006033458268579 | boost_roi fell by 0.006033; goal-facing improvement=-0.006033 (need >= 0).
  - `boost_mid_late_share` pass | baseline=0.66015771686068 candidate=0.66015771686068 delta=0 | boost_mid_late_share held by 0; goal-facing improvement=0 (need >= 0).
  - `boost_focused_gap` pass | baseline=2781 candidate=2781 delta=0 | boost_focused_gap held by 0; goal-facing improvement=0 (need >= 0).
