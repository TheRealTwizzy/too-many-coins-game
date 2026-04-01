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
