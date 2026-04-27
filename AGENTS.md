# Too Many Coins Agent Rules

## Repo model
- TheRealTwizzy/too-many-coins-game is the public test repo
- source/dev is the dev sandbox branch inside too-many-coins-game
- main is the public test branch deployed to test.too-many-coins.com
- too-many-coins.com currently redirects to test.too-many-coins.com until full release
- TheRealTwizzy/too-many-coins-live is the full release/live repo

## Deployment model
- Dokploy app `too-many-coins-test` deploys only from TheRealTwizzy/too-many-coins-game `main`
- live deployment deploys only from live repo

## Release discipline
- all feature work starts in source/dev
- promote approved source/dev builds to too-many-coins-game main for public test first
- only promote approved tested commits to live

## Notes
- keep deployment changes minimal
- preserve init/db behavior unless explicitly fixing it
- do not mix public test and live env values

## Simulation config integrity rules
- Every simulation run must pass through the canonical effective-config resolver.
- Any candidate change touching an inactive, unknown, shadowed, or disabled key must fail preflight.
- No simulation may start unless `effective_config.json` and `effective_config_audit.md` are generated.
- Unknown config keys are errors, not warnings.
- Candidate patches must be validated against the canonical schema before execution.
