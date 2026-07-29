// Repository-grounded wiki content for Too Many Coins.
//
// Source of truth: includes/config.php, includes/economy.php, includes/actions.php,
// includes/tick_engine.php, includes/family_actions.php, schema.sql.
//
// Rules for editing this file:
//   1. Every number here is read from the source, not remembered. If you cannot
//      point at the constant, do not state the number - describe the behaviour.
//   2. Values that are per-season columns (schema defaults) can be changed by an
//      operator per season. Values that are define()s are global. The Reference
//      category marks which is which, because "the wiki said 25" is only useful
//      if you know whether 25 can move.
//   3. Cross-reference with [[chapter-id]] or [[section-id|label]]. The renderer
//      resolves them against the real ids and flags anything that does not
//      resolve, so a rename fails loudly instead of leaving a dead link.
window.WIKI_CATEGORIES = [
  {
    id: "getting-started",
    title: "Getting Started",
    summary: "What the game is, how a season works, and what to do in your first hour.",
    chapters: [
      {
        id: "what-is-tmc",
        number: 1,
        title: "What Is Too Many Coins?",
        icon: "BookOpen",
        description: "A season-based economic competition built on timing and tradeoffs.",
        seeAlso: ["season-structure", "ubi-and-activity", "lock-in"],
        sections: [
          {
            id: "game-overview",
            title: "Game Overview",
            content: `Too Many Coins is a season-based economic game. You earn **Coins** continuously, convert them into **Seasonal Stars**, and compete against everyone else in the same season on a live leaderboard.

Seasonal Stars are only worth something if you convert them into **Global Stars**, which are permanent and carry across every season you ever play. There are exactly two ways to make that conversion, and choosing between them is the central decision of a season - see [[lock-in]].

Three resources matter:

| Resource | Scope | How you get it | What it does |
|---|---|---|---|
| Coins | One season | Earned every tick from UBI | Buys Seasonal Stars, nothing else |
| Seasonal Stars | One season | Bought with Coins | Your rank in this season |
| Global Stars | Permanent | Converted from Seasonal Stars | Lifetime standing; buys cosmetics |

Coins do not carry between seasons. Anything unspent when a season ends is simply gone, which is why hoarding is a losing habit - and why the game actively taxes it, as [[hoarding-sink]] explains.`
          },
          {
            id: "season-structure",
            title: "Season Structure",
            content: `A season runs **14 days** and a new one starts every **7 days**, so two seasons overlap at any time and you can usually choose which to join.

The last **72 hours** of every season are the **Blackout**.

| Phase | Length | What happens |
|---|---|---|
| Active | ~11 days | Full income, sigil drops, star purchases, hostile actions |
| Blackout | 72 hours | No income, no drops, no purchases - the season settles |
| Expired | - | Final payout; resources destroyed or converted |

The Blackout is not a wind-down; it is a hard stop on earning. Everything you intend to accumulate has to be accumulated before it starts. See [[blackout]].

> These three durations are global constants, not per-season settings, so they are the same in every season.`
          },
          {
            id: "ticks-and-time",
            title: "Ticks and Real Time",
            content: `The game advances in **ticks**. Every income calculation, drop roll, price update and cooldown is counted in ticks, not in minutes.

How much real time one tick takes is a deployment setting, and it is **not fixed across deployments**. The shipped default is 60 seconds; the live server currently runs a much faster cadence.

This matters more than it sounds. Rates in this wiki are given **per real minute** wherever possible, because a "per tick" number means nothing until you know the cadence. The interface shows the live cadence - trust that over any number you remember.

> If you are reading a value with "per tick" in its name, check whether the code divides it by ticks-per-real-minute first. Several do, including base UBI. See [[reference-timing]].`
          }
        ]
      },
      {
        id: "first-season",
        number: 2,
        title: "Your First Season",
        icon: "Compass",
        description: "Join, stay Active, buy your first stars, and decide how to exit.",
        seeAlso: ["ubi-and-activity", "buying-stars", "sigil-drops", "lock-in"],
        sections: [
          {
            id: "first-steps",
            title: "First Steps",
            content: `1. Join an **Active**, non-Blackout season.
2. Stay **Active** rather than Idle - Idle pays 30% of the Active rate, which is the single largest swing available to you. See [[ubi-and-activity]].
3. Let Coins accumulate, then convert them into Seasonal Stars at the Star Forge. See [[buying-stars]].
4. Watch for **Sigils**, which drop on their own and are worth real Stars at lock-in. See [[sigil-drops]].
5. Before the Blackout starts, decide whether to lock in. See [[lock-in]].

There is no hidden progression track and nothing to unlock. Everything is visible from your first minute; the difficulty is entirely in the timing.`
          },
          {
            id: "first-mistakes",
            title: "Common First-Season Mistakes",
            content: `**Hoarding Coins.** Coins do not convert at the end of a season, they vanish - and holding a large balance triggers the hoarding sink, which reduces your income the longer you sit on it. See [[hoarding-sink]].

**Going Idle without meaning to.** You drop to Idle after a period of inactivity and stay there until you return. Idle income is 30% of Active.

**Missing the lock-in window.** You must have participated for a cumulative **12 hours** in a season before you can lock in at all. If you join late and intend to lock in, that clock matters.

**Assuming sigils are safe.** Sigils are destroyed at natural expiry and refunded at lock-in. That asymmetry is the whole reason lock-in timing is interesting - see [[lock-in-versus-expiry]].`
          }
        ]
      }
    ]
  },

  {
    id: "economy",
    title: "Economy",
    summary: "Where Coins come from, what drains them, and how the star price moves.",
    chapters: [
      {
        id: "ubi-and-activity",
        number: 1,
        title: "Income and Activity",
        icon: "TrendingUp",
        description: "Your Coin rate, and the things that modify it.",
        seeAlso: ["hoarding-sink", "freeze", "reference-economy"],
        sections: [
          {
            id: "base-income",
            title: "Base Income",
            content: `Every player in a season earns Coins automatically, every tick, with no action required. This is the UBI.

The base rate is **100 Coins per real minute** while Active. The underlying column is named \`base_ubi_active_per_tick\`, but the code divides it by ticks-per-real-minute before use - so it is a per-minute figure regardless of the tick cadence.

Your **presence state** scales it:

| State | Multiplier | Effective base |
|---|---|---|
| Active | x1.00 | 100 / min |
| Idle | x0.30 | 30 / min |
| Offline | - | treated as Idle for income |

You fall to Idle after a period without activity, and there is a longer hold before a forced-offline player is released back. Both thresholds are in [[reference-timing]].`
          },
          {
            id: "inflation-dampening",
            title: "Inflation Dampening",
            content: `The base rate is not what you actually receive. It is multiplied by an **inflation factor** derived from the total Coin supply in the season.

As the season-wide supply grows, everyone's rate falls. This is a deliberate brake on runaway accumulation, and it has one consequence worth understanding clearly:

> The dampener keys on **season-wide** supply, not your own balance. Other players hoarding lowers *your* rate. You cannot fix that individually.

A per-season floor guarantees the rate never reaches zero from dampening alone. Being frozen is different - that sets income to zero outright. See [[freeze]].`
          },
          {
            id: "rate-composition",
            title: "Reading Your Rate",
            content: `Your displayed rate is the sum of several terms, and it is worth knowing which is which when it moves:

- **Base** - the UBI above, after dampening
- **Activity** - the bonus for being Active rather than Idle
- **Boost** - any active Coin boost you have purchased
- **Hoarding sink** - subtracted, not added; see [[hoarding-sink]]

A rate that drops without you doing anything is almost always one of: the season supply grew, you went Idle, a boost expired, your balance crossed a hoarding threshold, or someone froze you.`
          }
        ]
      },
      {
        id: "hoarding-sink",
        number: 2,
        title: "The Hoarding Sink",
        icon: "TrendingDown",
        description: "A tax on sitting still, and how to avoid paying it.",
        seeAlso: ["ubi-and-activity", "buying-stars", "reference-economy"],
        sections: [
          {
            id: "why-it-exists",
            title: "Why It Exists",
            content: `Coins are worthless at the end of a season. Without a counter-pressure, the optimal play would be to sit on a large balance and convert at the last possible moment, which makes for a boring season and a leaderboard decided by one action.

The hoarding sink removes a fraction of your income based on **how much you are holding and for how long**. It is a drain on the rate, not a deduction from the balance - you never watch Coins disappear, you watch your income get worse.`
          },
          {
            id: "how-it-scales",
            title: "How It Scales",
            content: `Three things protect you, and all three are per-season settings an operator can change:

- A **safe period** - roughly the first half-day of holding is untaxed
- A **safe minimum** - balances under a floor are never taxed at all
- A **tiered rate** - the rate rises in bands as the excess above the floor grows

The total is capped as a fraction of your gross rate, so the sink can never take your whole income. Being Idle makes it **worse**, not better - there is an idle multiplier above 1.0, so parking a big balance and walking away is the most heavily taxed thing you can do.

Exact defaults are tabulated in [[reference-economy]].`
          },
          {
            id: "avoiding-it",
            title: "Avoiding It",
            content: `Spend. That is the entire counterplay, and it is intentional.

Converting Coins into Seasonal Stars removes them from your balance, which resets the pressure. The sink is therefore not really a punishment - it is a nudge toward the decision the game wants you making regularly, which is *when to buy*, not *whether to hold*.

> A season where you buy stars steadily will out-earn one where you hold and convert once, even before considering what the price curve does to a single large purchase. See [[star-price]].`
          }
        ]
      },
      {
        id: "star-price",
        number: 3,
        title: "The Star Price",
        icon: "Activity",
        description: "What sets the cost of a Seasonal Star, and why it moves.",
        seeAlso: ["buying-stars", "hoarding-sink", "reference-economy"],
        sections: [
          {
            id: "price-basics",
            title: "How the Price Is Set",
            content: `Seasonal Stars are bought with Coins at a price that changes over time. The price is driven by the **Coin supply in the season** - more Coins in circulation means a higher price per Star.

The supply figure is weighted by presence: Coins held by Idle players count less than Coins held by Active ones, and a season can be configured to ignore Idle balances entirely.

This means the price is a shared surface. Your purchases move it, and so does everyone else's accumulation.`
          },
          {
            id: "price-movement-limits",
            title: "Movement Limits",
            content: `The price cannot jump. Each tick it is clamped to a maximum step in either direction, and the limits are deliberately asymmetric:

- Upward movement is capped tightly - the price climbs slowly
- Downward movement is allowed to be several times faster

The effect is that a price rise is a durable signal about the season, while a fall can correct quickly. There is also an absolute floor and a per-season ceiling.

> **Historical note.** An earlier pricing model applied its affordability adjustment after the movement clamp rather than before, which caused the price to converge to a fixed value regardless of supply - the market layer effectively did not exist. Seasons now carry a pricing model version, and new seasons use the corrected model. Older seasons keep the old behaviour so their results stay reproducible.`
          }
        ]
      },
      {
        id: "buying-stars",
        number: 4,
        title: "Buying Stars",
        icon: "Star",
        description: "Converting Coins into score, and when to do it.",
        seeAlso: ["star-price", "hoarding-sink", "lock-in"],
        sections: [
          {
            id: "the-purchase",
            title: "The Purchase",
            content: `You choose a quantity of Seasonal Stars and pay the current price per Star in Coins. The purchase is atomic: either you can afford the whole quantity or it is rejected outright.

Large purchases are gated behind an explicit confirmation, because spending a substantial share of your balance is a decision the game wants you to make deliberately rather than by mis-clicking.

Your purchase also moves the price. Buying a large block costs more per Star at the end of the block than at the start.`
          },
          {
            id: "when-to-buy",
            title: "When to Buy",
            content: `There is no single right answer, which is the point. The tension:

- **Buying early** converts Coins at a lower price and avoids the sink, but commits you before you know how the season develops.
- **Buying late** lets you react to rivals, but you pay the sink the whole time and the price is usually higher.

The one clearly bad play is holding a large balance passively while Idle - you pay the maximum sink rate and gain nothing for it. See [[hoarding-sink]].`
          }
        ]
      }
    ]
  },

  {
    id: "sigils-and-families",
    title: "Sigils & Families",
    summary: "The drop economy, the six tiers, combining, and what a family affinity does.",
    chapters: [
      {
        id: "sigil-drops",
        number: 1,
        title: "Sigil Drops",
        icon: "Hexagon",
        description: "How sigils arrive, and the two things that make them arrive less often.",
        seeAlso: ["sigil-tiers", "sigil-families", "reference-sigils"],
        sections: [
          {
            id: "how-drops-work",
            title: "How Drops Work",
            content: `Sigils are not bought. They arrive on their own, rolled per tick, and they are the only resource in the game you cannot directly purchase.

There are **six tiers**, T1 through T6, with sharply falling odds as the tier rises. The roll checks the highest tier first, so a lucky tick can produce a high-tier sigil directly rather than requiring you to work up through the lower ones.

The season's real per-tier chances are shown in the interface. They are not hidden - but two live multipliers modify them, and those are the part players usually miss.`
          },
          {
            id: "drop-pressure",
            title: "The Two Multipliers",
            content: `**Inventory pressure.** Holding sigils damps your own drop odds. Below a threshold of **10 held** you get the full rate; above it the multiplier falls linearly to zero at the inventory cap of **25**. A full inventory stops drops entirely.

**Boost pressure.** An active Coin boost reduces sigil odds - a step down per increment of boost, with a hard floor so it never reaches zero.

Both are multipliers on the gate chance, not progress bars. Neither fills up, and neither guarantees anything.

> These are the real mechanics. Some constants with promising names - a pity timer, a per-window drop cap - exist in the config but are either read for one unrelated purpose or not referenced at all. **There is no pity system.** Do not plan around one.`
          },
          {
            id: "season-phase",
            title: "Season Phase",
            content: `Drop behaviour changes across the season. The phases are Early, Mid, Late-Active and Blackout, and higher tiers become reachable as the season progresses.

During the Blackout there are no drops at all. Whatever you are holding when the Blackout begins is what you finish the season with. See [[blackout]].`
          }
        ]
      },
      {
        id: "sigil-tiers",
        number: 2,
        title: "Tiers, Combining and Value",
        icon: "Layers",
        description: "What each tier is worth and how to move up.",
        seeAlso: ["sigil-drops", "lock-in", "reference-sigils"],
        sections: [
          {
            id: "tier-values",
            title: "Tier Values",
            content: `Every tier has a canonical Star value, used when sigils are refunded at lock-in:

| Tier | Reference value (Seasonal Stars) |
|---|---|
| T1 | 50 |
| T2 | 250 |
| T3 | 1,000 |
| T4 | 3,000 |
| T5 | 9,000 |
| T6 | 12,000 |

The jump from T1 to T6 is 240x. A single high-tier sigil can be worth more than hours of income, which is why theft targets them and why wards exist. See [[theft]] and [[ward]].

> T6 was previously valued at 0 - the rarest drop in the game settled for nothing at lock-in. It is now the most valuable.`
          },
          {
            id: "combining",
            title: "Combining",
            content: `Sigils of the same tier can be combined into one of the next tier up. This is the only way to convert breadth into height, and it matters for two reasons beyond the obvious:

1. It reduces your **held count**, which restores your inventory pressure multiplier and gets drops flowing again. See [[drop-pressure]].
2. Higher tiers are worth disproportionately more at lock-in.

The inventory cap of 25 makes combining a maintenance task, not an optional one. A player who never combines will cap out and stop receiving drops entirely.`
          }
        ]
      },
      {
        id: "sigil-families",
        number: 3,
        title: "The Six Families",
        icon: "Gem",
        description: "Yield, Time, Ward, Larceny, Market and Sight - and what an affinity commits you to.",
        seeAlso: ["theft", "ward", "sigil-tiers"],
        sections: [
          {
            id: "the-families",
            title: "The Families",
            content: `Beyond a tier, a sigil belongs to a **family**, and the family decides what it can do:

| Family | Verb | Effect |
|---|---|---|
| Yield | rises | Increases income |
| Time | sweeps | Shortens cooldowns |
| Ward | shields | Blocks incoming hostile actions |
| Larceny | snatches | Enables stealing from other players |
| Market | exchanges | Shifts purchase pricing in your favour |
| Sight | reveals | Reveals information about other players |

Sight is deliberately not counted toward the subsystem's activation requirement - a roster of nothing but Sight would have nothing to reveal about.`
          },
          {
            id: "affinity",
            title: "Affinity and Caps",
            content: `You hold one **affinity** at a time, which determines which verb your sigils perform. Changing it does not re-roll or destroy anything; it changes what your holdings do from that point forward.

Holdings are capped **per family**, not just in total, which prevents stacking a single family to an extreme. The per-family cap is deliberately aligned with the inventory-pressure threshold, so specialising into one family and specialising into drops pull against each other.

> Families are gated behind both a deployment flag and per-family enablement in the database. If the interface shows no families at all, the subsystem is switched off in that deployment rather than broken.`
          }
        ]
      }
    ]
  },

  {
    id: "hostile-actions",
    title: "Hostile Actions",
    summary: "Freeze, theft, wards, cooldowns, and what actually protects you.",
    chapters: [
      {
        id: "freeze",
        number: 1,
        title: "Freeze",
        icon: "Snowflake",
        description: "Setting another player's income to zero, and the limits on doing it.",
        seeAlso: ["theft", "ward", "reference-hostile"],
        sections: [
          {
            id: "freeze-basics",
            title: "How Freeze Works",
            content: `Spending a high-tier sigil lets you **freeze** another player: their Coin income drops to zero for the duration.

Freeze is the bluntest hostile action in the game. It does not take anything from the target - it stops them earning, which over a long enough window is worse.

Only the top tiers can be spent on a freeze, so freezing costs you something genuinely valuable. It is not a cheap harassment tool.`
          },
          {
            id: "freeze-limits",
            title: "Cooldowns and Protection",
            content: `Two limits apply, mirroring the ones on theft:

- A **cooldown** after you freeze someone, before you can freeze again
- A **protection window** on the target, during which they cannot be frozen again by anyone

Freezes also **do not stack**. A second freeze on an already-frozen player does not extend or deepen the effect.

> Earlier builds had neither limit and allowed stacking, which meant a group could hold one player at zero income indefinitely and anonymously. Victims are now notified and the attacker is named - the same treatment [[theft]] has always had.`
          }
        ]
      },
      {
        id: "theft",
        number: 2,
        title: "Theft",
        icon: "AlertTriangle",
        description: "Taking a sigil from another player, and why it usually is not worth it.",
        seeAlso: ["freeze", "ward", "sigil-tiers"],
        sections: [
          {
            id: "theft-basics",
            title: "How Theft Works",
            content: `Spending a mid-tier sigil lets you attempt to steal a sigil from another player. Any tier can be targeted.

Theft is a **roll, not a guarantee**. The success chance is capped well below certainty - it can never be a sure thing regardless of what you spend or who you target.

Both players are notified and the attacker is named. There is no anonymous theft.`
          },
          {
            id: "theft-economics",
            title: "The Economics",
            content: `Theft has a hard success cap and costs a real sigil to attempt. Run the expected value and it is, in most situations, **negative** - you are spending a certain asset for a capped chance at another.

That is a deliberate design position: hostile action should be available and should carry weight, but it should not be the optimal way to play. If theft were profitable in expectation, every season would collapse into a raiding contest.

Use it when the target holds something specific and valuable, or when the disruption is worth more to you than the sigil. Not as a routine income strategy.`
          },
          {
            id: "theft-limits",
            title: "Cooldowns and Protection",
            content: `As with [[freeze]], a cooldown applies to the attacker and a protection window applies to the target after a successful attempt. Both are shortened during the Blackout - see [[blackout]].`
          }
        ]
      },
      {
        id: "ward",
        number: 3,
        title: "Wards and Defence",
        icon: "Shield",
        description: "Blocking incoming actions, and the limits of doing so.",
        seeAlso: ["freeze", "theft", "sigil-families"],
        sections: [
          {
            id: "ward-basics",
            title: "Raising a Ward",
            content: `A **Ward** blocks incoming hostile actions for a duration. It is the direct counter to both [[freeze]] and [[theft]], and it is checked before either resolves.

A ward's duration is capped as a fraction of the season's remaining time, so you cannot raise one early and coast to the end untouchable.`
          },
          {
            id: "blocking-is-not-defence",
            title: "Blocking Is Not Defence",
            content: `The social **block** list and hostile-action protection are separate systems, deliberately.

Blocking a player affects social surfaces - chat and similar. It does **not** make you immune to their hostile actions, and this is intentional: if blocking granted immunity, the correct play would be to block the entire leaderboard and become untouchable.

If you want protection from an attacker, raise a ward. If you want to stop hearing from them, block them. They are different problems.`
          }
        ]
      }
    ]
  },

  {
    id: "competition",
    title: "Competition",
    summary: "Leaderboards, lock-in, Global Stars, and how a season pays out.",
    chapters: [
      {
        id: "leaderboards",
        number: 1,
        title: "Leaderboards and Ranking",
        icon: "Trophy",
        description: "What each board ranks you on.",
        seeAlso: ["lock-in", "global-stars"],
        sections: [
          {
            id: "season-leaderboard",
            title: "The Season Leaderboard",
            content: `Within a season, players are ranked on **Seasonal Stars**. The board also shows each player's current income rate, which tells you something the star count alone does not: who is gaining on you.

A rank change is animated as movement rather than a jump, so position changes are visible as they happen rather than only in retrospect.`
          },
          {
            id: "global-leaderboard",
            title: "The Global Leaderboard",
            content: `Across all seasons, players are ranked on **lifetime Global Stars earned** - not on the balance they currently hold.

This distinction matters because Global Stars are also the currency for cosmetics. Ranking on the spendable balance would mean every cosmetic purchase cost you rank, making the shop something no competitive player should ever use. See [[cosmetics]].

> This was previously the case: the board ranked on the same column cosmetics deducted from. It now ranks on lifetime earned, so spending is free of rank consequences.`
          }
        ]
      },
      {
        id: "lock-in",
        number: 2,
        title: "Lock-In",
        icon: "Lock",
        description: "The one irreversible decision in a season.",
        seeAlso: ["lock-in-versus-expiry", "sigil-tiers", "global-stars"],
        sections: [
          {
            id: "what-lock-in-does",
            title: "What Lock-In Does",
            content: `Locking in ends your season immediately and converts your Seasonal Stars into permanent Global Stars at **65%**.

Your sigils are refunded at their reference values and included in the conversion. See [[tier-values]].

It is **irreversible**. You cannot rejoin the season, cannot keep earning, and cannot undo it. You must also have participated for a cumulative **12 hours** in the season before you are allowed to lock in at all - a floor that exists to stop a join-and-immediately-exit exploit.`
          },
          {
            id: "lock-in-versus-expiry",
            title: "Lock-In versus Natural Expiry",
            content: `The alternative is to let the season expire naturally, which converts at **100%** instead of 65%.

That sounds strictly better, and for Stars alone it is. The catch is sigils:

| | Seasonal Stars | Sigils |
|---|---|---|
| Lock in early | 65% | Refunded at reference value |
| Let it expire | 100% | **Destroyed** |

So the choice is not "65% versus 100%". It is "65% of everything" versus "100% of your Stars and nothing for your sigils". Which is better depends entirely on how much of your value is sitting in sigils - and given a T6 is worth 12,000 Stars, that can easily be most of it.

> This is the single most consequential piece of arithmetic in the game. Do the comparison; do not assume 100% wins.`
          }
        ]
      },
      {
        id: "global-stars",
        number: 3,
        title: "Global Stars and Bonuses",
        icon: "Award",
        description: "Permanent standing, placement bonuses, and what you can spend on.",
        seeAlso: ["lock-in", "leaderboards", "reference-competition"],
        sections: [
          {
            id: "bonuses",
            title: "Participation and Placement",
            content: `Beyond conversion, two bonuses apply at the end of a season:

- A **participation bonus**, accruing with time spent in the season, up to a cap
- A **placement bonus** for the top three finishers

Placement pays **100 / 60 / 40** for first, second and third. Exact participation figures are in [[reference-competition]].`
          },
          {
            id: "cosmetics",
            title: "Cosmetics",
            content: `Global Stars are spendable on cosmetics, priced in five tiers: **25, 80, 250, 800 and 2,400**.

Cosmetics are purely visual. They confer no gameplay advantage, and - since the global board ranks on lifetime earned rather than current balance - buying them costs you nothing but the Stars themselves. See [[global-leaderboard]].`
          }
        ]
      }
    ]
  },

  {
    id: "strategy",
    title: "Strategy",
    summary: "How the systems interact, and where the real decisions are.",
    chapters: [
      {
        id: "core-tensions",
        number: 1,
        title: "The Core Tensions",
        icon: "Compass",
        description: "Every meaningful decision in the game is one of these.",
        seeAlso: ["buying-stars", "hoarding-sink", "lock-in-versus-expiry"],
        sections: [
          {
            id: "spend-versus-hold",
            title: "Spend versus Hold",
            content: `Holding Coins costs you income through the sink and risks the season ending with them unconverted. Spending commits you at today's price. See [[hoarding-sink]].

The sink is designed so that steady conversion beats a single large one. If you find yourself holding a large balance with no plan for it, that is the game telling you something.`
          },
          {
            id: "boost-tradeoff",
            title: "Boosting versus Drops",
            content: `A Coin boost raises income and **lowers sigil drop odds** - see [[drop-pressure]]. Given a T6 sigil is worth 12,000 Seasonal Stars, trading drop rate for Coin rate is not obviously correct.

This is a genuine tradeoff rather than an upgrade, and it should be evaluated per season depending on how much you value sigils versus a faster conversion loop.`
          },
          {
            id: "specialise-versus-spread",
            title: "Specialising versus Spreading",
            content: `Per-family caps mean you cannot stack one family indefinitely, and the inventory-pressure threshold means holding a lot of anything slows your drops. See [[affinity]].

Combining relieves both at once - it raises value and lowers held count. A player who treats combining as routine maintenance rather than an occasional action will out-drop one who does not. See [[combining]].`
          }
        ]
      },
      {
        id: "timing",
        number: 2,
        title: "Timing the Season",
        icon: "Clock",
        description: "When to join, when to convert, when to leave.",
        seeAlso: ["season-structure", "lock-in", "sigil-drops"],
        sections: [
          {
            id: "blackout",
            title: "The Blackout",
            content: `The final **72 hours** of a season are a hard stop: no income, no sigil drops, no star purchases. The season only settles.

Hostile action cooldowns and protection windows are shortened during the Blackout, so the last phase is relatively more dangerous even though nothing is being earned.

Everything you intend to accumulate must be accumulated before the Blackout begins. Plan the last purchase for before that boundary, not after.`
          },
          {
            id: "overlapping-seasons",
            title: "Overlapping Seasons",
            content: `Because seasons run 14 days and start every 7, there are always two in flight. You can choose which to join, and the phase of a season when you join changes what it is worth to you.

Joining a season already in its late phase gives you less time to accumulate, but higher-tier sigils are reachable sooner. Joining fresh gives the full 11 active days but starts you in the early phase.

Note the **12-hour cumulative participation floor** on lock-in - join too late and lock-in may not be available to you at all. See [[what-lock-in-does]].`
          }
        ]
      }
    ]
  },

  {
    id: "reference",
    title: "Reference",
    summary: "Raw constants and tables. Everything here is read from the source.",
    chapters: [
      {
        id: "reference-timing",
        number: 1,
        title: "Timing Constants",
        icon: "Clock",
        description: "Season, tick and presence timings.",
        seeAlso: ["season-structure", "ticks-and-time"],
        sections: [
          {
            id: "timing-table",
            title: "Timing",
            content: `All values are global constants unless noted.

| Setting | Value | Notes |
|---|---|---|
| Season duration | 14 days | |
| New season cadence | 7 days | Seasons overlap |
| Blackout duration | 72 hours | End of every season |
| Tick cadence | deployment setting | Default 60s; live deployments differ |
| Idle timeout | 15 minutes | Inactivity before Idle |
| Forced-offline hold | 45 minutes | |
| Minimum participation for lock-in | 12 hours | Cumulative across the season |
| Hoarding window | 24 hours | |
| Ability unit duration | 15 minutes | Base unit for cooldowns |

> The tick cadence is the one value here that is **not** fixed. Any figure expressed "per tick" must be read against it. See [[ticks-and-time]].`
          }
        ]
      },
      {
        id: "reference-economy",
        number: 2,
        title: "Economy Constants",
        icon: "Coins",
        description: "UBI, hoarding sink and star price settings.",
        seeAlso: ["ubi-and-activity", "hoarding-sink", "star-price"],
        sections: [
          {
            id: "ubi-table",
            title: "Income",
            content: `Per-season columns - an operator can set these differently per season.

| Setting | Default | Meaning |
|---|---|---|
| Base UBI (Active) | 100 / min | Divided by ticks-per-minute in code |
| Idle factor | 0.30 | Idle pays 30% of Active |
| Minimum UBI | 1 / min | Floor after dampening |`
          },
          {
            id: "sink-table",
            title: "Hoarding Sink",
            content: `Per-season columns. **Disabled by default** - an operator must enable it per season.

| Setting | Default |
|---|---|
| Safe period | 12 hours |
| Safe minimum balance | 20,000 Coins |
| Tier 1 excess cap | 50,000 |
| Tier 2 excess cap | 200,000 |
| Cap as fraction of gross rate | 35% |
| Idle multiplier | x1.25 |

The three tier rates rise across the bands. The cap means the sink can never take more than 35% of your gross income. See [[how-it-scales]].`
          },
          {
            id: "price-table",
            title: "Star Price",
            content: `| Setting | Default | Scope |
|---|---|---|
| Absolute floor | 1 | Global |
| Season price cap | 10,000 | Per season |
| Idle coin weight | 0.25 | Per season |
| Max up-step per tick | ~0.2% | Per season |
| Max down-step per tick | ~1.0% | Per season |
| Pricing model version | 2 for new seasons | Per season |

See [[price-movement-limits]].`
          }
        ]
      },
      {
        id: "reference-sigils",
        number: 3,
        title: "Sigil Constants",
        icon: "Hexagon",
        description: "Tiers, caps, pressure thresholds and reference values.",
        seeAlso: ["sigil-drops", "sigil-tiers", "sigil-families"],
        sections: [
          {
            id: "sigil-table",
            title: "Sigils",
            content: `| Setting | Value |
|---|---|
| Maximum tier | 6 |
| Total inventory cap | 25 |
| Inventory pressure starts at | 10 held |
| Inventory pressure reaches zero at | 25 held |
| Per-family holding cap | 8 |
| Base drop chance | 0.35% per roll |

Reference Star values by tier: **50 / 250 / 1,000 / 3,000 / 9,000 / 12,000**.

> Constants named for a pity timer and a per-window drop cap exist in the config but are not used as a pity system. There is no pity mechanic. See [[drop-pressure]].`
          }
        ]
      },
      {
        id: "reference-hostile",
        number: 4,
        title: "Hostile Action Constants",
        icon: "Shield",
        description: "Spend tiers, cooldowns, caps and durations.",
        seeAlso: ["freeze", "theft", "ward"],
        sections: [
          {
            id: "hostile-table",
            title: "Hostile Actions",
            content: `| Setting | Value |
|---|---|
| Freeze - spendable tiers | T4, T5, T6 |
| Freeze - base duration | 30 minutes |
| Freeze - cooldown | 15 minutes |
| Freeze - target protection | 15 minutes |
| Theft - spendable tiers | T3, T4, T5 |
| Theft - targetable tiers | T1 to T6 |
| Theft - success cap | 60% |
| Theft - cooldown | 15 minutes |
| Theft - target protection | 15 minutes |
| Ward - max duration | 25% of season remaining |
| Self-melt - spendable tiers | T5, T6 |

Cooldown and protection windows are shortened during the Blackout. See [[blackout]].`
          }
        ]
      },
      {
        id: "reference-competition",
        number: 5,
        title: "Competition Constants",
        icon: "Trophy",
        description: "Conversion, bonuses and cosmetic pricing.",
        seeAlso: ["lock-in", "global-stars"],
        sections: [
          {
            id: "competition-table",
            title: "Competition",
            content: `| Setting | Value |
|---|---|
| Lock-in conversion | 65% |
| Natural expiry conversion | 100% |
| Participation bonus interval | 1 hour |
| Participation bonus cap | 56 |
| Placement bonus | 100 / 60 / 40 |
| Cosmetic price tiers | 25 / 80 / 250 / 800 / 2,400 |

Sigils are refunded at reference value on lock-in and destroyed at natural expiry. See [[lock-in-versus-expiry]].`
          }
        ]
      }
    ]
  }
];
