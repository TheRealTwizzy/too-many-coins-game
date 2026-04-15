# Rejection Attribution

- Scenario: `focused-pair-starprice950-hoardingmin70000`
- Disposition: `reject`
- Generated: `2026-04-15T16:16:59+00:00`

## Control vs Baseline

- Wins: 3 | Losses: 13 | Mixed: 2
- Comparator note: Paired samples: 2 group comparisons across 2 simulator group(s).
- `B` B|ppa=2|seasons=1 | wins=1 losses=6 flags=dominant_archetype_shifted, lock_in_down_but_expiry_dominance_up
- `C` C|ppa=2|seasons=4 | wins=2 losses=7 flags=lock_in_down_but_expiry_dominance_up, long_run_concentration_worsened, skip_rejoin_exploit_worsened

## Changed Knobs

- `season.hoarding_min_factor_fp` => active | baseline=90000 | candidate=70000
- `season.starprice_max_upstep_fp` => active | baseline=1000 | candidate=950

## Failed Gate

- Primary: `lock_in_down_but_expiry_dominance_up` | Lock-in weakened while expiry pressure rose
- Secondary: `long_run_concentration_worsened` | Long-run concentration worsened
- Secondary: `seasonal_fairness_improves_but_long_run_concentration_worsens` | Seasonal fairness improved but long-run concentration regressed
- Secondary: `skip_rejoin_exploit_worsened` | Skip/rejoin edge worsened
- Secondary: `reduces_lock_in_but_expiry_dominance_rises` | Lock-in weakened while expiry pressure rose
- Secondary: `dominant_archetype_shifted` | Dominant archetype changed

## Causal Ranking

- #1 `season.hoarding_min_factor_fp` | confidence=low | score=11.305
  rationale=Knob was active in the effective config. Its key maps directly to the primary failed gate. Bundle interaction ambiguity lowers confidence.
- #2 `season.starprice_max_upstep_fp` | confidence=low | score=9.909
  rationale=Knob was active in the effective config. Its key maps directly to the primary failed gate. Bundle interaction ambiguity lowers confidence.

## Uncertainty

- Interaction ambiguity: present
- Note: 2 active knobs changed together, so the causal ranking is heuristic and interaction effects are not isolated.
- Paired samples: 2 group comparisons across 2 simulator group(s).
- Evidence is sparse: only 2 paired simulator group(s) contributed to this rejection.
- 2 active knobs changed together, so the causal ranking is heuristic and interaction effects are not isolated.
