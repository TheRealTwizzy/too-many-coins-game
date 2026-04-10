<?php

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/economy.php';
require_once __DIR__ . '/../../includes/game_time.php';
require_once __DIR__ . '/SimulationSeason.php';
require_once __DIR__ . '/SimulationPlayer.php';
require_once __DIR__ . '/Archetypes.php';
require_once __DIR__ . '/PolicyBehavior.php';
require_once __DIR__ . '/SimulationRandom.php';
require_once __DIR__ . '/MetricsCollector.php';

class SimulationPopulationLifetime
{
    public static function run(string $seed = 'phase1-lifetime', int $playersPerArchetype = 5, int $seasonCount = 12, ?string $seasonConfigPath = null): array
    {
        $seasonCount = max(2, $seasonCount);
        $playersPerArchetype = max(1, $playersPerArchetype);
        $seasonOverrides = self::loadSeasonOverrides($seasonConfigPath);

        $archetypes = Archetypes::all();
        $players = self::buildPopulation($archetypes, $playersPerArchetype, $seed);

        $seasonSummaries = [];
        $concentrationDrift = [];
        $throughputEntries = [];

        for ($seasonSeq = 1; $seasonSeq <= $seasonCount; $seasonSeq++) {
            $seasonSeed = $seed . '|season|' . $seasonSeq;
            $season = SimulationSeason::build($seasonSeq, $seasonSeed, $seasonOverrides);

            [$enteredPlayerIds, $skipStats] = self::selectEntrants($players, $archetypes, $seed, $seasonSeq, (int)$season['start_time']);
            $seasonResult = self::runSeasonForEntrants($season, $enteredPlayerIds, $players, $archetypes, $seed, $seasonSeq);

            $entered = 0;
            $locks = 0;
            $expiry = 0;
            foreach ($enteredPlayerIds as $playerId) {
                $entered++;
                $result = $seasonResult['results_by_player_id'][$playerId] ?? null;
                if ($result === null) {
                    continue;
                }

                $players[$playerId]['seasons_entered']++;
                $players[$playerId]['cumulative_global_stars'] += (int)$result['global_stars_gained'];
                $players[$playerId]['cumulative_participation_bonus'] += (int)($result['participation']['participation_bonus'] ?? 0);
                $players[$playerId]['cumulative_placement_bonus'] += (int)($result['participation']['placement_bonus'] ?? 0);
                $players[$playerId]['final_rank_sum'] += (int)$result['final_rank'];
                $players[$playerId]['final_rank_count'] += ((int)$result['final_rank'] > 0) ? 1 : 0;
                $players[$playerId]['score_progression'][] = [
                    'season_seq' => $seasonSeq,
                    'final_effective_score' => (int)$result['final_effective_score'],
                    'global_stars_gained' => (int)$result['global_stars_gained'],
                    'final_rank' => (int)$result['final_rank'],
                    'locked_in' => !empty($result['locked_in']),
                ];

                if (!empty($result['locked_in'])) {
                    $locks++;
                    $players[$playerId]['lock_in_count']++;
                    $lockTick = (int)($result['participation']['lock_in_effect_tick'] ?? (int)$season['end_time']);
                    $players[$playerId]['available_tick'] = max((int)$season['start_time'], $lockTick + 1);
                    if ($lockTick < (int)$season['blackout_time']) {
                        $players[$playerId]['early_lock_count']++;
                    }
                } else {
                    $expiry++;
                    $players[$playerId]['natural_expiry_count']++;
                    $players[$playerId]['available_tick'] = (int)$season['end_time'] + 1;
                }
            }

            $overlapAtStart = self::activeSeasonCountAtStart($seasonSeq, $seasonCount, (int)$season['start_time']);
            $throughputEntries[] = [
                'season_seq' => $seasonSeq,
                'entered_players' => $entered,
                'lock_ins' => $locks,
                'natural_expiry' => $expiry,
            ];

            $seasonSummaries[] = [
                'season_seq' => $seasonSeq,
                'season_id' => (int)$season['season_id'],
                'start_time' => (int)$season['start_time'],
                'blackout_time' => (int)$season['blackout_time'],
                'end_time' => (int)$season['end_time'],
                'active_seasons_at_start' => $overlapAtStart,
                'entered_players' => $entered,
                'skipped_voluntary' => $skipStats['voluntary'],
                'skipped_busy' => $skipStats['busy'],
                'lock_ins' => $locks,
                'natural_expiry' => $expiry,
                'late_active_engaged_rate' => (float)($seasonResult['season_payload']['diagnostics']['late_active_engaged_rate'] ?? 0.0),
                'action_volume_by_phase' => (array)($seasonResult['season_payload']['diagnostics']['action_volume_by_phase'] ?? []),
                't6_total' => (int)($seasonResult['season_payload']['diagnostics']['t6_total_acquired'] ?? 0),
            ];

            $concentrationDrift[] = self::buildConcentrationSnapshot($players, $seasonSeq);
        }

        $playerRows = array_values(array_map(static function (array $player): array {
            $entered = max(1, $player['seasons_entered']);
            $avgRejoinDelay = !empty($player['rejoin_delays'])
                ? (array_sum($player['rejoin_delays']) / count($player['rejoin_delays']))
                : 0.0;
            $avgFinalRank = $player['final_rank_count'] > 0
                ? ($player['final_rank_sum'] / $player['final_rank_count'])
                : 0.0;

            return [
                'player_id' => (int)$player['player_id'],
                'archetype_key' => (string)$player['archetype_key'],
                'archetype_label' => (string)$player['archetype_label'],
                'seasons_entered' => (int)$player['seasons_entered'],
                'seasons_skipped' => (int)$player['seasons_skipped'],
                'seasons_skipped_voluntary' => (int)$player['seasons_skipped_voluntary'],
                'seasons_skipped_busy' => (int)$player['seasons_skipped_busy'],
                'rejoin_delay_average' => $avgRejoinDelay,
                'rejoin_delay_samples' => $player['rejoin_delays'],
                'cumulative_global_stars' => (int)$player['cumulative_global_stars'],
                'cumulative_participation_bonus' => (int)$player['cumulative_participation_bonus'],
                'cumulative_placement_bonus' => (int)$player['cumulative_placement_bonus'],
                'lock_in_count' => (int)$player['lock_in_count'],
                'natural_expiry_count' => (int)$player['natural_expiry_count'],
                'average_final_rank' => $avgFinalRank,
                'score_progression_over_time' => $player['score_progression'],
                'skip_rate' => ((float)$player['seasons_skipped']) / max(1.0, (float)($player['seasons_entered'] + $player['seasons_skipped'])),
                'entry_throughput' => ((float)$player['seasons_entered']) / max(1.0, (float)($player['seasons_entered'] + $player['seasons_skipped'])),
            ];
        }, $players));

        $archetypeStats = self::buildArchetypeLifetimeStats($playerRows, $archetypes);
        $population = self::buildPopulationDiagnostics($playerRows, $archetypeStats, $throughputEntries, $seasonCount, $seed);

        return [
            'schema_version' => MetricsCollector::SCHEMA_VERSION,
            'simulator' => 'lifetime-overlapping-season',
            'generated_at' => gmdate('c'),
            'seed' => $seed,
            'config' => [
                'players_per_archetype' => $playersPerArchetype,
                'total_players' => count($playerRows),
                'season_count' => $seasonCount,
                'season_duration_ticks' => (int)SEASON_DURATION,
                'season_cadence_ticks' => (int)SEASON_CADENCE,
                'expected_overlap' => (int)max(1, (int)ceil((float)SEASON_DURATION / max(1.0, (float)SEASON_CADENCE))),
            ],
            'season_timeline' => $seasonSummaries,
            'players' => $playerRows,
            'archetypes' => $archetypeStats,
            'concentration_drift' => $concentrationDrift,
            'population_diagnostics' => $population,
            'unmodeled_mechanics' => PolicyBehavior::UNMODELED_MECHANICS,
        ];
    }

    private static function loadSeasonOverrides(?string $seasonConfigPath): array
    {
        if ($seasonConfigPath === null || $seasonConfigPath === '') {
            return [];
        }
        if (!is_file($seasonConfigPath)) {
            throw new InvalidArgumentException('Season config file not found: ' . $seasonConfigPath);
        }

        $decoded = json_decode((string)file_get_contents($seasonConfigPath), true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Season config JSON must decode to an object');
        }

        $stripKeys = ['season_id', 'start_time', 'end_time', 'blackout_time', 'season_seed'];
        foreach ($stripKeys as $key) {
            unset($decoded[$key]);
        }

        return SimulationSeason::normalizeImportedRow($decoded);
    }

    private static function buildPopulation(array $archetypes, int $playersPerArchetype, string $seed): array
    {
        $players = [];
        $nextId = 1;
        foreach ($archetypes as $key => $archetype) {
            for ($i = 0; $i < $playersPerArchetype; $i++) {
                $playerId = $nextId++;
                $personaSeed = $seed . '|persona|' . $playerId;
                $players[$playerId] = [
                    'player_id' => $playerId,
                    'archetype_key' => $key,
                    'archetype_label' => (string)$archetype['label'],
                    'persona_seed' => $personaSeed,
                    'available_tick' => 1,
                    'seasons_entered' => 0,
                    'seasons_skipped' => 0,
                    'seasons_skipped_voluntary' => 0,
                    'seasons_skipped_busy' => 0,
                    'current_skip_streak' => 0,
                    'rejoin_delays' => [],
                    'cumulative_global_stars' => 0,
                    'cumulative_participation_bonus' => 0,
                    'cumulative_placement_bonus' => 0,
                    'lock_in_count' => 0,
                    'natural_expiry_count' => 0,
                    'early_lock_count' => 0,
                    'final_rank_sum' => 0,
                    'final_rank_count' => 0,
                    'score_progression' => [],
                    'join_commitment' => SimulationRandom::float01($personaSeed, ['join-commitment']),
                    'skip_tendency' => SimulationRandom::float01($personaSeed, ['skip-tendency']),
                    'rejoin_push' => SimulationRandom::float01($personaSeed, ['rejoin-push']),
                ];
            }
        }

        return $players;
    }

    private static function selectEntrants(array &$players, array $archetypes, string $seed, int $seasonSeq, int $seasonStart): array
    {
        $entered = [];
        $skipVoluntary = 0;
        $skipBusy = 0;

        foreach ($players as $playerId => &$player) {
            if ((int)$player['available_tick'] > $seasonStart) {
                $player['seasons_skipped']++;
                $player['seasons_skipped_busy']++;
                $player['current_skip_streak']++;
                $skipBusy++;
                continue;
            }

            $archetype = (array)$archetypes[$player['archetype_key']];
            if (!self::shouldEnterSeason($player, $archetype, $seed, $seasonSeq)) {
                $player['seasons_skipped']++;
                $player['seasons_skipped_voluntary']++;
                $player['current_skip_streak']++;
                $skipVoluntary++;
                continue;
            }

            if ((int)$player['seasons_entered'] > 0) {
                $player['rejoin_delays'][] = (int)$player['current_skip_streak'];
            }
            $player['current_skip_streak'] = 0;
            $entered[] = (int)$playerId;
        }
        unset($player);

        return [$entered, ['voluntary' => $skipVoluntary, 'busy' => $skipBusy]];
    }

    private static function shouldEnterSeason(array $player, array $archetype, string $seed, int $seasonSeq): bool
    {
        $traits = (array)($archetype['traits'] ?? []);
        $discipline = (float)($traits['discipline'] ?? 0.5);
        $patience = (float)($traits['patience'] ?? 0.5);
        $risk = (float)($traits['risk_tolerance'] ?? 0.5);
        $lateConversion = (float)($traits['late_conversion'] ?? 0.5);
        $expiryBias = (float)($traits['expiry_bias'] ?? 0.2);

        $p = 0.40;
        $p += 0.25 * $discipline;
        $p += 0.10 * $player['join_commitment'];
        $p += 0.08 * $risk;
        $p -= 0.14 * $player['skip_tendency'];
        $p -= 0.08 * $patience;
        $p += 0.10 * min(1.0, ((float)$player['current_skip_streak']) / 3.0);

        if ($player['lock_in_count'] > 0) {
            $p += 0.05 * min(1.0, ((float)$player['early_lock_count']) / max(1.0, (float)$player['lock_in_count']));
        }
        if ((string)$player['archetype_key'] === 'late_deployer') {
            $p -= 0.05;
        }
        if ((string)$player['archetype_key'] === 'mostly_idle') {
            $p -= 0.10;
        }
        if ((string)$player['archetype_key'] === 'hardcore') {
            $p += 0.10;
        }

        $p += 0.04 * $lateConversion;
        $p -= 0.03 * $expiryBias;
        $p += (SimulationRandom::float01($seed, ['lifetime-join-noise', $player['player_id'], $seasonSeq]) - 0.5) * 0.20;
        $p = max(0.05, min(0.96, $p));

        return SimulationRandom::chance($seed, $p, ['lifetime-join', $player['player_id'], $seasonSeq]);
    }

    private static function runSeasonForEntrants(array $season, array $enteredPlayerIds, array $players, array $archetypes, string $seed, int $seasonSeq): array
    {
        if ($enteredPlayerIds === []) {
            return [
                'results_by_player_id' => [],
                'season_payload' => [
                    'diagnostics' => [
                        'late_active_engaged_rate' => 0.0,
                        'action_volume_by_phase' => [
                            'EARLY' => ['boost' => 0, 'combine' => 0, 'freeze' => 0, 'theft' => 0],
                            'MID' => ['boost' => 0, 'combine' => 0, 'freeze' => 0, 'theft' => 0],
                            'LATE_ACTIVE' => ['boost' => 0, 'combine' => 0, 'freeze' => 0, 'theft' => 0],
                            'BLACKOUT' => ['boost' => 0, 'combine' => 0, 'freeze' => 0, 'theft' => 0],
                        ],
                        't6_total_acquired' => 0,
                    ],
                ],
            ];
        }

        $simPlayers = [];
        foreach ($enteredPlayerIds as $playerId) {
            $archetypeKey = (string)$players[$playerId]['archetype_key'];
            $seasonSeed = $seed . '|lifetime-season|' . $seasonSeq;
            $playerSeed = $seasonSeed . '|player|' . $playerId;
            $simPlayers[] = new SimulationPlayer((int)$playerId, $archetypeKey, $archetypes[$archetypeKey], $playerSeed, (int)$season['season_id']);
        }

        $previousPhase = null;
        $starPriceSummary = ['tick_count' => 0, 'sum' => 0, 'min' => PHP_INT_MAX, 'max' => 0, 'at_cap' => 0, 'at_floor' => 0];
        $starPriceCap = (int)($season['star_price_cap'] ?? 10000);

        for ($tick = (int)$season['start_time']; $tick < (int)$season['end_time']; $tick++) {
            $status = SimulationSeason::updateComputedStatus($season, $tick);
            if ($status === 'Blackout' && $season['blackout_star_price_snapshot'] === null) {
                $season['blackout_star_price_snapshot'] = (int)$season['current_star_price'];
                $season['blackout_started_tick'] = $tick;
            }

            foreach ($simPlayers as $player) {
                $player->expireEffects($tick);
            }

            $currentPhase = ($status === 'Blackout')
                ? 'BLACKOUT'
                : (string)Economy::sigilSeasonPhase($season, $tick);

            if ($previousPhase !== null && $currentPhase !== $previousPhase && $previousPhase !== 'BLACKOUT') {
                foreach ($simPlayers as $player) {
                    $player->snapshotPhaseEnd($previousPhase);
                }
            }
            $previousPhase = $currentPhase;

            foreach ($simPlayers as $player) {
                if (!$player->isParticipating()) {
                    continue;
                }
                $phase = $currentPhase;
                $snapshot = $player->snapshot();
                $presence = PolicyBehavior::resolvePresenceState(
                    Archetypes::get($snapshot['archetype_key']),
                    $phase,
                    $seed,
                    (int)$snapshot['player_id'],
                    $tick
                );
                $player->setPresenceState($presence, $tick);
                if ($status !== 'Blackout' && $tick < ((int)$season['end_time'] - 1)) {
                    $player->processSigilDrop($season, $tick);
                }
                $player->accrue($season, $phase);
            }

            $snapshots = array_map(static fn($p) => $p->snapshot(), $simPlayers);
            $playerMap = [];
            foreach ($simPlayers as $player) {
                $playerMap[$player->snapshot()['player_id']] = $player;
            }

            foreach ($simPlayers as $player) {
                if (!$player->isParticipating()) {
                    continue;
                }
                $phase = $currentPhase;
                $player->act($season, $phase, $tick, $snapshots, $playerMap);
            }

            self::recomputeSeasonSupply($season, $simPlayers, $tick);

            $price = (int)$season['current_star_price'];
            $starPriceSummary['tick_count']++;
            $starPriceSummary['sum'] += $price;
            if ($price < $starPriceSummary['min']) $starPriceSummary['min'] = $price;
            if ($price > $starPriceSummary['max']) $starPriceSummary['max'] = $price;
            if ($price >= $starPriceCap) $starPriceSummary['at_cap']++;
            if ($price <= 1) $starPriceSummary['at_floor']++;
        }

        if ($previousPhase !== null && $previousPhase !== 'BLACKOUT') {
            foreach ($simPlayers as $player) {
                $player->snapshotPhaseEnd($previousPhase);
            }
        }

        $starPriceSummary['mean'] = $starPriceSummary['tick_count'] > 0
            ? round($starPriceSummary['sum'] / $starPriceSummary['tick_count'], 2)
            : 0;
        $starPriceSummary['cap_share'] = $starPriceSummary['tick_count'] > 0
            ? round($starPriceSummary['at_cap'] / $starPriceSummary['tick_count'], 4)
            : 0;
        $starPriceSummary['floor_share'] = $starPriceSummary['tick_count'] > 0
            ? round($starPriceSummary['at_floor'] / $starPriceSummary['tick_count'], 4)
            : 0;
        $season['_star_price_summary'] = $starPriceSummary;

        self::finalizeSeason($simPlayers);

        $results = array_map(static fn($player) => $player->exportResult(), $simPlayers);
        $resultsByPlayerId = [];
        foreach ($results as $result) {
            $resultsByPlayerId[(int)$result['player_id']] = $result;
        }

        $seasonPayload = MetricsCollector::buildSeasonOutput(
            $seed . '|lifetime-season|' . $seasonSeq,
            $season,
            $results,
            $archetypes,
            0
        );
        $seasonPayload['diagnostics']['t6_total_acquired'] = array_sum(array_map(static function ($a) {
            return (int)($a['t6_total_acquired'] ?? 0);
        }, (array)$seasonPayload['archetypes']));

        return [
            'results_by_player_id' => $resultsByPlayerId,
            'season_payload' => $seasonPayload,
        ];
    }

    private static function recomputeSeasonSupply(array &$season, array $players, int $tick): void
    {
        $totalSupply = 0;
        $coinsActive = 0;
        $coinsIdle = 0;
        $coinsOffline = 0;
        foreach ($players as $player) {
            if (!$player->isParticipating()) {
                continue;
            }
            $coins = $player->totalCoins();
            $totalSupply += $coins;
            $presence = $player->currentPresence();
            if ($presence === 'Active') {
                $coinsActive += $coins;
            } elseif ($presence === 'Offline') {
                $coinsOffline += $coins;
            } else {
                $coinsIdle += $coins;
            }
        }

        $season['total_coins_supply'] = $totalSupply;
        $season['total_coins_supply_end_of_tick'] = $totalSupply;
        $season['coins_active_total'] = $coinsActive;
        $season['coins_idle_total'] = $coinsIdle;
        $season['coins_offline_total'] = $coinsOffline;
        $season['effective_price_supply'] = $coinsActive + Economy::fpMultiply($coinsIdle, (int)$season['starprice_idle_weight_fp']);
        $season['last_processed_tick'] = $tick;
        $season['current_star_price'] = ($season['status'] === 'Blackout')
            ? Economy::publishedStarPrice($season, 'Blackout')
            : Economy::calculateStarPrice($season);
    }

    private static function finalizeSeason(array $players): void
    {
        foreach ($players as $player) {
            $player->markEndMembership();
        }

        $rankable = [];
        foreach ($players as $player) {
            $snapshot = $player->snapshot();
            $participation = $snapshot['participation'];
            if (!empty($participation['end_membership']) || !empty($participation['lock_in_effect_tick'])) {
                $rankable[] = [
                    'player' => $player,
                    'score' => Economy::effectiveSeasonalStars($participation),
                    'player_id' => $snapshot['player_id'],
                ];
            }
        }

        usort($rankable, static function ($left, $right) {
            if ($left['score'] === $right['score']) {
                return $left['player_id'] <=> $right['player_id'];
            }
            return $right['score'] <=> $left['score'];
        });

        $topValue = !empty($rankable) ? (int)$rankable[0]['score'] : 0;
        $award = $topValue > 0;
        foreach ($rankable as $index => $entry) {
            $entry['player']->setFinalRank($index + 1);
        }
        foreach ($rankable as $index => $entry) {
            $entry['player']->applyNaturalExpiry($index + 1, $award);
        }
    }

    private static function activeSeasonCountAtStart(int $seasonSeq, int $seasonCount, int $startTick): int
    {
        $active = 0;
        for ($seq = 1; $seq <= $seasonCount; $seq++) {
            $seqStart = (int)GameTime::seasonStartTime($seq);
            $seqEnd = (int)GameTime::seasonEndTime($seqStart);
            if ($seqStart <= $startTick && $startTick < $seqEnd) {
                $active++;
            }
        }

        return $active;
    }

    private static function buildConcentrationSnapshot(array $players, int $seasonSeq): array
    {
        $values = array_values(array_map(static fn($p) => (int)$p['cumulative_global_stars'], $players));
        sort($values);
        $count = count($values);
        $total = array_sum($values);
        $median = ($count > 0) ? (float)$values[(int)floor(($count - 1) / 2)] : 0.0;

        $top10Count = max(1, (int)ceil($count * 0.10));
        $top1Count = max(1, (int)ceil($count * 0.01));
        $top10 = array_slice($values, -$top10Count);
        $top1 = array_slice($values, -$top1Count);
        $top10Sum = array_sum($top10);
        $top1Sum = array_sum($top1);

        return [
            'season_seq' => $seasonSeq,
            'median_cumulative_global_stars' => $median,
            'top_10_percent_average' => $top10Count > 0 ? ($top10Sum / $top10Count) : 0.0,
            'top_1_percent_average' => $top1Count > 0 ? ($top1Sum / $top1Count) : 0.0,
            'top_10_percent_share' => $total > 0 ? ($top10Sum / $total) : 0.0,
            'top_1_percent_share' => $total > 0 ? ($top1Sum / $total) : 0.0,
            'total_cumulative_global_stars' => $total,
        ];
    }

    private static function buildArchetypeLifetimeStats(array $playerRows, array $archetypes): array
    {
        $stats = [];
        foreach ($archetypes as $key => $archetype) {
            $stats[$key] = [
                'label' => (string)$archetype['label'],
                'players' => 0,
                'cumulative_global_stars_sum' => 0,
                'cumulative_global_stars_avg' => 0.0,
                'cumulative_global_stars_median' => 0.0,
                'lock_in_count_sum' => 0,
                'natural_expiry_count_sum' => 0,
                'seasons_entered_avg' => 0.0,
                'seasons_skipped_avg' => 0.0,
                'average_final_rank' => 0.0,
            ];
        }

        foreach ($playerRows as $row) {
            $key = (string)$row['archetype_key'];
            if (!isset($stats[$key])) {
                continue;
            }
            $stats[$key]['players']++;
            $stats[$key]['cumulative_global_stars_sum'] += (int)$row['cumulative_global_stars'];
            $stats[$key]['lock_in_count_sum'] += (int)$row['lock_in_count'];
            $stats[$key]['natural_expiry_count_sum'] += (int)$row['natural_expiry_count'];
            $stats[$key]['seasons_entered_avg'] += (int)$row['seasons_entered'];
            $stats[$key]['seasons_skipped_avg'] += (int)$row['seasons_skipped'];
            $stats[$key]['average_final_rank'] += (float)$row['average_final_rank'];
            $stats[$key]['_values'][] = (int)$row['cumulative_global_stars'];
        }

        foreach ($stats as $key => &$entry) {
            $players = max(1, (int)$entry['players']);
            $entry['cumulative_global_stars_avg'] = $entry['cumulative_global_stars_sum'] / $players;
            $entry['seasons_entered_avg'] /= $players;
            $entry['seasons_skipped_avg'] /= $players;
            $entry['average_final_rank'] /= $players;
            $values = $entry['_values'] ?? [0];
            sort($values);
            $entry['cumulative_global_stars_median'] = (float)$values[(int)floor((count($values) - 1) / 2)];
            unset($entry['_values']);
        }
        unset($entry);

        return $stats;
    }

    private static function buildPopulationDiagnostics(array $playerRows, array $archetypeStats, array $throughputEntries, int $seasonCount, string $seed): array
    {
        $totalStars = array_sum(array_map(static fn($p) => (int)$p['cumulative_global_stars'], $playerRows));
        $consistent = array_values(array_filter($playerRows, static function ($p) use ($seasonCount) {
            return (int)$p['seasons_skipped'] <= max(1, (int)floor($seasonCount * 0.2))
                && (int)$p['seasons_entered'] >= max(1, (int)floor($seasonCount * 0.6));
        }));
        $skipHeavy = array_values(array_filter($playerRows, static function ($p) {
            return (int)$p['seasons_skipped'] >= (int)$p['seasons_entered'];
        }));

        $consistentAvg = !empty($consistent)
            ? (array_sum(array_map(static fn($p) => (int)$p['cumulative_global_stars'], $consistent)) / count($consistent))
            : 0.0;
        $skipHeavyAvg = !empty($skipHeavy)
            ? (array_sum(array_map(static fn($p) => (int)$p['cumulative_global_stars'], $skipHeavy)) / count($skipHeavy))
            : 0.0;

        $maxArchetype = ['label' => null, 'avg' => 0.0];
        foreach ($archetypeStats as $entry) {
            if ((float)$entry['cumulative_global_stars_avg'] > $maxArchetype['avg']) {
                $maxArchetype = ['label' => (string)$entry['label'], 'avg' => (float)$entry['cumulative_global_stars_avg']];
            }
        }

        $throughputLockRate = 0.0;
        $throughputEntered = array_sum(array_map(static fn($x) => (int)$x['entered_players'], $throughputEntries));
        if ($throughputEntered > 0) {
            $throughputLockRate = array_sum(array_map(static fn($x) => (int)$x['lock_ins'], $throughputEntries)) / $throughputEntered;
        }

        return [
            'total_cumulative_global_stars' => $totalStars,
            'average_cumulative_global_stars' => !empty($playerRows) ? ($totalStars / count($playerRows)) : 0.0,
            'consistent_participation_group' => [
                'players' => count($consistent),
                'average_cumulative_global_stars' => $consistentAvg,
            ],
            'skip_heavy_group' => [
                'players' => count($skipHeavy),
                'average_cumulative_global_stars' => $skipHeavyAvg,
            ],
            'skip_strategy_edge' => $skipHeavyAvg - $consistentAvg,
            'highest_compounding_archetype' => $maxArchetype,
            'throughput_lock_in_rate' => $throughputLockRate,
            'seed_checksum' => hash('sha256', $seed . '|lifetime-output'),
        ];
    }
}
