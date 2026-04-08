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
                $seasonConfigPath = null;

                if (!$isBaseline) {
                    $seasonConfigPath = self::writeScenarioConfig($runDir, (string)$scenario['name'], $scenario['overrides']);
                }

                try {
                    if ($simulator === 'B') {
                        $payload = SimulationPopulationSeason::run($runSeed, $playersPerArchetype, $seasonConfigPath);
                        $simulatorLabel = 'B';
                    } else {
                        $payload = SimulationPopulationLifetime::run($runSeed, $playersPerArchetype, $seasonCount, $seasonConfigPath);
                        $simulatorLabel = 'C';
                    }
                } finally {
                    if ($seasonConfigPath !== null && is_file($seasonConfigPath)) {
                        @unlink($seasonConfigPath);
                    }
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

                $baseName = self::buildBaseName((string)$scenario['name'], $simulatorLabel, $runSeed, $playersPerArchetype, $seasonCount);
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

    private static function writeScenarioConfig(string $runDir, string $scenarioName, array $overrides): string
    {
        $path = rtrim($runDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'season_override_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $scenarioName) . '_'
            . substr(hash('sha256', json_encode($overrides, JSON_UNESCAPED_SLASHES)), 0, 12) . '.json';

        file_put_contents($path, json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $path;
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
