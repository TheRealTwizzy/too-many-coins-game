<?php
/**
 * PlayabilityGateValidator — machine-readable release gate validation.
 *
 * Milestone 5B: Defines and evaluates release-relevant playability gates.
 * Each gate tests a critical lifecycle/economy condition. The output is a
 * structured array suitable for CI artifact, JSON export, or programmatic
 * blocking of release if critical gates fail.
 *
 * Gate results are classified:
 *   - PASS:  condition met
 *   - FAIL:  condition not met — release-blocking if severity is 'critical'
 *   - SKIP:  condition could not be evaluated (missing data or deferred)
 *   - WARN:  non-blocking warning (informational)
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/economy.php';
require_once __DIR__ . '/../../includes/game_time.php';
require_once __DIR__ . '/SimulationSeason.php';

class PlayabilityGateValidator
{
    public const SCHEMA_VERSION = 'tmc-playability-gates.v1';

    /**
     * Evaluate all playability gates against configuration and optional run data.
     *
     * @param string $seed  Seed for deterministic season config generation
     * @param array|null $runArtifact  Optional fresh-run artifact for lifecycle gates
     * @return array  Machine-readable gate results
     */
    public static function evaluate(string $seed = 'gate-check', ?array $runArtifact = null): array
    {
        $gates = [];

        // --- Economy gates ---
        $gates[] = self::gateStarPriceTableValid($seed);
        $gates[] = self::gateBlackoutZeroAccrual($seed);
        $gates[] = self::gateBlackoutPriceSnapshot($seed);
        $gates[] = self::gateLockInPayoutConsistent();
        $gates[] = self::gateNaturalExpiryPayoutPositive();
        $gates[] = self::gateEffectiveScoreSemantics();
        $gates[] = self::gateSeasonTimingCoherence($seed);

        // --- Lifecycle gates (require run artifact) ---
        if ($runArtifact !== null) {
            $gates[] = self::gateLifecycleCompleted($runArtifact);
            $gates[] = self::gatePlayersJoined($runArtifact);
            $gates[] = self::gateSeasonFinalized($runArtifact);
            $gates[] = self::gateDeterminismFingerprint($runArtifact);
            $gates[] = self::gateNoPhantomSeasons($runArtifact);
        } else {
            $gates[] = self::skip('lifecycle_completed', 'critical', 'No run artifact provided');
            $gates[] = self::skip('players_joined', 'critical', 'No run artifact provided');
            $gates[] = self::skip('season_finalized', 'major', 'No run artifact provided');
            $gates[] = self::skip('determinism_fingerprint', 'major', 'No run artifact provided');
            $gates[] = self::skip('no_phantom_seasons', 'minor', 'No run artifact provided');
        }

        $passCount = count(array_filter($gates, fn($g) => $g['result'] === 'PASS'));
        $failCount = count(array_filter($gates, fn($g) => $g['result'] === 'FAIL'));
        $skipCount = count(array_filter($gates, fn($g) => $g['result'] === 'SKIP'));
        $warnCount = count(array_filter($gates, fn($g) => $g['result'] === 'WARN'));
        $criticalFail = count(array_filter($gates, fn($g) =>
            $g['result'] === 'FAIL' && $g['severity'] === 'critical'
        ));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'release_blocked' => $criticalFail > 0,
            'summary' => [
                'total' => count($gates),
                'pass' => $passCount,
                'fail' => $failCount,
                'skip' => $skipCount,
                'warn' => $warnCount,
                'critical_failures' => $criticalFail,
            ],
            'gates' => $gates,
        ];
    }

    // --- Economy gates ---

    private static function gateStarPriceTableValid(string $seed): array
    {
        $season = SimulationSeason::build(1, $seed);
        $table = json_decode($season['starprice_table'], true);

        if (empty($table)) {
            return self::fail('star_price_table_valid', 'critical', 'Star price table is empty');
        }

        $prevPrice = -1;
        foreach ($table as $entry) {
            if (($entry['price'] ?? 0) < $prevPrice) {
                return self::fail('star_price_table_valid', 'critical',
                    'Star price table is not monotonically non-decreasing');
            }
            $prevPrice = $entry['price'];
        }

        if ($table[0]['price'] <= 0) {
            return self::fail('star_price_table_valid', 'critical', 'Base star price is zero or negative');
        }

        return self::pass('star_price_table_valid', 'critical',
            'Star price table has ' . count($table) . ' entries, monotonically non-decreasing');
    }

    private static function gateBlackoutZeroAccrual(string $seed): array
    {
        $season = SimulationSeason::build(1, $seed);
        $season['status'] = 'Blackout';
        $season['computed_status'] = 'Blackout';
        $season['blackout_star_price_snapshot'] = 300;

        $player = [
            'player_id' => 1,
            'participation_enabled' => 1,
            'activity_state' => 'Active',
            'economic_presence_state' => 'Active',
            'current_game_time' => (int)$season['blackout_time'],
            'online_current' => 1,
        ];
        $participation = [
            'coins' => 50000,
            'coins_fractional_fp' => 0,
            'seasonal_stars' => 100,
            'active_ticks_total' => 5000,
        ];

        $rate = Economy::calculateRateBreakdown($season, $player, $participation, 0, false);
        if ((int)$rate['gross_rate_fp'] !== 0 || (int)$rate['net_rate_fp'] !== 0) {
            return self::fail('blackout_zero_accrual', 'critical',
                'Blackout accrual is non-zero: gross=' . $rate['gross_rate_fp'] . ' net=' . $rate['net_rate_fp']);
        }

        return self::pass('blackout_zero_accrual', 'critical', 'Blackout produces zero UBI accrual');
    }

    private static function gateBlackoutPriceSnapshot(string $seed): array
    {
        $season = [
            'current_star_price' => 800,
            'blackout_star_price_snapshot' => 555,
        ];

        $price = Economy::publishedStarPrice($season, 'Blackout');
        if ($price !== 555) {
            return self::fail('blackout_price_snapshot', 'critical',
                "Blackout price used $price, expected 555 (snapshot)");
        }

        return self::pass('blackout_price_snapshot', 'critical', 'Blackout price correctly uses snapshot');
    }

    private static function gateLockInPayoutConsistent(): array
    {
        $lockIn = Economy::computeEarlyLockInPayout(100, [1, 2, 0, 1, 0], [50, 250, 1000, 3000, 9000]);

        if ((int)$lockIn['sigil_refund_stars'] !== 3550) {
            return self::fail('lock_in_payout_consistent', 'critical',
                'Sigil refund mismatch: ' . $lockIn['sigil_refund_stars'] . ' expected 3550');
        }
        if ((int)$lockIn['total_seasonal_stars'] !== 3650) {
            return self::fail('lock_in_payout_consistent', 'critical',
                'Total payout mismatch: ' . $lockIn['total_seasonal_stars'] . ' expected 3650');
        }

        return self::pass('lock_in_payout_consistent', 'critical', 'Lock-in payout: 3650 (100 base + 3550 refund)');
    }

    private static function gateNaturalExpiryPayoutPositive(): array
    {
        $payout = Economy::computeNaturalExpiryPayout(200, 7200, 1, 0, true);
        if ((int)$payout['total_source_stars'] <= 0) {
            return self::fail('natural_expiry_payout_positive', 'critical',
                'Natural expiry payout is zero or negative');
        }
        if ((int)$payout['participation_bonus'] <= 0) {
            return self::fail('natural_expiry_payout_positive', 'major',
                'Participation bonus is zero for 7200 active ticks');
        }

        return self::pass('natural_expiry_payout_positive', 'critical',
            'Natural expiry: ' . $payout['total_source_stars'] . ' total source stars');
    }

    private static function gateEffectiveScoreSemantics(): array
    {
        $locked = Economy::effectiveSeasonalStars([
            'lock_in_effect_tick' => 10,
            'lock_in_snapshot_seasonal_stars' => 555,
            'seasonal_stars' => 999,
        ]);
        $ended = Economy::effectiveSeasonalStars([
            'end_membership' => 1,
            'final_seasonal_stars' => 444,
            'seasonal_stars' => 999,
        ]);
        $live = Economy::effectiveSeasonalStars([
            'seasonal_stars' => 777,
        ]);

        if ($locked !== 555 || $ended !== 444 || $live !== 777) {
            return self::fail('effective_score_semantics', 'critical',
                "Score semantics mismatch: locked=$locked ended=$ended live=$live");
        }

        return self::pass('effective_score_semantics', 'critical',
            'Effective score: locked=555, ended=444, live=777');
    }

    private static function gateSeasonTimingCoherence(string $seed): array
    {
        $season = SimulationSeason::build(1, $seed);
        $start = (int)$season['start_time'];
        $end = (int)$season['end_time'];
        $blackout = (int)$season['blackout_time'];

        if ($start >= $blackout || $blackout >= $end) {
            return self::fail('season_timing_coherence', 'critical',
                "Timing incoherent: start=$start blackout=$blackout end=$end");
        }

        $startStatus = GameTime::getSeasonStatus($season, $start);
        $blackoutStatus = GameTime::getSeasonStatus($season, $blackout);
        $endStatus = GameTime::getSeasonStatus($season, $end);

        if ($startStatus !== 'Active' || $blackoutStatus !== 'Blackout' || $endStatus !== 'Expired') {
            return self::fail('season_timing_coherence', 'critical',
                "Status mismatch: start=$startStatus blackout=$blackoutStatus end=$endStatus");
        }

        return self::pass('season_timing_coherence', 'critical', 'Season timing coherent');
    }

    // --- Lifecycle gates ---

    private static function gateLifecycleCompleted(array $artifact): array
    {
        $status = $artifact['termination']['status'] ?? 'unknown';
        if ($status !== 'completed') {
            return self::fail('lifecycle_completed', 'critical',
                "Lifecycle terminated with status: $status");
        }
        return self::pass('lifecycle_completed', 'critical', 'Lifecycle completed successfully');
    }

    private static function gatePlayersJoined(array $artifact): array
    {
        $joined = (int)($artifact['execution_metrics']['players_joined'] ?? 0);
        $failed = (int)($artifact['execution_metrics']['players_join_failed'] ?? 0);

        if ($joined === 0) {
            return self::fail('players_joined', 'critical', 'No players joined');
        }
        if ($failed > 0) {
            return self::warn('players_joined', 'major',
                "$joined joined, $failed failed");
        }

        return self::pass('players_joined', 'critical', "$joined players joined, 0 failed");
    }

    private static function gateSeasonFinalized(array $artifact): array
    {
        $reason = $artifact['termination']['reason'] ?? 'unknown';
        if ($reason === 'season_finalized') {
            return self::pass('season_finalized', 'major', 'Season finalized via expiration');
        }
        if ($reason === 'max_tick_reached') {
            return self::warn('season_finalized', 'major',
                'Season reached max tick without explicit finalization');
        }

        return self::fail('season_finalized', 'major', "Season termination reason: $reason");
    }

    private static function gateDeterminismFingerprint(array $artifact): array
    {
        $fp = $artifact['determinism_fingerprint'] ?? null;
        if ($fp === null || strlen($fp) !== 64) {
            return self::fail('determinism_fingerprint', 'major',
                'Missing or invalid determinism fingerprint');
        }

        return self::pass('determinism_fingerprint', 'major',
            'Fingerprint: ' . substr($fp, 0, 16) . '...');
    }

    private static function gateNoPhantomSeasons(array $artifact): array
    {
        $extra = $artifact['metadata']['season']['extra_season_ids'] ?? [];
        if (!empty($extra)) {
            return self::warn('no_phantom_seasons', 'minor',
                count($extra) . ' phantom season(s) detected: ' . implode(', ', $extra));
        }

        return self::pass('no_phantom_seasons', 'minor', 'No phantom seasons');
    }

    // --- Result constructors ---

    private static function pass(string $gate, string $severity, string $detail): array
    {
        return ['gate' => $gate, 'result' => 'PASS', 'severity' => $severity, 'detail' => $detail];
    }

    private static function fail(string $gate, string $severity, string $detail): array
    {
        return ['gate' => $gate, 'result' => 'FAIL', 'severity' => $severity, 'detail' => $detail];
    }

    private static function skip(string $gate, string $severity, string $detail): array
    {
        return ['gate' => $gate, 'result' => 'SKIP', 'severity' => $severity, 'detail' => $detail];
    }

    private static function warn(string $gate, string $severity, string $detail): array
    {
        return ['gate' => $gate, 'result' => 'WARN', 'severity' => $severity, 'detail' => $detail];
    }

    /**
     * Write gate results to a JSON file.
     *
     * @return string  Path to the written file
     */
    public static function writeResults(array $results, string $outputDir, string $baseName = 'playability_gates'): string
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $path = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $baseName . '.json';
        file_put_contents(
            $path,
            json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        return $path;
    }
}
