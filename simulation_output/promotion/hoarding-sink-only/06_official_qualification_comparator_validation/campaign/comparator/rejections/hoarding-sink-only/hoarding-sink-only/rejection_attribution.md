# Rejection Attribution

- Scenario: `hoarding-sink-only`
- Disposition: `reject`
- Generated: `2026-04-15T23:32:22+00:00`

## Control vs Baseline

- Wins: 10 | Losses: 6 | Mixed: 2
- Comparator note: Paired samples: 2 group comparisons across 2 simulator group(s).
- `B` B|ppa=2|seasons=1 | wins=3 losses=4 flags=none
- `C` C|ppa=2|seasons=4 | wins=7 losses=2 flags=long_run_concentration_worsened

## Changed Knobs

- `season.hoarding_safe_hours` => active | baseline=12 | candidate=24
- `season.hoarding_sink_enabled` => active | baseline=0 | candidate=1

## Failed Gate

- Primary: `long_run_concentration_worsened` | Long-run concentration worsened

## Causal Ranking

- #1 `season.hoarding_safe_hours` | confidence=low | score=4.25
  rationale=Knob was active in the effective config. Its subsystem aligns with the primary failed gate. Bundle interaction ambiguity lowers confidence.
- #2 `season.hoarding_sink_enabled` | confidence=low | score=4.25
  rationale=Knob was active in the effective config. Its subsystem aligns with the primary failed gate. Bundle interaction ambiguity lowers confidence.

## Uncertainty

- Interaction ambiguity: present
- Note: 2 active knobs changed together, so the causal ranking is heuristic and interaction effects are not isolated.
- Paired samples: 2 group comparisons across 2 simulator group(s).
- Evidence is sparse: only 2 paired simulator group(s) contributed to this rejection.
- 2 active knobs changed together, so the causal ranking is heuristic and interaction effects are not isolated.
