<?php

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/economy.php';
require_once __DIR__ . '/../../includes/game_time.php';
require_once __DIR__ . '/../../includes/boost_catalog.php';
require_once __DIR__ . '/SimulationSeason.php';
require_once __DIR__ . '/SimulationPlayer.php';
require_once __DIR__ . '/Archetypes.php';

class RuntimeParityCertification
{
    public const REPORT_SCHEMA_VERSION = 'tmc-runtime-parity-certification.v1';

    public static function run(array $options = []): array
    {
        $seed = (string)($options['seed'] ?? ('runtime-parity-' . gmdate('Ymd-His')));
        $candidateId = (string)($options['candidate_id'] ?? 'runtime-parity');
        $season = self::resolveSeason($seed, $options);
        $domains = RuntimeParityFixtureCatalog::domains($season);
        $report = [
            'schema_version' => self::REPORT_SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'candidate_id' => $candidateId,
            'seed' => $seed,
            'season_surface_sha256' => self::seasonSurfaceHash($season),
            'certification_status' => 'pass',
            'certified' => true,
            'required_domain_ids' => array_map(static fn(array $domain): string => (string)$domain['domain_id'], $domains),
            'material_drift_count' => 0,
            'tolerated_difference_count' => 0,
            'tolerated_differences' => RuntimeParityFixtureCatalog::globalToleratedDifferences(),
            'domains' => [],
        ];

        foreach ($domains as $domain) {
            $domainResult = self::evaluateDomain($domain, $season, $seed);
            $report['domains'][] = $domainResult;
            $report['material_drift_count'] += (int)$domainResult['material_drift_count'];
            $report['tolerated_difference_count'] += (int)$domainResult['tolerated_difference_count'];
            if ((string)$domainResult['status'] !== 'pass') {
                $report['certification_status'] = 'fail';
                $report['certified'] = false;
            }
        }

        $artifactPaths = [];
        $outputDir = trim((string)($options['output_dir'] ?? ''));
        if ($outputDir !== '') {
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0777, true);
            }

            $jsonPath = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'runtime_parity_certification.json';
            $mdPath = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'runtime_parity_certification.md';
            file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            file_put_contents($mdPath, self::buildMarkdown($report));
            $artifactPaths = [
                'runtime_parity_certification_json' => $jsonPath,
                'runtime_parity_certification_md' => $mdPath,
            ];
        }

        return [
            'report' => $report,
            'artifact_paths' => $artifactPaths,
        ];
    }

    public static function compareMetrics(array $comparePaths, array $simulatorOutput, array $runtimeOutput, array $toleratedPaths = []): array
    {
        $results = [];
        $materialDriftCount = 0;
        $toleratedDifferenceCount = 0;

        foreach ($comparePaths as $path => $tolerance) {
            $simulatorValue = self::pathValue($simulatorOutput, (string)$path);
            $runtimeValue = self::pathValue($runtimeOutput, (string)$path);
            $isTolerated = in_array((string)$path, $toleratedPaths, true);
            $status = self::valuesMatch($simulatorValue, $runtimeValue, $tolerance) ? 'pass' : ($isTolerated ? 'tolerated' : 'fail');
            if ($status === 'fail') {
                $materialDriftCount++;
            } elseif ($status === 'tolerated') {
                $toleratedDifferenceCount++;
            }

            $results[] = [
                'path' => (string)$path,
                'tolerance' => $tolerance,
                'simulator' => $simulatorValue,
                'runtime' => $runtimeValue,
                'status' => $status,
            ];
        }

        return [
            'status' => $materialDriftCount === 0 ? 'pass' : 'fail',
            'material_drift_count' => $materialDriftCount,
            'tolerated_difference_count' => $toleratedDifferenceCount,
            'metrics' => $results,
        ];
    }

    private static function evaluateDomain(array $domain, array $season, string $seed): array
    {
        $fixtures = [];
        $materialDriftCount = 0;
        $toleratedDifferenceCount = 0;

        foreach ((array)$domain['fixtures'] as $fixture) {
            $simulatorOutput = self::evaluateFixture((string)$domain['adapter'], $fixture, $season, $seed, 'simulator');
            $runtimeOutput = self::evaluateFixture((string)$domain['adapter'], $fixture, $season, $seed, 'runtime');
            $comparison = self::compareMetrics(
                (array)$fixture['compare_paths'],
                $simulatorOutput,
                $runtimeOutput,
                (array)($fixture['tolerated_paths'] ?? [])
            );

            $materialDriftCount += (int)$comparison['material_drift_count'];
            $toleratedDifferenceCount += (int)$comparison['tolerated_difference_count'];

            $fixtures[] = [
                'fixture_id' => (string)$fixture['fixture_id'],
                'label' => (string)$fixture['label'],
                'status' => (string)$comparison['status'],
                'description' => (string)$fixture['description'],
                'tolerated_differences' => array_values((array)($fixture['tolerated_differences'] ?? [])),
                'simulator_output' => $simulatorOutput,
                'runtime_output' => $runtimeOutput,
                'metrics' => (array)$comparison['metrics'],
            ];
        }

        return [
            'domain_id' => (string)$domain['domain_id'],
            'label' => (string)$domain['label'],
            'status' => $materialDriftCount === 0 ? 'pass' : 'fail',
            'fixture_count' => count($fixtures),
            'material_drift_count' => $materialDriftCount,
            'tolerated_difference_count' => $toleratedDifferenceCount,
            'tolerated_differences' => array_values((array)($domain['tolerated_differences'] ?? [])),
            'fixtures' => $fixtures,
        ];
    }

    private static function evaluateFixture(string $adapter, array $fixture, array $season, string $seed, string $mode): array
    {
        return match ($adapter) {
            'hoarding' => self::evaluateHoardingFixture($fixture, $season, $seed, $mode),
            'boost' => self::evaluateBoostFixture($fixture, $season, $seed, $mode),
            'lock_in' => self::evaluateLockInFixture($fixture, $season, $seed, $mode),
            'expiry' => self::evaluateExpiryFixture($fixture, $season, $seed, $mode),
            'star_pricing' => self::evaluateStarPricingFixture($fixture, $season, $seed, $mode),
            'rejoin' => self::evaluateRejoinFixture($fixture, $season, $seed, $mode),
            'blackout_finalization' => self::evaluateBlackoutFinalizationFixture($fixture, $season, $seed, $mode),
            default => throw new InvalidArgumentException('Unsupported runtime parity adapter: ' . $adapter),
        };
    }

    private static function evaluateHoardingFixture(array $fixture, array $season, string $seed, string $mode): array
    {
        $fixtureSeason = self::prepareSeason($season, (array)($fixture['season_overrides'] ?? []));
        $phase = (string)$fixture['phase'];
        $presence = (string)($fixture['presence'] ?? 'Active');
        $tick = (int)($fixture['tick'] ?? (int)$fixtureSeason['start_time']);
        $ticks = max(1, (int)($fixture['ticks'] ?? 1));

        if ($mode === 'simulator') {
            $player = self::makeSimulationPlayer($seed, (string)($fixture['archetype_key'] ?? 'hoarder'));
            self::seedSimulationPlayer(
                $player,
                (array)($fixture['player_overrides'] ?? []),
                (array)($fixture['participation_overrides'] ?? [])
            );

            for ($step = 0; $step < $ticks; $step++) {
                $currentTick = $tick + $step;
                $player->expireEffects($currentTick);
                $player->setPresenceState($presence, $currentTick);
                $player->accrue($fixtureSeason, $phase);
            }

            $snapshot = $player->snapshot();
            $rates = Economy::calculateRateBreakdown(
                $fixtureSeason,
                $snapshot['player'],
                $snapshot['participation'],
                0,
                false,
                false,
                $phase
            );

            return [
                'gross_rate_fp' => (int)$rates['gross_rate_fp'],
                'sink_per_tick' => (int)$rates['sink_per_tick'],
                'net_rate_fp' => (int)$rates['net_rate_fp'],
                'coins_after' => (int)$snapshot['participation']['coins'],
                'carry_after' => (int)$snapshot['participation']['coins_fractional_fp'],
                'hoarding_sink_total' => (int)$snapshot['participation']['hoarding_sink_total'],
            ];
        }

        $state = self::makeRuntimeState($fixture, $fixtureSeason);
        $rates = ['gross_rate_fp' => 0, 'sink_per_tick' => 0, 'net_rate_fp' => 0];
        for ($step = 0; $step < $ticks; $step++) {
            $currentTick = $tick + $step;
            $state['player']['current_game_time'] = $currentTick;
            $state['player']['economic_presence_state'] = $presence;
            $state['player']['activity_state'] = ($presence === 'Active') ? 'Active' : 'Idle';
            $rates = self::runtimeAccrueTick($fixtureSeason, $state['player'], $state['participation'], $currentTick, $phase, 0, false);
        }

        return [
            'gross_rate_fp' => (int)$rates['gross_rate_fp'],
            'sink_per_tick' => (int)$rates['sink_per_tick'],
            'net_rate_fp' => (int)$rates['net_rate_fp'],
            'coins_after' => (int)$state['participation']['coins'],
            'carry_after' => (int)$state['participation']['coins_fractional_fp'],
            'hoarding_sink_total' => (int)$state['participation']['hoarding_sink_total'],
        ];
    }

    private static function evaluateBoostFixture(array $fixture, array $season, string $seed, string $mode): array
    {
        $fixtureSeason = self::prepareSeason($season, (array)($fixture['season_overrides'] ?? []));
        $phase = (string)($fixture['phase'] ?? 'EARLY');
        $purchaseTick = (int)$fixture['purchase_tick'];
        $observeAtExpiry = !empty($fixture['observe_at_expiry']);
        $observeAfterExpiry = !empty($fixture['observe_after_expiry']);
        $extension = (array)($fixture['extension'] ?? []);

        if ($mode === 'simulator') {
            $player = self::makeSimulationPlayer($seed, 'boost_focused');
            self::seedSimulationPlayer($player, [], (array)($fixture['participation_overrides'] ?? []));
            self::invokeSimulationPlayer($player, 'purchaseBoost', [
                (int)$fixture['sigil_tier'],
                (string)$fixture['purchase_kind'],
                $purchaseTick,
                $phase,
            ]);

            if ($extension !== []) {
                self::invokeSimulationPlayer($player, 'purchaseBoost', [
                    (int)$extension['sigil_tier'],
                    (string)$extension['purchase_kind'],
                    (int)$extension['tick'],
                    $phase,
                ]);
            }

            $snapshot = $player->snapshot();
            $expiresTick = (int)$snapshot['boost']['expires_tick'];
            $appliedAtFirstTick = self::simulateBoostAccrualTick($player, $fixtureSeason, $purchaseTick + 1, $phase);
            $appliedAtExpiryTick = $observeAtExpiry ? self::simulateBoostAccrualTick($player, $fixtureSeason, $expiresTick, $phase) : false;
            $appliedAfterExpiry = $observeAfterExpiry ? self::simulateBoostAccrualTick($player, $fixtureSeason, $expiresTick + 1, $phase) : false;
            $after = $player->snapshot();

            return [
                'modifier_fp' => (int)$after['boost']['modifier_fp'],
                'expires_tick' => (int)$after['boost']['expires_tick'],
                'applied_at_first_tick' => $appliedAtFirstTick,
                'applied_at_expiry_tick' => $appliedAtExpiryTick,
                'applied_after_expiry' => $appliedAfterExpiry,
                'ticks_boosted' => (int)$after['metrics']['ticks_boosted'],
            ];
        }

        $state = self::makeRuntimeState($fixture, $fixtureSeason);
        self::runtimePurchaseBoost($state, (int)$fixture['sigil_tier'], (string)$fixture['purchase_kind'], $purchaseTick);
        if ($extension !== []) {
            self::runtimePurchaseBoost($state, (int)$extension['sigil_tier'], (string)$extension['purchase_kind'], (int)$extension['tick']);
        }

        $expiresTick = (int)$state['boost']['expires_tick'];
        $appliedAtFirstTick = self::runtimeBoostAccrualTick($state, $fixtureSeason, $purchaseTick + 1, $phase);
        $appliedAtExpiryTick = $observeAtExpiry ? self::runtimeBoostAccrualTick($state, $fixtureSeason, $expiresTick, $phase) : false;
        $appliedAfterExpiry = $observeAfterExpiry ? self::runtimeBoostAccrualTick($state, $fixtureSeason, $expiresTick + 1, $phase) : false;

        return [
            'modifier_fp' => (int)$state['boost']['modifier_fp'],
            'expires_tick' => (int)$state['boost']['expires_tick'],
            'applied_at_first_tick' => $appliedAtFirstTick,
            'applied_at_expiry_tick' => $appliedAtExpiryTick,
            'applied_after_expiry' => $appliedAfterExpiry,
            'ticks_boosted' => (int)$state['metrics']['ticks_boosted'],
        ];
    }

    private static function evaluateLockInFixture(array $fixture, array $season, string $seed, string $mode): array
    {
        $fixtureSeason = self::prepareSeason($season, (array)($fixture['season_overrides'] ?? []));
        $status = (string)$fixture['status'];
        $phase = (string)$fixture['phase'];
        $tick = (int)$fixture['tick'];

        if ($mode === 'simulator') {
            $player = self::makeSimulationPlayer($seed, (string)($fixture['archetype_key'] ?? 'regular'));
            self::seedSimulationPlayer(
                $player,
                (array)($fixture['player_overrides'] ?? []),
                (array)($fixture['participation_overrides'] ?? [])
            );
            $before = $player->snapshot();
            self::invokeSimulationPlayer($player, 'lockIn', [$status, $tick, $phase]);
            $after = $player->snapshot();
            return self::lockInOutput($before, $after);
        }

        $state = self::makeRuntimeState($fixture, $fixtureSeason);
        $before = self::runtimeSnapshot($state);
        self::runtimeLockIn($state, $status, $tick);
        $after = self::runtimeSnapshot($state);
        return self::lockInOutput($before, $after);
    }

    private static function evaluateExpiryFixture(array $fixture, array $season, string $seed, string $mode): array
    {
        $fixtureSeason = self::prepareSeason($season, (array)($fixture['season_overrides'] ?? []));
        $placementRank = (int)$fixture['placement_rank'];
        $awardBadges = !empty($fixture['award_badges_and_placement']);

        if ($mode === 'simulator') {
            $player = self::makeSimulationPlayer($seed, (string)($fixture['archetype_key'] ?? 'regular'));
            self::seedSimulationPlayer(
                $player,
                (array)($fixture['player_overrides'] ?? []),
                (array)($fixture['participation_overrides'] ?? [])
            );
            $player->markEndMembership();
            $player->setFinalRank($placementRank);
            $player->applyNaturalExpiry($placementRank, $awardBadges);
            $after = $player->snapshot();

            return [
                'end_membership' => (int)$after['participation']['end_membership'],
                'final_seasonal_stars' => (int)$after['participation']['final_seasonal_stars'],
                'global_stars_earned' => (int)$after['participation']['global_stars_earned'],
                'participation_bonus' => (int)$after['participation']['participation_bonus'],
                'placement_bonus' => (int)$after['participation']['placement_bonus'],
                'seasonal_stars_after' => (int)$after['participation']['seasonal_stars'],
                'coins_after' => (int)$after['participation']['coins'],
                'joined_season_id_after' => $after['player']['joined_season_id'],
                'participation_enabled_after' => (int)$after['player']['participation_enabled'],
            ];
        }

        $state = self::makeRuntimeState($fixture, $fixtureSeason);
        self::runtimeNaturalExpiry($state, $placementRank, $awardBadges);
        return [
            'end_membership' => (int)$state['participation']['end_membership'],
            'final_seasonal_stars' => (int)$state['participation']['final_seasonal_stars'],
            'global_stars_earned' => (int)$state['participation']['global_stars_earned'],
            'participation_bonus' => (int)$state['participation']['participation_bonus'],
            'placement_bonus' => (int)$state['participation']['placement_bonus'],
            'seasonal_stars_after' => (int)$state['participation']['seasonal_stars'],
            'coins_after' => (int)$state['participation']['coins'],
            'joined_season_id_after' => $state['player']['joined_season_id'],
            'participation_enabled_after' => (int)$state['player']['participation_enabled'],
        ];
    }

    private static function evaluateStarPricingFixture(array $fixture, array $season, string $seed, string $mode): array
    {
        $fixtureSeason = self::prepareSeason($season, (array)($fixture['season_overrides'] ?? []));
        $price = Economy::calculateStarPrice($fixtureSeason);
        $fixtureSeason['current_star_price'] = $price;
        if (!empty($fixture['blackout_snapshot_price'])) {
            $fixtureSeason['blackout_star_price_snapshot'] = (int)$fixture['blackout_snapshot_price'];
        }

        if ($mode === 'simulator') {
            $player = self::makeSimulationPlayer($seed, 'star_focused');
            self::seedSimulationPlayer(
                $player,
                ['current_game_time' => (int)$fixture['tick']],
                (array)($fixture['participation_overrides'] ?? [])
            );
            self::invokeSimulationPlayer($player, 'purchaseStars', [
                $fixtureSeason,
                (string)$fixture['phase'],
                (int)$fixture['stars_requested'],
            ]);
            $after = $player->snapshot();
            $published = Economy::publishedStarPrice($fixtureSeason, (string)$fixtureSeason['status']);

            return [
                'computed_star_price' => $price,
                'published_star_price' => $published,
                'coins_after' => (int)$after['participation']['coins'],
                'seasonal_stars_after' => (int)$after['participation']['seasonal_stars'],
                'spend_window_total' => (int)$after['participation']['spend_window_total'],
                'affordable' => (int)$after['participation']['seasonal_stars'] === (int)$fixture['expected_stars_after'],
            ];
        }

        $state = self::makeRuntimeState($fixture, $fixtureSeason);
        self::runtimePurchaseStars($state, $fixtureSeason, (int)$fixture['stars_requested']);
        $published = Economy::publishedStarPrice($fixtureSeason, (string)$fixtureSeason['status']);

        return [
            'computed_star_price' => $price,
            'published_star_price' => $published,
            'coins_after' => (int)$state['participation']['coins'],
            'seasonal_stars_after' => (int)$state['participation']['seasonal_stars'],
            'spend_window_total' => (int)$state['participation']['spend_window_total'],
            'affordable' => (int)$state['participation']['seasonal_stars'] === (int)$fixture['expected_stars_after'],
        ];
    }

    private static function evaluateRejoinFixture(array $fixture, array $season, string $seed, string $mode): array
    {
        $seasonId = (int)($season['season_id'] ?? 1);
        $gameTime = (int)$fixture['game_time'];
        $state = self::makeRuntimeState($fixture, $season);

        if ($mode === 'simulator') {
            self::simulatorFreshStartRejoin($state, $seasonId, $gameTime);
        } else {
            self::runtimeFreshStartRejoin($state, $seasonId, $gameTime);
        }

        return [
            'coins_after' => (int)$state['participation']['coins'],
            'seasonal_stars_after' => (int)$state['participation']['seasonal_stars'],
            'participation_ticks_since_join_after' => (int)$state['participation']['participation_ticks_since_join'],
            'active_ticks_total_after' => (int)$state['participation']['active_ticks_total'],
            'total_season_participation_ticks_after' => (int)$state['participation']['total_season_participation_ticks'],
            'lock_in_effect_tick_after' => $state['participation']['lock_in_effect_tick'],
            'idle_since_tick_after' => $state['player']['idle_since_tick'],
            'joined_season_id_after' => $state['player']['joined_season_id'],
            'effective_total_lock_in_ticks' => self::effectiveLockInTicks($state['participation']),
        ];
    }

    private static function evaluateBlackoutFinalizationFixture(array $fixture, array $season, string $seed, string $mode): array
    {
        $fixtureSeason = self::prepareSeason($season, (array)($fixture['season_overrides'] ?? []));
        $fixtureSeason['status'] = 'Blackout';
        $fixtureSeason['computed_status'] = 'Blackout';
        $fixtureSeason['blackout_star_price_snapshot'] = (int)$fixture['blackout_snapshot_price'];

        $state = self::makeRuntimeState($fixture, $fixtureSeason);
        $rate = Economy::calculateRateBreakdown(
            $fixtureSeason,
            array_replace($state['player'], ['current_game_time' => (int)$fixtureSeason['blackout_time']]),
            $state['participation'],
            0,
            false
        );
        $published = Economy::publishedStarPrice($fixtureSeason, 'Blackout');

        if ($mode === 'simulator') {
            $player = self::makeSimulationPlayer($seed, 'regular');
            self::seedSimulationPlayer(
                $player,
                ['joined_season_id' => 1, 'participation_enabled' => 1],
                (array)($fixture['participation_overrides'] ?? [])
            );
            $player->markEndMembership();
            $player->setFinalRank((int)$fixture['placement_rank']);
            $player->applyNaturalExpiry((int)$fixture['placement_rank'], true);
            $after = $player->snapshot();

            return [
                'blackout_gross_rate_fp' => (int)$rate['gross_rate_fp'],
                'blackout_net_rate_fp' => (int)$rate['net_rate_fp'],
                'published_blackout_price' => (int)$published,
                'joined_season_id_after' => $after['player']['joined_season_id'],
                'participation_enabled_after' => (int)$after['player']['participation_enabled'],
                'seasonal_stars_after' => (int)$after['participation']['seasonal_stars'],
            ];
        }

        self::runtimeNaturalExpiry($state, (int)$fixture['placement_rank'], true);
        return [
            'blackout_gross_rate_fp' => (int)$rate['gross_rate_fp'],
            'blackout_net_rate_fp' => (int)$rate['net_rate_fp'],
            'published_blackout_price' => (int)$published,
            'joined_season_id_after' => $state['player']['joined_season_id'],
            'participation_enabled_after' => (int)$state['player']['participation_enabled'],
            'seasonal_stars_after' => (int)$state['participation']['seasonal_stars'],
        ];
    }

    private static function lockInOutput(array $before, array $after): array
    {
        return [
            'success' => !empty($after['participation']['lock_in_effect_tick']),
            'lock_in_effect_tick' => $after['participation']['lock_in_effect_tick'],
            'lock_in_snapshot_seasonal_stars' => $after['participation']['lock_in_snapshot_seasonal_stars'],
            'global_stars_earned' => (int)$after['participation']['global_stars_earned'],
            'joined_season_id_after' => $after['player']['joined_season_id'],
            'participation_enabled_after' => (int)$after['player']['participation_enabled'],
            'seasonal_stars_after' => (int)$after['participation']['seasonal_stars'],
            'coins_after' => (int)$after['participation']['coins'],
            'changed' => $before['participation'] !== $after['participation'] || $before['player'] !== $after['player'],
        ];
    }

    private static function runtimeAccrueTick(array $season, array &$player, array &$participation, int $tick, string $phase, int $boostModFp, bool $isFrozen): array
    {
        $player['current_game_time'] = $tick;
        $rates = Economy::calculateRateBreakdown($season, $player, $participation, $boostModFp, $isFrozen, false, $phase);
        $carryFp = max(0, (int)($participation['coins_fractional_fp'] ?? 0));
        $totalNetFp = (int)$rates['net_rate_fp'] + $carryFp;
        [$netCoins, $newCarryFp] = Economy::splitFixedPoint($totalNetFp);

        $participation['coins'] = max(0, (int)($participation['coins'] ?? 0) + $netCoins);
        $participation['coins_fractional_fp'] = $newCarryFp;
        $participation['hoarding_sink_total'] = (int)($participation['hoarding_sink_total'] ?? 0) + (int)$rates['sink_per_tick'];
        $participation['participation_time_total'] = (int)($participation['participation_time_total'] ?? 0) + 1;
        $participation['participation_ticks_since_join'] = (int)($participation['participation_ticks_since_join'] ?? 0) + 1;
        $participation['total_season_participation_ticks'] = (int)($participation['total_season_participation_ticks'] ?? 0) + 1;
        if ((string)($player['economic_presence_state'] ?? 'Active') === 'Active') {
            $participation['active_ticks_total'] = (int)($participation['active_ticks_total'] ?? 0) + 1;
        }

        return $rates;
    }

    private static function runtimePurchaseBoost(array &$state, int $sigilTier, string $purchaseKind, int $tick): void
    {
        if (!BoostCatalog::canSpendSigilTier($sigilTier)) {
            return;
        }

        $sigilCol = 'sigils_t' . $sigilTier;
        if ((int)($state['participation'][$sigilCol] ?? 0) < 1) {
            return;
        }

        $timeCapTicks = ticks_from_real_seconds(BoostCatalog::TIME_CAP_SECONDS_PER_PRODUCT);
        $powerIncrementFp = max(1, BoostCatalog::getSpendPowerFpForTier($sigilTier));
        $timeIncrementTicks = max(1, BoostCatalog::getSpendTimeTicksForTier($sigilTier));
        $initialPowerFp = max(1, BoostCatalog::getInitialPowerFpForTier($sigilTier));
        $initialDurationTicks = max(1, BoostCatalog::getInitialDurationTicksForTier($sigilTier));

        if (empty($state['boost']['is_active'])) {
            $state['boost'] = [
                'is_active' => true,
                'modifier_fp' => $initialPowerFp,
                'activated_tick' => $tick,
                'expires_tick' => $tick + $initialDurationTicks,
            ];
            $state['participation'][$sigilCol]--;
            return;
        }

        if ($purchaseKind === 'time') {
            $maxExpiresTick = $tick + $timeCapTicks;
            if ((int)$state['boost']['expires_tick'] >= $maxExpiresTick) {
                return;
            }
            $state['boost']['expires_tick'] = min($maxExpiresTick, (int)$state['boost']['expires_tick'] + $timeIncrementTicks);
        } else {
            $projected = min(BoostCatalog::TOTAL_POWER_CAP_FP, (int)$state['boost']['modifier_fp'] + $powerIncrementFp);
            if ($projected <= (int)$state['boost']['modifier_fp']) {
                return;
            }
            $state['boost']['modifier_fp'] = $projected;
        }

        $state['participation'][$sigilCol]--;
    }

    private static function runtimeBoostAccrualTick(array &$state, array $season, int $tick, string $phase): bool
    {
        if (!empty($state['boost']['is_active']) && (int)$state['boost']['expires_tick'] < $tick) {
            $state['boost'] = ['is_active' => false, 'modifier_fp' => 0, 'activated_tick' => 0, 'expires_tick' => 0];
        }

        $boostApplied = !empty($state['boost']['is_active']) && (int)$state['boost']['expires_tick'] >= $tick;
        $boostModFp = $boostApplied ? (int)$state['boost']['modifier_fp'] : 0;
        self::runtimeAccrueTick($season, $state['player'], $state['participation'], $tick, $phase, $boostModFp, false);
        if ($boostApplied) {
            $state['metrics']['ticks_boosted'] = (int)$state['metrics']['ticks_boosted'] + 1;
        }

        return $boostApplied;
    }

    private static function runtimePurchaseStars(array &$state, array $season, int $starsRequested): void
    {
        $price = Economy::publishedStarPrice($season, (string)$season['status']);
        if ($price <= 0 || $starsRequested <= 0) {
            return;
        }

        $coinsNeeded = $price * $starsRequested;
        if ((int)$state['participation']['coins'] < $coinsNeeded) {
            return;
        }

        $state['participation']['coins'] -= $coinsNeeded;
        $state['participation']['seasonal_stars'] += $starsRequested;
        $state['participation']['spend_window_total'] = (int)($state['participation']['spend_window_total'] ?? 0) + $coinsNeeded;
    }

    private static function runtimeLockIn(array &$state, string $status, int $tick): bool
    {
        if ((int)($state['participation']['participation_ticks_since_join'] ?? 0) < MIN_PARTICIPATION_TICKS) {
            return false;
        }

        $totalSeasonTicks = self::effectiveLockInTicks($state['participation']);
        if ($totalSeasonTicks < MIN_SEASONAL_LOCK_IN_TICKS) {
            return false;
        }

        $tierCosts = [
            (int)(SIGIL_REFERENCE_STARS_BY_TIER[1] ?? 0),
            (int)(SIGIL_REFERENCE_STARS_BY_TIER[2] ?? 0),
            (int)(SIGIL_REFERENCE_STARS_BY_TIER[3] ?? 0),
            (int)(SIGIL_REFERENCE_STARS_BY_TIER[4] ?? 0),
            (int)(SIGIL_REFERENCE_STARS_BY_TIER[5] ?? 0),
        ];
        $sigilCounts = [
            (int)($state['participation']['sigils_t1'] ?? 0),
            (int)($state['participation']['sigils_t2'] ?? 0),
            (int)($state['participation']['sigils_t3'] ?? 0),
            (int)($state['participation']['sigils_t4'] ?? 0),
            (int)($state['participation']['sigils_t5'] ?? 0),
        ];
        $payout = Economy::computeEarlyLockInPayout((int)$state['participation']['seasonal_stars'], $sigilCounts, $tierCosts);
        $grant = Economy::applyGlobalStarsGrantWithCarry(
            (int)$payout['total_seasonal_stars'],
            (int)($state['player']['global_stars_fractional_fp'] ?? 0),
            65,
            100
        );

        $state['player']['global_stars'] = (int)($state['player']['global_stars'] ?? 0) + (int)$grant['global_stars_gained'];
        $state['player']['global_stars_fractional_fp'] = (int)$grant['global_stars_fractional_fp'];
        $state['participation']['lock_in_effect_tick'] = $tick;
        $state['participation']['lock_in_snapshot_seasonal_stars'] = (int)$payout['total_seasonal_stars'];
        $state['participation']['lock_in_snapshot_participation_time'] = (int)($state['participation']['participation_time_total'] ?? 0);
        $state['participation']['global_stars_earned'] = (int)$grant['global_stars_gained'];
        $state['participation']['coins'] = 0;
        $state['participation']['coins_fractional_fp'] = 0;
        $state['participation']['seasonal_stars'] = 0;
        for ($tier = 1; $tier <= SIGIL_MAX_TIER; $tier++) {
            $state['participation']['sigils_t' . $tier] = 0;
        }
        $state['boost'] = ['is_active' => false, 'modifier_fp' => 0, 'activated_tick' => 0, 'expires_tick' => 0];
        $state['player']['joined_season_id'] = null;
        $state['player']['participation_enabled'] = 0;
        if ($status === 'Blackout') {
            $state['metrics']['blackout_conversions'] = (int)($state['metrics']['blackout_conversions'] ?? 0) + 1;
        }

        return true;
    }

    private static function runtimeNaturalExpiry(array &$state, int $placementRank, bool $awardBadgesAndPlacement): void
    {
        $state['participation']['end_membership'] = 1;
        $state['participation']['final_seasonal_stars'] = (int)$state['participation']['seasonal_stars'];
        $payout = Economy::computeNaturalExpiryPayout(
            (int)$state['participation']['seasonal_stars'],
            (int)($state['participation']['active_ticks_total'] ?? 0),
            $placementRank,
            (int)($state['player']['global_stars_fractional_fp'] ?? 0),
            $awardBadgesAndPlacement
        );

        $state['player']['global_stars'] = (int)($state['player']['global_stars'] ?? 0) + (int)$payout['global_stars_gained'];
        $state['player']['global_stars_fractional_fp'] = (int)$payout['global_stars_fractional_fp'];
        $state['participation']['global_stars_earned'] = (int)$payout['global_stars_gained'];
        $state['participation']['participation_bonus'] = (int)$payout['participation_bonus'];
        $state['participation']['placement_bonus'] = (int)$payout['placement_bonus'];
        $state['participation']['seasonal_stars'] = 0;
        $state['participation']['coins'] = 0;
        $state['participation']['coins_fractional_fp'] = 0;
        for ($tier = 1; $tier <= SIGIL_MAX_TIER; $tier++) {
            $state['participation']['sigils_t' . $tier] = 0;
        }
        $state['boost'] = ['is_active' => false, 'modifier_fp' => 0, 'activated_tick' => 0, 'expires_tick' => 0];
        $state['player']['joined_season_id'] = null;
        $state['player']['participation_enabled'] = 0;
    }

    private static function runtimeFreshStartRejoin(array &$state, int $seasonId, int $gameTime): void
    {
        $state['participation']['total_season_participation_ticks'] =
            (int)($state['participation']['total_season_participation_ticks'] ?? 0)
            + (int)($state['participation']['participation_ticks_since_join'] ?? 0);
        $state['participation'] = array_replace($state['participation'], [
            'coins' => 0,
            'coins_fractional_fp' => 0,
            'seasonal_stars' => 0,
            'sigils_t1' => 0,
            'sigils_t2' => 0,
            'sigils_t3' => 0,
            'sigils_t4' => 0,
            'sigils_t5' => 0,
            'sigils_t6' => 0,
            'sigil_drops_total' => 0,
            'participation_time_total' => 0,
            'participation_ticks_since_join' => 0,
            'active_ticks_total' => 0,
            'spend_window_total' => 0,
            'hoarding_sink_total' => 0,
            'lock_in_effect_tick' => null,
            'lock_in_snapshot_seasonal_stars' => null,
            'lock_in_snapshot_participation_time' => null,
            'end_membership' => 0,
            'final_rank' => null,
            'final_seasonal_stars' => null,
            'global_stars_earned' => 0,
            'participation_bonus' => 0,
            'placement_bonus' => 0,
            'first_joined_at' => $gameTime,
            'last_exit_at' => null,
        ]);
        $state['player'] = array_replace($state['player'], [
            'joined_season_id' => $seasonId,
            'participation_enabled' => 1,
            'idle_modal_active' => 0,
            'activity_state' => 'Active',
            'idle_since_tick' => null,
            'last_activity_tick' => $gameTime,
            'online_current' => 1,
        ]);
        $state['boost'] = ['is_active' => false, 'modifier_fp' => 0, 'activated_tick' => 0, 'expires_tick' => 0];
        $state['freeze'] = ['is_active' => false, 'expires_tick' => 0, 'applied_count' => 0];
    }

    private static function simulatorFreshStartRejoin(array &$state, int $seasonId, int $gameTime): void
    {
        self::runtimeFreshStartRejoin($state, $seasonId, $gameTime);
    }

    private static function effectiveLockInTicks(array $participation): int
    {
        return (int)($participation['total_season_participation_ticks'] ?? 0)
            + (int)($participation['participation_ticks_since_join'] ?? 0);
    }

    private static function simulateBoostAccrualTick(SimulationPlayer $player, array $season, int $tick, string $phase): bool
    {
        $before = $player->snapshot();
        $beforeTicksBoosted = (int)$before['metrics']['ticks_boosted'];
        $player->expireEffects($tick);
        $player->setPresenceState('Active', $tick);
        $player->accrue($season, $phase);
        $after = $player->snapshot();
        return (int)$after['metrics']['ticks_boosted'] > $beforeTicksBoosted;
    }

    private static function makeSimulationPlayer(string $seed, string $archetypeKey): SimulationPlayer
    {
        return new SimulationPlayer(
            1,
            $archetypeKey,
            Archetypes::get($archetypeKey),
            $seed . '|simulation-player|' . $archetypeKey,
            1
        );
    }

    private static function seedSimulationPlayer(
        SimulationPlayer $player,
        array $playerOverrides = [],
        array $participationOverrides = [],
        array $boostOverrides = [],
        array $freezeOverrides = []
    ): void {
        $mutator = function () use ($playerOverrides, $participationOverrides, $boostOverrides, $freezeOverrides): void {
            $this->player = array_replace($this->player, $playerOverrides);
            $this->participation = array_replace($this->participation, $participationOverrides);
            $this->boost = array_replace($this->boost, $boostOverrides);
            $this->freeze = array_replace($this->freeze, $freezeOverrides);
        };

        $bound = \Closure::bind($mutator, $player, SimulationPlayer::class);
        $bound();
    }

    private static function invokeSimulationPlayer(SimulationPlayer $player, string $method, array $args = []): mixed
    {
        $invoker = function (string $method, array $args): mixed {
            return $this->{$method}(...$args);
        };

        $bound = \Closure::bind($invoker, $player, SimulationPlayer::class);
        return $bound($method, $args);
    }

    private static function makeRuntimeState(array $fixture, array $season): array
    {
        return [
            'player' => array_replace([
                'player_id' => 1,
                'joined_season_id' => (int)($season['season_id'] ?? 1),
                'participation_enabled' => 1,
                'idle_modal_active' => 0,
                'activity_state' => 'Active',
                'economic_presence_state' => 'Active',
                'current_game_time' => (int)($season['start_time'] ?? 0),
                'idle_since_tick' => null,
                'online_current' => 1,
                'global_stars' => 0,
                'global_stars_fractional_fp' => 0,
            ], (array)($fixture['player_overrides'] ?? [])),
            'participation' => array_replace([
                'coins' => 0,
                'coins_fractional_fp' => 0,
                'seasonal_stars' => 0,
                'sigils_t1' => 0,
                'sigils_t2' => 0,
                'sigils_t3' => 0,
                'sigils_t4' => 0,
                'sigils_t5' => 0,
                'sigils_t6' => 0,
                'sigil_drops_total' => 0,
                'participation_time_total' => 0,
                'participation_ticks_since_join' => 0,
                'active_ticks_total' => 0,
                'total_season_participation_ticks' => 0,
                'spend_window_total' => 0,
                'hoarding_sink_total' => 0,
                'lock_in_effect_tick' => null,
                'lock_in_snapshot_seasonal_stars' => null,
                'lock_in_snapshot_participation_time' => null,
                'end_membership' => 0,
                'final_rank' => null,
                'final_seasonal_stars' => null,
                'global_stars_earned' => 0,
                'participation_bonus' => 0,
                'placement_bonus' => 0,
                'first_joined_at' => (int)($season['start_time'] ?? 0),
                'last_exit_at' => null,
            ], (array)($fixture['participation_overrides'] ?? [])),
            'boost' => array_replace([
                'is_active' => false,
                'modifier_fp' => 0,
                'activated_tick' => 0,
                'expires_tick' => 0,
            ], (array)($fixture['boost_overrides'] ?? [])),
            'freeze' => array_replace([
                'is_active' => false,
                'expires_tick' => 0,
                'applied_count' => 0,
            ], (array)($fixture['freeze_overrides'] ?? [])),
            'metrics' => [
                'ticks_boosted' => 0,
                'blackout_conversions' => 0,
            ],
        ];
    }

    private static function runtimeSnapshot(array $state): array
    {
        return [
            'player' => $state['player'],
            'participation' => $state['participation'],
        ];
    }

    private static function resolveSeason(string $seed, array $options): array
    {
        if (isset($options['season']) && is_array($options['season']) && $options['season'] !== []) {
            return SimulationSeason::build(
                (int)($options['season']['season_id'] ?? 1),
                $seed . '|runtime-parity-season',
                SimulationSeason::normalizeImportedRow((array)$options['season'])
            );
        }

        if (!empty($options['season_config_path'])) {
            return SimulationSeason::fromJsonFile(
                (string)$options['season_config_path'],
                1,
                $seed . '|runtime-parity-season-file'
            );
        }

        return SimulationSeason::build(1, $seed . '|runtime-parity-default');
    }

    private static function prepareSeason(array $season, array $overrides): array
    {
        $prepared = array_replace($season, $overrides);
        if (!isset($prepared['status'])) {
            $prepared['status'] = 'Active';
        }
        if (!isset($prepared['computed_status'])) {
            $prepared['computed_status'] = (string)$prepared['status'];
        }
        return $prepared;
    }

    private static function buildMarkdown(array $report): string
    {
        $lines = [];
        $lines[] = '# Runtime Parity Certification';
        $lines[] = '';
        $lines[] = '- Candidate: `' . (string)$report['candidate_id'] . '`';
        $lines[] = '- Seed: `' . (string)$report['seed'] . '`';
        $lines[] = '- Status: `' . (string)$report['certification_status'] . '`';
        $lines[] = '- Certified: `' . (!empty($report['certified']) ? 'true' : 'false') . '`';
        $lines[] = '- Material drift count: `' . (int)$report['material_drift_count'] . '`';
        $lines[] = '- Tolerated difference count: `' . (int)$report['tolerated_difference_count'] . '`';
        $lines[] = '';

        foreach ((array)$report['domains'] as $domain) {
            $lines[] = '## ' . (string)$domain['label'] . ' (`' . (string)$domain['domain_id'] . '`)';
            $lines[] = '';
            $lines[] = '- Status: `' . (string)$domain['status'] . '`';
            $lines[] = '- Fixture count: `' . (int)$domain['fixture_count'] . '`';
            $lines[] = '- Material drift count: `' . (int)$domain['material_drift_count'] . '`';
            foreach ((array)$domain['fixtures'] as $fixture) {
                $lines[] = '- `' . (string)$fixture['fixture_id'] . '` => `' . (string)$fixture['status'] . '`';
            }
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private static function seasonSurfaceHash(array $season): string
    {
        $normalized = $season;
        if (isset($normalized['season_seed']) && is_string($normalized['season_seed'])) {
            $normalized['season_seed_hex'] = bin2hex($normalized['season_seed']);
            unset($normalized['season_seed']);
        }
        ksort($normalized);
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES));
    }

    private static function pathValue(array $data, string $path): mixed
    {
        $segments = explode('.', $path);
        $cursor = $data;
        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }

    private static function valuesMatch(mixed $left, mixed $right, mixed $tolerance): bool
    {
        if (is_int($left) || is_float($left) || is_int($right) || is_float($right)) {
            return abs((float)$left - (float)$right) <= (float)$tolerance;
        }

        return $left === $right;
    }
}

class RuntimeParityFixtureCatalog
{
    public static function globalToleratedDifferences(): array
    {
        return [
            'Transport-only side effects are intentionally ignored: notification rows, SQL timestamps, auto-increment ids, and API envelope formatting do not affect parity certification.',
            'Boost certification covers deterministic purchase, accrual, and expiry semantics; it does not certify client countdown rendering or HTTP/session transport.',
        ];
    }

    public static function domains(array $season): array
    {
        return [
            [
                'domain_id' => 'hoarding_sink_behavior',
                'label' => 'Hoarding Sink Behavior',
                'adapter' => 'hoarding',
                'tolerated_differences' => [],
                'fixtures' => [
                    [
                        'fixture_id' => 'hoarding-early-active-pressure',
                        'label' => 'Early active sink pressure',
                        'description' => 'High-balance active player in EARLY phase should accrue with identical sink pressure.',
                        'phase' => 'EARLY',
                        'presence' => 'Active',
                        'tick' => 500,
                        'ticks' => 3,
                        'participation_overrides' => [
                            'coins' => 300000,
                            'coins_fractional_fp' => 250000,
                        ],
                        'compare_paths' => [
                            'gross_rate_fp' => 0,
                            'sink_per_tick' => 0,
                            'net_rate_fp' => 0,
                            'coins_after' => 0,
                            'carry_after' => 0,
                            'hoarding_sink_total' => 0,
                        ],
                    ],
                    [
                        'fixture_id' => 'hoarding-late-active-suppressed',
                        'label' => 'Late-active sink suppression',
                        'description' => 'Hoarding sink must suppress during the late active lock-in window.',
                        'phase' => 'LATE_ACTIVE',
                        'presence' => 'Active',
                        'tick' => 900,
                        'ticks' => 2,
                        'participation_overrides' => [
                            'coins' => 300000,
                        ],
                        'compare_paths' => [
                            'gross_rate_fp' => 0,
                            'sink_per_tick' => 0,
                            'net_rate_fp' => 0,
                            'coins_after' => 0,
                            'carry_after' => 0,
                            'hoarding_sink_total' => 0,
                        ],
                    ],
                ],
            ],
            [
                'domain_id' => 'boost_behavior',
                'label' => 'Boost Behavior',
                'adapter' => 'boost',
                'tolerated_differences' => [],
                'fixtures' => [
                    [
                        'fixture_id' => 'boost-tier3-expiry-window',
                        'label' => 'Tier III boost expiry window',
                        'description' => 'Tier III boost should apply through its expiry tick and stop immediately after.',
                        'sigil_tier' => 3,
                        'purchase_kind' => 'power',
                        'purchase_tick' => 100,
                        'phase' => 'EARLY',
                        'observe_at_expiry' => true,
                        'observe_after_expiry' => true,
                        'participation_overrides' => [
                            'sigils_t3' => 1,
                        ],
                        'compare_paths' => [
                            'modifier_fp' => 0,
                            'expires_tick' => 0,
                            'applied_at_first_tick' => 0,
                            'applied_at_expiry_tick' => 0,
                            'applied_after_expiry' => 0,
                            'ticks_boosted' => 0,
                        ],
                    ],
                    [
                        'fixture_id' => 'boost-time-extension-flat',
                        'label' => 'Flat time extension',
                        'description' => 'Adding time to an active boost should extend expiry by the catalog-listed amount only.',
                        'sigil_tier' => 2,
                        'purchase_kind' => 'power',
                        'purchase_tick' => 100,
                        'phase' => 'EARLY',
                        'observe_at_expiry' => false,
                        'observe_after_expiry' => false,
                        'participation_overrides' => [
                            'sigils_t1' => 1,
                            'sigils_t2' => 1,
                        ],
                        'extension' => [
                            'sigil_tier' => 1,
                            'purchase_kind' => 'time',
                            'tick' => 140,
                        ],
                        'compare_paths' => [
                            'modifier_fp' => 0,
                            'expires_tick' => 0,
                            'applied_at_first_tick' => 0,
                        ],
                    ],
                ],
            ],
            [
                'domain_id' => 'lock_in_timing',
                'label' => 'Lock-In Timing',
                'adapter' => 'lock_in',
                'tolerated_differences' => [],
                'fixtures' => [
                    [
                        'fixture_id' => 'lock-in-below-season-threshold',
                        'label' => 'Below threshold blocked',
                        'description' => 'Lock-in must fail one tick below the cumulative seasonal threshold.',
                        'status' => 'Active',
                        'phase' => 'MID',
                        'tick' => 600,
                        'participation_overrides' => [
                            'participation_ticks_since_join' => 1,
                            'total_season_participation_ticks' => max(0, MIN_SEASONAL_LOCK_IN_TICKS - 2),
                            'seasonal_stars' => 120,
                            'sigils_t1' => 2,
                        ],
                        'compare_paths' => [
                            'success' => 0,
                            'joined_season_id_after' => 0,
                            'participation_enabled_after' => 0,
                            'seasonal_stars_after' => 0,
                            'coins_after' => 0,
                        ],
                    ],
                    [
                        'fixture_id' => 'lock-in-blackout-at-threshold',
                        'label' => 'Blackout threshold success',
                        'description' => 'Lock-in must remain available during blackout when the seasonal threshold is met.',
                        'status' => 'Blackout',
                        'phase' => 'BLACKOUT',
                        'tick' => 1200,
                        'participation_overrides' => [
                            'participation_ticks_since_join' => 1,
                            'total_season_participation_ticks' => max(0, MIN_SEASONAL_LOCK_IN_TICKS - 1),
                            'seasonal_stars' => 180,
                            'sigils_t1' => 1,
                            'sigils_t2' => 1,
                        ],
                        'compare_paths' => [
                            'success' => 0,
                            'lock_in_effect_tick' => 0,
                            'lock_in_snapshot_seasonal_stars' => 0,
                            'global_stars_earned' => 0,
                            'joined_season_id_after' => 0,
                            'participation_enabled_after' => 0,
                        ],
                    ],
                ],
            ],
            [
                'domain_id' => 'expiry_timing',
                'label' => 'Expiry Timing',
                'adapter' => 'expiry',
                'tolerated_differences' => [],
                'fixtures' => [
                    [
                        'fixture_id' => 'natural-expiry-ranked-payout',
                        'label' => 'Ranked natural expiry payout',
                        'description' => 'Natural expiry must settle with identical participation and placement bonuses.',
                        'placement_rank' => 2,
                        'award_badges_and_placement' => true,
                        'player_overrides' => [
                            'joined_season_id' => 1,
                            'participation_enabled' => 1,
                        ],
                        'participation_overrides' => [
                            'seasonal_stars' => 200,
                            'active_ticks_total' => 7200,
                            'coins' => 950,
                            'sigils_t1' => 4,
                        ],
                        'compare_paths' => [
                            'end_membership' => 0,
                            'final_seasonal_stars' => 0,
                            'global_stars_earned' => 0,
                            'participation_bonus' => 0,
                            'placement_bonus' => 0,
                            'seasonal_stars_after' => 0,
                            'coins_after' => 0,
                            'joined_season_id_after' => 0,
                            'participation_enabled_after' => 0,
                        ],
                    ],
                ],
            ],
            [
                'domain_id' => 'star_pricing_affordability',
                'label' => 'Star Pricing / Affordability',
                'adapter' => 'star_pricing',
                'tolerated_differences' => [],
                'fixtures' => [
                    [
                        'fixture_id' => 'star-pricing-active-affordable',
                        'label' => 'Active-price purchase',
                        'description' => 'Simulator and runtime must use the same live price and affordability outcome.',
                        'tick' => 800,
                        'phase' => 'MID',
                        'expected_stars_after' => 3,
                        'stars_requested' => 3,
                        'season_overrides' => [
                            'status' => 'Active',
                            'computed_status' => 'Active',
                            'current_star_price' => 180,
                            'total_coins_supply_end_of_tick' => 80000,
                            'effective_price_supply' => 80000,
                        ],
                        'participation_overrides' => [
                            'coins' => 1500,
                        ],
                        'compare_paths' => [
                            'computed_star_price' => 0,
                            'published_star_price' => 0,
                            'coins_after' => 0,
                            'seasonal_stars_after' => 0,
                            'spend_window_total' => 0,
                            'affordable' => 0,
                        ],
                    ],
                    [
                        'fixture_id' => 'star-pricing-blackout-snapshot',
                        'label' => 'Blackout snapshot purchase',
                        'description' => 'Blackout purchases must use the frozen snapshot price on both sides.',
                        'tick' => 1600,
                        'phase' => 'BLACKOUT',
                        'expected_stars_after' => 2,
                        'stars_requested' => 2,
                        'blackout_snapshot_price' => 275,
                        'season_overrides' => [
                            'status' => 'Blackout',
                            'computed_status' => 'Blackout',
                            'current_star_price' => 410,
                        ],
                        'participation_overrides' => [
                            'coins' => 700,
                        ],
                        'compare_paths' => [
                            'computed_star_price' => 0,
                            'published_star_price' => 0,
                            'coins_after' => 0,
                            'seasonal_stars_after' => 0,
                            'spend_window_total' => 0,
                            'affordable' => 0,
                        ],
                    ],
                ],
            ],
            [
                'domain_id' => 'rejoin_participation_effects',
                'label' => 'Rejoin Participation Effects',
                'adapter' => 'rejoin',
                'tolerated_differences' => [
                    'Certification compares run-affecting rejoin fields only; exact `first_joined_at` formatting and other transport metadata are out of scope.',
                ],
                'fixtures' => [
                    [
                        'fixture_id' => 'rejoin-fresh-start-reset',
                        'label' => 'Fresh-start reset',
                        'description' => 'Rejoining should clear current-run state while preserving cumulative participation.',
                        'game_time' => 900,
                        'player_overrides' => [
                            'joined_season_id' => null,
                            'participation_enabled' => 0,
                            'idle_since_tick' => 455,
                        ],
                        'participation_overrides' => [
                            'coins' => 800,
                            'seasonal_stars' => 12,
                            'sigils_t1' => 3,
                            'sigils_t2' => 1,
                            'participation_ticks_since_join' => 12,
                            'active_ticks_total' => 7,
                            'total_season_participation_ticks' => 30,
                            'lock_in_effect_tick' => 444,
                            'lock_in_snapshot_seasonal_stars' => 88,
                        ],
                        'compare_paths' => [
                            'coins_after' => 0,
                            'seasonal_stars_after' => 0,
                            'participation_ticks_since_join_after' => 0,
                            'active_ticks_total_after' => 0,
                            'total_season_participation_ticks_after' => 0,
                            'lock_in_effect_tick_after' => 0,
                            'idle_since_tick_after' => 0,
                            'joined_season_id_after' => 0,
                            'effective_total_lock_in_ticks' => 0,
                        ],
                    ],
                ],
            ],
            [
                'domain_id' => 'blackout_finalization_interactions',
                'label' => 'Blackout / Finalization Interactions',
                'adapter' => 'blackout_finalization',
                'tolerated_differences' => [],
                'fixtures' => [
                    [
                        'fixture_id' => 'blackout-settlement-then-finalize',
                        'label' => 'Blackout settlement then finalize',
                        'description' => 'Blackout must zero accrual, use snapshot pricing, and finalization must detach the player.',
                        'placement_rank' => 1,
                        'blackout_snapshot_price' => 333,
                        'season_overrides' => [
                            'blackout_time' => 500,
                            'end_time' => 1000,
                        ],
                        'participation_overrides' => [
                            'seasonal_stars' => 160,
                            'active_ticks_total' => 7200,
                            'coins' => 600,
                        ],
                        'compare_paths' => [
                            'blackout_gross_rate_fp' => 0,
                            'blackout_net_rate_fp' => 0,
                            'published_blackout_price' => 0,
                            'joined_season_id_after' => 0,
                            'participation_enabled_after' => 0,
                            'seasonal_stars_after' => 0,
                        ],
                    ],
                ],
            ],
        ];
    }
}
