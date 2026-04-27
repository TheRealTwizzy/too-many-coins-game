# Boost Burst Window v2.2 Design

Date: 2026-04-27
Branch: source/dev

## Problem

The v2.1 tactical utility patch improved freeze access, but the diagnosis regressed to a high-severity boost-dominance finding:

- Top-quartile coin delta was 98.1% boost-driven.
- Boost-focused vs Regular score ratio rose to 1.60.
- Mean boosted ticks increased across most archetypes.

The root issue is not only sigil choice. Boosts still provide too much season coverage through long initial windows and long time extensions.

## Goal

Keep v2.1 utility flow, but make boosts tactical bursts:

- Reduce the per-product boost time cap from 12 hours to 4 hours.
- Shorten initial boost windows by tier.
- Shorten time-extension purchases sharply.
- Preserve boost power identity for now so the patch isolates time coverage.

## Proposed Timing

Initial boost windows:

- Tier 1: 4 hours
- Tier 2: 3 hours
- Tier 3: 2 hours
- Tier 4: 1 hour
- Tier 5: 30 minutes

Time extensions:

- Tier 1: 5 minutes
- Tier 2: 10 minutes
- Tier 3: 30 minutes
- Tier 4: 60 minutes
- Tier 5: 120 minutes

## Success Criteria

- Focused tests prove initial windows and extension windows match the burst model.
- Canonical runtime audit records the new boost cap.
- Full baseline batch has 21/21 completed runs and all effective-config artifacts.
- Diagnosis removes the high-severity boost-dominance finding or clearly identifies the next direct boost-power/cost lever.
