# Too Many Coins Agent Rules

## Repo model
- source/dev is the working lane
- too-many-coins-game is public sandbox
- too-many-coins-live is public live

## Deployment model
- Dokploy app `too-many-coins-test` deploys only from test repo
- live deployment deploys only from live repo

## Release discipline
- all feature work starts in source/dev
- push approved builds to test first
- only promote approved tested commits to live

## Notes
- keep deployment changes minimal
- preserve init/db behavior unless explicitly fixing it
- do not mix sandbox and live env values

## Simulation config integrity rules
- Every simulation run must pass through the canonical effective-config resolver.
- Any candidate change touching an inactive, unknown, shadowed, or disabled key must fail preflight.
- No simulation may start unless `effective_config.json` and `effective_config_audit.md` are generated.
- Unknown config keys are errors, not warnings.
- Candidate patches must be validated against the canonical schema before execution.