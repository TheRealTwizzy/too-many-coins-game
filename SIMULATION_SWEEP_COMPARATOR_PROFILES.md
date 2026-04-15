# Sweep Comparator Profiles

## Official Qualification Profile

Use this to prove the standalone sweep/comparator path in a reproducible way without paying the cost of the full follow-up campaign.

- Profile id: `qualification`
- Scenario source: `simulation_output/sweep/followup-tuning-candidates-20260413.json`
- Scenario set:
  - `phase-gated-safe-24h-v1`
- Simulators: `B,C`
- Include baseline: `true`
- Players per archetype: `2`
- Season count: `4`
- Expected completion envelope: `2.5-4.0 minutes`
- Why this is the qualification profile:
  - still produces a real `reject` disposition
  - still produces rejection attribution artifacts
  - still exercises both Sim B and Sim C with baseline pairing
  - stays inside the qualification budget that the full follow-up bundle exceeded

Command:

```powershell
php scripts/run_sweep_comparator_campaign.php `
  --profile=qualification `
  --seed=qualification-proof
```

Artifacts:

- `simulation_output/sweep-comparator/<seed>/sweep/policy_sweep_<seed>_ppa2_s4.json`
- `simulation_output/sweep-comparator/<seed>/comparator/comparison_<seed>.json`
- `simulation_output/sweep-comparator/<seed>/sweep_comparator_report.json`
- `simulation_output/sweep-comparator/<seed>/sweep_comparator_report.md`

## Official Full Campaign Profile

Use this when reviewing the full fixed follow-up bundle at the same reproducible baseline.

- Profile id: `full-campaign`
- Scenario source: `simulation_output/sweep/followup-tuning-candidates-20260413.json`
- Scenario set:
  - `phase-gated-safe-24h-v1`
  - `phase-gated-safe-48h-v1`
  - `phase-gated-high-floor-v1`
  - `phase-gated-plus-inflation-tighten-v1`
- Simulators: `B,C`
- Include baseline: `true`
- Players per archetype: `2`
- Season count: `4`
- Expected completion envelope: `6.5-9.0 minutes`
- Operational meaning:
  - this is the campaign-level follow-up review
  - it is not the default qualification proof because the extra scenarios add several minutes while not changing the comparator mechanics being qualified

Command:

```powershell
php scripts/run_sweep_comparator_campaign.php `
  --profile=full-campaign `
  --seed=followup-campaign
```

## Timing Notes

- The timeout root cause is in Simulation D sweep execution, not in Simulation E comparison.
- On the current repo and fixed follow-up bundle, Sim E finished in well under one second for both profiles.
- The runner now records per-run sweep timings, comparator stage timings, and a top-level campaign report so qualification decisions can cite measured durations instead of anecdotal timeouts.
- If you want to run either profile against a DB-derived season snapshot, pass `--season-config=FILE` only when that file was produced by the current canonical exporter. Legacy snapshots that still contain metadata keys such as `season_id` are expected to fail strict preflight.
