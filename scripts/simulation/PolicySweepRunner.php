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
        $simulators = self::normalizeSimulators((array)($options['simulators'] ?? ['B', 'C']));
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

        $runEntries = [];
        foreach ($scenarios as $scenario) {
            foreach ($simulators as $simulator) {
                $isBaseline = ((string)$scenario['name'] === 'baseline');
                $runSeed = $seed . '|scenario|' . (string)$scenario['name'] . '|sim|' . $simulator;
                $seasonConfigPath = $baseSeasonConfigPath;
                $baseName = self::buildBaseName((string)$scenario['name'], $simulator, $runSeed, $playersPerArchetype, $seasonCount);
                $auditDir = $runDir . DIRECTORY_SEPARATOR . $baseName . '.audit';

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

                $jsonPath = MetricsCollector::writeJson($payload, $runDir, $baseName);
                $csvPath = ($simulatorLabel === 'B')
                    ? MetricsCollector::writeSeasonCsv($payload, $runDir, $baseName)
                    : MetricsCollector::writeLifetimeCsv($payload, $runDir, $baseName);

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
                ];
            }
        }

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
}
