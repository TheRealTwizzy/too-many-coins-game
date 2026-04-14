# Coupling Harness Report

Generated: 2026-04-14T14:29:41+00:00
Seed: `smoke-harness`
Overall status: `pass`
Selected families: star_affordability_pricing_instability

## Promotion Ladder

- This gate runs before tier2/tier3 promotion in the agentic ladder.
- A failing family is an early reject even if the local objective score looks good.

## Star affordability and pricing instability (`star_affordability_pricing_instability`)

- Status: `pass`
- Harness profile: `tier1_star_pricing`
- Simulators: B
- Estimated speedup vs tier3 full: 3600x
- Proves: The focused star-market slice did not show weaker affordability or more unstable price movement.
- Cannot prove: Does not prove production star-price behavior under the full player mix or full-duration seasons.
- Directional diagnostics:
  - `star_purchase_density` pass | baseline=145165.375 candidate=145165.375 delta=0 | star_purchase_density held by 0; goal-facing improvement=0 (need >= 0).
  - `first_choice_viability` pass | baseline=1 candidate=1 delta=0 | first_choice_viability held by 0; goal-facing improvement=0 (need >= 0).
  - `star_price_cap_share` pass | baseline=0 candidate=0 delta=0 | star_price_cap_share held by 0; goal-facing improvement=0 (need >= 0).
  - `star_price_range_ratio` pass | baseline=0.33251833740831 candidate=0.33251833740831 delta=0 | star_price_range_ratio held by 0; goal-facing improvement=0 (need >= 0).
