<?php

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/economy.php';
require_once __DIR__ . '/../../includes/boost_catalog.php';
require_once __DIR__ . '/PolicyBehavior.php';
require_once __DIR__ . '/SimulationRandom.php';

class SimulationPlayer
{
    private array $archetype;
    private string $seed;
    private array $player;
    private array $participation;
    private array $boost;
    private array $freeze;
    private array $policy;
    private array $metrics;
    private int $globalStarsGained = 0;
    private bool $lockedIn = false;
    private int $finalRank = 0;

    public function __construct(int $playerId, string $archetypeKey, array $archetype, string $seed, int $seasonId)
    {
        $this->archetype = $archetype;
        $this->seed = $seed;
        $this->player = [
            'player_id' => $playerId,
            'handle' => 'sim_' . $playerId,
            'joined_season_id' => $seasonId,
            'participation_enabled' => 1,
            'idle_modal_active' => 0,
            'activity_state' => 'Active',
            'economic_presence_state' => 'Active',
            'current_game_time' => 0,
            'last_activity_tick' => 0,
            'idle_since_tick' => null,
            'online_current' => 1,
            'global_stars' => 0,
            'global_stars_fractional_fp' => 0,
            'archetype_key' => $archetypeKey,
            'archetype_label' => (string)$archetype['label'],
        ];
        $this->participation = [
            'player_id' => $playerId,
            'season_id' => $seasonId,
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
        ];
        $this->boost = [
            'is_active' => false,
            'modifier_fp' => 0,
            'activated_tick' => 0,
            'expires_tick' => 0,
        ];
        $this->freeze = [
            'is_active' => false,
            'expires_tick' => 0,
            'applied_count' => 0,
        ];
        $this->policy = [
            'next_lock_review_tick' => 0,
            'lock_reviews' => 0,
        ];
        $this->metrics = [
            'coins_earned_by_phase' => ['EARLY' => 0, 'MID' => 0, 'LATE_ACTIVE' => 0, 'BLACKOUT' => 0],
            'stars_purchased_by_phase' => ['EARLY' => 0, 'MID' => 0, 'LATE_ACTIVE' => 0, 'BLACKOUT' => 0],
            'presence_ticks_by_phase' => ['EARLY' => 0, 'MID' => 0, 'LATE_ACTIVE' => 0, 'BLACKOUT' => 0],
            'active_ticks_by_phase' => ['EARLY' => 0, 'MID' => 0, 'LATE_ACTIVE' => 0, 'BLACKOUT' => 0],
            'sigils_acquired_by_tier' => ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0, '6' => 0],
            'sigils_spent_by_action' => ['boost' => 0, 'combine' => 0, 'freeze' => 0, 'theft' => 0, 'melt' => 0],
            'actions_by_phase' => [
                'EARLY' => ['boost' => 0, 'combine' => 0, 'freeze' => 0, 'theft' => 0],
                'MID' => ['boost' => 0, 'combine' => 0, 'freeze' => 0, 'theft' => 0],
                'LATE_ACTIVE' => ['boost' => 0, 'combine' => 0, 'freeze' => 0, 'theft' => 0],
                'BLACKOUT' => ['boost' => 0, 'combine' => 0, 'freeze' => 0, 'theft' => 0],
            ],
            't6_total_acquired' => 0,
            't6_by_source' => ['drop' => 0, 'combine' => 0, 'theft' => 0],
            'blackout_conversions' => 0,
            'blackout_global_stars_gained' => 0,
            'lock_in_phase' => null,
            'natural_expiry' => false,
            'coins_earned_while_boosted' => 0,
            'ticks_boosted' => 0,
            'ticks_frozen' => 0,
            'score_at_phase_end' => ['EARLY' => null, 'MID' => null, 'LATE_ACTIVE' => null],
        ];
    }

    public function expireEffects(int $tick): void
    {
        if ($this->boost['is_active'] && (int)$this->boost['expires_tick'] < $tick) {
            $this->boost = ['is_active' => false, 'modifier_fp' => 0, 'activated_tick' => 0, 'expires_tick' => 0];
        }
        if ($this->freeze['is_active'] && (int)$this->freeze['expires_tick'] < $tick) {
            $this->freeze = ['is_active' => false, 'expires_tick' => 0, 'applied_count' => 0];
        }
    }

    public function setPresenceState(string $presenceState, int $tick): void
    {
        $this->player['current_game_time'] = $tick;
        $this->player['economic_presence_state'] = $presenceState;
        $this->player['activity_state'] = ($presenceState === 'Active') ? 'Active' : 'Idle';
        $this->player['online_current'] = ($presenceState === 'Offline') ? 0 : 1;
        if ($presenceState !== 'Active' && $this->player['idle_since_tick'] === null) {
            $this->player['idle_since_tick'] = $tick;
        }
        if ($presenceState === 'Active') {
            $this->player['idle_since_tick'] = null;
            $this->player['last_activity_tick'] = $tick;
        }
    }

    public function processSigilDrop(array $season, int $tick): void
    {
        if (!$this->isParticipating()) {
            return;
        }

        $drop = Economy::evaluateSigilDropForTick($season, $this->player, $tick);
        if ($drop === null) {
            return;
        }

        $tier = (int)$drop['tier'];
        if (!Economy::canReceiveSigilTier($this->participation, $tier, 1, 0)) {
            return;
        }

        $column = 'sigils_t' . $tier;
        $this->participation[$column]++;
        $this->participation['sigil_drops_total']++;
        $this->metrics['sigils_acquired_by_tier'][(string)$tier]++;
        if ($tier === 6) {
            $this->metrics['t6_total_acquired']++;
            $this->metrics['t6_by_source']['drop']++;
        }
    }

    public function accrue(array $season, string $phase): array
    {
        if (!$this->isParticipating()) {
            return ['net_coins' => 0, 'sink' => 0];
        }

        $rates = Economy::calculateRateBreakdown(
            $season,
            $this->player,
            $this->participation,
            $this->currentBoostModifier(),
            $this->isFrozen(),
            false,
            $phase
        );
        $carryFp = max(0, (int)$this->participation['coins_fractional_fp']);
        $totalNetFp = (int)$rates['net_rate_fp'] + $carryFp;
        [$netCoins, $newCarryFp] = Economy::splitFixedPoint($totalNetFp);

        $this->participation['coins'] += $netCoins;
        $this->participation['coins_fractional_fp'] = $newCarryFp;
        $this->participation['hoarding_sink_total'] += (int)$rates['sink_per_tick'];
        $this->participation['participation_time_total']++;
        $this->participation['participation_ticks_since_join']++;
        $this->participation['total_season_participation_ticks']++;
        $this->metrics['presence_ticks_by_phase'][$phase]++;
        if ($this->player['economic_presence_state'] === 'Active') {
            $this->participation['active_ticks_total']++;
            $this->metrics['active_ticks_by_phase'][$phase]++;
        }

        $this->metrics['coins_earned_by_phase'][$phase] += $netCoins;
        if ($this->boost['is_active']) {
            $this->metrics['coins_earned_while_boosted'] += $netCoins;
            $this->metrics['ticks_boosted']++;
        }
        if ($this->freeze['is_active']) {
            $this->metrics['ticks_frozen']++;
        }

        return [
            'net_coins' => $netCoins,
            'sink' => (int)$rates['sink_per_tick'],
        ];
    }

    public function act(array &$season, string $phase, int $tick, array $playerSnapshots, array $playerMap): void
    {
        if (!$this->isParticipating()) {
            return;
        }

        $state = $this->snapshot();

        $combineTier = PolicyBehavior::decideCombineTier($this->archetype, $state, $phase, $this->seed, $tick);
        if ($combineTier !== null) {
            $this->combineSigils($combineTier, $phase);
        }

        $boostDecision = PolicyBehavior::decideBoostPurchase($this->archetype, $state, $phase, $this->seed, $tick);
        if ($boostDecision !== null) {
            $this->purchaseBoost((int)$boostDecision['tier'], (string)$boostDecision['kind'], $tick, $phase);
        }

        $freezeTarget = PolicyBehavior::chooseFreezeTarget($this->archetype, $this->snapshot(), $playerSnapshots, $phase, $this->seed, $tick);
        if ($freezeTarget !== null && isset($playerMap[$freezeTarget])) {
            $this->freezeTarget($playerMap[$freezeTarget], (string)$season['status'], $tick, $phase);
        }

        $theftTarget = PolicyBehavior::chooseTheftTarget($this->archetype, $this->snapshot(), $playerSnapshots, $phase, $this->seed, $tick);
        if ($theftTarget !== null && isset($playerMap[$theftTarget])) {
            $this->attemptTheft($playerMap[$theftTarget], (string)$season['status'], $tick, $phase);
        }

        $starsToBuy = PolicyBehavior::decideStarPurchase($this->archetype, $this->snapshot(), $season, $phase, $this->seed, $tick);
        if ($starsToBuy > 0) {
            $this->purchaseStars($season, $phase, $starsToBuy);
        }

        if (in_array($phase, ['MID', 'LATE_ACTIVE', 'BLACKOUT'], true) && $tick >= (int)$this->policy['next_lock_review_tick']) {
            $this->policy['lock_reviews']++;
            if (PolicyBehavior::shouldLockIn($this->archetype, $this->snapshot(), $season, $phase, $this->seed, $tick)) {
                $this->lockIn((string)$season['status'], $tick, $phase);
                return;
            }

            $this->policy['next_lock_review_tick'] = PolicyBehavior::scheduleNextLockReview(
                $this->archetype,
                $season,
                $phase,
                $this->seed,
                (int)$this->player['player_id'],
                $tick
            );
        }
    }

    public function markEndMembership(): void
    {
        if (!$this->isParticipating()) {
            return;
        }
        $this->participation['end_membership'] = 1;
        $this->participation['final_seasonal_stars'] = (int)$this->participation['seasonal_stars'];
    }

    public function applyNaturalExpiry(int $placementRank, bool $awardBadgesAndPlacement): void
    {
        if (!$this->isParticipating()) {
            return;
        }

        $payout = Economy::computeNaturalExpiryPayout(
            (int)$this->participation['seasonal_stars'],
            (int)$this->participation['active_ticks_total'],
            $placementRank,
            (int)$this->player['global_stars_fractional_fp'],
            $awardBadgesAndPlacement
        );

        $this->player['global_stars'] += (int)$payout['global_stars_gained'];
        $this->player['global_stars_fractional_fp'] = (int)$payout['global_stars_fractional_fp'];
        $this->globalStarsGained += (int)$payout['global_stars_gained'];
        $this->participation['global_stars_earned'] = (int)$payout['global_stars_gained'];
        $this->participation['participation_bonus'] = (int)$payout['participation_bonus'];
        $this->participation['placement_bonus'] = (int)$payout['placement_bonus'];
        $this->participation['seasonal_stars'] = 0;
        $this->participation['coins'] = 0;
        $this->participation['coins_fractional_fp'] = 0;
        for ($tier = 1; $tier <= SIGIL_MAX_TIER; $tier++) {
            $this->participation['sigils_t' . $tier] = 0;
        }
        $this->boost = ['is_active' => false, 'modifier_fp' => 0, 'activated_tick' => 0, 'expires_tick' => 0];
        $this->player['joined_season_id'] = null;
        $this->player['participation_enabled'] = 0;
        $this->metrics['natural_expiry'] = true;
    }

    public function setFinalRank(int $rank): void
    {
        $this->finalRank = $rank;
        $this->participation['final_rank'] = $rank;
    }

    public function snapshot(): array
    {
        return [
            'player_id' => (int)$this->player['player_id'],
            'archetype_key' => (string)$this->player['archetype_key'],
            'archetype_label' => (string)$this->player['archetype_label'],
            'participation' => $this->participation,
            'locked_out' => !$this->isParticipating(),
            'player' => $this->player,
            'boost' => $this->boost,
            'freeze' => $this->freeze,
            'policy' => $this->policy,
            'metrics' => $this->metrics,
        ];
    }

    public function exportResult(): array
    {
        return [
            'player_id' => (int)$this->player['player_id'],
            'archetype_key' => (string)$this->player['archetype_key'],
            'archetype_label' => (string)$this->player['archetype_label'],
            'final_effective_score' => $this->effectiveScore(),
            'final_rank' => $this->finalRank,
            'global_stars_gained' => $this->globalStarsGained,
            'locked_in' => $this->lockedIn,
            'metrics' => $this->metrics,
            'participation' => $this->participation,
        ];
    }

    public function effectiveScore(): int
    {
        return Economy::effectiveSeasonalStars($this->participation);
    }

    public function isParticipating(): bool
    {
        return !empty($this->player['participation_enabled']) && !empty($this->player['joined_season_id']);
    }

    public function snapshotPhaseEnd(string $phase): void
    {
        if (isset($this->metrics['score_at_phase_end'][$phase])) {
            $this->metrics['score_at_phase_end'][$phase] = (int)$this->participation['seasonal_stars'];
        }
    }

    public function currentPresence(): string
    {
        return (string)$this->player['economic_presence_state'];
    }

    public function totalCoins(): int
    {
        return max(0, (int)$this->participation['coins']);
    }

    private function currentBoostModifier(): int
    {
        return $this->boost['is_active'] ? (int)$this->boost['modifier_fp'] : 0;
    }

    private function isFrozen(): bool
    {
        return !empty($this->freeze['is_active']);
    }

    private function purchaseStars(array $season, string $phase, int $quantity): void
    {
        $price = Economy::publishedStarPrice($season, (string)$season['status']);
        if ($price <= 0 || $quantity <= 0) {
            return;
        }

        $coinsNeeded = $price * $quantity;
        if ((int)$this->participation['coins'] < $coinsNeeded) {
            return;
        }

        $this->participation['coins'] -= $coinsNeeded;
        $this->participation['seasonal_stars'] += $quantity;
        $this->participation['spend_window_total'] += $coinsNeeded;
        $this->metrics['stars_purchased_by_phase'][$phase] += $quantity;
        $this->player['last_activity_tick'] = (int)$this->player['current_game_time'];
    }

    private function combineSigils(int $fromTier, string $phase): void
    {
        $required = (int)(SIGIL_COMBINE_RECIPES[$fromTier] ?? 0);
        if ($required <= 0) {
            return;
        }
        $toTier = $fromTier + 1;
        $fromCol = 'sigils_t' . $fromTier;
        $toCol = 'sigils_t' . $toTier;
        if ((int)$this->participation[$fromCol] < $required) {
            return;
        }
        if (!Economy::canReceiveSigilTier($this->participation, $toTier, 1, $required)) {
            return;
        }

        $this->participation[$fromCol] -= $required;
        $this->participation[$toCol] += 1;
        $this->metrics['sigils_spent_by_action']['combine'] += $required;
        $this->recordAction($phase, 'combine');
        if ($toTier === 6) {
            $this->metrics['t6_total_acquired']++;
            $this->metrics['t6_by_source']['combine']++;
        }
    }

    private function purchaseBoost(int $sigilTier, string $purchaseKind, int $tick, string $phase): void
    {
        if (!BoostCatalog::canSpendSigilTier($sigilTier)) {
            return;
        }
        $sigilCol = 'sigils_t' . $sigilTier;
        if ((int)$this->participation[$sigilCol] < 1) {
            return;
        }

        $timeCapTicks = ticks_from_real_seconds(BoostCatalog::TIME_CAP_SECONDS_PER_PRODUCT);
        $powerIncrementFp = max(1, BoostCatalog::getSpendPowerFpForTier($sigilTier));
        $timeIncrementTicks = max(1, BoostCatalog::getSpendTimeTicksForTier($sigilTier));
        $initialPowerFp = max(1, BoostCatalog::getInitialPowerFpForTier($sigilTier));
        $initialDurationTicks = max(1, BoostCatalog::getInitialDurationTicksForTier($sigilTier));

        if (!$this->boost['is_active']) {
            $this->boost = [
                'is_active' => true,
                'modifier_fp' => $initialPowerFp,
                'activated_tick' => $tick,
                'expires_tick' => $tick + $initialDurationTicks,
            ];
            $this->participation[$sigilCol] -= 1;
            $this->metrics['sigils_spent_by_action']['boost']++;
            $this->recordAction($phase, 'boost');
            return;
        }

        if ($purchaseKind === 'time') {
            $maxExpiresTick = $tick + $timeCapTicks;
            if ((int)$this->boost['expires_tick'] >= $maxExpiresTick) {
                return;
            }
            $this->boost['expires_tick'] = min($maxExpiresTick, (int)$this->boost['expires_tick'] + $timeIncrementTicks);
        } else {
            $projected = min(BoostCatalog::TOTAL_POWER_CAP_FP, (int)$this->boost['modifier_fp'] + $powerIncrementFp);
            if ($projected <= (int)$this->boost['modifier_fp']) {
                return;
            }
            $this->boost['modifier_fp'] = $projected;
        }

        $this->participation[$sigilCol] -= 1;
        $this->metrics['sigils_spent_by_action']['boost']++;
        $this->recordAction($phase, 'boost');
    }

    private function freezeTarget(self $target, string $status, int $tick, string $phase): void
    {
        if ((int)$this->participation['sigils_t6'] < 1 || !$target->isParticipating()) {
            return;
        }

        $baseTicks = (int)($status === 'Blackout' ? FREEZE_BLACKOUT_BASE_DURATION_TICKS : FREEZE_BASE_DURATION_TICKS);
        $stackTicks = (int)($status === 'Blackout' ? FREEZE_BLACKOUT_STACK_EXTENSION_TICKS : FREEZE_STACK_EXTENSION_TICKS);
        $this->participation['sigils_t6'] -= 1;
        $this->metrics['sigils_spent_by_action']['freeze']++;
        $this->recordAction($phase, 'freeze');

        if ($target->freeze['is_active']) {
            $target->freeze['expires_tick'] = max((int)$target->freeze['expires_tick'], $tick) + $stackTicks;
            $target->freeze['applied_count'] = (int)$target->freeze['applied_count'] + 1;
        } else {
            $target->freeze = [
                'is_active' => true,
                'expires_tick' => $tick + $baseTicks,
                'applied_count' => 1,
            ];
        }
    }

    private function attemptTheft(self $target, string $status, int $tick, string $phase): void
    {
        if (!$target->isParticipating()) {
            return;
        }
        if ((int)$this->player['current_game_time'] < (int)($this->player['theft_cooldown_until'] ?? 0)) {
            return;
        }
        if ($tick < (int)($target->player['theft_protection_until'] ?? 0)) {
            return;
        }

        $spendTier = ((int)$this->participation['sigils_t5'] > 0) ? 5 : (((int)$this->participation['sigils_t4'] > 0) ? 4 : 0);
        if ($spendTier === 0) {
            return;
        }

        $requestedTier = 0;
        $spendValue = (int)(SIGIL_UTILITY_VALUE_BY_TIER[$spendTier] ?? 0);
        for ($tier = 6; $tier >= 1; $tier--) {
            $targetCount = (int)($target->participation['sigils_t' . $tier] ?? 0);
            $tierValue = (int)(SIGIL_UTILITY_VALUE_BY_TIER[$tier] ?? 0);
            if ($targetCount > 0 && $tierValue <= $spendValue && Economy::canReceiveSigilTier($this->participation, $tier, 1, 1)) {
                $requestedTier = $tier;
                break;
            }
        }
        if ($requestedTier === 0) {
            return;
        }

        $requestedValue = (int)(SIGIL_UTILITY_VALUE_BY_TIER[$requestedTier] ?? 0);
        $successChanceFp = self::computeTheftSuccessChanceFp($spendValue, $requestedValue);
        $this->participation['sigils_t' . $spendTier] -= 1;
        $this->metrics['sigils_spent_by_action']['theft']++;
        $this->recordAction($phase, 'theft');
        $this->player['theft_cooldown_until'] = $tick + (int)($status === 'Blackout' ? SIGIL_THEFT_BLACKOUT_COOLDOWN_TICKS : SIGIL_THEFT_COOLDOWN_TICKS);
        $target->player['theft_protection_until'] = $tick + (int)($status === 'Blackout' ? SIGIL_THEFT_BLACKOUT_PROTECTION_TICKS : SIGIL_THEFT_PROTECTION_TICKS);

        $roll = (int)floor(SimulationRandom::float01($this->seed, ['theft-roll', $this->player['player_id'], $target->player['player_id'], $tick]) * FP_SCALE);
        if ($roll >= $successChanceFp) {
            return;
        }

        $targetCol = 'sigils_t' . $requestedTier;
        if ((int)$target->participation[$targetCol] < 1) {
            return;
        }
        if (!Economy::canReceiveSigilTier($this->participation, $requestedTier, 1, 1)) {
            return;
        }

        $target->participation[$targetCol] -= 1;
        $this->participation[$targetCol] += 1;
        if ($requestedTier === 6) {
            $this->metrics['t6_total_acquired']++;
            $this->metrics['t6_by_source']['theft']++;
        }
    }

    private static function computeTheftSuccessChanceFp(int $spendValue, int $requestedValue): int
    {
        if ($spendValue <= 0 || $requestedValue <= 0) {
            return 0;
        }
        $denominator = $spendValue + ((int)SIGIL_THEFT_VALUE_PRESSURE_MULTIPLIER * $requestedValue);
        return min((int)SIGIL_THEFT_SUCCESS_CAP_FP, intdiv($spendValue * FP_SCALE, max(1, $denominator)));
    }

    private function lockIn(string $status, int $tick, string $phase): void
    {
        // Enforce MIN_SEASONAL_LOCK_IN_TICKS (mirrors production lockIn gate).
        $totalSeasonTicks = (int)($this->participation['total_season_participation_ticks'] ?? 0)
                          + (int)($this->participation['participation_ticks_since_join'] ?? 0);
        if ($totalSeasonTicks < MIN_SEASONAL_LOCK_IN_TICKS) {
            return; // Not yet eligible — suppress lock-in
        }

        $tierCosts = [
            (int)(SIGIL_REFERENCE_STARS_BY_TIER[1] ?? 0),
            (int)(SIGIL_REFERENCE_STARS_BY_TIER[2] ?? 0),
            (int)(SIGIL_REFERENCE_STARS_BY_TIER[3] ?? 0),
            (int)(SIGIL_REFERENCE_STARS_BY_TIER[4] ?? 0),
            (int)(SIGIL_REFERENCE_STARS_BY_TIER[5] ?? 0),
        ];
        $sigilCounts = [
            (int)$this->participation['sigils_t1'],
            (int)$this->participation['sigils_t2'],
            (int)$this->participation['sigils_t3'],
            (int)$this->participation['sigils_t4'],
            (int)$this->participation['sigils_t5'],
        ];
        $payout = Economy::computeEarlyLockInPayout((int)$this->participation['seasonal_stars'], $sigilCounts, $tierCosts);
        $grant = Economy::applyGlobalStarsGrantWithCarry(
            (int)$payout['total_seasonal_stars'],
            (int)$this->player['global_stars_fractional_fp'],
            65,
            100
        );

        $this->player['global_stars'] += (int)$grant['global_stars_gained'];
        $this->player['global_stars_fractional_fp'] = (int)$grant['global_stars_fractional_fp'];
        $this->globalStarsGained += (int)$grant['global_stars_gained'];
        $this->participation['lock_in_effect_tick'] = $tick;
        $this->participation['lock_in_snapshot_seasonal_stars'] = (int)$payout['total_seasonal_stars'];
        $this->participation['lock_in_snapshot_participation_time'] = (int)$this->participation['participation_time_total'];
        $this->participation['global_stars_earned'] = (int)$grant['global_stars_gained'];
        $this->participation['coins'] = 0;
        $this->participation['coins_fractional_fp'] = 0;
        $this->participation['seasonal_stars'] = 0;
        for ($tier = 1; $tier <= SIGIL_MAX_TIER; $tier++) {
            $this->participation['sigils_t' . $tier] = 0;
        }
        $this->boost = ['is_active' => false, 'modifier_fp' => 0, 'activated_tick' => 0, 'expires_tick' => 0];
        $this->player['joined_season_id'] = null;
        $this->player['participation_enabled'] = 0;
        $this->lockedIn = true;
        $this->metrics['lock_in_phase'] = $phase;
        if ($status === 'Blackout') {
            $this->metrics['blackout_conversions']++;
            $this->metrics['blackout_global_stars_gained'] += (int)$grant['global_stars_gained'];
        }
    }

    private function recordAction(string $phase, string $action): void
    {
        if (!isset($this->metrics['actions_by_phase'][$phase][$action])) {
            return;
        }

        $this->metrics['actions_by_phase'][$phase][$action]++;
    }
}
