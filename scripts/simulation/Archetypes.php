<?php

class Archetypes
{
    public static function all(): array
    {
        $all = [
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

        foreach ($all as $key => &$archetype) {
            $archetype['traits'] = self::traitsFor($key);
        }
        unset($archetype);

        return $all;
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

    private static function traitsFor(string $key): array
    {
        $map = [
            'casual' => [
                'discipline' => 0.35,
                'risk_tolerance' => 0.42,
                'patience' => 0.38,
                'late_conversion' => 0.34,
                'hoard_bias' => 0.36,
                'aggression' => 0.10,
                'boost_bias' => 0.18,
                'star_bias' => 0.32,
                'expiry_bias' => 0.22,
                'exit_by_phase' => ['MID' => 0.08, 'LATE_ACTIVE' => 0.12, 'BLACKOUT' => 0.16],
            ],
            'regular' => [
                'discipline' => 0.58,
                'risk_tolerance' => 0.48,
                'patience' => 0.46,
                'late_conversion' => 0.42,
                'hoard_bias' => 0.24,
                'aggression' => 0.18,
                'boost_bias' => 0.24,
                'star_bias' => 0.45,
                'expiry_bias' => 0.18,
                'exit_by_phase' => ['MID' => 0.10, 'LATE_ACTIVE' => 0.16, 'BLACKOUT' => 0.18],
            ],
            'hardcore' => [
                'discipline' => 0.82,
                'risk_tolerance' => 0.78,
                'patience' => 0.70,
                'late_conversion' => 0.54,
                'hoard_bias' => 0.16,
                'aggression' => 0.48,
                'boost_bias' => 0.42,
                'star_bias' => 0.48,
                'expiry_bias' => 0.34,
                'exit_by_phase' => ['MID' => 0.04, 'LATE_ACTIVE' => 0.12, 'BLACKOUT' => 0.14],
            ],
            'hoarder' => [
                'discipline' => 0.72,
                'risk_tolerance' => 0.32,
                'patience' => 0.68,
                'late_conversion' => 0.62,
                'hoard_bias' => 0.88,
                'aggression' => 0.06,
                'boost_bias' => 0.14,
                'star_bias' => 0.18,
                'expiry_bias' => 0.26,
                'exit_by_phase' => ['MID' => 0.02, 'LATE_ACTIVE' => 0.08, 'BLACKOUT' => 0.12],
            ],
            'early_locker' => [
                'discipline' => 0.68,
                'risk_tolerance' => 0.20,
                'patience' => 0.22,
                'late_conversion' => 0.20,
                'hoard_bias' => 0.22,
                'aggression' => 0.12,
                'boost_bias' => 0.18,
                'star_bias' => 0.40,
                'expiry_bias' => 0.08,
                'exit_by_phase' => ['MID' => 0.26, 'LATE_ACTIVE' => 0.12, 'BLACKOUT' => 0.08],
            ],
            'late_deployer' => [
                'discipline' => 0.64,
                'risk_tolerance' => 0.66,
                'patience' => 0.80,
                'late_conversion' => 0.92,
                'hoard_bias' => 0.42,
                'aggression' => 0.22,
                'boost_bias' => 0.34,
                'star_bias' => 0.62,
                'expiry_bias' => 0.34,
                'exit_by_phase' => ['MID' => 0.01, 'LATE_ACTIVE' => 0.08, 'BLACKOUT' => 0.18],
            ],
            'boost_focused' => [
                'discipline' => 0.70,
                'risk_tolerance' => 0.60,
                'patience' => 0.58,
                'late_conversion' => 0.58,
                'hoard_bias' => 0.24,
                'aggression' => 0.18,
                'boost_bias' => 0.94,
                'star_bias' => 0.30,
                'expiry_bias' => 0.24,
                'exit_by_phase' => ['MID' => 0.04, 'LATE_ACTIVE' => 0.10, 'BLACKOUT' => 0.16],
            ],
            'star_focused' => [
                'discipline' => 0.66,
                'risk_tolerance' => 0.46,
                'patience' => 0.44,
                'late_conversion' => 0.46,
                'hoard_bias' => 0.12,
                'aggression' => 0.08,
                'boost_bias' => 0.14,
                'star_bias' => 0.95,
                'expiry_bias' => 0.18,
                'exit_by_phase' => ['MID' => 0.08, 'LATE_ACTIVE' => 0.16, 'BLACKOUT' => 0.18],
            ],
            'aggressive_sigil_user' => [
                'discipline' => 0.60,
                'risk_tolerance' => 0.72,
                'patience' => 0.42,
                'late_conversion' => 0.50,
                'hoard_bias' => 0.18,
                'aggression' => 0.98,
                'boost_bias' => 0.30,
                'star_bias' => 0.28,
                'expiry_bias' => 0.20,
                'exit_by_phase' => ['MID' => 0.03, 'LATE_ACTIVE' => 0.10, 'BLACKOUT' => 0.14],
            ],
            'mostly_idle' => [
                'discipline' => 0.20,
                'risk_tolerance' => 0.28,
                'patience' => 0.30,
                'late_conversion' => 0.24,
                'hoard_bias' => 0.50,
                'aggression' => 0.04,
                'boost_bias' => 0.08,
                'star_bias' => 0.16,
                'expiry_bias' => 0.30,
                'exit_by_phase' => ['MID' => 0.12, 'LATE_ACTIVE' => 0.10, 'BLACKOUT' => 0.18],
            ],
        ];

        return $map[$key] ?? [
            'discipline' => 0.50,
            'risk_tolerance' => 0.50,
            'patience' => 0.50,
            'late_conversion' => 0.50,
            'hoard_bias' => 0.25,
            'aggression' => 0.10,
            'boost_bias' => 0.20,
            'star_bias' => 0.40,
            'expiry_bias' => 0.20,
            'exit_by_phase' => ['MID' => 0.05, 'LATE_ACTIVE' => 0.10, 'BLACKOUT' => 0.16],
        ];
    }
}
