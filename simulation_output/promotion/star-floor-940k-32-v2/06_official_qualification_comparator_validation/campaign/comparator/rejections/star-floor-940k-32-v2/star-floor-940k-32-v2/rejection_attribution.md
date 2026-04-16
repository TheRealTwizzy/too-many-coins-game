# Rejection Attribution

- Scenario: `star-floor-940k-32-v2`
- Disposition: `reject`
- Generated: `2026-04-15T23:22:51+00:00`

## Control vs Baseline

- Wins: 9 | Losses: 7 | Mixed: 2
- Comparator note: Paired samples: 2 group comparisons across 2 simulator group(s).
- `B` B|ppa=2|seasons=1 | wins=4 losses=3 flags=engagement_up_but_t6_supply_spike, lock_in_down_but_expiry_dominance_up
- `C` C|ppa=2|seasons=4 | wins=5 losses=4 flags=lock_in_down_but_expiry_dominance_up, long_run_concentration_worsened

## Changed Knobs

- `season.market_affordability_bias_fp` => active | baseline=970000 | candidate=940000
- `season.star_price_minimum_absolute` => active | baseline=1 | candidate=32

## Failed Gate

- Primary: `lock_in_down_but_expiry_dominance_up` | Lock-in weakened while expiry pressure rose
- Secondary: `long_run_concentration_worsened` | Long-run concentration worsened
- Secondary: `reduces_lock_in_but_expiry_dominance_rises` | Lock-in weakened while expiry pressure rose
- Secondary: `engagement_up_but_t6_supply_spike` | Engagement gain came with excess T6 supply
- Secondary: `improves_engagement_but_t6_supply_spikes` | Engagement gain came with excess T6 supply

## Causal Ranking

- #1 `season.market_affordability_bias_fp` | confidence=low | score=4.014
  rationale=Knob was active in the effective config. Its link to the primary gate is indirect. Bundle interaction ambiguity lowers confidence.
- #2 `season.star_price_minimum_absolute` | confidence=low | score=4.014
  rationale=Knob was active in the effective config. Its link to the primary gate is indirect. Bundle interaction ambiguity lowers confidence.

## Uncertainty

- Interaction ambiguity: present
- Note: 2 active knobs changed together, so the causal ranking is heuristic and interaction effects are not isolated.
- Paired samples: 2 group comparisons across 2 simulator group(s).
- Evidence is sparse: only 2 paired simulator group(s) contributed to this rejection.
- 2 active knobs changed together, so the causal ranking is heuristic and interaction effects are not isolated.
