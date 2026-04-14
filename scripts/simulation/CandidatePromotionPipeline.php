<?php

require_once __DIR__ . '/SimulationSeason.php';
require_once __DIR__ . '/CanonicalEconomyConfigContract.php';
require_once __DIR__ . '/EconomicCandidateValidator.php';
require_once __DIR__ . '/SimulationConfigPreflight.php';
require_once __DIR__ . '/SimulationPopulationSeason.php';
require_once __DIR__ . '/SimulationPopulationLifetime.php';
require_once __DIR__ . '/MetricsCollector.php';
require_once __DIR__ . '/PolicySweepRunner.php';
require_once __DIR__ . '/../optimization/AgenticOptimization.php';

class CandidatePromotionPipeline
{
    public const STATE_SCHEMA_VERSION = 'tmc-candidate-promotion-state.v1';
    public const REPORT_SCHEMA_VERSION = 'tmc-candidate-promotion-report.v1';
    public const PATCH_SCHEMA_VERSION = 'tmc-candidate-patch.v1';
    public const DEBUG_BYPASS_ENV = 'TMC_SIMULATION_PROMOTION_DEBUG_BYPASS';

    private const STAGES = [
        ['index' => 1, 'id' => 'candidate_schema_validation', 'label' => 'candidate schema validation'],
        ['index' => 2, 'id' => 'effective_config_preflight', 'label' => 'effective-config preflight'],
        ['index' => 3, 'id' => 'targeted_subsystem_harnesses', 'label' => 'targeted subsystem harnesses'],
        ['index' => 4, 'id' => 'full_single_season_validation', 'label' => 'full single-season validation'],
        ['index' => 5, 'id' => 'multi_season_exploit_regression_validation', 'label' => 'multi-season exploit/regression validation'],
        ['index' => 6, 'id' => 'patch_serialization_validation', 'label' => 'patch serialization validation'],
        ['index' => 7, 'id' => 'play_test_repo_compatibility_validation', 'label' => 'play-test repo compatibility validation'],
        ['index' => 8, 'id' => 'promotion_eligibility_marking', 'label' => 'promotion eligibility marking'],
    ];

    public static function run(array $options): array
    {
        $seed = (string)($options['seed'] ?? ('promotion-' . gmdate('Ymd-His')));
        $candidateDocument = $options['candidate_document'] ?? $options['candidate_patch'] ?? null;
        if (!is_array($candidateDocument)) {
            throw new InvalidArgumentException('Candidate promotion pipeline requires `candidate_document` or `candidate_patch` array input.');
        }

        $baseSeason = self::loadBaseSeason($seed, $options);
        $canonicalChanges = self::normalizeCandidateDocumentToCanonicalChanges($candidateDocument);
        $candidateId = (string)($options['candidate_id'] ?? self::defaultCandidateId($canonicalChanges));
        $outputDir = self::resolveOutputDir($candidateId, $options);
        AgenticOptimizationUtils::ensureDir($outputDir);

        $debugBypassStages = self::resolveDebugBypassStages($options);
        $warnings = [];
        if ($debugBypassStages !== []) {
            $warnings[] = 'Developer debug bypass requested for stages: ' . implode(', ', $debugBypassStages) . '.';
        }

        $state = [
            'schema_version' => self::STATE_SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'candidate_id' => $candidateId,
            'seed' => $seed,
            'status' => 'running',
            'patch_ready' => false,
            'promotion_eligible' => false,
            'debug_bypass_used' => false,
            'debug_bypass_stages' => $debugBypassStages,
            'warnings' => $warnings,
            'stage_order' => array_map(static fn(array $stage): string => (string)$stage['id'], self::STAGES),
            'stages' => [],
            'artifact_paths' => [],
        ];

        $context = [
            'seed' => $seed,
            'candidate_id' => $candidateId,
            'candidate_document' => $candidateDocument,
            'canonical_changes' => $canonicalChanges,
            'season_changes' => self::filterCanonicalChangesByScope($canonicalChanges, 'season'),
            'base_season' => $baseSeason,
            'output_dir' => $outputDir,
            'options' => $options,
            'preflight' => null,
            'candidate_season' => array_replace($baseSeason, self::canonicalSeasonValueMap($canonicalChanges)),
            'serialized_patch_path' => null,
            'exported_candidate_config_path' => null,
        ];

        $pipelineBlocked = false;
        foreach (array_slice(self::STAGES, 0, 7) as $stageDef) {
            if ($pipelineBlocked) {
                $state['stages'][] = self::blockedStage($stageDef, 'blocked by failed prior required stage');
                continue;
            }

            $stageResult = self::runStage($stageDef['id'], $stageDef, $context, $debugBypassStages);
            $state['stages'][] = $stageResult;

            if ($stageResult['status'] === 'bypassed') {
                $state['debug_bypass_used'] = true;
                foreach ((array)$stageResult['warnings'] as $warning) {
                    $state['warnings'][] = $warning;
                }
                continue;
            }

            if ($stageResult['status'] !== 'pass') {
                $pipelineBlocked = true;
            }
        }

        $eligibilityStage = self::runEligibilityStage($state);
        $state['stages'][] = $eligibilityStage;
        if ($eligibilityStage['status'] === 'pass') {
            $state['status'] = 'eligible';
            $state['patch_ready'] = true;
            $state['promotion_eligible'] = true;
        } elseif ($state['debug_bypass_used']) {
            $state['status'] = 'debug_only';
        } else {
            $state['status'] = 'ineligible';
        }

        $report = self::buildReport($context, $state);
        $artifactPaths = self::writeFinalArtifacts($outputDir, $state, $report);
        $state['artifact_paths'] = $artifactPaths;
        $report['artifact_paths'] = $artifactPaths;

        return [
            'state' => $state,
            'report' => $report,
            'artifact_paths' => $artifactPaths,
        ];
    }

    private static function runStage(string $stageId, array $stageDef, array &$context, array $debugBypassStages): array
    {
        $startedAt = microtime(true);
        $stageDir = self::stageDir($context['output_dir'], (int)$stageDef['index'], (string)$stageDef['id']);
        AgenticOptimizationUtils::ensureDir($stageDir);
        $bypassRequested = in_array($stageId, $debugBypassStages, true);

        try {
            $result = match ($stageId) {
                'candidate_schema_validation' => self::stageCandidateSchemaValidation($context, $stageDir),
                'effective_config_preflight' => self::stageEffectiveConfigPreflight($context, $stageDir, $bypassRequested),
                'targeted_subsystem_harnesses' => self::stageTargetedSubsystemHarnesses($context, $stageDir, $bypassRequested),
                'full_single_season_validation' => self::stageFullSingleSeasonValidation($context, $stageDir, $bypassRequested),
                'multi_season_exploit_regression_validation' => self::stageMultiSeasonValidation($context, $stageDir, $bypassRequested),
                'patch_serialization_validation' => self::stagePatchSerializationValidation($context, $stageDir),
                'play_test_repo_compatibility_validation' => self::stagePlayTestCompatibilityValidation($context, $stageDir, $bypassRequested),
                default => throw new InvalidArgumentException('Unsupported promotion stage: ' . $stageId),
            };
        } catch (Throwable $e) {
            $result = [
                'status' => $bypassRequested ? 'bypassed' : 'fail',
                'summary' => $e->getMessage(),
                'details' => [
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                ],
                'warnings' => $bypassRequested
                    ? ['Developer debug bypass applied to `' . $stageId . '` after exception: ' . $e->getMessage()]
                    : [],
                'artifacts' => [],
            ];
        }

        if ($bypassRequested && (string)($result['status'] ?? '') === 'fail') {
            $warnings = array_values((array)($result['warnings'] ?? []));
            $warnings[] = 'Developer debug bypass applied to `' . $stageId . '`; failed stage was retained for diagnostics only.';
            $result['status'] = 'bypassed';
            $result['warnings'] = $warnings;
        }

        return [
            'index' => (int)$stageDef['index'],
            'id' => (string)$stageDef['id'],
            'label' => (string)$stageDef['label'],
            'required' => true,
            'status' => (string)$result['status'],
            'started_at' => gmdate('c', (int)floor($startedAt)),
            'completed_at' => gmdate('c'),
            'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            'summary' => (string)$result['summary'],
            'warnings' => array_values((array)($result['warnings'] ?? [])),
            'artifacts' => (array)($result['artifacts'] ?? []),
            'details' => (array)($result['details'] ?? []),
        ];
    }

    private static function runEligibilityStage(array $state): array
    {
        $requiredStages = array_slice($state['stages'], 0, 7);
        $nonPassing = array_values(array_filter($requiredStages, static function (array $stage): bool {
            return (string)$stage['status'] !== 'pass';
        }));

        if ($nonPassing === []) {
            return [
                'index' => 8,
                'id' => 'promotion_eligibility_marking',
                'label' => 'promotion eligibility marking',
                'required' => true,
                'status' => 'pass',
                'started_at' => gmdate('c'),
                'completed_at' => gmdate('c'),
                'duration_ms' => 0,
                'summary' => 'Candidate passed every required promotion stage and is patch-ready.',
                'warnings' => [],
                'artifacts' => [],
                'details' => [
                    'promotion_eligible' => true,
                    'patch_ready' => true,
                ],
            ];
        }

        $reasonMap = array_map(static function (array $stage): array {
            return [
                'stage_id' => (string)$stage['id'],
                'status' => (string)$stage['status'],
                'summary' => (string)$stage['summary'],
            ];
        }, $nonPassing);

        return [
            'index' => 8,
            'id' => 'promotion_eligibility_marking',
            'label' => 'promotion eligibility marking',
            'required' => true,
            'status' => 'fail',
            'started_at' => gmdate('c'),
            'completed_at' => gmdate('c'),
            'duration_ms' => 0,
            'summary' => 'Candidate is not patch-ready because one or more required promotion stages did not pass.',
            'warnings' => !empty($state['debug_bypass_used'])
                ? ['Developer debug bypass was used earlier in the ladder; bypassed candidates can never be marked patch-ready.']
                : [],
            'artifacts' => [],
            'details' => [
                'promotion_eligible' => false,
                'patch_ready' => false,
                'blocking_stages' => $reasonMap,
            ],
        ];
    }

    private static function buildReport(array $context, array $state): array
    {
        return [
            'schema_version' => self::REPORT_SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'candidate_id' => $context['candidate_id'],
            'seed' => $context['seed'],
            'pipeline_status' => (string)$state['status'],
            'patch_ready' => (bool)$state['patch_ready'],
            'promotion_eligible' => (bool)$state['promotion_eligible'],
            'debug_bypass_used' => (bool)$state['debug_bypass_used'],
            'debug_bypass_stages' => (array)$state['debug_bypass_stages'],
            'warnings' => array_values((array)$state['warnings']),
            'candidate' => [
                'raw_document' => $context['candidate_document'],
                'canonical_changes' => self::stableCanonicalChanges($context['canonical_changes']),
                'serialized_patch_path' => $context['serialized_patch_path'],
            ],
            'base_season' => [
                'surface_sha256' => self::seasonSurfaceHash($context['base_season']),
                'effective_config' => self::seasonForExport($context['base_season']),
            ],
            'candidate_effective_season' => [
                'surface_sha256' => self::seasonSurfaceHash($context['candidate_season']),
                'effective_config' => self::seasonForExport($context['candidate_season']),
                'export_path' => $context['exported_candidate_config_path'],
            ],
            'stages' => $state['stages'],
        ];
    }

    private static function writeFinalArtifacts(string $outputDir, array $state, array $report): array
    {
        $statePath = $outputDir . DIRECTORY_SEPARATOR . 'promotion_state.json';
        $reportJsonPath = $outputDir . DIRECTORY_SEPARATOR . 'promotion_report.json';
        $reportMdPath = $outputDir . DIRECTORY_SEPARATOR . 'promotion_report.md';

        file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($reportJsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($reportMdPath, self::buildMarkdownReport($report));

        return [
            'promotion_state_json' => $statePath,
            'promotion_report_json' => $reportJsonPath,
            'promotion_report_md' => $reportMdPath,
        ];
    }

    private static function buildMarkdownReport(array $report): string
    {
        $lines = [];
        $lines[] = '# Candidate Promotion Report';
        $lines[] = '';
        $lines[] = '- Candidate: `' . (string)$report['candidate_id'] . '`';
        $lines[] = '- Seed: `' . (string)$report['seed'] . '`';
        $lines[] = '- Pipeline status: `' . (string)$report['pipeline_status'] . '`';
        $lines[] = '- Patch ready: `' . ((bool)$report['patch_ready'] ? 'true' : 'false') . '`';
        $lines[] = '- Promotion eligible: `' . ((bool)$report['promotion_eligible'] ? 'true' : 'false') . '`';
        $lines[] = '- Debug bypass used: `' . ((bool)$report['debug_bypass_used'] ? 'true' : 'false') . '`';
        if (!empty($report['debug_bypass_stages'])) {
            $lines[] = '- Debug bypass stages: ' . implode(', ', (array)$report['debug_bypass_stages']);
        }
        $lines[] = '';

        if (!empty($report['warnings'])) {
            $lines[] = '## Warnings';
            $lines[] = '';
            foreach ((array)$report['warnings'] as $warning) {
                $lines[] = '- ' . $warning;
            }
            $lines[] = '';
        }

        $lines[] = '## Stage Outcomes';
        $lines[] = '';
        foreach ((array)$report['stages'] as $stage) {
            $lines[] = '### ' . (int)$stage['index'] . '. ' . (string)$stage['label'];
            $lines[] = '';
            $lines[] = '- Status: `' . (string)$stage['status'] . '`';
            $lines[] = '- Summary: ' . (string)$stage['summary'];
            if (!empty($stage['warnings'])) {
                $lines[] = '- Warnings:';
                foreach ((array)$stage['warnings'] as $warning) {
                    $lines[] = '  - ' . $warning;
                }
            }
            if (!empty($stage['artifacts'])) {
                $lines[] = '- Artifacts:';
                foreach ((array)$stage['artifacts'] as $name => $path) {
                    $lines[] = '  - `' . (string)$name . '` => `' . (string)$path . '`';
                }
            }
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private static function stageCandidateSchemaValidation(array &$context, string $stageDir): array
    {
        if ($context['canonical_changes'] === []) {
            return [
                'status' => 'fail',
                'summary' => 'Candidate patch is empty and cannot be promoted.',
                'details' => ['reason_code' => 'candidate_empty_package'],
                'artifacts' => [],
            ];
        }

        $failures = EconomicCandidateValidator::validateCandidateDocument(
            $context['candidate_document'],
            ['base_season' => $context['base_season']]
        );

        $report = [
            'schema_version' => EconomicCandidateValidator::SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'candidate_id' => $context['candidate_id'],
            'failure_count' => count($failures),
            'failures' => $failures,
            'canonical_changes' => $context['canonical_changes'],
        ];
        $jsonPath = $stageDir . DIRECTORY_SEPARATOR . 'candidate_schema_validation.json';
        file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($failures !== []) {
            return [
                'status' => 'fail',
                'summary' => EconomicCandidateValidator::buildFailureMessage($failures),
                'details' => [
                    'failure_count' => count($failures),
                    'failures' => $failures,
                ],
                'artifacts' => ['schema_validation_json' => $jsonPath],
            ];
        }

        return [
            'status' => 'pass',
            'summary' => 'Candidate schema validated successfully.',
            'details' => [
                'canonical_change_count' => count($context['canonical_changes']),
                'canonical_changes' => $context['canonical_changes'],
            ],
            'artifacts' => ['schema_validation_json' => $jsonPath],
        ];
    }

    private static function stageEffectiveConfigPreflight(array &$context, string $stageDir, bool $bypassRequested): array
    {
        $resolved = SimulationConfigPreflight::resolve([
            'seed' => $context['seed'],
            'season_id' => (int)($context['base_season']['season_id'] ?? 1),
            'simulator' => 'promotion-preflight',
            'players_per_archetype' => (int)($context['options']['players_per_archetype'] ?? 1),
            'season_count' => (int)($context['options']['season_count'] ?? 4),
            'run_label' => $context['candidate_id'] . '-promotion-preflight',
            'base_season_overrides' => $context['base_season'],
            'candidate_patch' => $context['canonical_changes'],
            'artifact_dir' => $stageDir,
            'debug_allow_inactive_candidate' => $bypassRequested,
        ]);

        $context['preflight'] = $resolved;
        $context['candidate_season'] = (array)$resolved['season'];

        $status = (string)($resolved['report']['status'] ?? 'fail');
        if ($status === 'debug_bypass') {
            return [
                'status' => 'bypassed',
                'summary' => 'Effective-config preflight only continued under developer debug bypass.',
                'warnings' => [
                    'Developer debug bypass applied to `effective_config_preflight`; candidate remains ineligible for patch-ready promotion.',
                ],
                'details' => [
                    'preflight_status' => $status,
                    'requested_candidate_changes' => (array)($resolved['report']['requested_candidate_changes'] ?? []),
                    'candidate_validation' => (array)($resolved['report']['candidate_validation'] ?? []),
                ],
                'artifacts' => (array)$resolved['artifact_paths'],
            ];
        }

        return [
            'status' => 'pass',
            'summary' => 'Effective-config preflight passed and produced canonical audit artifacts.',
            'details' => [
                'preflight_status' => $status,
                'requested_candidate_changes' => (array)($resolved['report']['requested_candidate_changes'] ?? []),
            ],
            'artifacts' => (array)$resolved['artifact_paths'],
        ];
    }

    private static function stageTargetedSubsystemHarnesses(array &$context, string $stageDir, bool $bypassRequested): array
    {
        $seasonChanges = self::filterCanonicalChangesByScope($context['canonical_changes'], 'season');
        if ($seasonChanges === []) {
            $jsonPath = $stageDir . DIRECTORY_SEPARATOR . 'targeted_harness_report.json';
            file_put_contents($jsonPath, json_encode([
                'schema_version' => 'tmc-candidate-targeted-harness-report.v1',
                'generated_at' => gmdate('c'),
                'candidate_id' => $context['candidate_id'],
                'status' => 'pass',
                'selected_family_ids' => [],
                'families' => [],
                'reason' => 'No season-scope candidate keys were present.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return [
                'status' => 'pass',
                'summary' => 'No season-scope candidate keys required targeted harness execution.',
                'warnings' => [
                    'Candidate only touched non-season keys; targeted subsystem harnesses had no applicable family selection.',
                ],
                'details' => ['selected_family_ids' => []],
                'artifacts' => ['targeted_harness_report_json' => $jsonPath],
            ];
        }

        $decomposition = AgenticEconomyDecomposition::build();
        $selectedFamilyIds = self::resolveTargetedHarnessFamilyIds($seasonChanges, $decomposition);
        $runner = new AgenticHarnessRunner($stageDir);
        $result = AgenticCouplingHarnessEvaluator::runFamilies(
            $runner,
            $decomposition,
            $context['base_season'],
            $context['candidate_season'],
            $seasonChanges,
            $selectedFamilyIds,
            $context['candidate_id']
        );

        $report = [
            'schema_version' => 'tmc-candidate-targeted-harness-report.v1',
            'generated_at' => gmdate('c'),
            'candidate_id' => $context['candidate_id'],
            'status' => (string)$result['status'],
            'selected_family_ids' => $selectedFamilyIds,
            'failed_family_ids' => (array)$result['failed_family_ids'],
            'families' => (array)$result['families'],
        ];
        $jsonPath = $stageDir . DIRECTORY_SEPARATOR . 'targeted_harness_report.json';
        file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ((string)$result['status'] !== 'pass') {
            return [
                'status' => $bypassRequested ? 'bypassed' : 'fail',
                'summary' => 'Targeted subsystem harnesses detected promotion-blocking regressions.',
                'warnings' => $bypassRequested
                    ? ['Developer debug bypass applied to `targeted_subsystem_harnesses`; failed harness families were retained for diagnostics only.']
                    : [],
                'details' => [
                    'selected_family_ids' => $selectedFamilyIds,
                    'failed_family_ids' => (array)$result['failed_family_ids'],
                    'families' => (array)$result['families'],
                ],
                'artifacts' => ['targeted_harness_report_json' => $jsonPath],
            ];
        }

        return [
            'status' => 'pass',
            'summary' => 'Targeted subsystem harnesses passed.',
            'details' => [
                'selected_family_ids' => $selectedFamilyIds,
                'failed_family_ids' => [],
            ],
            'artifacts' => ['targeted_harness_report_json' => $jsonPath],
        ];
    }

    private static function stageFullSingleSeasonValidation(array &$context, string $stageDir, bool $bypassRequested): array
    {
        $payload = SimulationPopulationSeason::run(
            $context['seed'] . '|promotion|B',
            max(1, (int)($context['options']['players_per_archetype'] ?? 1)),
            null,
            [
                'run_label' => $context['candidate_id'] . '-promotion-B',
                'preflight_artifact_dir' => $stageDir . DIRECTORY_SEPARATOR . 'audit',
                'base_season_overrides' => $context['base_season'],
                'candidate_patch' => self::filterCanonicalChangesByScope($context['canonical_changes'], 'season'),
                'debug_allow_inactive_candidate' => $bypassRequested || !empty($context['preflight']['report']['context']['debug_bypass']),
            ]
        );

        $baseName = 'promotion_single_season_' . AgenticOptimizationUtils::sanitize($context['candidate_id']);
        $jsonPath = MetricsCollector::writeJson($payload, $stageDir, $baseName);
        $csvPath = MetricsCollector::writeSeasonCsv($payload, $stageDir, $baseName);

        $auditStatus = (string)($payload['config_audit']['status'] ?? 'unknown');
        $resultStatus = ($auditStatus === 'pass' || $auditStatus === 'debug_bypass') ? 'pass' : 'fail';
        if ($resultStatus !== 'pass' && $bypassRequested) {
            $resultStatus = 'bypassed';
        }

        return [
            'status' => $resultStatus,
            'summary' => 'Full single-season validation completed with config audit status `' . $auditStatus . '`.',
            'warnings' => ($auditStatus === 'debug_bypass' || $resultStatus === 'bypassed')
                ? ['Single-season validation ran with debug-bypass config audit semantics; candidate remains ineligible for patch-ready promotion.']
                : [],
            'details' => [
                'audit_status' => $auditStatus,
                'schema_version' => (string)($payload['schema_version'] ?? ''),
                'simulator' => (string)($payload['simulator'] ?? ''),
            ],
            'artifacts' => array_filter([
                'single_season_json' => $jsonPath,
                'single_season_csv' => $csvPath,
                'effective_config_json' => (string)($payload['config_audit']['artifact_paths']['effective_config_json'] ?? ''),
                'effective_config_audit_md' => (string)($payload['config_audit']['artifact_paths']['effective_config_audit_md'] ?? ''),
            ]),
        ];
    }

    private static function stageMultiSeasonValidation(array &$context, string $stageDir, bool $bypassRequested): array
    {
        $playersPerArchetype = max(1, (int)($context['options']['players_per_archetype'] ?? 1));
        $seasonCount = max(2, (int)($context['options']['season_count'] ?? 4));
        $seasonChanges = self::filterCanonicalChangesByScope($context['canonical_changes'], 'season');
        $sharedSeed = $context['seed'] . '|promotion|comparison|C';

        $baselinePayload = SimulationPopulationLifetime::run(
            $sharedSeed,
            $playersPerArchetype,
            $seasonCount,
            null,
            [
                'run_label' => $context['candidate_id'] . '-promotion-C-baseline',
                'preflight_artifact_dir' => $stageDir . DIRECTORY_SEPARATOR . 'baseline.audit',
                'base_season_overrides' => $context['base_season'],
                'candidate_patch' => [],
            ]
        );
        $candidatePayload = SimulationPopulationLifetime::run(
            $sharedSeed,
            $playersPerArchetype,
            $seasonCount,
            null,
            [
                'run_label' => $context['candidate_id'] . '-promotion-C-candidate',
                'preflight_artifact_dir' => $stageDir . DIRECTORY_SEPARATOR . 'candidate.audit',
                'base_season_overrides' => $context['base_season'],
                'candidate_patch' => $seasonChanges,
                'debug_allow_inactive_candidate' => $bypassRequested || !empty($context['preflight']['report']['context']['debug_bypass']),
            ]
        );

        $baselineJson = MetricsCollector::writeJson($baselinePayload, $stageDir, 'promotion_lifetime_baseline');
        $baselineCsv = MetricsCollector::writeLifetimeCsv($baselinePayload, $stageDir, 'promotion_lifetime_baseline');
        $candidateJson = MetricsCollector::writeJson($candidatePayload, $stageDir, 'promotion_lifetime_candidate');
        $candidateCsv = MetricsCollector::writeLifetimeCsv($candidatePayload, $stageDir, 'promotion_lifetime_candidate');

        $baselineMetrics = AgenticMetricEvaluator::extractMetrics(null, $baselinePayload);
        $candidateMetrics = AgenticMetricEvaluator::extractMetrics(null, $candidatePayload);
        $regressionFlags = AgenticMetricEvaluator::regressionFlags($baselineMetrics, $candidateMetrics);
        $globalDelta = AgenticMetricEvaluator::globalScore($candidateMetrics) - AgenticMetricEvaluator::globalScore($baselineMetrics);

        $report = [
            'schema_version' => 'tmc-candidate-multi-season-validation.v1',
            'generated_at' => gmdate('c'),
            'candidate_id' => $context['candidate_id'],
            'baseline_metrics' => $baselineMetrics,
            'candidate_metrics' => $candidateMetrics,
            'regression_flags' => $regressionFlags,
            'global_score_delta' => $globalDelta,
            'baseline_payload' => $baselineJson,
            'candidate_payload' => $candidateJson,
        ];
        $reportPath = $stageDir . DIRECTORY_SEPARATOR . 'multi_season_validation.json';
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($regressionFlags !== []) {
            return [
                'status' => $bypassRequested ? 'bypassed' : 'fail',
                'summary' => 'Multi-season exploit/regression validation found regression flags: ' . implode(', ', $regressionFlags) . '.',
                'warnings' => $bypassRequested
                    ? ['Developer debug bypass applied to `multi_season_exploit_regression_validation`; regression flags were recorded but not promoted.']
                    : [],
                'details' => [
                    'regression_flags' => $regressionFlags,
                    'global_score_delta' => $globalDelta,
                ],
                'artifacts' => array_filter([
                    'multi_season_validation_json' => $reportPath,
                    'baseline_lifetime_json' => $baselineJson,
                    'baseline_lifetime_csv' => $baselineCsv,
                    'candidate_lifetime_json' => $candidateJson,
                    'candidate_lifetime_csv' => $candidateCsv,
                ]),
            ];
        }

        return [
            'status' => 'pass',
            'summary' => 'Multi-season exploit/regression validation passed without regression flags.',
            'details' => [
                'regression_flags' => [],
                'global_score_delta' => $globalDelta,
            ],
            'artifacts' => array_filter([
                'multi_season_validation_json' => $reportPath,
                'baseline_lifetime_json' => $baselineJson,
                'baseline_lifetime_csv' => $baselineCsv,
                'candidate_lifetime_json' => $candidateJson,
                'candidate_lifetime_csv' => $candidateCsv,
            ]),
        ];
    }

    private static function stagePatchSerializationValidation(array &$context, string $stageDir): array
    {
        $serialized = [
            'schema_version' => self::PATCH_SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'candidate_id' => $context['candidate_id'],
            'changes' => self::stableCanonicalChanges($context['canonical_changes']),
        ];

        $jsonPath = $stageDir . DIRECTORY_SEPARATOR . 'candidate_patch.json';
        file_put_contents($jsonPath, json_encode($serialized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $decoded = json_decode((string)file_get_contents($jsonPath), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Serialized patch JSON could not be decoded.');
        }

        $roundTrip = self::normalizeCandidateDocumentToCanonicalChanges((array)($decoded['changes'] ?? []));
        $expected = self::logicalCanonicalChanges($context['canonical_changes']);
        $actual = self::logicalCanonicalChanges($roundTrip);
        $failures = EconomicCandidateValidator::validateCandidateDocument((array)($decoded['changes'] ?? []), [
            'base_season' => $context['base_season'],
        ]);
        $roundTripEqual = ($expected === $actual);
        $context['serialized_patch_path'] = $jsonPath;

        if (!$roundTripEqual || $failures !== []) {
            return [
                'status' => 'fail',
                'summary' => 'Patch serialization validation failed round-trip or revalidation checks.',
                'details' => [
                    'round_trip_equal' => $roundTripEqual,
                    'validation_failures' => $failures,
                    'expected_changes' => $expected,
                    'actual_changes' => $actual,
                ],
                'artifacts' => ['serialized_patch_json' => $jsonPath],
            ];
        }

        return [
            'status' => 'pass',
            'summary' => 'Patch serialization validation passed.',
            'details' => [
                'round_trip_equal' => true,
                'serialized_change_count' => count($expected),
            ],
            'artifacts' => ['serialized_patch_json' => $jsonPath],
        ];
    }

    private static function stagePlayTestCompatibilityValidation(array &$context, string $stageDir, bool $bypassRequested): array
    {
        $exported = self::seasonForExport($context['candidate_season']);
        $exportPath = $stageDir . DIRECTORY_SEPARATOR . 'candidate_effective_season.json';
        file_put_contents($exportPath, json_encode($exported, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $context['exported_candidate_config_path'] = $exportPath;

        $imported = SimulationSeason::fromJsonFile(
            $exportPath,
            (int)($context['candidate_season']['season_id'] ?? 1),
            $context['seed'] . '|promotion|compat-import'
        );
        $importHash = self::seasonSurfaceHash($imported);
        $candidateHash = self::seasonSurfaceHash($context['candidate_season']);

        $sweepResult = PolicySweepRunner::run([
            'seed' => $context['seed'] . '|promotion|compat',
            'players_per_archetype' => max(1, (int)($context['options']['players_per_archetype'] ?? 1)),
            'season_count' => max(2, (int)($context['options']['season_count'] ?? 4)),
            'simulators' => ['B', 'C'],
            'scenarios' => [],
            'include_baseline' => true,
            'base_season_config_path' => $exportPath,
            'output_dir' => $stageDir . DIRECTORY_SEPARATOR . 'sweep',
            'debug_allow_inactive_candidate' => $bypassRequested || !empty($context['preflight']['report']['context']['debug_bypass']),
        ]);

        $manifest = (array)$sweepResult['manifest'];
        $runCount = count((array)($manifest['runs'] ?? []));
        $report = CanonicalEconomyConfigContract::buildCompatibilityReport(
            self::filterCanonicalChangesByScope($context['canonical_changes'], 'season')
        );
        $report['candidate_id'] = $context['candidate_id'];
        $report['candidate_surface_hash'] = $candidateHash;
        $report['imported_surface_hash'] = $importHash;
        $report['exported_candidate_config_path'] = $exportPath;
        $report['sweep_manifest_path'] = (string)$sweepResult['manifest_path'];
        $report['run_count'] = $runCount;

        if ($candidateHash !== $importHash) {
            $report['issues'][] = [
                'code' => 'lossy_conversion',
                'key' => '',
                'detail' => 'Exported play-test season config did not round-trip through import with the same season surface hash.',
                'candidate_surface_hash' => $candidateHash,
                'imported_surface_hash' => $importHash,
            ];
        }

        if ($runCount < 2) {
            $report['issues'][] = [
                'code' => 'missing_mapping',
                'key' => '',
                'detail' => 'Compatibility sweep did not produce the expected baseline B/C validation runs.',
                'run_count' => $runCount,
            ];
        }

        $report['compatible'] = ($report['issues'] === []);
        $report['status'] = $report['compatible'] ? 'pass' : 'fail';
        $report['round_trip_equal'] = !empty($report['round_trip_equal']) && ($candidateHash === $importHash);

        $artifacts = CanonicalEconomyConfigContract::writeCompatibilityArtifacts($stageDir, $report);
        $compatible = (bool)$report['compatible'];

        if (!$compatible) {
            return [
                'status' => $bypassRequested ? 'bypassed' : 'fail',
                'summary' => 'Play-test repo compatibility validation failed export/import or sweep compatibility checks.',
                'warnings' => $bypassRequested
                    ? ['Developer debug bypass applied to `play_test_repo_compatibility_validation`; compatibility mismatch was retained for diagnostics only.']
                    : [],
                'details' => [
                    'candidate_surface_hash' => $candidateHash,
                    'imported_surface_hash' => $importHash,
                    'run_count' => $runCount,
                ],
                'artifacts' => array_merge([
                    'candidate_effective_season_json' => $exportPath,
                    'sweep_manifest_json' => (string)$sweepResult['manifest_path'],
                ], $artifacts),
            ];
        }

        return [
            'status' => 'pass',
            'summary' => 'Play-test repo compatibility validation passed.',
            'details' => [
                'candidate_surface_hash' => $candidateHash,
                'imported_surface_hash' => $importHash,
                'run_count' => $runCount,
            ],
            'artifacts' => array_merge([
                'candidate_effective_season_json' => $exportPath,
                'sweep_manifest_json' => (string)$sweepResult['manifest_path'],
            ], $artifacts),
        ];
    }

    private static function loadBaseSeason(string $seed, array $options): array
    {
        if (isset($options['base_season']) && is_array($options['base_season']) && $options['base_season'] !== []) {
            return SimulationSeason::build(
                (int)($options['base_season']['season_id'] ?? 1),
                $seed . '|base-season',
                SimulationSeason::normalizeImportedRow((array)$options['base_season'])
            );
        }

        if (!empty($options['base_season_config_path'])) {
            return SimulationSeason::fromJsonFile(
                (string)$options['base_season_config_path'],
                1,
                $seed . '|base-season-file'
            );
        }

        if (!empty($options['base_season_overrides']) && is_array($options['base_season_overrides'])) {
            return SimulationSeason::build(
                1,
                $seed . '|base-season-overrides',
                SimulationSeason::normalizeImportedRow((array)$options['base_season_overrides'])
            );
        }

        return SimulationSeason::build(1, $seed . '|base-season-default');
    }

    private static function resolveOutputDir(string $candidateId, array $options): string
    {
        $outputRoot = (string)($options['output_dir'] ?? (__DIR__ . '/../../simulation_output/promotion'));
        return rtrim($outputRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . AgenticOptimizationUtils::sanitize($candidateId);
    }

    private static function resolveDebugBypassStages(array $options): array
    {
        $raw = $options['debug_bypass_stages'] ?? [];
        if (!is_array($raw)) {
            $raw = array_filter(array_map('trim', explode(',', (string)$raw)));
        }

        $envValue = getenv(self::DEBUG_BYPASS_ENV);
        if ($envValue !== false && trim((string)$envValue) !== '') {
            $raw = array_merge($raw, array_filter(array_map('trim', explode(',', (string)$envValue))));
        }

        $allowed = array_fill_keys(array_map(static fn(array $stage): string => (string)$stage['id'], self::STAGES), true);
        $resolved = [];
        foreach ($raw as $value) {
            $stageId = trim((string)$value);
            if ($stageId === '') {
                continue;
            }
            if (!isset($allowed[$stageId])) {
                throw new InvalidArgumentException('Unknown debug bypass stage: ' . $stageId);
            }
            $resolved[$stageId] = true;
        }

        return array_keys($resolved);
    }

    private static function normalizeCandidateDocumentToCanonicalChanges(array $document): array
    {
        if (isset($document['overrides']) && is_array($document['overrides'])) {
            $document = (array)$document['overrides'];
        } elseif (isset($document['changes']) && is_array($document['changes'])) {
            $document = (array)$document['changes'];
        } elseif (isset($document['packages']) || isset($document['scenarios'])) {
            throw new InvalidArgumentException('Promotion pipeline expects a single candidate patch, not a multi-package candidate document.');
        }

        if ($document === []) {
            return [];
        }

        $changes = [];
        if (self::isAssoc($document)) {
            foreach ($document as $path => $value) {
                $changes[] = self::canonicalChangeRecord((string)$path, $value);
            }
            return self::stableCanonicalChanges($changes);
        }

        foreach ($document as $index => $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('Candidate patch entry at index ' . $index . ' must be an object.');
            }

            $path = $entry['path'] ?? $entry['target'] ?? $entry['key'] ?? null;
            if ($path === null) {
                throw new InvalidArgumentException('Candidate patch entry at index ' . $index . ' is missing `path`, `target`, or `key`.');
            }

            $value = $entry['requested_value'] ?? $entry['proposed_value'] ?? $entry['value'] ?? $entry['new_value'] ?? null;
            $changes[] = self::canonicalChangeRecord((string)$path, $value);
        }

        return self::stableCanonicalChanges($changes);
    }

    private static function canonicalChangeRecord(string $rawPath, mixed $value): array
    {
        $path = trim($rawPath);
        $scope = null;
        $key = null;
        $pathStatus = 'valid';

        if ($path === '') {
            $pathStatus = 'invalid_path';
        } elseif (str_starts_with($path, 'season.')) {
            $scope = 'season';
            $key = substr($path, 7);
        } elseif (str_starts_with($path, 'runtime.')) {
            $scope = 'runtime';
            $key = substr($path, 8);
        } elseif (in_array($path, SimulationSeason::SEASON_ECONOMY_COLUMNS, true)) {
            $scope = 'season';
            $key = $path;
            $path = 'season.' . $path;
        } else {
            $pathStatus = 'invalid_path';
            $key = $path;
        }

        return [
            'raw_path' => $rawPath,
            'path' => $path,
            'scope' => $scope,
            'key' => $key,
            'requested_value' => $value,
            'path_status' => $pathStatus,
        ];
    }

    private static function stableCanonicalChanges(array $changes): array
    {
        usort($changes, static function (array $left, array $right): int {
            return strcmp((string)($left['path'] ?? $left['raw_path'] ?? ''), (string)($right['path'] ?? $right['raw_path'] ?? ''));
        });
        return array_values($changes);
    }

    private static function logicalCanonicalChanges(array $changes): array
    {
        return array_map(static function (array $change): array {
            return [
                'path' => $change['path'] ?? null,
                'scope' => $change['scope'] ?? null,
                'key' => $change['key'] ?? null,
                'requested_value' => $change['requested_value'] ?? null,
                'path_status' => $change['path_status'] ?? null,
            ];
        }, self::stableCanonicalChanges($changes));
    }

    private static function filterCanonicalChangesByScope(array $changes, string $scope): array
    {
        return array_values(array_filter($changes, static function (array $change) use ($scope): bool {
            return (string)($change['scope'] ?? '') === $scope && (string)($change['path_status'] ?? 'valid') === 'valid';
        }));
    }

    private static function canonicalSeasonValueMap(array $changes): array
    {
        $values = [];
        foreach (self::filterCanonicalChangesByScope($changes, 'season') as $change) {
            $values[(string)$change['key']] = $change['requested_value'];
        }
        return $values;
    }

    private static function resolveTargetedHarnessFamilyIds(array $seasonChanges, array $decomposition): array
    {
        $subsystemMap = [];
        foreach ((array)($decomposition['subsystems'] ?? []) as $subsystem) {
            $families = (array)($subsystem['coupling_harness_families'] ?? []);
            foreach ((array)($subsystem['owned_parameters'] ?? []) as $parameter) {
                $key = (string)($parameter['key'] ?? '');
                if ($key === '') {
                    continue;
                }
                if (!isset($subsystemMap[$key])) {
                    $subsystemMap[$key] = [];
                }
                $subsystemMap[$key] = array_values(array_unique(array_merge($subsystemMap[$key], $families)));
            }
        }

        $selected = [];
        foreach ($seasonChanges as $change) {
            $key = (string)($change['key'] ?? '');
            foreach ((array)($subsystemMap[$key] ?? []) as $familyId) {
                $selected[$familyId] = true;
            }
        }

        if ($selected === []) {
            foreach (array_keys(AgenticCouplingHarnessCatalog::families()) as $familyId) {
                $selected[$familyId] = true;
            }
        }

        $ids = array_keys($selected);
        sort($ids);
        return $ids;
    }

    private static function defaultCandidateId(array $canonicalChanges): string
    {
        $hash = substr(hash('sha256', json_encode(self::stableCanonicalChanges($canonicalChanges), JSON_UNESCAPED_SLASHES)), 0, 12);
        return 'candidate-' . $hash;
    }

    private static function seasonForExport(array $season): array
    {
        $export = $season;
        if (isset($export['season_seed']) && is_string($export['season_seed'])) {
            $export['season_seed_hex'] = bin2hex($export['season_seed']);
            unset($export['season_seed']);
        }
        return $export;
    }

    private static function seasonSurfaceHash(array $season): string
    {
        $normalized = self::seasonForExport($season);
        AgenticOptimizationUtils::sortAssocRecursively($normalized);
        return AgenticOptimizationUtils::jsonHash($normalized);
    }

    private static function blockedStage(array $stageDef, string $reason): array
    {
        return [
            'index' => (int)$stageDef['index'],
            'id' => (string)$stageDef['id'],
            'label' => (string)$stageDef['label'],
            'required' => true,
            'status' => 'blocked',
            'started_at' => gmdate('c'),
            'completed_at' => gmdate('c'),
            'duration_ms' => 0,
            'summary' => $reason,
            'warnings' => [],
            'artifacts' => [],
            'details' => [],
        ];
    }

    private static function stageDir(string $outputDir, int $index, string $id): string
    {
        return $outputDir . DIRECTORY_SEPARATOR . sprintf('%02d_%s', $index, $id);
    }

    private static function isAssoc(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
