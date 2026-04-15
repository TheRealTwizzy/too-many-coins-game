# Rejection Attribution

- Scenario: `phase-gated-plus-inflation-tighten-v1`
- Disposition: `reject`
- Generated: `2026-04-15T02:34:42+00:00`

## Control vs Baseline

- Wins: 8 | Losses: 8 | Mixed: 2
- Comparator note: Paired samples: 2 group comparisons across 2 simulator group(s).
- `B` B|ppa=2|seasons=1 | wins=2 losses=5 flags=dominant_archetype_shifted, lock_in_down_but_expiry_dominance_up
- `C` C|ppa=2|seasons=4 | wins=6 losses=3 flags=dominant_archetype_shifted, lock_in_down_but_expiry_dominance_up

## Changed Knobs

- `season.base_ubi_active_per_tick` => active | baseline=30 | candidate=36
- `season.base_ubi_idle_factor_fp` => active | baseline=250000 | candidate=220000
- `season.hoarding_safe_hours` => active | baseline=12 | candidate=24
- `season.hoarding_sink_enabled` => active | baseline=1 | candidate=1
- `season.inflation_table` => active | baseline="[{\"x\":0,\"factor_fp\":1000000},{\"x\":50000,\"factor_fp\":620000},{\"x\":200000,\"factor_fp\":280000},{\"x\":800000,\"factor_fp\":110000},{\"x\":3000000,\"factor_fp\":50000}]" | candidate="[{\"x\": 0, \"factor_fp\": 1000000}, {\"x\": 50000, \"factor_fp\": 620000}, {\"x\": 200000, \"factor_fp\": 280000}, {\"x\": 800000, \"factor_fp\": 110000}, {\"x\": 3000000, \"factor_fp\": 50000}]"

## Failed Gate

- Primary: `lock_in_down_but_expiry_dominance_up` | Lock-in weakened while expiry pressure rose
- Secondary: `reduces_lock_in_but_expiry_dominance_rises` | Lock-in weakened while expiry pressure rose
- Secondary: `dominant_archetype_shifted` | Dominant archetype changed

## Causal Ranking

- #1 `season.hoarding_safe_hours` | confidence=low | score=8.245
  rationale=Knob was active in the effective config. Its key maps directly to the primary failed gate. Bundle interaction ambiguity lowers confidence.
- #2 `season.hoarding_sink_enabled` | confidence=low | score=8.245
  rationale=Knob was active in the effective config. Its key maps directly to the primary failed gate. Bundle interaction ambiguity lowers confidence.
- #3 `season.base_ubi_active_per_tick` | confidence=low | score=3.746
  rationale=Knob was active in the effective config. Its link to the primary gate is indirect. Bundle interaction ambiguity lowers confidence.
- #4 `season.base_ubi_idle_factor_fp` | confidence=low | score=3.746
  rationale=Knob was active in the effective config. Its link to the primary gate is indirect. Bundle interaction ambiguity lowers confidence.
- #5 `season.inflation_table` | confidence=low | score=3.746
  rationale=Knob was active in the effective config. Its link to the primary gate is indirect. Bundle interaction ambiguity lowers confidence.

## Uncertainty

- Interaction ambiguity: present
- Note: 5 active knobs changed together, so the causal ranking is heuristic and interaction effects are not isolated.
- Paired samples: 2 group comparisons across 2 simulator group(s).
- Evidence is sparse: only 2 paired simulator group(s) contributed to this rejection.
- 5 active knobs changed together, so the causal ranking is heuristic and interaction effects are not isolated.
