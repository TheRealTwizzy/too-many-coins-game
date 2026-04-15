<?php

require_once __DIR__ . '/MetricsCollector.php';
require_once __DIR__ . '/PolicyScenarioCatalog.php';
require_once __DIR__ . '/SimulationPopulationSeason.php';
require_once __DIR__ . '/SimulationPopulationLifetime.php';

class PolicySweepRunner
{
    public const SWEEP_SCHEMA_VERSION = 'tmc-sim-sweep.v1';

    public static function run(array $options): array
    {
        $startedAt = microtime(true);
        $simulators = self::normalizeSimulators((array)($options['simulators'] ?? ['B', 'C']));
        $scenarioResolutionStartedAt = microtime(true);
        $scenarioNames = PolicyScenarioCatalog::normalizeScenarioNames((array)($options['scenarios'] ?? []));
        $includeBaseline = (bool)($options['include_baseline'] ?? true);
        $seed = (string)($options['seed'] ?? 'phase1-sweep');
        $playersPerArchetype = max(1, (int)($options['players_per_archetype'] ?? 5));
        $seasonCount = max(2, (int)($options['season_count'] ?? 12));
        $outputDir = (string)($options['output_dir'] ?? (__DIR__ . '/../../simulation_output/sweep'));
        $baseSeasonConfigPath = isset($options['base_season_config_path']) && $options['base_season_config_path'] !== ''
            ? (string)$options['base_season_config_path']
            : null;
        $debugAllowInactive = !empty($options['debug_allow_inactive_candidate']);
        self::assertReadableBaseSeasonConfig($baseSeasonConfigPath);

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $runDir = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'runs';
        if (!is_dir($runDir)) {
            mkdir($runDir, 0777, true);
        }

        $scenarios = [];
        if ($includeBaseline) {
            $scenarios[] = PolicyScenarioCatalog::baselineScenario();
        }
        foreach ($scenarioNames as $name) {
            $scenarios[] = PolicyScenarioCatalog::get($name);
        }
        if ($scenarios === []) {
            throw new InvalidArgumentException('At least one scenario or baseline must be selected.');
        }
        $scenarioResolutionDurationMs = self::msSince($scenarioResolutionStartedAt);

        $runEntries = [];
        $timingSummary = [
            'run_count' => 0,
            'scenario_resolution_duration_ms' => $scenarioResolutionDurationMs,
            'run_execution_duration_ms' => 0,
            'manifest_write_duration_ms' => 0,
            'total_duration_ms' => 0,
            'by_simulator' => [],
            'slowest_runs' => [],
        ];
        $runExecutionStartedAt = microtime(true);
        foreach ($scenarios as $scenario) {
            foreach ($simulators as $simulator) {
                $runStartedAt = microtime(true);
                $isBaseline = ((string)$scenario['name'] === 'baseline');
                $runSeed = $seed . '|scenario|' . (string)$scenario['name'] . '|sim|' . $simulator;
                $seasonConfigPath = $baseSeasonConfigPath;
                $baseName = self::buildBaseName((string)$scenario['name'], $simulator, $runSeed, $playersPerArchetype, $seasonCount);
                $auditDir = $runDir . DIRECTORY_SEPARATOR . $baseName . '.audit';

                $simulationStartedAt = microtime(true);
                if ($simulator === 'B') {
                    $payload = SimulationPopulationSeason::run(
                        $runSeed,
                        $playersPerArchetype,
                        $seasonConfigPath,
                        [
                            'run_label' => $baseName,
                            'preflight_artifact_dir' => $auditDir,
                            'candidate_patch' => $isBaseline ? [] : (array)($scenario['overrides'] ?? []),
                            'debug_allow_inactive_candidate' => $debugAllowInactive,
                        ]
                    );
                    $simulatorLabel = 'B';
                } else {
                    $payload = SimulationPopulationLifetime::run(
                        $runSeed,
                        $playersPerArchetype,
                        $seasonCount,
                        $seasonConfigPath,
                        [
                            'run_label' => $baseName,
                            'preflight_artifact_dir' => $auditDir,
                            'candidate_patch' => $isBaseline ? [] : (array)($scenario['overrides'] ?? []),
                            'debug_allow_inactive_candidate' => $debugAllowInactive,
                        ]
                    );
                    $simulatorLabel = 'C';
                }
                $simulationDurationMs = self::msSince($simulationStartedAt);

                $payload['sweep'] = [
                    'schema_version' => self::SWEEP_SCHEMA_VERSION,
                    'scenario_name' => (string)$scenario['name'],
                    'scenario_description' => (string)$scenario['description'],
                    'simulator_type' => $simulatorLabel,
                    'is_baseline' => $isBaseline,
                    'seed' => $runSeed,
                    'cohort' => [
                        'players_per_archetype' => $playersPerArchetype,
                        'total_players' => (int)($payload['config']['total_players'] ?? 0),
                    ],
                    'horizon' => [
                        'season_count' => ($simulatorLabel === 'C') ? $seasonCount : 1,
                    ],
                    'override_keys' => array_keys((array)($scenario['overrides'] ?? [])),
                    'override_categories' => (array)($scenario['categories'] ?? []),
                ];

                $jsonWriteStartedAt = microtime(true);
                $jsonPath = MetricsCollector::writeJson($payload, $runDir, $baseName);
                $jsonWriteDurationMs = self::msSince($jsonWriteStartedAt);
                $csvWriteStartedAt = microtime(true);
                $csvPath = ($simulatorLabel === 'B')
                    ? MetricsCollector::writeSeasonCsv($payload, $runDir, $baseName)
                    : MetricsCollector::writeLifetimeCsv($payload, $runDir, $baseName);
                $csvWriteDurationMs = self::msSince($csvWriteStartedAt);
                $runDurationMs = self::msSince($runStartedAt);

                self::recordRunTiming($timingSummary, (string)$scenario['name'], $simulatorLabel, $runDurationMs);

                $runEntries[] = [
                    'scenario_name' => (string)$scenario['name'],
                    'simulator_type' => $simulatorLabel,
                    'schema_version' => (string)($payload['schema_version'] ?? MetricsCollector::SCHEMA_VERSION),
                    'seed' => $runSeed,
                    'is_baseline' => $isBaseline,
                    'cohort' => [
                        'players_per_archetype' => $playersPerArchetype,
                        'total_players' => (int)($payload['config']['total_players'] ?? 0),
                    ],
                    'horizon' => [
                        'season_count' => ($simulatorLabel === 'C') ? $seasonCount : 1,
                    ],
                    'override_categories' => (array)($scenario['categories'] ?? []),
                    'override_keys' => array_keys((array)($scenario['overrides'] ?? [])),
                    'json' => $jsonPath,
                    'csv' => $csvPath,
                    'config_audit' => (array)($payload['config_audit']['artifact_paths'] ?? []),
                    'timings' => [
                        'simulation_duration_ms' => $simulationDurationMs,
                        'json_write_duration_ms' => $jsonWriteDurationMs,
                        'csv_write_duration_ms' => $csvWriteDurationMs,
                        'total_duration_ms' => $runDurationMs,
                    ],
                ];
            }
        }
        $timingSummary['run_execution_duration_ms'] = self::msSince($runExecutionStartedAt);

        $manifest = [
            'schema_version' => MetricsCollector::SCHEMA_VERSION,
            'sweep_schema_version' => self::SWEEP_SCHEMA_VERSION,
            'scenario_schema_version' => PolicyScenarioCatalog::SCENARIO_SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'seed' => $seed,
            'config' => [
                'simulators' => $simulators,
                'include_baseline' => $includeBaseline,
                'players_per_archetype' => $playersPerArchetype,
                'season_count' => $seasonCount,
                'scenario_names' => array_map(static fn($s) => (string)$s['name'], $scenarios),
                'base_season_config_path' => $baseSeasonConfigPath,
            ],
            'runs' => $runEntries,
            'supported_override_categories' => array_keys(PolicyScenarioCatalog::categoryAllowlist()),
        ];

        $manifestBase = 'policy_sweep_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $seed) . '_ppa' . $playersPerArchetype . '_s' . $seasonCount;
        $manifestWriteStartedAt = microtime(true);
        $manifestPath = MetricsCollector::writeJson($manifest, $outputDir, $manifestBase);
        $timingSummary['manifest_write_duration_ms'] = self::msSince($manifestWriteStartedAt);
        $timingSummary['total_duration_ms'] = self::msSince($startedAt);
        usort($timingSummary['slowest_runs'], static function (array $left, array $right): int {
            return ((int)$right['total_duration_ms'] <=> (int)$left['total_duration_ms']);
        });
        $timingSummary['slowest_runs'] = array_slice($timingSummary['slowest_runs'], 0, 3);
        $manifest['timing_summary'] = $timingSummary;
        $manifestPath = MetricsCollector::writeJson($manifest, $outputDir, $manifestBase);

        return [
            'manifest' => $manifest,
            'manifest_path' => $manifestPath,
        ];
    }

    public static function normalizeSimulators(array $simulators): array
    {
        $normalized = [];
        foreach ($simulators as $simulator) {
            $value = strtoupper(trim((string)$simulator));
            if ($value === '') {
                continue;
            }
            if (!in_array($value, ['B', 'C'], true)) {
                throw new InvalidArgumentException('Unsupported simulator type: ' . $value);
            }
            $normalized[$value] = true;
        }

        $keys = array_keys($normalized);
        if ($keys === []) {
            throw new InvalidArgumentException('At least one simulator type must be selected (B/C).');
        }

        sort($keys);
        return $keys;
    }

    private static function assertReadableBaseSeasonConfig(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }
        if (!is_file($path)) {
            throw new InvalidArgumentException('Base season config file not found: ' . $path);
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Base season config must be a JSON object: ' . $path);
        }
    }

    private static function buildBaseName(string $scenarioName, string $simulator, string $seed, int $ppa, int $seasonCount): string
    {
        $sanitizedSeed = preg_replace('/[^A-Za-z0-9_-]/', '_', $seed);
        $sanitizedScenario = preg_replace('/[^A-Za-z0-9_-]/', '_', $scenarioName);
        if ($simulator === 'B') {
            return 'sweep_' . $sanitizedScenario . '_B_' . $sanitizedSeed . '_ppa' . $ppa;
        }

        return 'sweep_' . $sanitizedScenario . '_C_' . $sanitizedSeed . '_ppa' . $ppa . '_s' . $seasonCount;
    }

    private static function recordRunTiming(array &$timingSummary, string $scenarioName, string $simulator, int $runDurationMs): void
    {
        $timingSummary['run_count']++;
        if (!isset($timingSummary['by_simulator'][$simulator])) {
            $timingSummary['by_simulator'][$simulator] = [
                'run_count' => 0,
                'total_duration_ms' => 0,
            ];
        }

        $timingSummary['by_simulator'][$simulator]['run_count']++;
        $timingSummary['by_simulator'][$simulator]['total_duration_ms'] += $runDurationMs;
        $timingSummary['slowest_runs'][] = [
            'scenario_name' => $scenarioName,
            'simulator_type' => $simulator,
            'total_duration_ms' => $runDurationMs,
        ];
    }

    private static function msSince(float $startedAt): int
    {
        return (int)round((microtime(true) - $startedAt) * 1000);
    }
}
