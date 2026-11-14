# Branch inventory

Snapshot taken 2026-11-13 against `origin/main` at `49637ea`. This file drives the stale-branch cleanup (room tasks B1–B5). Update a branch's row when its audit finishes. Nothing is deleted until the owner approves the delete list.

| Branch | Tip | Last commit | Ahead / behind main | Open PR | Verdict |
|---|---|---|---|---|---|
| `main` | `49637ea` | 2026-11-13 | — | — | **Keep**: public test branch, deployed to test.too-many-coins.com |
| `source/dev` | `42243bf` | 2026-11-13 | 1 / 4 | — | **Keep**: dev sandbox. Resync with main in B4 |
| `task/9-t2-port-active-return-hermes` | `fd2c357` | 2026-11-13 | 1 / 0 | #90 | **Keep until #90 merges**, then delete |
| `codex/active-return-gameplay-flow` | `fa0f597` | 2026-04-29 | 29 / 105 | — | **Delete after B2 audit** (archive tag first) |
| `backup/source-dev-2026-11-13` | `b761b0b` | 2026-04-29 | 21 / 105 | — | **Delete after B3 audit** (archive tag first) |

## Notes per branch

### `source/dev`
Its one commit ahead of main is `42243bf docs: carry over source/dev design and plan docs`. Main gained four commits since (#88, #86, #84, #85). B4 merges main back in through a PR, with no force-push.

### `task/9-t2-port-active-return-hermes`
Head of PR #90. It ports participation pacing (return and dry-spell pulses) to main as a single commit. GitHub can delete it on merge once auto-delete is turned on (B5).

### `codex/active-return-gameplay-flow`
- `backup/source-dev-2026-11-13` is a **strict ancestor** of this branch: the backup's 21 commits plus 8 participation-pacing commits on top.
- `git cherry origin/main` finds 14 of its commits already on main as equivalent patches (the social account moderation feature) and 14 with no equivalent:
  - docs and plans: `632c387`, `d20e04f`, `92379c0`, `b761b0b`
  - simulation: `0112679 Tighten simulation config validation`, `8a9285b Align simulation smoke with hourly test cadence`
  - participation pacing (8): `3936559`, `f9cdf59`, `ce54e23`, `dfdf522`, `dacdb6e`, `b7fd57c`, `1d3a070`, `fa0f597`
- The pacing commits are expected to be covered by #90's squashed port. The docs may already be on main by content via #86. **B2 must confirm both and decide what happens to the two simulation commits.**

### `backup/source-dev-2026-11-13`
- Despite its name, the tip is dated 2026-04-29: it is a snapshot of the April `source/dev`, not of today's.
- Every commit on it is also on `codex/active-return-gameplay-flow`, so once B2 clears that branch, this one holds nothing unique. **B3 confirms this.**

## Cleanup rules
1. Before deleting a branch, push an `archive/<branch-name>` tag at its tip so the commits stay reachable.
2. Delete only branches the owner approved through `request_approval`.
3. Never force-push or delete `main` or `source/dev`.
4. Turn on "Automatically delete head branches" so merged PR branches stop piling up.

## How to refresh this table
```sh
git fetch origin --prune
for b in $(git for-each-ref --format='%(refname:strip=3)' refs/remotes/origin | grep -v '^HEAD$'); do
  echo "$b $(git rev-list --left-right --count origin/$b...origin/main) $(git log -1 --format=%cs origin/$b)"
done
```
