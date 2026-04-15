<?php

class SweepComparatorProfileCatalog
{
    public const PROFILE_SCHEMA_VERSION = 'tmc-sweep-comparator-profile.v1';

    public static function all(): array
    {
        $followupBundle = self::defaultFollowupBundlePath();

        return [
            'qualification' => [
                'schema_version' => self::PROFILE_SCHEMA_VERSION,
                'id' => 'qualification',
                'label' => 'Qualification Comparator',
                'description' => 'Reduced-cost sweep/comparator profile that still exercises baseline pairing, cross-simulator rejection, and rejection-attribution artifact generation.',
                'players_per_archetype' => 2,
                'season_count' => 4,
                'simulators' => ['B', 'C'],
                'include_baseline' => true,
                'scenario_names' => [
                    'phase-gated-safe-24h-v1',
                ],
                'tuning_candidates_path' => $followupBundle,
                'expected_completion_envelope' => [
                    'min_minutes' => 2.5,
                    'max_minutes' => 4.0,
                    'basis' => 'Measured on 2026-04-15 in-repo using the fixed follow-up bundle and the default canonical simulator season.',
                ],
            ],
            'full-campaign' => [
                'schema_version' => self::PROFILE_SCHEMA_VERSION,
                'id' => 'full-campaign',
                'label' => 'Full Follow-up Campaign',
                'description' => 'Full follow-up scenario bundle for campaign-level review across both simulators with the same reproducible baseline.',
                'players_per_archetype' => 2,
                'season_count' => 4,
                'simulators' => ['B', 'C'],
                'include_baseline' => true,
                'scenario_names' => [
                    'phase-gated-safe-24h-v1',
                    'phase-gated-safe-48h-v1',
                    'phase-gated-high-floor-v1',
                    'phase-gated-plus-inflation-tighten-v1',
                ],
                'tuning_candidates_path' => $followupBundle,
                'expected_completion_envelope' => [
                    'min_minutes' => 6.5,
                    'max_minutes' => 9.0,
                    'basis' => 'Measured on 2026-04-15 in-repo using the fixed follow-up bundle and the default canonical simulator season.',
                ],
            ],
        ];
    }

    public static function resolve(string $profileId): array
    {
        $profiles = self::all();
        if (!isset($profiles[$profileId])) {
            throw new InvalidArgumentException('Unknown sweep/comparator profile: ' . $profileId);
        }

        return $profiles[$profileId];
    }

    public static function ids(): array
    {
        return array_keys(self::all());
    }

    private static function defaultFollowupBundlePath(): string
    {
        return __DIR__ . '/../../simulation_output/sweep/followup-tuning-candidates-20260413.json';
    }
}
