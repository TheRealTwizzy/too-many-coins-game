# Rejection Attribution

- Scenario: `stage1-starprice_max_upstep_fp-03`
- Disposition: `reject`
- Generated: `2026-04-15T08:13:19+00:00`

## Control vs Baseline

- Wins: 7 | Losses: 9 | Mixed: 2
- Comparator note: Paired samples: 2 group comparisons across 2 simulator group(s).
- `B` B|ppa=2|seasons=1 | wins=2 losses=5 flags=engagement_up_but_t6_supply_spike, lock_in_down_but_expiry_dominance_up
- `C` C|ppa=2|seasons=4 | wins=5 losses=4 flags=dominant_archetype_shifted, long_run_concentration_worsened

## Changed Knobs

- `season.starprice_max_upstep_fp` => active | baseline=1000 | candidate=750

## Failed Gate

- Primary: `lock_in_down_but_expiry_dominance_up` | Lock-in weakened while expiry pressure rose
- Secondary: `long_run_concentration_worsened` | Long-run concentration worsened
- Secondary: `engagement_up_but_t6_supply_spike` | Engagement gain came with excess T6 supply
- Secondary: `reduces_lock_in_but_expiry_dominance_rises` | Lock-in weakened while expiry pressure rose
- Secondary: `improves_engagement_but_t6_supply_spikes` | Engagement gain came with excess T6 supply
- Secondary: `dominant_archetype_shifted` | Dominant archetype changed

## Causal Ranking

- #1 `season.starprice_max_upstep_fp` | confidence=low | score=7.623
  rationale=Knob was active in the effective config. Its link to the primary gate is indirect.

## Uncertainty

- Interaction ambiguity: not explicit
- Note: One active knob changed, which narrows attribution, but the comparator still measures outcome rather than a direct causal counterfactual.
- Paired samples: 2 group comparisons across 2 simulator group(s).
- Evidence is sparse: only 2 paired simulator group(s) contributed to this rejection.
- A single active knob reduces bundle ambiguity, but this is still an attribution estimate rather than a counterfactual proof.
