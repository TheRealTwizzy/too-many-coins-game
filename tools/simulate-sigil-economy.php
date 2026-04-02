<?php
/**
 * Monte Carlo simulator for seasonal sigil economy.
 *
 * Usage examples:
 *   php tools/simulate-sigil-economy.php
 *   php tools/simulate-sigil-economy.php --seasons=5000 --active-ratio=0.7 --idle-ratio=0.25 --seed=42
 *   php tools/simulate-sigil-economy.php --gate-fp=45000 --active-ratio=0.65 --idle-ratio=0.30
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

function argValue(array $argv, string $name, ?string $default = null): ?string {
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) {
            return substr($arg, strlen($prefix));
        }
    }
    return $default;
}

function clampFloat(float $x, float $min, float $max): float {
    return max($min, min($max, $x));
}

function interpolateTierWeights(int $progressFp): array {
    $weights = [];
    for ($tier = 1; $tier <= 6; $tier++) {
        $from = (int)SIGIL_TIER_WEIGHT_START[$tier];
        $to = (int)SIGIL_TIER_WEIGHT_END[$tier];
        $weights[$tier] = $from + intdiv(($to - $from) * $progressFp, FP_SCALE);
    }
    return $weights;
}

function pickWeightedTier(array $weights): int {
    $sum = 0;
    foreach ($weights as $w) {
        $sum += max(0, (int)$w);
    }
    if ($sum <= 0) {
        return 1;
    }

    $roll = mt_rand(1, $sum);
    $acc = 0;
    foreach ($weights as $tier => $w) {
        $acc += max(0, (int)$w);
        if ($roll <= $acc) {
            return (int)$tier;
        }
    }

    return 1;
}

function pickActivityState(float $activeRatio, float $idleRatio): string {
    $r = mt_rand() / mt_getrandmax();
    if ($r < $activeRatio) return 'Active';
    if ($r < ($activeRatio + $idleRatio)) return 'Idle';
    return 'Offline';
}

function applySigilAward(array &$inv, int $tier): bool {
    $tier = max(1, min(6, $tier));
    $cap = (int)(SIGIL_INVENTORY_TIER_CAPS[$tier] ?? 0);
    if ($cap > 0 && $inv[$tier] >= $cap) {
        return false;
    }

    $total = 0;
    for ($i = 1; $i <= 6; $i++) {
        $total += (int)$inv[$i];
    }
    if ($total >= (int)SIGIL_INVENTORY_TOTAL_CAP) {
        return false;
    }

    $inv[$tier]++;
    return true;
}

function runAutoCombines(array &$inv, bool $consumeT6Immediately): int {
    $t6Attainments = 0;
    $changed = true;

    while ($changed) {
        $changed = false;
        for ($tier = 1; $tier <= 5; $tier++) {
            $required = (int)(SIGIL_COMBINE_RECIPES[$tier] ?? 0);
            if ($required <= 0) continue;

            $next = $tier + 1;
            $nextCap = (int)(SIGIL_INVENTORY_TIER_CAPS[$next] ?? 0);
            while ($inv[$tier] >= $required && ($nextCap <= 0 || $inv[$next] < $nextCap)) {
                $inv[$tier] -= $required;
                $inv[$next] += 1;
                $changed = true;

                if ($next === 6) {
                    $t6Attainments++;
                    if ($consumeT6Immediately && $inv[6] > 0) {
                        $inv[6] -= 1;
                    }
                }
            }
        }
    }

    return $t6Attainments;
}

function percentile(array $values, float $p): float {
    if (empty($values)) return 0.0;
    sort($values);
    $n = count($values);
    if ($n === 1) return (float)$values[0];

    $rank = ($p / 100.0) * ($n - 1);
    $low = (int)floor($rank);
    $high = (int)ceil($rank);
    if ($low === $high) return (float)$values[$low];

    $w = $rank - $low;
    return (1.0 - $w) * (float)$values[$low] + $w * (float)$values[$high];
}

$seasons = max(1, (int)(argValue($argv, 'seasons', '1000') ?? '1000'));
$durationTicks = max(1, (int)(argValue($argv, 'duration-ticks', (string)SEASON_DURATION) ?? (string)SEASON_DURATION));
$gateFp = max(1, (int)(argValue($argv, 'gate-fp', (string)SIGIL_DROP_CHANCE_FP) ?? (string)SIGIL_DROP_CHANCE_FP));
$activeRatio = clampFloat((float)(argValue($argv, 'active-ratio', '0.65') ?? '0.65'), 0.0, 1.0);
$idleRatio = clampFloat((float)(argValue($argv, 'idle-ratio', '0.30') ?? '0.30'), 0.0, 1.0);
if (($activeRatio + $idleRatio) > 1.0) {
    $scale = ($activeRatio + $idleRatio);
    $activeRatio = $activeRatio / $scale;
    $idleRatio = $idleRatio / $scale;
}
$consumeT6Immediately = ((int)(argValue($argv, 'consume-t6', '1') ?? '1')) === 1;
$seed = (int)(argValue($argv, 'seed', (string)time()) ?? (string)time());
mt_srand($seed);

$results = [];
$activityMultiplier = SIGIL_ACTIVITY_MULTIPLIER_FP;

for ($s = 0; $s < $seasons; $s++) {
    $inv = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0];
    $t6Attainments = 0;

    for ($tick = 0; $tick < $durationTicks; $tick++) {
        $state = pickActivityState($activeRatio, $idleRatio);
        $multFp = (int)($activityMultiplier[$state] ?? 0);
        if ($multFp <= 0) {
            continue;
        }

        $effectiveGateFp = intdiv($gateFp * $multFp, FP_SCALE);
        $gateRoll = mt_rand(1, FP_SCALE);
        if ($gateRoll > $effectiveGateFp) {
            continue;
        }

        $progressFp = intdiv($tick * FP_SCALE, max(1, $durationTicks));
        $weights = interpolateTierWeights($progressFp);
        $tier = pickWeightedTier($weights);

        if (!applySigilAward($inv, $tier)) {
            continue;
        }

        if ($tier === 6) {
            $t6Attainments++;
            if ($consumeT6Immediately && $inv[6] > 0) {
                $inv[6] -= 1;
            }
        }

        $t6Attainments += runAutoCombines($inv, $consumeT6Immediately);
    }

    $results[] = $t6Attainments;
}

$mean = array_sum($results) / max(1, count($results));
$median = percentile($results, 50);
$p10 = percentile($results, 10);
$p90 = percentile($results, 90);
$min = min($results);
$max = max($results);

$output = [
    'params' => [
        'seasons' => $seasons,
        'duration_ticks' => $durationTicks,
        'gate_fp' => $gateFp,
        'active_ratio' => $activeRatio,
        'idle_ratio' => $idleRatio,
        'offline_ratio' => max(0.0, 1.0 - $activeRatio - $idleRatio),
        'consume_t6_immediately' => $consumeT6Immediately,
        'seed' => $seed,
    ],
    't6_attainments_per_season' => [
        'mean' => round($mean, 4),
        'median' => round($median, 4),
        'p10' => round($p10, 4),
        'p90' => round($p90, 4),
        'min' => (int)$min,
        'max' => (int)$max,
    ],
    'target_band' => [
        'min' => 3,
        'max' => 5,
        'in_band_median' => ($median >= 3.0 && $median <= 5.0),
    ],
];

echo json_encode($output, JSON_PRETTY_PRINT) . PHP_EOL;
