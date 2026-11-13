# Active Return Gameplay Flow Design

Date: 2026-04-29
Branch: source/dev

## Context

Recent play-test feedback says the economy feels too slow, stagnant, and uneventful. One player reported staying active for more than five minutes without being able to boost, collect sigils, or perform an economic action.

The last economy batch solved high-severity boost dominance and mostly-idle viability, but it did so by making boost recovery longer and sigil drops scarcer. The current result is mathematically safer but experientially too quiet. Players need two reliable loops:

- idle or offline players need a reason to return and continue playing
- active players need enough ongoing fuel to keep making economic decisions

This pass is only about gameplay flow and pace. It does not add sigil destruction, more sigil tiers, new theft rules, or other future mechanics.

## Problem

The existing deterministic sigil drop gate is too sparse for short active sessions. With the current `SIGIL_DROP_CHANCE_FP = 3500`, a five-minute active window can easily produce no drop, especially on the public-test cadence where ticks are not one per second. That makes the correct long-run scarcity tune feel like short-run dead air.

The current simulation diagnosis also shows underused economic actions:

- `combine`: 3.6% of actions
- `freeze`: 3.8% of actions
- `theft`: 3.3% of actions
- `BLACKOUT`: 0.9% of actions

The player-facing issue is not only total earning rate. It is the absence of a guaranteed near-term thing to do.

## Goals

- Returning from idle or offline should produce a small participation moment quickly.
- Active players should not sit for five or more real minutes with no sigil, boost fuel, or economic action opportunity.
- The fix should preserve long-run scarcity and avoid reopening boost dominance.
- The implementation must remain canonical-config safe: simulation runs still go through the effective-config resolver and generate `effective_config.json` plus `effective_config_audit.md`.
- The first shipped version should be simple enough to reason about and tune.

## Non-Goals

- No new sigil tiers.
- No sigil destruction mechanic.
- No redesign of theft, freeze, boost, lock-in, or star pricing.
- No global economy reset.
- No deployment or live-repo changes.

## Options Considered

1. Raise global sigil drop and idle rates.
   - This is simple, but it increases output everywhere and can revive boost chaining or sigil overabundance.

2. Add only a return reward.
   - This helps players come back, but it does not solve the active-session dead zone.

3. Add a return pulse plus an active dry-spell pulse.
   - This targets the exact dead-air moments while keeping random drops and long-run scarcity mostly intact.
   - This is the recommended design.

## Selected Design

Add a bounded participation pacing system with two pulse types.

### Return Pulse

When a player transitions into `Active` from `Idle` or `Offline`, the game checks whether they are eligible for a small return participation pulse.

Initial candidate behavior:

- eligible after a meaningful idle/offline gap, initially 5 real minutes
- cooldown after a return pulse, initially 30 real minutes
- reward is one low-tier sigil, initially Tier 1
- if the player is already at sigil capacity, do not overfill inventory; the player already has spendable fuel
- record the grant source as `return_pulse`

This should run through the same capacity rules used by normal sigil drops, especially `Economy::canReceiveSigilTier()`.

Trigger points:

- login/session restoration when presence becomes active
- `idleAck()`
- season join or rejoin when the player enters active play

### Active Dry-Spell Pulse

While a player remains active, track whether they have gone too long without a meaningful economic event.

Initial candidate behavior:

- if an active player has no sigil drop and no meaningful economic action for 5 real minutes, grant one Tier 1 sigil
- reset the dry-spell timer when the player receives a sigil, buys or extends a boost, combines sigils, buys stars, freezes, melts, attempts theft, locks in, joins, or rejoins
- record the grant source as `active_dry_spell_pulse`
- do not grant beyond sigil capacity

This is not a flat drop-rate increase. Random drops can still happen naturally. The pulse only catches unlucky or quiet active windows.

### State

Add explicit participation pacing state to `season_participation` so runtime and simulation can agree:

- `last_meaningful_economy_tick`
- `last_return_pulse_tick`
- `last_active_pulse_tick`
- `return_pulses_total`
- `active_pulses_total`

These are the intended column names. They answer three questions:

- when did this player last receive or perform something meaningful?
- when did this player last receive a return pulse?
- when did this player last receive an active pulse?

### Canonical Config

Expose pacing values through the canonical economy config contract before they are used in simulation candidates:

- `return_pulse_min_gap_ticks`
- `return_pulse_cooldown_ticks`
- `active_dry_spell_ticks`
- `participation_pulse_reward_tier`

Candidate patches touching these keys must be validated by the canonical schema. Unknown, inactive, shadowed, or disabled keys remain hard errors.

Every simulation run for this work must generate:

- `effective_config.json`
- `effective_config_audit.md`

The audit must show the participation pacing keys and their sources.

### Simulation Metrics

Add or extend metrics so the diagnosis can see flow, not only long-run balance:

- active dry-spell gap distribution
- return pulse count per player
- active pulse count per player
- sigils granted by source
- action opportunities created by pulses
- boost coin share after pulses

The key gameplay metric is: active players should not have a 5+ real-minute no-op window unless they are already at inventory capacity or blocked by a deliberate game rule.

## Guardrails

- Pulse rewards are low tier only in this pass.
- Pulse grants must respect inventory capacity.
- Pulse cadence must be expressed in real-time-derived ticks through existing timing helpers.
- Pulses must not bypass idle gating for actions.
- Pulses must not grant during blackout if the existing sigil drop path would block that reward.
- If simulation reopens a high-severity boost dominance finding, reduce pulse cadence or add a stricter per-session cap before considering global rate changes.

## Tests

Focused tests should cover:

- a player returning from idle/offline receives one eligible return pulse
- repeated idle acknowledgements or refreshes do not farm return pulses
- an active player with no economic event receives an active dry-spell pulse at the configured threshold
- normal sigil drops and economic actions reset the active dry-spell timer
- full sigil inventory blocks pulse overfill
- simulation preflight reports the pacing keys as active and audited
- candidate patches for unknown or inactive pacing keys fail preflight

## Validation

Run focused PHP tests first, then run a canonical simulation smoke with candidate pacing values, then run the standard batch and diagnosis.

Success criteria:

- focused tests pass
- simulation audits include the pacing config
- no HIGH severity diagnosis findings
- boost top-quartile coin delta share remains below the current high-severity line
- active dry-spell gaps are bounded by the configured threshold for eligible active players
- return pulses occur for eligible returning players and do not appear farmable

## Rollout

Ship this first as a source/dev play-test candidate. If validation passes, promote to public test through the normal `source/dev` to `main` path. Do not touch live repo values in this pass.
