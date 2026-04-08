<?php

class MetricsCollector
{
    public const SCHEMA_VERSION = 'tmc-sim-phase1.v1';

    public static function buildContractOutput(string $seed, array $checks): array
    {
        $passed = 0;
        foreach ($checks as $check) {
            if (!empty($check['passed'])) {
                $passed++;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'simulator' => 'contract-simulator',
            'generated_at' => gmdate('c'),
            'seed' => $seed,
            'summary' => [
                'passed' => $passed,
                'failed' => count($checks) - $passed,
                'all_passed' => $passed === count($checks),
            ],
            'checks' => $checks,
        ];
    }

    public static function buildSeasonOutput(string $seed, array $season, array $players, array $archetypes, int $playersPerArchetype): array
    {
        $archetypeMetrics = [];
        $overallDiagnostics = [
            'lock_in_timing' => ['EARLY' => 0, 'MID' => 0, 'LATE_ACTIVE' => 0, 'BLACKOUT' => 0, 'NONE' => 0],
            'natural_expiry_count' => 0,
            'late_active_active_players' => 0,
            'late_active_engaged_players' => 0,
            'action_volume_by_phase' => [
                'EARLY' => ['boost' => 0, 'combine' => 0, 'freeze' => 0, 'theft' => 0],
                'MID' => ['boost' => 0, 'combine' => 0, 'freeze' => 0, 'theft' => 0],
                'LATE_ACTIVE' => ['boost' => 0, 'combine' => 0, 'freeze' => 0, 'theft' => 0],
                'BLACKOUT' => ['boost' => 0, 'combine' => 0, 'freeze' => 0, 'theft' => 0],
            ],
        ];

        foreach ($archetypes as $key => $archetype) {
            $rows = array_values(array_filter($players, static function ($player) use ($key) {
                return $player['archetype_key'] === $key;
            }));

            $phaseCoins = ['EARLY' => 0, 'MID' => 0, 'LATE_ACTIVE' => 0, 'BLACKOUT' => 0];
            $phaseStars = ['EARLY' => 0, 'MID' => 0, 'LATE_ACTIVE' => 0, 'BLACKOUT' => 0];
            $sigilsByTier = ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0, '6' => 0];
            $sigilsSpent = ['boost' => 0, 'combine' => 0, 'freeze' => 0, 'theft' => 0, 'melt' => 0];
            $finalScores = [];
            $rankDistribution = [];
            $globalStarsGained = 0;
            $lockIns = 0;
            $naturalExpiry = 0;
            $blackoutConversions = 0;
            $t6Total = 0;
            $t6BySource = ['drop' => 0, 'combine' => 0, 'theft' => 0];
            $lockInTiming = ['EARLY' => 0, 'MID' => 0, 'LATE_ACTIVE' => 0, 'BLACKOUT' => 0, 'NONE' => 0];
            $lateActiveActivePlayers = 0;
            $lateActiveEngagedPlayers = 0;
            $actionVolumeByPhase = [
                'EARLY' => ['boost' => 0, 'combine' => 0, 'freeze' => 0, 'theft' => 0],
                'MID' => ['boost' => 0, 'combine' => 0, 'freeze' => 0, 'theft' => 0],
                'LATE_ACTIVE' => ['boost' => 0, 'combine' => 0, 'freeze' => 0, 'theft' => 0],
                'BLACKOUT' => ['boost' => 0, 'combine' => 0, 'freeze' => 0, 'theft' => 0],
            ];

            foreach ($rows as $row) {
                foreach ($phaseCoins as $phase => $_) {
                    $phaseCoins[$phase] += (int)($row['metrics']['coins_earned_by_phase'][$phase] ?? 0);
                    $phaseStars[$phase] += (int)($row['metrics']['stars_purchased_by_phase'][$phase] ?? 0);
                }
                foreach ($sigilsByTier as $tier => $_) {
                    $sigilsByTier[$tier] += (int)($row['metrics']['sigils_acquired_by_tier'][$tier] ?? 0);
                }
                foreach ($sigilsSpent as $action => $_) {
                    $sigilsSpent[$action] += (int)($row['metrics']['sigils_spent_by_action'][$action] ?? 0);
                }

                $finalScores[] = (int)$row['final_effective_score'];
                $rank = (string)((int)$row['final_rank']);
                $rankDistribution[$rank] = (int)($rankDistribution[$rank] ?? 0) + 1;
                $globalStarsGained += (int)$row['global_stars_gained'];
                $lockIns += !empty($row['locked_in']) ? 1 : 0;
                $naturalExpiry += empty($row['locked_in']) ? 1 : 0;
                $blackoutConversions += (int)($row['metrics']['blackout_conversions'] ?? 0);
                $t6Total += (int)($row['metrics']['t6_total_acquired'] ?? 0);

                $lockPhase = (string)($row['metrics']['lock_in_phase'] ?? 'NONE');
                if (!isset($lockInTiming[$lockPhase])) {
                    $lockPhase = 'NONE';
                }
                $lockInTiming[$lockPhase]++;

                $activeLateTicks = (int)($row['metrics']['active_ticks_by_phase']['LATE_ACTIVE'] ?? 0);
                if ($activeLateTicks > 0) {
                    $lateActiveActivePlayers++;
                }

                $lateEngaged = $activeLateTicks > 0 || (int)($row['metrics']['stars_purchased_by_phase']['LATE_ACTIVE'] ?? 0) > 0;
                foreach ($actionVolumeByPhase as $phase => $_) {
                    foreach ($actionVolumeByPhase[$phase] as $action => $_count) {
                        $count = (int)($row['metrics']['actions_by_phase'][$phase][$action] ?? 0);
                        $actionVolumeByPhase[$phase][$action] += $count;
                        $overallDiagnostics['action_volume_by_phase'][$phase][$action] += $count;
                        if ($phase === 'LATE_ACTIVE' && $count > 0) {
                            $lateEngaged = true;
                        }
                    }
                }
                if ($lateEngaged) {
                    $lateActiveEngagedPlayers++;
                }

                foreach ($t6BySource as $source => $_) {
                    $t6BySource[$source] += (int)($row['metrics']['t6_by_source'][$source] ?? 0);
                }
            }

            sort($finalScores);
            $count = max(1, count($rows));
            $median = 0;
            if (!empty($finalScores)) {
                $median = (int)$finalScores[(int)floor((count($finalScores) - 1) / 2)];
            }
            $archetypeMetrics[$key] = [
                'label' => (string)$archetype['label'],
                'players' => count($rows),
                'average_final_effective_score' => (int)floor(array_sum($finalScores) / $count),
                'median_final_effective_score' => $median,
                'coins_earned_by_phase' => $phaseCoins,
                'stars_purchased_by_phase' => $phaseStars,
                'lock_in_count' => $lockIns,
                'natural_expiry_count' => $naturalExpiry,
                'sigils_acquired_by_tier' => $sigilsByTier,
                'sigils_spent_by_action' => $sigilsSpent,
                't6_total_acquired' => $t6Total,
                't6_by_source' => $t6BySource,
                'blackout_conversions' => $blackoutConversions,
                'final_rank_distribution' => $rankDistribution,
                'global_stars_gained' => $globalStarsGained,
                'lock_in_timing' => $lockInTiming,
                'natural_expiry_rate' => $naturalExpiry / $count,
                'late_active_active_players' => $lateActiveActivePlayers,
                'late_active_active_rate' => $lateActiveActivePlayers / $count,
                'late_active_engaged_players' => $lateActiveEngagedPlayers,
                'late_active_engaged_rate' => $lateActiveEngagedPlayers / $count,
                'action_volume_by_phase' => $actionVolumeByPhase,
            ];

            foreach ($lockInTiming as $phase => $phaseCount) {
                $overallDiagnostics['lock_in_timing'][$phase] += $phaseCount;
            }
            $overallDiagnostics['natural_expiry_count'] += $naturalExpiry;
            $overallDiagnostics['late_active_active_players'] += $lateActiveActivePlayers;
            $overallDiagnostics['late_active_engaged_players'] += $lateActiveEngagedPlayers;
        }

        $playerCount = max(1, count($players));
        $overallDiagnostics['natural_expiry_rate'] = $overallDiagnostics['natural_expiry_count'] / $playerCount;
        $overallDiagnostics['late_active_active_rate'] = $overallDiagnostics['late_active_active_players'] / $playerCount;
        $overallDiagnostics['late_active_engaged_rate'] = $overallDiagnostics['late_active_engaged_players'] / $playerCount;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'simulator' => 'single-season-population',
            'generated_at' => gmdate('c'),
            'seed' => $seed,
            'config' => [
                'season_id' => (int)$season['season_id'],
                'players_per_archetype' => $playersPerArchetype,
                'total_players' => count($players),
            ],
            'diagnostics' => $overallDiagnostics,
            'archetypes' => $archetypeMetrics,
            'players' => array_map(static function ($row) {
                return [
                    'player_id' => (int)$row['player_id'],
                    'archetype_key' => (string)$row['archetype_key'],
                    'archetype_label' => (string)$row['archetype_label'],
                    'final_effective_score' => (int)$row['final_effective_score'],
                    'final_rank' => (int)$row['final_rank'],
                    'global_stars_gained' => (int)$row['global_stars_gained'],
                    'locked_in' => (bool)$row['locked_in'],
                    'participation' => $row['participation'],
                    'metrics' => $row['metrics'],
                ];
            }, $players),
        ];
    }

    public static function writeJson(array $payload, string $outputDir, string $baseName): string
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $path = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $baseName . '.json';
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $path;
    }

    public static function writeSeasonCsv(array $payload, string $outputDir, string $baseName): ?string
    {
        if (($payload['simulator'] ?? '') !== 'single-season-population') {
            return null;
        }
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $path = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $baseName . '.csv';
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            return null;
        }

        fputcsv($handle, [
            'archetype',
            'players',
            'avg_final_score',
            'median_final_score',
            'lock_in_count',
            'natural_expiry_count',
            'global_stars_gained',
            't6_total_acquired',
            'blackout_conversions',
        ]);

        foreach ((array)$payload['archetypes'] as $metrics) {
            fputcsv($handle, [
                (string)$metrics['label'],
                (int)$metrics['players'],
                (int)$metrics['average_final_effective_score'],
                (int)$metrics['median_final_effective_score'],
                (int)$metrics['lock_in_count'],
                (int)$metrics['natural_expiry_count'],
                (int)$metrics['global_stars_gained'],
                (int)$metrics['t6_total_acquired'],
                (int)$metrics['blackout_conversions'],
            ]);
        }

        fclose($handle);
        return $path;
    }

    public static function writeLifetimeCsv(array $payload, string $outputDir, string $baseName): ?string
    {
        if (($payload['simulator'] ?? '') !== 'lifetime-overlapping-season') {
            return null;
        }
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $path = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $baseName . '.csv';
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            return null;
        }

        fputcsv($handle, [
            'player_id',
            'archetype',
            'seasons_entered',
            'seasons_skipped',
            'avg_rejoin_delay',
            'cumulative_global_stars',
            'cumulative_participation_bonus',
            'cumulative_placement_bonus',
            'lock_in_count',
            'natural_expiry_count',
            'avg_final_rank',
        ]);

        foreach ((array)$payload['players'] as $player) {
            fputcsv($handle, [
                (int)$player['player_id'],
                (string)$player['archetype_label'],
                (int)$player['seasons_entered'],
                (int)$player['seasons_skipped'],
                round((float)$player['rejoin_delay_average'], 4),
                (int)$player['cumulative_global_stars'],
                (int)$player['cumulative_participation_bonus'],
                (int)$player['cumulative_placement_bonus'],
                (int)$player['lock_in_count'],
                (int)$player['natural_expiry_count'],
                round((float)$player['average_final_rank'], 4),
            ]);
        }

        fclose($handle);
        return $path;
    }

    public static function printContractSummary(array $payload): void
    {
        echo 'Contract Simulator' . PHP_EOL;
        echo 'Passed: ' . (int)$payload['summary']['passed'] . '  Failed: ' . (int)$payload['summary']['failed'] . PHP_EOL;
        foreach ((array)$payload['checks'] as $check) {
            echo sprintf('[%s] %s', !empty($check['passed']) ? 'PASS' : 'FAIL', (string)$check['name']) . PHP_EOL;
        }
    }

    public static function printSeasonSummary(array $payload): void
    {
        echo 'Single-Season Population Simulator' . PHP_EOL;
        foreach ((array)$payload['archetypes'] as $metrics) {
            echo sprintf(
                '%s | avg score %d | lock-in %d | expiry %d | late-active %.0f%% | global stars %d',
                (string)$metrics['label'],
                (int)$metrics['average_final_effective_score'],
                (int)$metrics['lock_in_count'],
                (int)$metrics['natural_expiry_count'],
                ((float)$metrics['late_active_engaged_rate']) * 100,
                (int)$metrics['global_stars_gained']
            ) . PHP_EOL;
        }
    }

    public static function printLifetimeSummary(array $payload): void
    {
        $diag = (array)($payload['population_diagnostics'] ?? []);
        $concentration = (array)($payload['concentration_drift'] ?? []);
        $final = !empty($concentration) ? (array)$concentration[count($concentration) - 1] : [];

        echo 'Lifetime / Overlapping-Season Simulator' . PHP_EOL;
        echo sprintf(
            'Players %d | Seasons %d | Avg cumulative stars %.2f',
            (int)($payload['config']['total_players'] ?? 0),
            (int)($payload['config']['season_count'] ?? 0),
            (float)($diag['average_cumulative_global_stars'] ?? 0.0)
        ) . PHP_EOL;
        echo sprintf(
            'Concentration final | median %.2f | top10 avg %.2f | top1 avg %.2f',
            (float)($final['median_cumulative_global_stars'] ?? 0.0),
            (float)($final['top_10_percent_average'] ?? 0.0),
            (float)($final['top_1_percent_average'] ?? 0.0)
        ) . PHP_EOL;
        echo sprintf(
            'Consistent avg %.2f | Skip-heavy avg %.2f | edge %.2f',
            (float)($diag['consistent_participation_group']['average_cumulative_global_stars'] ?? 0.0),
            (float)($diag['skip_heavy_group']['average_cumulative_global_stars'] ?? 0.0),
            (float)($diag['skip_strategy_edge'] ?? 0.0)
        ) . PHP_EOL;
    }
}
