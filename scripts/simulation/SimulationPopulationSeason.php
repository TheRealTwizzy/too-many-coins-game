<?php

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/economy.php';
require_once __DIR__ . '/../../includes/game_time.php';
require_once __DIR__ . '/SimulationSeason.php';
require_once __DIR__ . '/SimulationPlayer.php';
require_once __DIR__ . '/Archetypes.php';
require_once __DIR__ . '/PolicyBehavior.php';
require_once __DIR__ . '/MetricsCollector.php';

class SimulationPopulationSeason
{
    public static function run(string $seed = 'phase1', int $playersPerArchetype = 5, ?string $seasonConfigPath = null): array
    {
        $season = $seasonConfigPath
            ? SimulationSeason::fromJsonFile($seasonConfigPath, 1, $seed)
            : SimulationSeason::build(1, $seed);

        $archetypes = Archetypes::all();
        $players = [];
        $nextPlayerId = 1;
        foreach ($archetypes as $key => $archetype) {
            for ($index = 0; $index < $playersPerArchetype; $index++) {
                $players[] = new SimulationPlayer($nextPlayerId++, $key, $archetype, $seed, (int)$season['season_id']);
            }
        }

        for ($tick = (int)$season['start_time']; $tick < (int)$season['end_time']; $tick++) {
            $status = SimulationSeason::updateComputedStatus($season, $tick);
            if ($status === 'Blackout' && $season['blackout_star_price_snapshot'] === null) {
                $season['blackout_star_price_snapshot'] = (int)$season['current_star_price'];
                $season['blackout_started_tick'] = $tick;
            }

            $playerMap = [];
            foreach ($players as $player) {
                $player->expireEffects($tick);
                $playerMap[$player->snapshot()['player_id']] = $player;
            }

            foreach ($players as $player) {
                if (!$player->isParticipating()) {
                    continue;
                }
                $phase = ($status === 'Blackout')
                    ? 'BLACKOUT'
                    : (string)Economy::sigilSeasonPhase($season, $tick);
                $presence = PolicyBehavior::resolvePresenceState(
                    Archetypes::get($player->snapshot()['archetype_key']),
                    $phase,
                    $seed,
                    $player->snapshot()['player_id'],
                    $tick
                );
                $player->setPresenceState($presence, $tick);
                if ($status !== 'Blackout' && $tick < ((int)$season['end_time'] - 1)) {
                    $player->processSigilDrop($season, $tick);
                }
                $player->accrue($season, $phase);
            }

            $snapshots = array_map(static fn($p) => $p->snapshot(), $players);
            $map = [];
            foreach ($players as $player) {
                $map[$player->snapshot()['player_id']] = $player;
            }
            foreach ($players as $player) {
                if (!$player->isParticipating()) {
                    continue;
                }
                $phase = ($status === 'Blackout')
                    ? 'BLACKOUT'
                    : (string)Economy::sigilSeasonPhase($season, $tick);
                $player->act($season, $phase, $tick, $snapshots, $map);
            }

            self::recomputeSeasonSupply($season, $players, $tick);
        }

        self::finalizeSeason($players);

        $results = array_map(static fn($player) => $player->exportResult(), $players);
        return MetricsCollector::buildSeasonOutput($seed, $season, $results, $archetypes, $playersPerArchetype);
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
                $rankable[] = ['player' => $player, 'score' => Economy::effectiveSeasonalStars($participation), 'player_id' => $snapshot['player_id']];
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
            $rank = $index + 1;
            $entry['player']->setFinalRank($rank);
        }

        foreach ($rankable as $index => $entry) {
            $entry['player']->applyNaturalExpiry($index + 1, $award);
        }
    }
}
