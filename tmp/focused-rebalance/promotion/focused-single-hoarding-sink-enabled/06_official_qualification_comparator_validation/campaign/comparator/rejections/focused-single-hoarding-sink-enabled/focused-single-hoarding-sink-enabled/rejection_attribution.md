# Rejection Attribution

- Scenario: `focused-single-hoarding-sink-enabled`
- Disposition: `reject`
- Generated: `2026-04-15T16:09:37+00:00`

## Control vs Baseline

- Wins: 7 | Losses: 8 | Mixed: 2
- Comparator note: Paired samples: 2 group comparisons across 2 simulator group(s).
- `B` B|ppa=2|seasons=1 | wins=4 losses=3 flags=dominant_archetype_shifted, lock_in_down_but_expiry_dominance_up
- `C` C|ppa=2|seasons=4 | wins=3 losses=5 flags=dominant_archetype_shifted, long_run_concentration_worsened, skip_rejoin_exploit_worsened

## Changed Knobs

- `season.hoarding_sink_enabled` => active | baseline=0 | candidate=1

## Failed Gate

- Primary: `lock_in_down_but_expiry_dominance_up` | Lock-in weakened while expiry pressure rose
- Secondary: `skip_rejoin_exploit_worsened` | Skip/rejoin edge worsened
- Secondary: `long_run_concentration_worsened` | Long-run concentration worsened
- Secondary: `seasonal_fairness_improves_but_long_run_concentration_worsens` | Seasonal fairness improved but long-run concentration regressed
- Secondary: `reduces_lock_in_but_expiry_dominance_rises` | Lock-in weakened while expiry pressure rose
- Secondary: `candidate_improves_B_but_worsens_C` | Short-run gains flipped into long-run losses
- Secondary: `reduced_one_dominant_but_created_new_dominant` | One dominant strategy fell but another took over
- Secondary: `dominant_archetype_shifted` | Dominant archetype changed

## Causal Ranking

- #1 `season.hoarding_sink_enabled` | confidence=moderate | score=17.1
  rationale=Knob was active in the effective config. Its key maps directly to the primary failed gate.

## Uncertainty

- Interaction ambiguity: not explicit
- Note: One active knob changed, which narrows attribution, but the comparator still measures outcome rather than a direct causal counterfactual.
- Paired samples: 2 group comparisons across 2 simulator group(s).
- Evidence is sparse: only 2 paired simulator group(s) contributed to this rejection.
- A single active knob reduces bundle ambiguity, but this is still an attribution estimate rather than a counterfactual proof.
