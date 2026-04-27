# Baseline Analysis Summary

Generated: 2026-04-27T06:16:56+00:00
Sim B runs: 15 | Sim C runs: 6

## 1. Lock-In vs Expiry

- Overall lock-in rate: **71.5%** (n=1750)
- Overall natural expiry rate: **28.5%**

**Lock-in timing distribution:**
- EARLY: 0.0%
- MID: 25.8%
- LATE_ACTIVE: 41.0%
- BLACKOUT: 4.7%
- NONE: 28.5%

**By archetype:**
| Archetype | Lock-in Rate | Expiry Rate | Sample |
|---|---|---|---|
| Casual | 96.6% | 3.4% | 175 |
| Regular | 84.6% | 15.4% | 175 |
| Hardcore | 21.7% | 78.3% | 175 |
| Hoarder | 97.7% | 2.3% | 175 |
| Early Locker | 100.0% | 0.0% | 175 |
| Late Deployer | 40.0% | 60.0% | 175 |
| Boost-Focused | 11.4% | 88.6% | 175 |
| Star-Focused | 100.0% | 0.0% | 175 |
| Aggressive Sigil User | 63.4% | 36.6% | 175 |
| Mostly Idle | 100.0% | 0.0% | 175 |

## 2. Star Accumulation

| Archetype | Score Mean | Score Median | Global Stars Mean |
|---|---|---|---|
| Casual | 12379 | 11535 | 95440 |
| Regular | 16953 | 17084 | 139852 |
| Hardcore | 21624 | 21170 | 232019 |
| Hoarder | 12005 | 12104 | 91721 |
| Early Locker | 15790 | 15698 | 119737 |
| Late Deployer | 17543 | 16768 | 174223 |
| Boost-Focused | 17728 | 17727 | 200379 |
| Star-Focused | 22639 | 22415 | 171673 |
| Aggressive Sigil User | 18057 | 17737 | 160813 |
| Mostly Idle | 11568 | 10879 | 87715 |

## 3. Sigil Tier Distribution

*(Per-player mean acquisition by tier, averaged across runs)*

| Archetype | T1 | T2 | T3 | T4 | T5 | T6 |
|---|---|---|---|---|---|---|
| Casual | 180.5 | 92.6 | 28.7 | 4.2 | 0.8 | 0.0 |
| Regular | 209.2 | 106.8 | 34.5 | 5.6 | 1.2 | 0.1 |
| Hardcore | 260.3 | 138.9 | 51.3 | 10.8 | 2.1 | 0.1 |
| Hoarder | 411.0 | 203.3 | 56.1 | 6.0 | 1.2 | 0.0 |
| Early Locker | 143.7 | 69.8 | 20.1 | 2.0 | 0.3 | 0.0 |
| Late Deployer | 222.3 | 118.7 | 43.7 | 8.3 | 1.4 | 0.1 |
| Boost-Focused | 242.0 | 133.8 | 49.3 | 10.6 | 2.0 | 0.2 |
| Star-Focused | 240.6 | 127.4 | 41.3 | 5.7 | 1.1 | 0.0 |
| Aggressive Sigil User | 218.4 | 115.6 | 40.8 | 7.8 | 1.2 | 0.1 |
| Mostly Idle | 138.5 | 67.7 | 18.6 | 1.6 | 0.3 | 0.0 |

## 4. Boost Usage

Boost-focused vs Regular score ratio: **1.04**

## 5. Ranking Concentration

- Sim B top-10% share (mean): **15.0%**
- Sim C final top-10% share (mean): **22.2%**

## 6. Archetype Outcome Spread

Overall mean of medians: **15998**


| Archetype | Mean Score | Ratio to Overall |
|---|---|---|
| Casual | 11911 | 0.74 |
| Regular | 16873 | 1.05 |
| Hardcore | 21214 | 1.33 |
| Hoarder | 11064 | 0.69 |
| Early Locker | 15099 | 0.94 |
| Late Deployer | 16794 | 1.05 |
| Boost-Focused | 17479 | 1.09 |
| Star-Focused | 22044 | 1.38 |
| Aggressive Sigil User | 17021 | 1.06 |
| Mostly Idle | 10482 | 0.66 |

## 7. Final Standing Distribution

- Total players: 1750
- Mean: 16628 | Median: 16981 | P10: 9658 | P90: 21754
- Locked-in mean: 16028 | Expired mean: 18138 | Gap: -2110

## 8. Dominant Strategies

No archetype+phase combos exceed 1.5x median threshold.

## 9. Hoarding vs Spending

Hoarder vs Regular score ratio: **0.66**

## 10. Phase-by-Phase Behavior

Grand total actions: 503043
Late-active engaged rate (mean): **73.1%**

| Phase | Actions | Share |
|---|---|---|
| EARLY | 224784 | 44.7% |
| MID | 195103 | 38.8% |
| LATE_ACTIVE | 83113 | 16.5% |
| BLACKOUT | 43 | 0.0% |

## 11. Cross-Seed Stability

Seeds: 5 | Threshold CV: 0.15

| Metric | CV | Status |
|---|---|---|
| lock_in_rate | 0.0375 | OK |
| mean_score | 0.0181 | OK |
| expiry_rate | 0.0866 | OK |

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
| Casual | 99.8% | 129229 | 0 |
| Regular | 99.9% | 152223 | 0 |
| Hardcore | 99.9% | 184238 | 720 |
| Hoarder | 98.5% | 122528 | 0 |
| Early Locker | 99.8% | 99253 | 0 |
| Late Deployer | 99.9% | 175136 | 0 |
| Boost-Focused | 100.0% | 192019 | 0 |
| Star-Focused | 99.8% | 141073 | 3460 |
| Aggressive Sigil User | 99.9% | 163407 | 0 |
| Mostly Idle | 99.7% | 93283 | 0 |

**Top-quartile analysis** (n=438):
- Top coin delta vs rest: 122982
- Boost share of coin delta: **100.4%**

