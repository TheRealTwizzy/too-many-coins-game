# Rejection Attribution

- Scenario: `phase-gated-safe-24h-v1`
- Disposition: `reject`
- Generated: `2026-04-15T02:27:15+00:00`

## Control vs Baseline

- Wins: 8 | Losses: 6 | Mixed: 2
- Comparator note: Paired samples: 2 group comparisons across 2 simulator group(s).
- `B` B|ppa=2|seasons=1 | wins=3 losses=3 flags=dominant_archetype_shifted
- `C` C|ppa=2|seasons=4 | wins=5 losses=3 flags=dominant_archetype_shifted

## Changed Knobs

- `season.hoarding_safe_hours` => active | baseline=12 | candidate=24
- `season.hoarding_sink_enabled` => active | baseline=1 | candidate=1

## Failed Gate

- Primary: `reduced_one_dominant_but_created_new_dominant` | One dominant strategy fell but another took over
- Secondary: `dominant_archetype_shifted` | Dominant archetype changed

## Causal Ranking

- #1 `season.hoarding_safe_hours` | confidence=low | score=5.015
  rationale=Knob was active in the effective config. Its subsystem aligns with the primary failed gate. Bundle interaction ambiguity lowers confidence.
- #2 `season.hoarding_sink_enabled` | confidence=low | score=5.015
  rationale=Knob was active in the effective config. Its subsystem aligns with the primary failed gate. Bundle interaction ambiguity lowers confidence.

## Uncertainty

- Interaction ambiguity: present
- Note: 2 active knobs changed together, so the causal ranking is heuristic and interaction effects are not isolated.
- Paired samples: 2 group comparisons across 2 simulator group(s).
- Evidence is sparse: only 2 paired simulator group(s) contributed to this rejection.
- 2 active knobs changed together, so the causal ranking is heuristic and interaction effects are not isolated.
- The primary failure is cross-simulator, so the report reflects an interaction-level regression instead of a single local metric threshold.
