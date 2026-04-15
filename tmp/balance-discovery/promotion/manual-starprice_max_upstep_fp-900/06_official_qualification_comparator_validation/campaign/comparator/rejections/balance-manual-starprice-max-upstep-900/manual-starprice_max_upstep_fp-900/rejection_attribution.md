# Rejection Attribution

- Scenario: `manual-starprice_max_upstep_fp-900`
- Disposition: `reject`
- Generated: `2026-04-15T08:17:34+00:00`

## Control vs Baseline

- Wins: 6 | Losses: 10 | Mixed: 2
- Comparator note: Paired samples: 2 group comparisons across 2 simulator group(s).
- `B` B|ppa=2|seasons=1 | wins=3 losses=4 flags=dominant_archetype_shifted, engagement_up_but_t6_supply_spike, lock_in_down_but_expiry_dominance_up
- `C` C|ppa=2|seasons=4 | wins=3 losses=6 flags=long_run_concentration_worsened

## Changed Knobs

- `season.starprice_max_upstep_fp` => active | baseline=1000 | candidate=900

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
