# Baseline Analysis Summary

Generated: 2026-04-27T19:37:22+00:00
Sim B runs: 15 | Sim C runs: 6

## 1. Lock-In vs Expiry

- Overall lock-in rate: **68.7%** (n=1750)
- Overall natural expiry rate: **31.3%**

**Lock-in timing distribution:**
- EARLY: 0.0%
- MID: 26.1%
- LATE_ACTIVE: 36.0%
- BLACKOUT: 6.6%
- NONE: 31.3%

**By archetype:**
| Archetype | Lock-in Rate | Expiry Rate | Sample |
|---|---|---|---|
| Casual | 91.4% | 8.6% | 175 |
| Regular | 81.1% | 18.9% | 175 |
| Hardcore | 15.4% | 84.6% | 175 |
| Hoarder | 85.7% | 14.3% | 175 |
| Early Locker | 100.0% | 0.0% | 175 |
| Late Deployer | 40.0% | 60.0% | 175 |
| Boost-Focused | 32.6% | 67.4% | 175 |
| Star-Focused | 92.0% | 8.0% | 175 |
| Aggressive Sigil User | 48.6% | 51.4% | 175 |
| Mostly Idle | 100.0% | 0.0% | 175 |

## 2. Star Accumulation

| Archetype | Score Mean | Score Median | Global Stars Mean |
|---|---|---|---|
| Casual | 2312 | 2168 | 18332 |
| Regular | 3372 | 3454 | 28484 |
| Hardcore | 5381 | 5391 | 60050 |
| Hoarder | 3456 | 3599 | 28670 |
| Early Locker | 3204 | 3161 | 24294 |
| Late Deployer | 3052 | 3020 | 30905 |
| Boost-Focused | 4969 | 4912 | 51680 |
| Star-Focused | 4031 | 3999 | 31860 |
| Aggressive Sigil User | 4539 | 4524 | 44308 |
| Mostly Idle | 1974 | 1920 | 14966 |

## 3. Sigil Tier Distribution

*(Per-player mean acquisition by tier, averaged across runs)*

| Archetype | T1 | T2 | T3 | T4 | T5 | T6 |
|---|---|---|---|---|---|---|
| Casual | 11.0 | 5.2 | 1.8 | 0.2 | 0.0 | 0.0 |
| Regular | 17.0 | 9.3 | 3.0 | 0.6 | 0.1 | 0.0 |
| Hardcore | 27.5 | 14.8 | 5.8 | 1.3 | 0.2 | 0.0 |
| Hoarder | 19.9 | 10.8 | 3.3 | 0.6 | 0.1 | 0.0 |
| Early Locker | 14.0 | 7.0 | 2.4 | 0.3 | 0.0 | 0.0 |
| Late Deployer | 15.9 | 8.5 | 3.2 | 0.6 | 0.1 | 0.0 |
| Boost-Focused | 23.9 | 13.8 | 5.0 | 1.1 | 0.2 | 0.0 |
| Star-Focused | 19.2 | 10.8 | 3.2 | 0.6 | 0.1 | 0.0 |
| Aggressive Sigil User | 22.4 | 12.1 | 4.7 | 0.8 | 0.2 | 0.0 |
| Mostly Idle | 7.6 | 3.8 | 1.1 | 0.1 | 0.0 | 0.0 |

## 4. Boost Usage

Boost-focused vs Regular score ratio: **1.44**

## 5. Ranking Concentration

- Sim B top-10% share (mean): **15.2%**
- Sim C final top-10% share (mean): **20.2%**

## 6. Archetype Outcome Spread

Overall mean of medians: **3708**

Non-viable (<0.5x mean): mostly_idle

| Archetype | Mean Score | Ratio to Overall |
|---|---|---|
| Casual | 2308 | 0.62 |
| Regular | 3621 | 0.98 |
| Hardcore | 5520 | 1.49 |
| Hoarder | 3609 | 0.97 |
| Early Locker | 3311 | 0.89 |
| Late Deployer | 3091 | 0.83 |
| Boost-Focused | 5094 | 1.37 |
| Star-Focused | 4065 | 1.10 |
| Aggressive Sigil User | 4621 | 1.25 |
| Mostly Idle | 1836 | 0.50 |

## 7. Final Standing Distribution

- Total players: 1750
- Mean: 3629 | Median: 3606 | P10: 2011 | P90: 5277
- Locked-in mean: 3277 | Expired mean: 4402 | Gap: -1125

## 8. Dominant Strategies

| Combo | Mean Score | Ratio |
|---|---|---|
| Hardcore / EARLY | 5535 | 1.55 |
| Hardcore / MID | 5535 | 1.55 |
| Hardcore / LATE_ACTIVE | 5535 | 1.55 |
| Hardcore / BLACKOUT | 5535 | 1.55 |

## 9. Hoarding vs Spending

Hoarder vs Regular score ratio: **1.00**

## 10. Phase-by-Phase Behavior

Grand total actions: 45170
Late-active engaged rate (mean): **70.1%**

| Phase | Actions | Share |
|---|---|---|
| EARLY | 17179 | 38.0% |
| MID | 17926 | 39.7% |
| LATE_ACTIVE | 9671 | 21.4% |
| BLACKOUT | 394 | 0.9% |

## 11. Cross-Seed Stability

Seeds: 5 | Threshold CV: 0.15

| Metric | CV | Status |
|---|---|---|
| lock_in_rate | 0.0229 | OK |
| mean_score | 0.0249 | OK |
| expiry_rate | 0.0477 | OK |

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
| Casual | 36.7% | 21182 | 0 |
| Regular | 37.6% | 29955 | 0 |
| Hardcore | 35.9% | 37038 | 857 |
| Hoarder | 31.6% | 27868 | 0 |
| Early Locker | 39.9% | 22725 | 0 |
| Late Deployer | 34.2% | 29837 | 0 |
| Boost-Focused | 37.9% | 36621 | 11 |
| Star-Focused | 34.4% | 31110 | 0 |
| Aggressive Sigil User | 37.6% | 34895 | 0 |
| Mostly Idle | 37.5% | 14501 | 0 |

**Top-quartile analysis** (n=438):
- Top coin delta vs rest: 82461
- Boost share of coin delta: **37.1%**

