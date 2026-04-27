# Baseline Analysis Summary

Generated: 2026-04-27T20:48:53+00:00
Sim B runs: 15 | Sim C runs: 6

## 1. Lock-In vs Expiry

- Overall lock-in rate: **68.1%** (n=1750)
- Overall natural expiry rate: **31.9%**

**Lock-in timing distribution:**
- EARLY: 0.0%
- MID: 26.1%
- LATE_ACTIVE: 35.9%
- BLACKOUT: 6.1%
- NONE: 31.9%

**By archetype:**
| Archetype | Lock-in Rate | Expiry Rate | Sample |
|---|---|---|---|
| Casual | 92.0% | 8.0% | 175 |
| Regular | 80.6% | 19.4% | 175 |
| Hardcore | 13.1% | 86.9% | 175 |
| Hoarder | 85.7% | 14.3% | 175 |
| Early Locker | 100.0% | 0.0% | 175 |
| Late Deployer | 38.9% | 61.1% | 175 |
| Boost-Focused | 30.9% | 69.1% | 175 |
| Star-Focused | 91.4% | 8.6% | 175 |
| Aggressive Sigil User | 48.6% | 51.4% | 175 |
| Mostly Idle | 100.0% | 0.0% | 175 |

## 2. Star Accumulation

| Archetype | Score Mean | Score Median | Global Stars Mean |
|---|---|---|---|
| Casual | 2404 | 2298 | 19034 |
| Regular | 3410 | 3534 | 28933 |
| Hardcore | 5385 | 5386 | 60447 |
| Hoarder | 3494 | 3578 | 28975 |
| Early Locker | 3212 | 3191 | 24352 |
| Late Deployer | 3171 | 3104 | 32186 |
| Boost-Focused | 5012 | 4905 | 52377 |
| Star-Focused | 4050 | 3991 | 32088 |
| Aggressive Sigil User | 4545 | 4516 | 44402 |
| Mostly Idle | 2030 | 2012 | 15388 |

## 3. Sigil Tier Distribution

*(Per-player mean acquisition by tier, averaged across runs)*

| Archetype | T1 | T2 | T3 | T4 | T5 | T6 |
|---|---|---|---|---|---|---|
| Casual | 10.9 | 5.2 | 1.8 | 0.2 | 0.0 | 0.0 |
| Regular | 16.9 | 9.3 | 3.0 | 0.5 | 0.1 | 0.0 |
| Hardcore | 27.6 | 15.0 | 5.9 | 1.3 | 0.2 | 0.0 |
| Hoarder | 19.9 | 10.8 | 3.3 | 0.5 | 0.1 | 0.0 |
| Early Locker | 13.9 | 6.9 | 2.3 | 0.3 | 0.0 | 0.0 |
| Late Deployer | 16.1 | 8.6 | 3.2 | 0.7 | 0.1 | 0.0 |
| Boost-Focused | 24.0 | 13.8 | 5.0 | 1.1 | 0.2 | 0.0 |
| Star-Focused | 19.1 | 10.7 | 3.2 | 0.6 | 0.1 | 0.0 |
| Aggressive Sigil User | 22.4 | 12.1 | 4.7 | 0.8 | 0.1 | 0.0 |
| Mostly Idle | 7.7 | 3.8 | 1.1 | 0.1 | 0.0 | 0.0 |

## 4. Boost Usage

Boost-focused vs Regular score ratio: **1.44**

## 5. Ranking Concentration

- Sim B top-10% share (mean): **15.1%**
- Sim C final top-10% share (mean): **20.0%**

## 6. Archetype Outcome Spread

Overall mean of medians: **3741**


| Archetype | Mean Score | Ratio to Overall |
|---|---|---|
| Casual | 2411 | 0.64 |
| Regular | 3587 | 0.96 |
| Hardcore | 5507 | 1.47 |
| Hoarder | 3700 | 0.99 |
| Early Locker | 3216 | 0.86 |
| Late Deployer | 3236 | 0.86 |
| Boost-Focused | 5093 | 1.36 |
| Star-Focused | 4097 | 1.10 |
| Aggressive Sigil User | 4652 | 1.24 |
| Mostly Idle | 1915 | 0.51 |

## 7. Final Standing Distribution

- Total players: 1750
- Mean: 3671 | Median: 3668 | P10: 2068 | P90: 5287
- Locked-in mean: 3316 | Expired mean: 4430 | Gap: -1114

## 8. Dominant Strategies

| Combo | Mean Score | Ratio |
|---|---|---|
| Hardcore / EARLY | 5546 | 1.51 |
| Hardcore / MID | 5546 | 1.51 |
| Hardcore / LATE_ACTIVE | 5546 | 1.51 |
| Hardcore / BLACKOUT | 5546 | 1.51 |

## 9. Hoarding vs Spending

Hoarder vs Regular score ratio: **1.03**

## 10. Phase-by-Phase Behavior

Grand total actions: 45159
Late-active engaged rate (mean): **69.5%**

| Phase | Actions | Share |
|---|---|---|
| EARLY | 17218 | 38.1% |
| MID | 17860 | 39.6% |
| LATE_ACTIVE | 9688 | 21.4% |
| BLACKOUT | 393 | 0.9% |

## 11. Cross-Seed Stability

Seeds: 5 | Threshold CV: 0.15

| Metric | CV | Status |
|---|---|---|
| lock_in_rate | 0.0252 | OK |
| mean_score | 0.0237 | OK |
| expiry_rate | 0.0523 | OK |

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
| Casual | 35.4% | 20901 | 0 |
| Regular | 37.6% | 29906 | 0 |
| Hardcore | 36.0% | 37242 | 846 |
| Hoarder | 31.4% | 27611 | 0 |
| Early Locker | 39.7% | 22445 | 0 |
| Late Deployer | 33.9% | 30147 | 0 |
| Boost-Focused | 37.8% | 36827 | 21 |
| Star-Focused | 34.0% | 30765 | 0 |
| Aggressive Sigil User | 37.5% | 34803 | 0 |
| Mostly Idle | 35.6% | 14539 | 0 |

**Top-quartile analysis** (n=438):
- Top coin delta vs rest: 80828
- Boost share of coin delta: **37.6%**

