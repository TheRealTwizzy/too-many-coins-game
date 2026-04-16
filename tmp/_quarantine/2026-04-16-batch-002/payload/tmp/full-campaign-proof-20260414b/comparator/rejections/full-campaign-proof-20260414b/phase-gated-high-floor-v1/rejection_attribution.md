# Rejection Attribution

- Scenario: `phase-gated-high-floor-v1`
- Disposition: `reject`
- Generated: `2026-04-15T02:34:42+00:00`

## Control vs Baseline

- Wins: 5 | Losses: 11 | Mixed: 2
- Comparator note: Paired samples: 2 group comparisons across 2 simulator group(s).
- `B` B|ppa=2|seasons=1 | wins=2 losses=5 flags=dominant_archetype_shifted, engagement_up_but_t6_supply_spike, lock_in_down_but_expiry_dominance_up
- `C` C|ppa=2|seasons=4 | wins=3 losses=6 flags=dominant_archetype_shifted, lock_in_down_but_expiry_dominance_up, skip_rejoin_exploit_worsened

## Changed Knobs

- `season.hoarding_safe_hours` => active | baseline=12 | candidate=24
- `season.hoarding_safe_min_coins` => active | baseline=20000 | candidate=40000
- `season.hoarding_sink_enabled` => active | baseline=1 | candidate=1

## Failed Gate

- Primary: `lock_in_down_but_expiry_dominance_up` | Lock-in weakened while expiry pressure rose
- Secondary: `skip_rejoin_exploit_worsened` | Skip/rejoin edge worsened
- Secondary: `engagement_up_but_t6_supply_spike` | Engagement gain came with excess T6 supply
- Secondary: `reduces_lock_in_but_expiry_dominance_rises` | Lock-in weakened while expiry pressure rose
- Secondary: `improves_engagement_but_t6_supply_spikes` | Engagement gain came with excess T6 supply
- Secondary: `dominant_archetype_shifted` | Dominant archetype changed

## Causal Ranking

- #1 `season.hoarding_sink_enabled` | confidence=low | score=10.043
  rationale=Knob was active in the effective config. Its key maps directly to the primary failed gate. Bundle interaction ambiguity lowers confidence.
- #2 `season.hoarding_safe_hours` | confidence=low | score=9.278
  rationale=Knob was active in the effective config. Its key maps directly to the primary failed gate. Bundle interaction ambiguity lowers confidence.
- #3 `season.hoarding_safe_min_coins` | confidence=low | score=9.278
  rationale=Knob was active in the effective config. Its key maps directly to the primary failed gate. Bundle interaction ambiguity lowers confidence.

## Uncertainty

- Interaction ambiguity: present
- Note: 3 active knobs changed together, so the causal ranking is heuristic and interaction effects are not isolated.
- Paired samples: 2 group comparisons across 2 simulator group(s).
- Evidence is sparse: only 2 paired simulator group(s) contributed to this rejection.
- 3 active knobs changed together, so the causal ranking is heuristic and interaction effects are not isolated.
