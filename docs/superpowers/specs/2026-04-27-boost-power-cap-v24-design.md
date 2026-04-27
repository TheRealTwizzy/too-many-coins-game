# Boost Power Cap v2.4 Design

Date: 2026-04-27
Branch: source/dev

## Problem

V2.3 verified the fixed-session uptime governor, but the diagnosis still reported boost dominance:

- Boost explains 67.8% of the top-quartile coin delta.
- Most archetypes earn more than 60% of coins while boosted.
- Median sigil acquisition throughput remains high enough to keep boost purchasing frequent.

The remaining high-severity issue is not only uptime. Players who are boosted can still stack one SELF boost row toward `BoostCatalog::TOTAL_POWER_CAP_FP` (500%). The catalog already defines `BoostCatalog::POWER_CAP_FP_PER_PRODUCT` (100%), but the runtime action path, simulator, and parity harness use the 500% total cap as the active row cap.

## Goal

Make one SELF boost session powerful but bounded:

- A single active SELF boost row may not exceed `BoostCatalog::POWER_CAP_FP_PER_PRODUCT` (100%).
- The existing `BoostCatalog::TOTAL_POWER_CAP_FP` (500%) remains available as the combined total guard for current and future multi-row/product behavior.
- Runtime purchase execution, API response metadata, simulator behavior, and runtime parity certification must agree.
- Simulation preflight audit must expose the active per-product power cap.
- No database schema or environment changes are required.

## Design

Boost power purchase logic should use two caps:

- `productPowerCapFp = BoostCatalog::POWER_CAP_FP_PER_PRODUCT`
- `totalPowerCapFp = BoostCatalog::TOTAL_POWER_CAP_FP`

When an active SELF boost exists and the player buys more power, the active row modifier is projected with the product cap:

`min(productPowerCapFp, currentModifier + tierPowerIncrement)`

The total combined guard then checks the projected row against the combined active boost total. This preserves the existing total-cap contract without letting one SELF row consume the entire 500% budget.

Initial activations already start at or below 100%, so no initial-duration or initial-power table changes are needed. Time extension behavior from v2.3 remains unchanged.

## Testing

- Add failing simulator and runtime parity tests showing Tier 5 cannot push an active SELF boost from 100% to 200%.
- Update catalog/runtime tests to assert the 100% per-product cap and 500% total cap remain distinct.
- Update preflight tests to assert `boost_power_cap_fp_per_product` is present in effective config output.
- Run the focused boost/economy/parity PHPUnit suite.
- Run a canonical effective-config simulation batch with generated audit artifacts before any promotion decision.

## Success Criteria

- Tests fail before implementation and pass after implementation.
- Runtime, simulator, and parity certification enforce the same per-product cap.
- API/catalog metadata reports `power_cap_fp = 1000000` and `total_power_cap_fp = 5000000`.
- Effective config audit reports `boost_power_cap_fp_per_product = 1000000`.
- V2.4 diagnosis materially reduces boost share of top-quartile coin delta from the v2.3 67.8% baseline, or clearly proves the next lever should be sigil supply rather than boost power.
