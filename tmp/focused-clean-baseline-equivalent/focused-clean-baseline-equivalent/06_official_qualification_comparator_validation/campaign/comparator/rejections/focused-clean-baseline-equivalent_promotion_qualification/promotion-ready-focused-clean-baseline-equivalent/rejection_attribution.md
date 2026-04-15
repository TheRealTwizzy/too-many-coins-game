# Rejection Attribution

- Scenario: `promotion-ready-focused-clean-baseline-equivalent`
- Disposition: `reject`
- Generated: `2026-04-15T07:10:01+00:00`

## Control vs Baseline

- Wins: 7 | Losses: 7 | Mixed: 2
- Comparator note: Paired samples: 2 group comparisons across 2 simulator group(s).
- `B` B|ppa=2|seasons=1 | wins=3 losses=3 flags=dominant_archetype_shifted
- `C` C|ppa=2|seasons=4 | wins=4 losses=4 flags=dominant_archetype_shifted

## Changed Knobs

- `season.base_ubi_active_per_tick` => active | baseline=30 | candidate=30

## Failed Gate

- Primary: `seasonal_fairness_improves_but_long_run_concentration_worsens` | Seasonal fairness improved but long-run concentration regressed
- Secondary: `reduced_one_dominant_but_created_new_dominant` | One dominant strategy fell but another took over
- Secondary: `dominant_archetype_shifted` | Dominant archetype changed

## Causal Ranking

- #1 `season.base_ubi_active_per_tick` | confidence=moderate | score=10.8
  rationale=Knob was active in the effective config. Its key maps directly to the primary failed gate.

## Uncertainty

- Interaction ambiguity: not explicit
- Note: One active knob changed, which narrows attribution, but the comparator still measures outcome rather than a direct causal counterfactual.
- Paired samples: 2 group comparisons across 2 simulator group(s).
- Evidence is sparse: only 2 paired simulator group(s) contributed to this rejection.
- A single active knob reduces bundle ambiguity, but this is still an attribution estimate rather than a counterfactual proof.
- The primary failure is cross-simulator, so the report reflects an interaction-level regression instead of a single local metric threshold.
