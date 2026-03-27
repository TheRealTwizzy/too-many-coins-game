# Wiki Migration Status

Imported source package:
- `wiki_source/imported/20260327-120122`

## Source profile
- Type: React + TypeScript + Tailwind wiki app
- Primary content source: `client/src/lib/wikiData.ts`
- Notes: Source contains 5 categories and 13 chapters.

## Migrated to static wiki routes
- `/wiki/` (landing and navigation)
- `/wiki/getting-started/` (full chapters 1-2)
- `/wiki/game-systems/` (full chapters 3-6)
- `/wiki/competition/` (full chapters 7-9)
- `/wiki/social/` (full chapters 10-11)
- `/wiki/strategy/` (full chapters 12-13)
- `/wiki/search/` (client-side full text search)

Data/render architecture for full parity:
- `public/wiki/assets/wiki-data.js` (auto-generated from imported `wikiData.ts`)
- `public/wiki/assets/wiki-render.js` (category rendering + markdown conversion + search engine)

## Pending for full parity
1. Bring over source imagery where desired and host under `public/wiki/assets/`.
2. Add chapter next/previous controls if desired.
3. Improve markdown fidelity for edge cases if future content adds new syntax.

## Update workflow for next ZIP refresh
1. Import ZIP:
   - `./tools/import-wiki-zip.ps1 -ZipPath "<path-to-zip>"`
2. Identify latest extracted folder under `wiki_source/imported/`.
3. Regenerate `public/wiki/assets/wiki-data.js` from new `client/src/lib/wikiData.ts`.
4. Reload static category routes under `public/wiki/` (rendered via shared script).
5. Validate routes and API coexistence:
   - `/wiki/`
   - `/wiki/getting-started/`
   - `/wiki/search/?q=lock+in`
   - `/api/index.php?action=game_state`
