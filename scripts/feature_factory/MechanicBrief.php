<?php

require_once __DIR__ . '/FeatureFactoryException.php';
require_once __DIR__ . '/../simulation/CanonicalEconomyConfigContract.php';

class MechanicBrief
{
    private const REQUIRED_ARCHETYPES = [
        'hoarder',
        'mostly_idle',
        'regular',
        'hardcore',
        'boost_focused',
        'star_focused',
        'early_locker',
        'late_deployer',
        'casual',
        'aggressive_sigil_user',
    ];

    private const REQUIRED_METRIC_FAMILIES = [
        'viability',
        'concentration_or_diversity',
        'mechanic_specific',
    ];

    public static function fromProposal(array $proposal): array
    {
        $failures = self::validate($proposal);
        if ($failures !== []) {
            throw new FeatureFactoryException('Mechanic brief validation failed', $failures);
        }

        $brief = [
            'schema_version' => 'tmc-mechanic-brief.v1',
            'mechanic_id' => self::cleanString($proposal['mechanic_id']),
            'title' => self::cleanString($proposal['title']),
            'summary' => self::cleanString($proposal['summary']),
            'player_fantasy' => self::cleanString($proposal['player_fantasy']),
            'affected_systems' => array_values((array)$proposal['affected_systems']),
            'primary_strategy' => self::cleanString($proposal['primary_strategy']),
            'secondary_strategies' => array_values((array)$proposal['secondary_strategies']),
            'counterplay' => array_values((array)$proposal['counterplay']),
            'failure_modes' => array_values((array)$proposal['failure_modes']),
            'tunable_parameters' => self::normalizeTunableParameters((array)$proposal['tunable_parameters']),
            'proposed_new_config_keys' => self::normalizeProposedKeys((array)($proposal['proposed_new_config_keys'] ?? [])),
            'required_metrics' => [
                'viability' => array_values((array)$proposal['required_metrics']['viability']),
                'concentration_or_diversity' => array_values((array)$proposal['required_metrics']['concentration_or_diversity']),
                'mechanic_specific' => array_values((array)$proposal['required_metrics']['mechanic_specific']),
            ],
            'archetype_expectations' => (array)$proposal['archetype_expectations'],
            'approval_required_for_player_facing_paths' => true,
        ];

        $brief['config_key_status'] = self::configKeyStatus($brief);

        return $brief;
    }

    public static function validate(array $proposal): array
    {
        $failures = [];
        foreach ([
            'mechanic_id',
            'title',
            'summary',
            'player_fantasy',
            'primary_strategy',
        ] as $field) {
            if (self::cleanString($proposal[$field] ?? '') === '') {
                $failures[] = self::failure($field, 'missing_required_field', 'Required field is missing or empty.');
            }
        }

        if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/', (string)($proposal['mechanic_id'] ?? ''))) {
            $failures[] = self::failure('mechanic_id', 'invalid_mechanic_id', 'Use lowercase snake_case, 3-64 characters.');
        }

        foreach (['affected_systems', 'secondary_strategies', 'failure_modes', 'tunable_parameters'] as $field) {
            if (count((array)($proposal[$field] ?? [])) === 0) {
                $failures[] = self::failure($field, 'missing_required_list', 'Required list must contain at least one item.');
            }
        }

        if (count((array)($proposal['counterplay'] ?? [])) === 0) {
            $failures[] = self::failure('counterplay', 'missing_counterplay', 'At least one counterplay entry is required.');
        }

        $metrics = (array)($proposal['required_metrics'] ?? []);
        foreach (self::REQUIRED_METRIC_FAMILIES as $family) {
            if (count((array)($metrics[$family] ?? [])) === 0) {
                $failures[] = self::failure('required_metrics.' . $family, 'missing_metric_family', 'Required metric family must contain at least one metric.');
            }
        }

        $expectations = (array)($proposal['archetype_expectations'] ?? []);
        foreach (self::REQUIRED_ARCHETYPES as $archetype) {
            if (!array_key_exists($archetype, $expectations)) {
                $failures[] = self::failure('archetype_expectations.' . $archetype, 'missing_archetype_expectation', 'Every existing archetype needs an expected impact.');
                continue;
            }
            if (!in_array($expectations[$archetype], ['up', 'down', 'flat', 'unknown'], true)) {
                $failures[] = self::failure('archetype_expectations.' . $archetype, 'invalid_archetype_expectation', 'Impact must be up, down, flat, or unknown.');
            }
        }

        foreach ((array)($proposal['tunable_parameters'] ?? []) as $index => $parameter) {
            if (!is_array($parameter)) {
                $failures[] = self::failure('tunable_parameters[' . $index . ']', 'invalid_tunable_parameter', 'Tunable parameter must be an object.');
                continue;
            }
            if (self::cleanString($parameter['key'] ?? '') === '') {
                $failures[] = self::failure('tunable_parameters[' . $index . '].key', 'missing_tunable_key', 'Tunable parameter key is required.');
            }
            if (!in_array((string)($parameter['kind'] ?? ''), ['existing', 'proposed'], true)) {
                $failures[] = self::failure('tunable_parameters[' . $index . '].kind', 'invalid_tunable_kind', 'Tunable kind must be existing or proposed.');
            }
        }

        return $failures;
    }

    private static function normalizeTunableParameters(array $parameters): array
    {
        $normalized = [];
        foreach ($parameters as $parameter) {
            $normalized[] = [
                'key' => self::cleanString($parameter['key'] ?? ''),
                'kind' => self::cleanString($parameter['kind'] ?? ''),
                'proposed_value' => $parameter['proposed_value'] ?? null,
                'reason' => self::cleanString($parameter['reason'] ?? ''),
            ];
        }
        return $normalized;
    }

    private static function normalizeProposedKeys(array $keys): array
    {
        $normalized = [];
        foreach ($keys as $key) {
            $normalized[] = [
                'key' => self::cleanString($key['key'] ?? ''),
                'type' => self::cleanString($key['type'] ?? ''),
                'reason' => self::cleanString($key['reason'] ?? ''),
                'optimizer_search_eligible' => false,
            ];
        }
        return $normalized;
    }

    private static function configKeyStatus(array $brief): array
    {
        $surface = CanonicalEconomyConfigContract::validatorSurfaceMeta();
        $status = [];
        foreach ((array)$brief['tunable_parameters'] as $parameter) {
            $key = (string)$parameter['key'];
            $status[$key] = isset($surface[$key]) ? 'existing' : 'proposed';
        }
        foreach ((array)$brief['proposed_new_config_keys'] as $key) {
            $status[(string)$key['key']] = 'proposed';
        }
        ksort($status);
        return $status;
    }

    private static function failure(string $path, string $code, string $detail): array
    {
        return [
            'path' => $path,
            'reason_code' => $code,
            'reason_detail' => $detail,
        ];
    }

    private static function cleanString(mixed $value): string
    {
        return trim((string)$value);
    }
}
