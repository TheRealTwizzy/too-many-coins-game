# Rejection Attribution

- Scenario: `phase-gated-safe-24h-v1`
- Disposition: `reject`
- Generated: `2026-04-15T03:17:52+00:00`

## Control vs Baseline

- Wins: 6 | Losses: 9 | Mixed: 2
- Comparator note: Paired samples: 2 group comparisons across 2 simulator group(s).
- `B` B|ppa=2|seasons=1 | wins=4 losses=3 flags=dominant_archetype_shifted
- `C` C|ppa=2|seasons=4 | wins=2 losses=6 flags=dominant_archetype_shifted, skip_rejoin_exploit_worsened

## Changed Knobs

- `season.hoarding_safe_hours` => active | baseline=12 | candidate=24
- `season.hoarding_sink_enabled` => active | baseline=0 | candidate=1

## Failed Gate

- Primary: `skip_rejoin_exploit_worsened` | Skip/rejoin edge worsened
- Secondary: `candidate_improves_B_but_worsens_C` | Short-run gains flipped into long-run losses
- Secondary: `reduced_one_dominant_but_created_new_dominant` | One dominant strategy fell but another took over
- Secondary: `dominant_archetype_shifted` | Dominant archetype changed

## Causal Ranking

- #1 `season.hoarding_sink_enabled` | confidence=low | score=8.245
  rationale=Knob was active in the effective config. Its key maps directly to the primary failed gate. Bundle interaction ambiguity lowers confidence.
- #2 `season.hoarding_safe_hours` | confidence=low | score=6.545
  rationale=Knob was active in the effective config. Its subsystem aligns with the primary failed gate. Bundle interaction ambiguity lowers confidence.

## Uncertainty

- Interaction ambiguity: present
- Note: 2 active knobs changed together, so the causal ranking is heuristic and interaction effects are not isolated.
- Paired samples: 2 group comparisons across 2 simulator group(s).
- Evidence is sparse: only 2 paired simulator group(s) contributed to this rejection.
- 2 active knobs changed together, so the causal ranking is heuristic and interaction effects are not isolated.
