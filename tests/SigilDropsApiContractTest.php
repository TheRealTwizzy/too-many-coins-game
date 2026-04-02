<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/sigil_drops_api.php';

class SigilDropsApiContractTest extends TestCase
{
    public function testNormalizeRecentSigilDropRowUsesPayloadMetadataWhenPresent(): void
    {
        $row = [
            'tier' => 4,
            'source' => 'rng',
            'drop_tick' => 1550,
            'created_at' => '2026-04-01 00:00:00',
            'season_start_time' => 1000,
            'season_end_time' => 2000,
            'payload_json' => json_encode([
                'drop_meta' => [
                    'activity_state' => 'Idle',
                    'season_progress_fp' => 250000,
                    'algorithm_version' => (string)SIGIL_DROP_ALGORITHM_VERSION,
                ],
            ]),
        ];

        $normalized = normalizeRecentSigilDropRow($row);

        $this->assertSame(4, (int)$normalized['tier']);
        $this->assertSame('RNG', (string)$normalized['source']);
        $this->assertSame(1550, (int)$normalized['tick_index']);
        $this->assertSame('Idle', (string)$normalized['activity_state']);
        $this->assertEqualsWithDelta(0.25, (float)$normalized['season_progress'], 0.000001);
        $this->assertSame((string)SIGIL_DROP_ALGORITHM_VERSION, (string)$normalized['metadata']['algorithm_version']);
    }

    public function testNormalizeRecentSigilDropRowFallsBackWhenPayloadMissing(): void
    {
        $row = [
            'tier' => 2,
            'source' => 'RNG',
            'drop_tick' => 1750,
            'created_at' => '2026-04-01 00:00:00',
            'season_start_time' => 1000,
            'season_end_time' => 2000,
            'payload_json' => null,
        ];

        $normalized = normalizeRecentSigilDropRow($row);

        $this->assertSame('Unknown', (string)$normalized['activity_state']);
        $this->assertEqualsWithDelta(0.75, (float)$normalized['season_progress'], 0.000001);
        $this->assertSame((string)SIGIL_DROP_ALGORITHM_VERSION, (string)$normalized['metadata']['algorithm_version']);
    }
}
