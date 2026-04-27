# Tactical Utility Flow v2.1 Plan

Date: 2026-04-27
Branch: source/dev

## Implementation Checklist

- [ ] Add failing tests for Tier 3 theft spend, Tier 4/5/6 freeze config, policy freeze targeting with Tier 4, and policy theft targeting with Tier 3.
- [ ] Add tier-scaled freeze config constants and runtime audit keys.
- [ ] Update production freeze to accept configured spend tiers and optional requested tier.
- [ ] Update theft error copy and spend-tier calculations for Tier 3/4/5.
- [ ] Update simulation policy and in-memory player execution to use configured utility tiers.
- [ ] Update production-path lifecycle runner theft/freeze dispatch for parity.
- [ ] Run focused unit tests and syntax checks.
- [ ] Run canonical v2.1 simulation batch, analysis, and diagnosis.
- [ ] Decide whether v2.1 is ready for sandbox/main playtesting or needs another patch.
