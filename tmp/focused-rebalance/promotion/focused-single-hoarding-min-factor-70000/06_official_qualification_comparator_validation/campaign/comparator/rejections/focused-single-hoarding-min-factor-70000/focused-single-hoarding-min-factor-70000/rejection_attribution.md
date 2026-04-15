# Rejection Attribution

- Scenario: `focused-single-hoarding-min-factor-70000`
- Disposition: `reject`
- Generated: `2026-04-15T16:08:55+00:00`

## Control vs Baseline

- Wins: 7 | Losses: 9 | Mixed: 2
- Comparator note: Paired samples: 2 group comparisons across 2 simulator group(s).
- `B` B|ppa=2|seasons=1 | wins=3 losses=4 flags=dominant_archetype_shifted, lock_in_down_but_expiry_dominance_up
- `C` C|ppa=2|seasons=4 | wins=4 losses=5 flags=dominant_archetype_shifted

## Changed Knobs

- `season.hoarding_min_factor_fp` => active | baseline=90000 | candidate=70000

## Failed Gate

- Primary: `lock_in_down_but_expiry_dominance_up` | Lock-in weakened while expiry pressure rose
- Secondary: `reduces_lock_in_but_expiry_dominance_rises` | Lock-in weakened while expiry pressure rose
- Secondary: `reduced_one_dominant_but_created_new_dominant` | One dominant strategy fell but another took over
- Secondary: `dominant_archetype_shifted` | Dominant archetype changed

## Causal Ranking

- #1 `season.hoarding_min_factor_fp` | confidence=moderate | score=12.6
  rationale=Knob was active in the effective config. Its key maps directly to the primary failed gate.

## Uncertainty

- Interaction ambiguity: not explicit
- Note: One active knob changed, which narrows attribution, but the comparator still measures outcome rather than a direct causal counterfactual.
- Paired samples: 2 group comparisons across 2 simulator group(s).
- Evidence is sparse: only 2 paired simulator group(s) contributed to this rejection.
- A single active knob reduces bundle ambiguity, but this is still an attribution estimate rather than a counterfactual proof.
