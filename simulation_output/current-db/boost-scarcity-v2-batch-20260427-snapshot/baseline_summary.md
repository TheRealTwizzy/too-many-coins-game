# Baseline Analysis Summary

Generated: 2026-04-27T07:59:46+00:00
Sim B runs: 15 | Sim C runs: 6

## 1. Lock-In vs Expiry

- Overall lock-in rate: **78.0%** (n=1750)
- Overall natural expiry rate: **22.0%**

**Lock-in timing distribution:**
- EARLY: 0.0%
- MID: 33.3%
- LATE_ACTIVE: 42.8%
- BLACKOUT: 1.9%
- NONE: 22.0%

**By archetype:**
| Archetype | Lock-in Rate | Expiry Rate | Sample |
|---|---|---|---|
| Casual | 100.0% | 0.0% | 175 |
| Regular | 94.9% | 5.1% | 175 |
| Hardcore | 19.4% | 80.6% | 175 |
| Hoarder | 99.4% | 0.6% | 175 |
| Early Locker | 100.0% | 0.0% | 175 |
| Late Deployer | 62.9% | 37.1% | 175 |
| Boost-Focused | 16.6% | 83.4% | 175 |
| Star-Focused | 99.4% | 0.6% | 175 |
| Aggressive Sigil User | 87.4% | 12.6% | 175 |
| Mostly Idle | 100.0% | 0.0% | 175 |

## 2. Star Accumulation

| Archetype | Score Mean | Score Median | Global Stars Mean |
|---|---|---|---|
| Casual | 5954 | 5286 | 45145 |
| Regular | 7950 | 6935 | 61679 |
| Hardcore | 10853 | 10041 | 115580 |
| Hoarder | 9755 | 8657 | 74082 |
| Early Locker | 6564 | 5824 | 49774 |
| Late Deployer | 8863 | 8164 | 75796 |
| Boost-Focused | 9945 | 9710 | 108807 |
| Star-Focused | 12171 | 12247 | 92416 |
| Aggressive Sigil User | 11737 | 11083 | 93337 |
| Mostly Idle | 5665 | 4864 | 42951 |

## 3. Sigil Tier Distribution

*(Per-player mean acquisition by tier, averaged across runs)*

| Archetype | T1 | T2 | T3 | T4 | T5 | T6 |
|---|---|---|---|---|---|---|
| Casual | 26.2 | 12.4 | 3.8 | 0.3 | 0.0 | 0.0 |
| Regular | 36.8 | 18.5 | 5.6 | 0.7 | 0.2 | 0.0 |
| Hardcore | 60.1 | 32.0 | 11.7 | 2.6 | 0.3 | 0.0 |
| Hoarder | 48.2 | 24.9 | 7.5 | 1.0 | 0.2 | 0.0 |
| Early Locker | 28.4 | 13.4 | 3.6 | 0.2 | 0.1 | 0.0 |
| Late Deployer | 36.8 | 19.6 | 7.0 | 1.3 | 0.1 | 0.0 |
| Boost-Focused | 54.1 | 30.6 | 11.1 | 2.4 | 0.5 | 0.0 |
| Star-Focused | 45.9 | 24.3 | 7.7 | 0.9 | 0.2 | 0.0 |
| Aggressive Sigil User | 46.0 | 24.1 | 8.5 | 1.3 | 0.2 | 0.0 |
| Mostly Idle | 19.4 | 9.3 | 2.6 | 0.2 | 0.0 | 0.0 |

## 4. Boost Usage

Boost-focused vs Regular score ratio: **1.23**

## 5. Ranking Concentration

- Sim B top-10% share (mean): **18.8%**
- Sim C final top-10% share (mean): **30.6%**

## 6. Archetype Outcome Spread

Overall mean of medians: **8148**


| Archetype | Mean Score | Ratio to Overall |
|---|---|---|
| Casual | 5254 | 0.64 |
| Regular | 7124 | 0.87 |
| Hardcore | 10359 | 1.27 |
| Hoarder | 8831 | 1.08 |
| Early Locker | 5636 | 0.69 |
| Late Deployer | 7638 | 0.94 |
| Boost-Focused | 9958 | 1.22 |
| Star-Focused | 11270 | 1.38 |
| Aggressive Sigil User | 10845 | 1.33 |
| Mostly Idle | 4568 | 0.56 |

## 7. Final Standing Distribution

- Total players: 1750
- Mean: 8946 | Median: 8830 | P10: 4093 | P90: 14483
- Locked-in mean: 8964 | Expired mean: 8882 | Gap: 82

## 8. Dominant Strategies

No archetype+phase combos exceed 1.5x median threshold.

## 9. Hoarding vs Spending

Hoarder vs Regular score ratio: **1.24**

## 10. Phase-by-Phase Behavior

Grand total actions: 97386
Late-active engaged rate (mean): **60.5%**

| Phase | Actions | Share |
|---|---|---|
| EARLY | 45707 | 46.9% |
| MID | 38493 | 39.5% |
| LATE_ACTIVE | 13179 | 13.5% |
| BLACKOUT | 7 | 0.0% |

## 11. Cross-Seed Stability

Seeds: 5 | Threshold CV: 0.15

| Metric | CV | Status |
|---|---|---|
| lock_in_rate | 0.0200 | OK |
| mean_score | 0.0364 | OK |
| expiry_rate | 0.0709 | OK |

## 12. Star Pricing

Seeds: 5 | Price CV across seeds: **0.0000**
- Global min price: 32 | Global max price: 97
- Stuck at cap share: **0.0%** | Stuck at floor share: **0.0%**
- Combined stuck share: **0.0%**

## 13. Progression Pacing

Progression pacing data not available (no phase-end score snapshots).

## 14. Mechanic Attribution

**Per-archetype boost contribution:**

| Archetype | Boost Coin Share | Mean Ticks Boosted | Mean Ticks Frozen |
|---|---|---|---|
| Casual | 90.7% | 74198 | 0 |
| Regular | 93.5% | 96621 | 0 |
| Hardcore | 95.1% | 152494 | 176 |
| Hoarder | 86.7% | 89088 | 0 |
| Early Locker | 94.8% | 71669 | 0 |
| Late Deployer | 91.7% | 108990 | 0 |
| Boost-Focused | 95.6% | 155940 | 57 |
| Star-Focused | 91.0% | 102764 | 0 |
| Aggressive Sigil User | 95.1% | 118166 | 0 |
| Mostly Idle | 90.7% | 57765 | 0 |

**Top-quartile analysis** (n=438):
- Top coin delta vs rest: -9691
- Boost share of coin delta: **0.0%**

