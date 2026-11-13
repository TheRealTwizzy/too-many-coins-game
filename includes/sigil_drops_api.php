<?php
/**
 * Sigil drop API contract helpers.
 */
require_once __DIR__ . '/config.php';

function normalizeRecentSigilDropRow(array $row): array {
    $payload = null;
    if (!empty($row['payload_json'])) {
        $decoded = json_decode((string)$row['payload_json'], true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $dropMeta = is_array($payload) && isset($payload['drop_meta']) && is_array($payload['drop_meta'])
        ? $payload['drop_meta']
        : [];

    $dropTick = (int)($row['drop_tick'] ?? 0);
    $seasonStart = (int)($row['season_start_time'] ?? 0);
    $seasonEnd = (int)($row['season_end_time'] ?? ($seasonStart + 1));
    $seasonDuration = max(1, $seasonEnd - $seasonStart);
    $seasonElapsed = max(0, min($seasonDuration, $dropTick - $seasonStart));
    $fallbackSeasonProgress = $seasonElapsed / $seasonDuration;

    if (!isset($dropMeta['algorithm_version'])) {
        $dropMeta['algorithm_version'] = (string)SIGIL_DROP_ALGORITHM_VERSION;
    }

    $row['sigil_id'] = null;
    $row['tick_index'] = $dropTick;
    $row['activity_state'] = (string)($dropMeta['activity_state'] ?? 'Unknown');
    $row['season_progress'] = isset($dropMeta['season_progress_fp'])
        ? ((int)$dropMeta['season_progress_fp'] / FP_SCALE)
        : (isset($dropMeta['season_progress']) ? (float)$dropMeta['season_progress'] : $fallbackSeasonProgress);
    $row['metadata'] = $dropMeta;
    $row['source'] = strtoupper((string)($row['source'] ?? 'RNG'));

    unset($row['payload_json']);
    unset($row['season_start_time']);
    unset($row['season_end_time']);

    return $row;
}

/**
 * The public shape of season.sigil_drop_rates, from Economy::sigilDropOddsForTick().
 *
 * Kept from the old payload: base_one_in, base_percent, and tiers[] with
 * tier/odds_fp/chance_percent/conditional_percent - now describing the live
 * model, where odds_fp is the tier's share of a drop on FP_SCALE. Pure, so
 * tools/sigil_odds_selfcheck.php can test it without a database.
 */
function sigilDropRatesPayload(array $odds, string $perspective): array {
    $chanceFp = max(0, (int)$odds['effective_drop_chance_fp']);
    $basePercent = $chanceFp / FP_SCALE * 100;

    $totalWeight = 0;
    foreach ($odds['tier_weights'] as $weight) {
        $totalWeight += max(0, (int)$weight);
    }

    $tiers = [];
    foreach ($odds['tier_weights'] as $tier => $weight) {
        $weight = max(0, (int)$weight);
        if ($weight <= 0 || $totalWeight <= 0) {
            continue;
        }
        $share = $weight / $totalWeight;
        $tiers[] = [
            'tier' => (int)$tier,
            'weight' => $weight,
            'odds_fp' => intdiv($weight * FP_SCALE, $totalWeight),
            'chance_percent' => round($basePercent * $share, 6),
            'conditional_percent' => round($share * 100, 6),
        ];
    }

    return [
        'source' => 'engine',
        'perspective' => $perspective,
        'eligible' => (bool)$odds['eligible'],
        'activity_state' => (string)$odds['activity_state'],
        'season_phase' => (string)$odds['season_phase'],
        'effective_drop_chance_fp' => $chanceFp,
        'base_percent' => round($basePercent, 6),
        'base_one_in' => $chanceFp > 0 ? (int)round(FP_SCALE / $chanceFp) : 0,
        'tiers' => $tiers,
        'factors' => [
            'activity_multiplier_fp' => (int)$odds['activity_multiplier_fp'],
            'inventory_pressure_fp' => (int)$odds['inventory_pressure_fp'],
            'boost_pressure_fp' => (int)$odds['boost_pressure_fp'],
            'swarm_active' => (bool)$odds['swarm_active'],
        ],
        // Outside the per-tick roll, applied by the tick engine's batch loop.
        'pity_after_attempts' => (int)SIGIL_PITY_TICKS,
        'max_drops_per_window' => (int)SIGIL_MAX_DROPS_WINDOW,
        'drop_window_ticks' => (int)SIGIL_DROP_WINDOW_TICKS,
    ];
}
