# Baseline Analysis Summary

Generated: 2026-04-10T20:43:40+00:00
Sim B runs: 15 | Sim C runs: 6

## 1. Lock-In vs Expiry

- Overall lock-in rate: **89.7%** (n=1750)
- Overall natural expiry rate: **10.3%**

**Lock-in timing distribution:**
- EARLY: 0.0%
- MID: 34.7%
- LATE_ACTIVE: 53.6%
- BLACKOUT: 1.4%
- NONE: 10.3%

**By archetype:**
| Archetype | Lock-in Rate | Expiry Rate | Sample |
|---|---|---|---|
| Casual | 100.0% | 0.0% | 175 |
| Regular | 97.7% | 2.3% | 175 |
| Hardcore | 69.1% | 30.9% | 175 |
| Hoarder | 99.4% | 0.6% | 175 |
| Early Locker | 100.0% | 0.0% | 175 |
| Late Deployer | 85.7% | 14.3% | 175 |
| Boost-Focused | 58.9% | 41.1% | 175 |
| Star-Focused | 100.0% | 0.0% | 175 |
| Aggressive Sigil User | 86.3% | 13.7% | 175 |
| Mostly Idle | 99.4% | 0.6% | 175 |

## 2. Star Accumulation

| Archetype | Score Mean | Score Median | Global Stars Mean |
|---|---|---|---|
| Casual | 1307 | 1005 | 9902 |
| Regular | 1247 | 1021 | 9468 |
| Hardcore | 719 | 282 | 5699 |
| Hoarder | 2442 | 2160 | 18521 |
| Early Locker | 1264 | 1060 | 9581 |
| Late Deployer | 1347 | 1012 | 10317 |
| Boost-Focused | 645 | 216 | 5219 |
| Star-Focused | 2554 | 2266 | 19362 |
| Aggressive Sigil User | 1242 | 1022 | 9524 |
| Mostly Idle | 967 | 754 | 7327 |

## 3. Sigil Tier Distribution

*(Per-player mean acquisition by tier, averaged across runs)*

| Archetype | T1 | T2 | T3 | T4 | T5 | T6 |
|---|---|---|---|---|---|---|
| Casual | 5.5 | 2.8 | 0.7 | 0.1 | 0.0 | 0.0 |
| Regular | 8.8 | 4.4 | 1.3 | 0.1 | 0.0 | 0.0 |
| Hardcore | 15.4 | 8.6 | 3.2 | 0.5 | 0.1 | 0.0 |
| Hoarder | 10.8 | 5.4 | 1.8 | 0.2 | 0.0 | 0.0 |
| Early Locker | 7.2 | 3.2 | 0.8 | 0.1 | 0.0 | 0.0 |
| Late Deployer | 8.1 | 4.4 | 1.5 | 0.2 | 0.1 | 0.0 |
| Boost-Focused | 14.6 | 8.2 | 2.9 | 0.4 | 0.1 | 0.0 |
| Star-Focused | 11.0 | 5.3 | 1.8 | 0.2 | 0.1 | 0.0 |
| Aggressive Sigil User | 12.3 | 5.8 | 2.1 | 0.3 | 0.1 | 0.0 |
| Mostly Idle | 4.2 | 1.9 | 0.5 | 0.0 | 0.0 | 0.0 |

## 4. Boost Usage

Boost-focused vs Regular score ratio: **0.51**

## 5. Ranking Concentration

- Sim B top-10% share (mean): **31.1%**
- Sim C final top-10% share (mean): **22.6%**

## 6. Archetype Outcome Spread

Overall mean of medians: **1102**

Non-viable (<0.5x mean): hardcore, boost_focused

| Archetype | Mean Score | Ratio to Overall |
|---|---|---|
| Casual | 1003 | 0.91 |
| Regular | 1005 | 0.91 |
| Hardcore | 314 | 0.28 |
| Hoarder | 2133 | 1.94 |
| Early Locker | 1119 | 1.02 |
| Late Deployer | 1262 | 1.15 |
| Boost-Focused | 270 | 0.25 |
| Star-Focused | 2130 | 1.93 |
| Aggressive Sigil User | 828 | 0.75 |
| Mostly Idle | 956 | 0.87 |

## 7. Final Standing Distribution

- Total players: 1750
- Mean: 1373 | Median: 1024 | P10: 48 | P90: 3020
- Locked-in mean: 1528 | Expired mean: 34 | Gap: 1494

## 8. Dominant Strategies

| Combo | Mean Score | Ratio |
|---|---|---|
| Hoarder / EARLY | 2412 | 2.00 |
| Hoarder / MID | 2412 | 2.00 |
| Hoarder / LATE_ACTIVE | 2412 | 2.00 |
| Hoarder / BLACKOUT | 2412 | 2.00 |
| Star-Focused / EARLY | 2412 | 2.00 |
| Star-Focused / MID | 2412 | 2.00 |
| Star-Focused / LATE_ACTIVE | 2412 | 2.00 |
| Star-Focused / BLACKOUT | 2412 | 2.00 |

## 9. Hoarding vs Spending

Hoarder vs Regular score ratio: **2.12**

## 10. Phase-by-Phase Behavior

Grand total actions: 19153
Late-active engaged rate (mean): **56.9%**

| Phase | Actions | Share |
|---|---|---|
| EARLY | 6656 | 34.8% |
| MID | 9797 | 51.1% |
| LATE_ACTIVE | 2655 | 13.9% |
| BLACKOUT | 45 | 0.2% |

## 11. Cross-Seed Stability

Seeds: 5 | Threshold CV: 0.15

| Metric | CV | Status |
|---|---|---|
| lock_in_rate | 0.0161 | OK |
| mean_score | 0.0720 | OK |
| expiry_rate | 0.1470 | OK |

## 12. Star Pricing

Seeds: 5 | Price CV across seeds: **0.0076**
- Global min price: 101 | Global max price: 252
- Stuck at cap share: **0.0%** | Stuck at floor share: **0.0%**
- Combined stuck share: **0.0%**

## 13. Progression Pacing

Progression pacing data not available (no phase-end score snapshots).

## 14. Mechanic Attribution

**Per-archetype boost contribution:**

| Archetype | Boost Coin Share | Mean Ticks Boosted | Mean Ticks Frozen |
|---|---|---|---|
| Casual | 42.6% | 582 | 0 |
| Regular | 54.9% | 941 | 0 |
| Hardcore | 66.4% | 1644 | 1 |
| Hoarder | 30.6% | 572 | 0 |
| Early Locker | 46.1% | 537 | 0 |
| Late Deployer | 52.6% | 1053 | 0 |
| Boost-Focused | 69.7% | 1841 | 0 |
| Star-Focused | 51.6% | 1048 | 0 |
| Aggressive Sigil User | 59.1% | 1274 | 0 |
| Mostly Idle | 35.1% | 368 | 0 |

**Top-quartile analysis** (n=438):
- Top coin delta vs rest: -108
- Boost share of coin delta: **0.0%**

