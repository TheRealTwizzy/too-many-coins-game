# Coupling Harness Report

Generated: 2026-04-15T22:46:45+00:00
Seed: `star-floor-940k-32`
Overall status: `pass`
Selected families: star_affordability_pricing_instability

## Promotion Ladder

- This gate runs before tier2/tier3 promotion in the agentic ladder.
- A failing family is an early reject even if the local objective score looks good.

## Star affordability and pricing instability (`star_affordability_pricing_instability`)

- Status: `pass`
- Harness profile: `coupling_star_pricing`
- Simulators: B
- Estimated speedup vs tier3 full: 320x
- Proves: The focused star-market slice did not show weaker affordability or more unstable price movement.
- Cannot prove: Does not prove production star-price behavior under the full player mix or full-duration seasons.
- Directional diagnostics:
  - `star_purchase_density` pass | baseline=6659.3333333333 candidate=6662 delta=2.666666666667 | star_purchase_density rose by 2.666667; goal-facing improvement=2.666667 (need >= 0).
  - `first_choice_viability` pass | baseline=1 candidate=1 delta=0 | first_choice_viability held by 0; goal-facing improvement=0 (need >= 0).
  - `star_price_cap_share` pass | baseline=0 candidate=0 delta=0 | star_price_cap_share held by 0; goal-facing improvement=0 (need >= 0).
  - `star_price_range_ratio` pass | baseline=2.0268163392579 candidate=1.9356852950359 delta=-0.09113104422197 | star_price_range_ratio fell by 0.091131; goal-facing improvement=0.091131 (need >= 0).
