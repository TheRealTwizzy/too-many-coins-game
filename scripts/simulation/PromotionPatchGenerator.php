<?php

require_once __DIR__ . '/CanonicalEconomyConfigContract.php';
require_once __DIR__ . '/SimulationSeason.php';
require_once __DIR__ . '/../optimization/AgenticOptimization.php';

class PromotionPatchGeneratorException extends RuntimeException
{
    private array $details;

    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message);
        $this->details = $details;
    }

    public function details(): array
    {
        return $this->details;
    }
}

class PromotionPatchGenerator
{
    public const BUNDLE_SCHEMA_VERSION = 'tmc-promotion-bundle.v1';
    public const PATCH_STYLE_VERSION = 'tmc-play-test-reset-sql.v1';

    private const APPROVED_REPO_FILE_REGEX = '/^migration_[A-Za-z0-9_-]+\.sql$/';
    private const DEFAULT_OUTPUT_ROOT = __DIR__ . '/../../simulation_output/promotion-bundles';

    private const RESET_SEASON_ASSIGNMENTS = [
        'status' => "'Scheduled'",
        'season_expired' => '0',
        'expiration_finalized' => '0',
        'current_star_price' => 'LEAST(star_price_cap, GREATEST(100, COALESCE(current_star_price, 100)))',
        'market_anchor_price' => 'LEAST(star_price_cap, GREATEST(100, COALESCE(market_anchor_price, 100)))',
        'blackout_star_price_snapshot' => 'NULL',
        'blackout_started_tick' => 'NULL',
        'pending_star_burn_coins' => '0',
        'star_burn_ema_fp' => '0',
        'net_mint_ema_fp' => '0',
        'market_pressure_fp' => '1000000',
        'total_coins_supply' => '0',
        'total_coins_supply_end_of_tick' => '0',
        'coins_active_total' => '0',
        'coins_idle_total' => '0',
        'coins_offline_total' => '0',
        'effective_price_supply' => '0',
        'last_processed_tick' => 'start_time',
    ];

    private const RESET_PLAYER_ASSIGNMENTS = [
        'joined_season_id' => 'NULL',
        'participation_enabled' => '0',
        'idle_modal_active' => '0',
        'activity_state' => "'Active'",
        'idle_since_tick' => 'NULL',
        'last_activity_tick' => 'NULL',
        'online_current' => '0',
    ];

    private const OPTIONAL_TRUNCATE_TABLES = [
        'yearly_state',
        'active_freezes',
        'active_boosts',
        'sigil_drop_log',
        'sigil_drop_tracking',
        'player_season_vault',
        'season_vault',
        'season_participation',
        'trades',
        'sigil_theft_attempts',
        'economy_ledger',
        'pending_actions',
        'badges',
        'player_notifications',
    ];

    public static function generate(array $options): array
    {
        $repoRoot = self::resolveRepoRoot((string)($options['repo_root'] ?? ''));
        $candidateSeason = self::normalizeSeasonConfig(
            (array)($options['canonical_config'] ?? []),
            (string)($options['candidate_id'] ?? 'promotion-candidate'),
            'candidate'
        );

        $baseSeason = isset($options['base_season']) && is_array($options['base_season'])
            ? self::normalizeSeasonConfig(
                (array)$options['base_season'],
                (string)($options['candidate_id'] ?? 'promotion-candidate') . '|base',
                'base'
            )
            : SimulationSeason::build(1, 'promotion-patch-generator-base');

        $candidateId = self::sanitizeId((string)($options['candidate_id'] ?? self::defaultCandidateId($candidateSeason)));
        $patchableKeys = array_keys(CanonicalEconomyConfigContract::patchableParameters());

        $candidateSurface = self::patchableSeasonSurface($candidateSeason, $patchableKeys);
        $baseSurface = self::patchableSeasonSurface($baseSeason, $patchableKeys);

        $candidateMapped = CanonicalEconomyConfigContract::mapSimulatorPatchToPlayTestPatch($candidateSurface);
        $baseMapped = CanonicalEconomyConfigContract::mapSimulatorPatchToPlayTestPatch($baseSurface);

        $changedCanonicalPatch = [];
        $changedPlayTestPatch = [];
        foreach ($patchableKeys as $key) {
            $candidateValue = $candidateMapped['canonical_patch'][$key] ?? null;
            $baseValue = $baseMapped['canonical_patch'][$key] ?? null;
            if ($candidateValue === $baseValue) {
                continue;
            }

            $changedCanonicalPatch[$key] = $candidateValue;
            $changedPlayTestPatch[$key] = $candidateMapped['play_test_patch'][$key] ?? null;
        }

        if ($changedCanonicalPatch === []) {
            throw new PromotionPatchGeneratorException(
                'Promotion patch generator found no canonical config delta between base and candidate season config.',
                [
                    'candidate_id' => $candidateId,
                    'base_surface_sha256' => self::seasonSurfaceHash($baseSeason),
                    'candidate_surface_sha256' => self::seasonSurfaceHash($candidateSeason),
                ]
            );
        }

        $patchedSeason = $baseSeason;
        foreach ($changedPlayTestPatch as $key => $value) {
            $patchedSeason[$key] = $value;
        }

        $validatedPatchedSeason = self::normalizeSeasonConfig(
            $patchedSeason,
            $candidateId . '|patched-play-test',
            'patched play-test'
        );
        $patchedMapped = CanonicalEconomyConfigContract::mapSimulatorPatchToPlayTestPatch(
            self::patchableSeasonSurface($validatedPatchedSeason, $patchableKeys)
        );

        $candidateCanonicalFull = self::stableAssoc($candidateMapped['canonical_patch']);
        $patchedCanonicalFull = self::stableAssoc($patchedMapped['canonical_patch']);
        if ($candidateCanonicalFull !== $patchedCanonicalFull) {
            throw new PromotionPatchGeneratorException(
                'Generated play-test config does not match the canonical candidate config after schema validation.',
                [
                    'candidate_id' => $candidateId,
                    'candidate_canonical_patch' => $candidateCanonicalFull,
                    'patched_canonical_patch' => $patchedCanonicalFull,
                ]
            );
        }

        $migrationRelativePath = (string)($options['migration_relative_path'] ?? self::defaultMigrationRelativePath($candidateId, $changedCanonicalPatch));
        self::assertApprovedRepoPath($migrationRelativePath);
        self::assertRepoTargetDoesNotExist($repoRoot, $migrationRelativePath);

        $bundleHash = substr(AgenticOptimizationUtils::jsonHash(self::stableAssoc([
            'candidate_id' => $candidateId,
            'changed_canonical_patch' => $changedCanonicalPatch,
            'patch_style' => self::PATCH_STYLE_VERSION,
        ])), 0, 12);
        $bundleId = self::sanitizeId((string)($options['bundle_id'] ?? ('promotion-bundle-' . $bundleHash)));
        $bundleRoot = rtrim((string)($options['output_dir'] ?? self::DEFAULT_OUTPUT_ROOT), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $bundleId;

        $migrationSql = self::buildMigrationSql($candidateId, $bundleHash, $changedPlayTestPatch);
        $repoDiff = self::buildNewFileDiff($migrationRelativePath, $migrationSql);

        $bundle = [
            'schema_version' => self::BUNDLE_SCHEMA_VERSION,
            'patch_style_version' => self::PATCH_STYLE_VERSION,
            'bundle_id' => $bundleId,
            'candidate_id' => $candidateId,
            'bundle_signature_sha256' => AgenticOptimizationUtils::jsonHash(self::stableAssoc([
                'changed_canonical_patch' => $changedCanonicalPatch,
                'repo_change' => [$migrationRelativePath => hash('sha256', $migrationSql)],
            ])),
            'base_surface_sha256' => self::seasonSurfaceHash($baseSeason),
            'candidate_surface_sha256' => self::seasonSurfaceHash($candidateSeason),
            'patched_surface_sha256' => self::seasonSurfaceHash($validatedPatchedSeason),
            'changed_keys' => array_values(array_keys($changedCanonicalPatch)),
            'canonical_patch' => self::stableAssoc($changedCanonicalPatch),
            'play_test_patch' => self::stableAssoc($changedPlayTestPatch),
            'repo_changes' => [[
                'relative_path' => $migrationRelativePath,
                'change_type' => 'add',
                'approved' => true,
                'staged_path' => 'repo_files/' . str_replace('\\', '/', $migrationRelativePath),
                'content_sha256' => hash('sha256', $migrationSql),
                'line_count' => self::lineCount($migrationSql),
            ]],
            'validation' => [
                'candidate_contract_status' => (string)$candidateMapped['report']['status'],
                'base_contract_status' => (string)$baseMapped['report']['status'],
                'patched_contract_status' => (string)$patchedMapped['report']['status'],
                'schema_valid' => true,
                'candidate_matches_patched' => true,
                'changed_key_count' => count($changedCanonicalPatch),
                'approved_file_count' => 1,
            ],
            'artifacts' => [
                'bundle_json' => 'promotion_bundle.json',
                'bundle_md' => 'promotion_bundle.md',
                'canonical_patch_json' => 'canonical_patch.json',
                'play_test_patch_json' => 'play_test_patch.json',
                'patched_play_test_season_json' => 'patched_play_test_season.json',
                'repo_patch_diff' => 'repo_patch.diff',
                'staged_repo_files' => ['repo_files/' . str_replace('\\', '/', $migrationRelativePath)],
            ],
        ];

        AgenticOptimizationUtils::ensureDir($bundleRoot);
        AgenticOptimizationUtils::ensureDir($bundleRoot . DIRECTORY_SEPARATOR . 'repo_files');

        $stagedRepoFilePath = $bundleRoot . DIRECTORY_SEPARATOR . 'repo_files' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $migrationRelativePath);
        AgenticOptimizationUtils::ensureDir(dirname($stagedRepoFilePath));

        $bundleJsonPath = $bundleRoot . DIRECTORY_SEPARATOR . 'promotion_bundle.json';
        $bundleMdPath = $bundleRoot . DIRECTORY_SEPARATOR . 'promotion_bundle.md';
        $canonicalPatchPath = $bundleRoot . DIRECTORY_SEPARATOR . 'canonical_patch.json';
        $playTestPatchPath = $bundleRoot . DIRECTORY_SEPARATOR . 'play_test_patch.json';
        $patchedSeasonPath = $bundleRoot . DIRECTORY_SEPARATOR . 'patched_play_test_season.json';
        $repoDiffPath = $bundleRoot . DIRECTORY_SEPARATOR . 'repo_patch.diff';

        file_put_contents($stagedRepoFilePath, $migrationSql);
        file_put_contents($bundleJsonPath, json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($bundleMdPath, self::buildBundleMarkdown($bundle));
        file_put_contents($canonicalPatchPath, json_encode(self::stableAssoc($changedCanonicalPatch), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($playTestPatchPath, json_encode(self::stableAssoc($changedPlayTestPatch), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($patchedSeasonPath, json_encode(AgenticOptimizationUtils::convertSeasonForJson($validatedPatchedSeason), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($repoDiffPath, $repoDiff);

        return [
            'bundle' => $bundle,
            'artifact_paths' => [
                'bundle_root' => $bundleRoot,
                'bundle_json' => $bundleJsonPath,
                'bundle_md' => $bundleMdPath,
                'canonical_patch_json' => $canonicalPatchPath,
                'play_test_patch_json' => $playTestPatchPath,
                'patched_play_test_season_json' => $patchedSeasonPath,
                'repo_patch_diff' => $repoDiffPath,
                'staged_repo_file' => $stagedRepoFilePath,
            ],
        ];
    }

    private static function resolveRepoRoot(string $repoRoot): string
    {
        $resolved = $repoRoot !== '' ? realpath($repoRoot) : realpath(__DIR__ . '/../..');
        if ($resolved === false || !is_dir($resolved)) {
            throw new PromotionPatchGeneratorException('Promotion patch generator could not resolve the target repo root.');
        }

        return $resolved;
    }

    private static function normalizeSeasonConfig(array $season, string $seed, string $label): array
    {
        if ($season === []) {
            throw new PromotionPatchGeneratorException('Missing ' . $label . ' season config for promotion patch generation.');
        }

        $normalized = SimulationSeason::normalizeImportedRow($season);
        $allowed = array_fill_keys(array_merge(SimulationSeason::SEASON_ECONOMY_COLUMNS, ['season_seed_hex']), true);
        $unknown = [];
        foreach (array_keys($normalized) as $key) {
            if (!isset($allowed[$key])) {
                $unknown[] = $key;
            }
        }

        if ($unknown !== []) {
            sort($unknown);
            throw new PromotionPatchGeneratorException(
                'Unknown ' . $label . ' season config keys: ' . implode(', ', $unknown) . '.',
                ['label' => $label, 'unknown_keys' => $unknown]
            );
        }

        return SimulationSeason::build(
            max(1, (int)($normalized['season_id'] ?? 1)),
            $seed,
            $normalized
        );
    }

    private static function patchableSeasonSurface(array $season, array $patchableKeys): array
    {
        $surface = [];
        foreach ($patchableKeys as $key) {
            if (!array_key_exists($key, $season)) {
                throw new PromotionPatchGeneratorException('Season config is missing patchable key: ' . $key);
            }
            $surface[$key] = $season[$key];
        }

        return $surface;
    }

    private static function defaultCandidateId(array $candidateSeason): string
    {
        return 'candidate-' . substr(self::seasonSurfaceHash($candidateSeason), 0, 12);
    }

    private static function defaultMigrationRelativePath(string $candidateId, array $changedCanonicalPatch): string
    {
        $hashInput = [
            'candidate_id' => $candidateId,
            'changed_canonical_patch' => self::stableAssoc($changedCanonicalPatch),
            'patch_style' => self::PATCH_STYLE_VERSION,
        ];
        $hash = substr(AgenticOptimizationUtils::jsonHash($hashInput), 0, 12);
        $slug = strtolower(substr(self::sanitizeId($candidateId), 0, 48));
        $slug = trim($slug, '_');
        if ($slug === '') {
            $slug = 'candidate';
        }

        return 'migration_z_sim_promotion_' . $slug . '_' . $hash . '_test_reset.sql';
    }

    private static function assertApprovedRepoPath(string $relativePath): void
    {
        $normalized = str_replace('\\', '/', $relativePath);
        if (!preg_match(self::APPROVED_REPO_FILE_REGEX, $normalized)) {
            throw new PromotionPatchGeneratorException(
                'Promotion patch generator may only stage approved root migration files.',
                ['relative_path' => $relativePath]
            );
        }

        if (str_contains($normalized, '/')) {
            throw new PromotionPatchGeneratorException(
                'Promotion patch generator may not stage nested repo paths.',
                ['relative_path' => $relativePath]
            );
        }
    }

    private static function assertRepoTargetDoesNotExist(string $repoRoot, string $relativePath): void
    {
        $target = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (file_exists($target)) {
            throw new PromotionPatchGeneratorException(
                'Promotion patch generator refuses to overwrite an existing repo file.',
                ['target_path' => $target]
            );
        }
    }

    private static function buildMigrationSql(string $candidateId, string $bundleHash, array $playTestPatch): string
    {
        $lines = [];
        $lines[] = '-- Generated play-test promotion patch from promotion-eligible canonical config.';
        $lines[] = '-- Candidate: ' . $candidateId;
        $lines[] = '-- Bundle hash: ' . $bundleHash;
        $lines[] = '-- Scope: apply canonical season knobs and reset play-test runtime state.';
        $lines[] = '';
        $lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
        $lines[] = '';
        $lines[] = '-- 1) Apply promoted season config and reset mutable season runtime surfaces.';
        $lines[] = 'UPDATE seasons';
        $lines[] = 'SET ' . implode(',' . "\n    ", array_merge(
            self::buildLiteralAssignments($playTestPatch),
            self::buildRawAssignments(self::RESET_SEASON_ASSIGNMENTS)
        )) . ';';
        $lines[] = '';
        $lines[] = '-- 2) Preserve account/auth while detaching all players from season state.';
        $lines[] = 'UPDATE players';
        $lines[] = 'SET ' . implode(',' . "\n    ", self::buildRawAssignments(self::RESET_PLAYER_ASSIGNMENTS)) . ';';
        $lines[] = '';
        $lines[] = '-- 3) Reset server epoch/tick bootstrap so the next request rebuilds season timing from tick 0.';
        $lines[] = 'DELETE FROM server_state WHERE id = 1;';
        $lines[] = '';
        $lines[] = '-- 4) Reset runtime gameplay tables used by play-test verification.';
        foreach (self::OPTIONAL_TRUNCATE_TABLES as $tableName) {
            $lines[] = self::buildOptionalTruncateSql($tableName);
            $lines[] = '';
        }
        $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
        $lines[] = '';
        $lines[] = "SELECT 'promotion_patch_" . $bundleHash . "_complete' AS status;";

        return implode("\n", $lines) . "\n";
    }

    private static function buildLiteralAssignments(array $playTestPatch): array
    {
        $assignments = [];
        foreach ($playTestPatch as $key => $value) {
            $assignments[] = $key . ' = ' . self::sqlLiteral($value);
        }
        return $assignments;
    }

    private static function buildRawAssignments(array $rawAssignments): array
    {
        $assignments = [];
        foreach ($rawAssignments as $key => $expression) {
            $assignments[] = $key . ' = ' . $expression;
        }
        return $assignments;
    }

    private static function buildOptionalTruncateSql(string $tableName): string
    {
        $quoted = str_replace('`', '``', $tableName);

        return implode("\n", [
            'SET @has_' . $quoted . ' := (',
            '    SELECT COUNT(*) FROM information_schema.tables',
            "    WHERE table_schema = DATABASE() AND table_name = '" . $quoted . "'",
            ');',
            "SET @sql := IF(@has_" . $quoted . " > 0, 'TRUNCATE TABLE `" . $quoted . "`', 'SELECT 1');",
            'PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;',
        ]);
    }

    private static function sqlLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if (!is_string($value)) {
            throw new PromotionPatchGeneratorException('Unsupported SQL literal type in promotion patch generation.');
        }

        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
    }

    private static function buildNewFileDiff(string $relativePath, string $content): string
    {
        $normalizedPath = str_replace('\\', '/', $relativePath);
        $lines = explode("\n", rtrim($content, "\n"));
        $diff = [];
        $diff[] = 'diff --git a/' . $normalizedPath . ' b/' . $normalizedPath;
        $diff[] = 'new file mode 100644';
        $diff[] = '--- /dev/null';
        $diff[] = '+++ b/' . $normalizedPath;
        $diff[] = '@@ -0,0 +1,' . count($lines) . ' @@';
        foreach ($lines as $line) {
            $diff[] = '+' . $line;
        }

        return implode("\n", $diff) . "\n";
    }

    private static function buildBundleMarkdown(array $bundle): string
    {
        $lines = [];
        $lines[] = '# Promotion Patch Bundle';
        $lines[] = '';
        $lines[] = '- Bundle: `' . (string)$bundle['bundle_id'] . '`';
        $lines[] = '- Candidate: `' . (string)$bundle['candidate_id'] . '`';
        $lines[] = '- Patch style: `' . (string)$bundle['patch_style_version'] . '`';
        $lines[] = '- Changed keys: `' . (int)count((array)$bundle['changed_keys']) . '`';
        $lines[] = '- Repo changes: `' . (int)count((array)$bundle['repo_changes']) . '`';
        $lines[] = '- Schema valid: `' . (!empty($bundle['validation']['schema_valid']) ? 'true' : 'false') . '`';
        $lines[] = '- Candidate matches patched: `' . (!empty($bundle['validation']['candidate_matches_patched']) ? 'true' : 'false') . '`';
        $lines[] = '';
        $lines[] = '## Changed Keys';
        $lines[] = '';
        foreach ((array)$bundle['changed_keys'] as $key) {
            $lines[] = '- `' . (string)$key . '`';
        }
        $lines[] = '';
        $lines[] = '## Repo Changes';
        $lines[] = '';
        foreach ((array)$bundle['repo_changes'] as $change) {
            $lines[] = '- `' . (string)$change['relative_path'] . '` (`' . (string)$change['change_type'] . '`)';
        }

        return implode("\n", $lines) . "\n";
    }

    private static function seasonSurfaceHash(array $season): string
    {
        $jsonSeason = AgenticOptimizationUtils::convertSeasonForJson($season);
        AgenticOptimizationUtils::sortAssocRecursively($jsonSeason);
        return AgenticOptimizationUtils::jsonHash($jsonSeason);
    }

    private static function stableAssoc(array $value): array
    {
        AgenticOptimizationUtils::sortAssocRecursively($value);
        return $value;
    }

    private static function sanitizeId(string $value): string
    {
        $sanitized = AgenticOptimizationUtils::sanitize($value);
        return $sanitized === '' ? 'promotion' : $sanitized;
    }

    private static function lineCount(string $content): int
    {
        return count(explode("\n", rtrim($content, "\n")));
    }
}
