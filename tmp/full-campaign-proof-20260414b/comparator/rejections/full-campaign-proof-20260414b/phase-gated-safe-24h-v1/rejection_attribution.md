# Rejection Attribution

- Scenario: `phase-gated-safe-24h-v1`
- Disposition: `reject`
- Generated: `2026-04-15T02:34:42+00:00`

## Control vs Baseline

- Wins: 8 | Losses: 7 | Mixed: 2
- Comparator note: Paired samples: 2 group comparisons across 2 simulator group(s).
- `B` B|ppa=2|seasons=1 | wins=3 losses=3 flags=dominant_archetype_shifted, lock_in_down_but_expiry_dominance_up
- `C` C|ppa=2|seasons=4 | wins=5 losses=4 flags=dominant_archetype_shifted, lock_in_down_but_expiry_dominance_up, skip_rejoin_exploit_worsened

## Changed Knobs

- `season.hoarding_safe_hours` => active | baseline=12 | candidate=24
- `season.hoarding_sink_enabled` => active | baseline=1 | candidate=1

## Failed Gate

- Primary: `lock_in_down_but_expiry_dominance_up` | Lock-in weakened while expiry pressure rose
- Secondary: `skip_rejoin_exploit_worsened` | Skip/rejoin edge worsened
- Secondary: `reduces_lock_in_but_expiry_dominance_rises` | Lock-in weakened while expiry pressure rose
- Secondary: `dominant_archetype_shifted` | Dominant archetype changed

## Causal Ranking

- #1 `season.hoarding_sink_enabled` | confidence=low | score=9.775
  rationale=Knob was active in the effective config. Its key maps directly to the primary failed gate. Bundle interaction ambiguity lowers confidence.
- #2 `season.hoarding_safe_hours` | confidence=low | score=9.01
  rationale=Knob was active in the effective config. Its key maps directly to the primary failed gate. Bundle interaction ambiguity lowers confidence.

## Uncertainty

- Interaction ambiguity: present
- Note: 2 active knobs changed together, so the causal ranking is heuristic and interaction effects are not isolated.
- Paired samples: 2 group comparisons across 2 simulator group(s).
- Evidence is sparse: only 2 paired simulator group(s) contributed to this rejection.
- 2 active knobs changed together, so the causal ranking is heuristic and interaction effects are not isolated.
