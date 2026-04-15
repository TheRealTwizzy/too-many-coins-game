# Rejection Attribution

- Scenario: `focused-single-starprice-max-upstep-950`
- Disposition: `reject`
- Generated: `2026-04-15T16:08:47+00:00`

## Control vs Baseline

- Wins: 6 | Losses: 9 | Mixed: 2
- Comparator note: Paired samples: 2 group comparisons across 2 simulator group(s).
- `B` B|ppa=2|seasons=1 | wins=3 losses=3 flags=dominant_archetype_shifted
- `C` C|ppa=2|seasons=4 | wins=3 losses=6 flags=dominant_archetype_shifted, long_run_concentration_worsened, skip_rejoin_exploit_worsened

## Changed Knobs

- `season.starprice_max_upstep_fp` => active | baseline=1000 | candidate=950

## Failed Gate

- Primary: `long_run_concentration_worsened` | Long-run concentration worsened
- Secondary: `skip_rejoin_exploit_worsened` | Skip/rejoin edge worsened
- Secondary: `dominant_archetype_shifted` | Dominant archetype changed

## Causal Ranking

- #1 `season.starprice_max_upstep_fp` | confidence=moderate | score=8.058
  rationale=Knob was active in the effective config. Its subsystem aligns with the primary failed gate.

## Uncertainty

- Interaction ambiguity: not explicit
- Note: One active knob changed, which narrows attribution, but the comparator still measures outcome rather than a direct causal counterfactual.
- Paired samples: 2 group comparisons across 2 simulator group(s).
- Evidence is sparse: only 2 paired simulator group(s) contributed to this rejection.
- A single active knob reduces bundle ambiguity, but this is still an attribution estimate rather than a counterfactual proof.
