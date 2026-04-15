# Simulation Mechanic Integration Contract

Any mechanic added to the economy must satisfy all of the following before it may enter the optimizer search space.

## Required Declarations

Every new mechanic must declare:

| Field | Required | Example |
|-------|----------|---------|
| `mechanic_id` | YES | `market_affordability_bias` |
| `runtime_path` | YES | `Economy::calculateStarPrice()` |
| `sim_path` | YES | `SimulationPlayer::buyStars()` |
| `parity_test_id` | YES | `star_pricing_affordability::star-pricing-affordability-bias` |
| `tunable_parameters` | YES | `['market_affordability_bias_fp']` |
| `affected_metrics` | YES | `['star_purchase_density', 'archetype_viability_min_ratio']` |
| `balance_risks` | YES | `['may reduce lock-in incentives if price drops too far']` |
| `feature_flag` | IF GATED | `hoarding_sink_enabled` |

## Required Steps for Adding a New Mechanic

1. **Wire into runtime (`includes/*.php`)**: The mechanic must be read by the actual tick engine or economy functions. Knobs that are stored but not read are no-ops.

2. **Mirror in simulation (`scripts/simulation/SimulationPlayer.php`)**: The simulation must call the same runtime function (or an identical inline copy) for parity.

3. **Mark as referenced in preflight (`scripts/simulation/SimulationConfigPreflight.php`)**: Set `'referenced' => true` in `SEASON_KEY_META`.

4. **Add to canonical schema (`scripts/simulation/CanonicalEconomyConfigContract.php`)**: Add to `PATCHABLE_PARAMETER_SCHEMA` and `candidateSearchParameters()`. Remove from `CANDIDATE_SEARCH_SURFACE_REMOVALS` if present.

5. **Add parity fixture (`scripts/simulation/RuntimeParityCertification.php`)**: Add at least one fixture to the relevant domain that proves the simulator and runtime agree on the mechanic's output.

6. **Run parity certification**: `php scripts/certify_runtime_parity.php` must pass with `certified: true`.

7. **Add to search surface only after parity passes**: Do not add to `AgenticEconomyDecomposition` subsystem `owned_parameters` until parity is confirmed.

8. **Wire into subsystem(s)**: Add the parameter to the most relevant agentic subsystem's `owned_parameters` with appropriate `mode` and `values`.

## No-Op Protection

Before removing a knob from `CANDIDATE_SEARCH_SURFACE_REMOVALS`, prove it is referenced by:
- Grepping for the knob name in `includes/economy.php` and `includes/tick_engine.php`
- Confirming it appears in a code path that runs on every tick or player action

If the knob is not found in those files, it is a no-op and must NOT enter the search space.

## Validation Before PR Merge

Before merging any mechanic that adds new patchable parameters:

```bash
php vendor/bin/phpunit tests/RuntimeParityCertificationTest.php --no-coverage -v
php vendor/bin/phpunit tests/SimulationConfigPreflightTest.php --no-coverage -v
php vendor/bin/phpunit tests/CanonicalEconomyConfigContractTest.php --no-coverage -v
php vendor/bin/phpunit tests/AgenticOptimizationTest.php --no-coverage -v
```

All must pass.
