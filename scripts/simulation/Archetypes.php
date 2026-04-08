<?php

class Archetypes
{
    public static function all(): array
    {
        return [
            'casual' => [
                'label' => 'Casual',
                'phases' => [
                    'EARLY' => self::phase(0.16, 0.64, 0.20, 0.010, 1, 0.005, 1, 0.10, 0.00, 0.00, 0.00),
                    'MID' => self::phase(0.18, 0.60, 0.22, 0.014, 1, 0.006, 1, 0.16, 0.00, 0.00, 0.00),
                    'LATE_ACTIVE' => self::phase(0.22, 0.55, 0.23, 0.018, 1, 0.008, 2, 0.20, 0.18, 0.00, 0.00),
                    'BLACKOUT' => self::phase(0.20, 0.58, 0.22, 0.022, 1, 0.000, 0, 0.00, 0.35, 0.00, 0.00),
                ],
                'coin_reserve_ratio' => 0.45,
            ],
            'regular' => [
                'label' => 'Regular',
                'phases' => [
                    'EARLY' => self::phase(0.42, 0.44, 0.14, 0.022, 1, 0.012, 1, 0.18, 0.00, 0.00, 0.00),
                    'MID' => self::phase(0.46, 0.40, 0.14, 0.028, 2, 0.015, 2, 0.24, 0.00, 0.01, 0.00),
                    'LATE_ACTIVE' => self::phase(0.50, 0.35, 0.15, 0.032, 2, 0.018, 3, 0.30, 0.22, 0.01, 0.00),
                    'BLACKOUT' => self::phase(0.48, 0.36, 0.16, 0.040, 2, 0.008, 2, 0.00, 0.42, 0.00, 0.00),
                ],
                'coin_reserve_ratio' => 0.30,
            ],
            'hardcore' => [
                'label' => 'Hardcore',
                'phases' => [
                    'EARLY' => self::phase(0.82, 0.14, 0.04, 0.045, 2, 0.020, 2, 0.40, 0.00, 0.02, 0.00),
                    'MID' => self::phase(0.84, 0.12, 0.04, 0.055, 3, 0.024, 3, 0.50, 0.00, 0.03, 0.01),
                    'LATE_ACTIVE' => self::phase(0.86, 0.10, 0.04, 0.060, 3, 0.026, 4, 0.62, 0.28, 0.05, 0.02),
                    'BLACKOUT' => self::phase(0.80, 0.14, 0.06, 0.068, 3, 0.018, 4, 0.00, 0.55, 0.04, 0.02),
                ],
                'coin_reserve_ratio' => 0.18,
            ],
            'hoarder' => [
                'label' => 'Hoarder',
                'phases' => [
                    'EARLY' => self::phase(0.62, 0.28, 0.10, 0.004, 1, 0.002, 1, 0.08, 0.00, 0.00, 0.00),
                    'MID' => self::phase(0.60, 0.30, 0.10, 0.006, 1, 0.003, 1, 0.10, 0.00, 0.00, 0.00),
                    'LATE_ACTIVE' => self::phase(0.58, 0.30, 0.12, 0.008, 1, 0.004, 2, 0.12, 0.05, 0.00, 0.00),
                    'BLACKOUT' => self::phase(0.54, 0.32, 0.14, 0.010, 1, 0.000, 0, 0.00, 0.12, 0.00, 0.00),
                ],
                'coin_reserve_ratio' => 0.70,
            ],
            'early_locker' => [
                'label' => 'Early Locker',
                'phases' => [
                    'EARLY' => self::phase(0.52, 0.34, 0.14, 0.024, 2, 0.012, 2, 0.24, 0.00, 0.00, 0.00),
                    'MID' => self::phase(0.56, 0.30, 0.14, 0.028, 2, 0.015, 2, 0.30, 0.00, 0.01, 0.00),
                    'LATE_ACTIVE' => self::phase(0.58, 0.26, 0.16, 0.034, 2, 0.012, 2, 0.26, 0.75, 0.00, 0.00),
                    'BLACKOUT' => self::phase(0.46, 0.36, 0.18, 0.030, 2, 0.004, 1, 0.00, 0.20, 0.00, 0.00),
                ],
                'coin_reserve_ratio' => 0.24,
            ],
            'late_deployer' => [
                'label' => 'Late Deployer',
                'phases' => [
                    'EARLY' => self::phase(0.20, 0.56, 0.24, 0.006, 1, 0.002, 1, 0.10, 0.00, 0.00, 0.00),
                    'MID' => self::phase(0.28, 0.50, 0.22, 0.010, 1, 0.004, 1, 0.12, 0.00, 0.00, 0.00),
                    'LATE_ACTIVE' => self::phase(0.52, 0.34, 0.14, 0.040, 3, 0.026, 4, 0.34, 0.08, 0.02, 0.01),
                    'BLACKOUT' => self::phase(0.68, 0.22, 0.10, 0.060, 3, 0.014, 3, 0.00, 0.62, 0.04, 0.02),
                ],
                'coin_reserve_ratio' => 0.16,
            ],
            'boost_focused' => [
                'label' => 'Boost-Focused',
                'phases' => [
                    'EARLY' => self::phase(0.70, 0.22, 0.08, 0.016, 1, 0.030, 3, 0.18, 0.00, 0.00, 0.00),
                    'MID' => self::phase(0.72, 0.20, 0.08, 0.022, 2, 0.034, 4, 0.22, 0.00, 0.01, 0.00),
                    'LATE_ACTIVE' => self::phase(0.74, 0.18, 0.08, 0.028, 2, 0.040, 5, 0.26, 0.18, 0.02, 0.00),
                    'BLACKOUT' => self::phase(0.68, 0.22, 0.10, 0.030, 2, 0.010, 3, 0.00, 0.44, 0.01, 0.00),
                ],
                'coin_reserve_ratio' => 0.22,
            ],
            'star_focused' => [
                'label' => 'Star-Focused',
                'phases' => [
                    'EARLY' => self::phase(0.56, 0.30, 0.14, 0.050, 2, 0.008, 1, 0.18, 0.00, 0.00, 0.00),
                    'MID' => self::phase(0.58, 0.28, 0.14, 0.060, 3, 0.010, 1, 0.22, 0.00, 0.00, 0.00),
                    'LATE_ACTIVE' => self::phase(0.60, 0.24, 0.16, 0.070, 3, 0.010, 2, 0.26, 0.26, 0.00, 0.00),
                    'BLACKOUT' => self::phase(0.58, 0.26, 0.16, 0.080, 3, 0.004, 1, 0.00, 0.48, 0.00, 0.00),
                ],
                'coin_reserve_ratio' => 0.12,
            ],
            'aggressive_sigil_user' => [
                'label' => 'Aggressive Sigil User',
                'phases' => [
                    'EARLY' => self::phase(0.64, 0.24, 0.12, 0.014, 1, 0.010, 2, 0.44, 0.00, 0.01, 0.00),
                    'MID' => self::phase(0.66, 0.22, 0.12, 0.020, 2, 0.014, 2, 0.60, 0.00, 0.04, 0.02),
                    'LATE_ACTIVE' => self::phase(0.68, 0.20, 0.12, 0.024, 2, 0.018, 3, 0.72, 0.16, 0.08, 0.05),
                    'BLACKOUT' => self::phase(0.66, 0.20, 0.14, 0.022, 2, 0.010, 2, 0.00, 0.34, 0.10, 0.06),
                ],
                'coin_reserve_ratio' => 0.26,
            ],
            'mostly_idle' => [
                'label' => 'Mostly Idle',
                'phases' => [
                    'EARLY' => self::phase(0.06, 0.72, 0.22, 0.004, 1, 0.001, 1, 0.05, 0.00, 0.00, 0.00),
                    'MID' => self::phase(0.07, 0.70, 0.23, 0.006, 1, 0.001, 1, 0.08, 0.00, 0.00, 0.00),
                    'LATE_ACTIVE' => self::phase(0.08, 0.68, 0.24, 0.008, 1, 0.002, 1, 0.10, 0.04, 0.00, 0.00),
                    'BLACKOUT' => self::phase(0.06, 0.70, 0.24, 0.010, 1, 0.000, 0, 0.00, 0.10, 0.00, 0.00),
                ],
                'coin_reserve_ratio' => 0.55,
            ],
        ];
    }

    public static function get(string $key): array
    {
        $all = self::all();
        if (!isset($all[$key])) {
            throw new InvalidArgumentException('Unknown archetype: ' . $key);
        }
        return $all[$key];
    }

    private static function phase(
        float $active,
        float $idle,
        float $offline,
        float $buyStars,
        int $starsPerBuy,
        float $buyBoost,
        int $boostTier,
        float $combine,
        float $lockIn,
        float $freeze,
        float $theft
    ): array {
        return [
            'presence' => [
                'Active' => $active,
                'Idle' => $idle,
                'Offline' => $offline,
            ],
            'buy_stars_probability' => $buyStars,
            'stars_per_purchase' => $starsPerBuy,
            'buy_boost_probability' => $buyBoost,
            'preferred_boost_tier' => $boostTier,
            'combine_probability' => $combine,
            'lock_in_probability' => $lockIn,
            'freeze_probability' => $freeze,
            'theft_probability' => $theft,
        ];
    }
}
