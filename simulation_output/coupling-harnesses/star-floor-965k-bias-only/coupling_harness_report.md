# Coupling Harness Report

Generated: 2026-04-15T22:43:34+00:00
Seed: `star-floor-965k-bias-only`
Overall status: `fail`
Selected families: star_affordability_pricing_instability

## Promotion Ladder

- This gate runs before tier2/tier3 promotion in the agentic ladder.
- A failing family is an early reject even if the local objective score looks good.

## Star affordability and pricing instability (`star_affordability_pricing_instability`)

- Status: `fail`
- Harness profile: `coupling_star_pricing`
- Simulators: B
- Estimated speedup vs tier3 full: 320x
- Proves: The focused star-market slice did not show weaker affordability or more unstable price movement.
- Cannot prove: Does not prove production star-price behavior under the full player mix or full-duration seasons.
- Directional diagnostics:
  - `star_purchase_density` pass | baseline=6659.3333333333 candidate=7394 delta=734.66666666667 | star_purchase_density rose by 734.666667; goal-facing improvement=734.666667 (need >= 0).
  - `first_choice_viability` pass | baseline=1 candidate=1 delta=0 | first_choice_viability held by 0; goal-facing improvement=0 (need >= 0).
  - `star_price_cap_share` pass | baseline=0 candidate=0 delta=0 | star_price_cap_share held by 0; goal-facing improvement=0 (need >= 0).
  - `star_price_range_ratio` fail | baseline=2.0268163392579 candidate=2.549889135255 delta=0.52307279599712 | star_price_range_ratio rose by 0.523073; goal-facing improvement=-0.523073 (need >= 0).
