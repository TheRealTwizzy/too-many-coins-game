# Cleanup Batch 002 Summary

## Batch
- ID: `2026-04-16-batch-002`
- Date: `2026-04-16`
- Action type: quarantine-only
- Deletes: none

## Scope
- `tmp/full-campaign-proof-20260414b`
- `tmp/qualification-20260414`
- `tmp/qualification-20260414-rerun`
- `tmp/q14`

## Actions Performed
- Moved all four scoped directories to `tmp/_quarantine/2026-04-16-batch-002/payload/tmp/...` with structure preserved.
- Wrote `quarantine_manifest.json` with required fields and per-item restore commands.

## Safety Notes
- No code/runtime/protected-path changes.
- No `simulation_output/` changes.
- Zero deletes in this batch.
- Fully reversible via the manifest restore instructions.
