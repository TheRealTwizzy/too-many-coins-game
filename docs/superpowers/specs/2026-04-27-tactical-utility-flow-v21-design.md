# Tactical Utility Flow v2.1 Design

Date: 2026-04-27
Branch: source/dev

## Problem

The v2 economy diagnosis removed the high-severity boost imbalance, but the remaining flow still routes too many sigils into boosts. Freeze and theft usage are low because the mechanics are gated behind scarce high-tier sigils:

- Freeze requires Tier 6, which is nearly unreachable after v2 scarcity.
- Theft requires Tier 4 or Tier 5, so lower-mid sigils rarely create tactical decisions.
- Simulation policy checks the same hard gates, so agents mostly never try those actions.

## Goal

Create a tactical utility lane without undoing v2 scarcity:

- Keep `SIGIL_DROP_CHANCE_FP` at the v2 1.0% gate.
- Allow lower-tier utility actions with scaled power.
- Keep production, simulation, and effective-config audit aligned.
- Re-run diagnosis after implementation before recommending promotion.

## Design

Freeze becomes tier-scaled:

- Tier 4: micro-freeze, 0.5 ability units.
- Tier 5: standard tactical freeze, 1.0 ability unit.
- Tier 6: existing premium freeze, 2.0 ability units.
- Blackout freezes keep the existing 50% duration rule.
- Legacy `FREEZE_BASE_DURATION_TICKS` remains Tier 6 duration for compatibility.

Theft opens one tier lower:

- Spend tiers become Tier 3, Tier 4, Tier 5.
- Tier 3 theft is naturally constrained by utility value, so it can fund only low-value loot.
- Existing cooldown, protection, value pressure, and success cap remain unchanged.

Policy behavior follows the same gates:

- Freeze policy checks `SIGIL_FREEZE_SPEND_TIERS` instead of hard-coded Tier 6.
- Theft policy checks `SIGIL_THEFT_SPEND_TIERS` instead of hard-coded Tier 4/5.
- Late and blackout utility pressure increases slightly when players carry usable utility sigils.

## Success Criteria

- Focused tests prove Tier 3 theft and Tier 4/5/6 freeze are available.
- Policy tests prove agents can select freeze with only Tier 4 and theft with only Tier 3.
- Simulation preflight audit includes the new utility-flow runtime constants.
- Canonical simulation batch completes with generated effective-config artifacts.
- Diagnosis improves action mix without reintroducing a high-severity boost imbalance.
