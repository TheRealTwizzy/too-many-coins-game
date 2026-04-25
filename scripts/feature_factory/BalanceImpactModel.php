<?php

class BalanceImpactModel
{
    public static function buildReport(array $brief): array
    {
        $expectations = (array)$brief['archetype_expectations'];
        $up = array_keys(array_filter($expectations, static fn($v) => $v === 'up'));
        $down = array_keys(array_filter($expectations, static fn($v) => $v === 'down'));
        $flat = array_keys(array_filter($expectations, static fn($v) => $v === 'flat'));
        $unknown = array_keys(array_filter($expectations, static fn($v) => $v === 'unknown'));

        return [
            'schema_version' => 'tmc-balance-impact-report.v1',
            'mechanic_id' => (string)$brief['mechanic_id'],
            'primary_strategy' => (string)$brief['primary_strategy'],
            'secondary_strategies' => array_values((array)$brief['secondary_strategies']),
            'counterplay' => array_values((array)$brief['counterplay']),
            'failure_modes' => array_values((array)$brief['failure_modes']),
            'required_metrics' => (array)$brief['required_metrics'],
            'archetype_expectations' => $expectations,
            'archetype_impact_summary' => [
                'up' => $up,
                'down' => $down,
                'flat' => $flat,
                'unknown' => $unknown,
            ],
            'risk_flags' => self::riskFlags($brief, $up, $down, $unknown),
            'optimizer_search_constraints' => self::optimizerSearchConstraints($brief),
        ];
    }

    private static function riskFlags(array $brief, array $up, array $down, array $unknown): array
    {
        $flags = [];
        $failureModes = array_fill_keys((array)$brief['failure_modes'], true);
        if (isset($failureModes['dominant_strategy_risk']) && count($up) <= 1) {
            $flags[] = [
                'risk' => 'single_strategy_overbuff',
                'severity' => 'major',
                'detail' => 'Primary declared risk plus one or fewer positively affected archetypes can create a dominant lane.',
            ];
        }
        if (isset($failureModes['hoarding_abuse']) && in_array('hoarder', $up, true)) {
            $flags[] = [
                'risk' => 'hoarder_positive_pressure',
                'severity' => 'critical',
                'detail' => 'Mechanic declares hoarding abuse risk while expecting hoarder impact to increase.',
            ];
        }
        if ($unknown !== []) {
            $flags[] = [
                'risk' => 'unknown_archetype_impact',
                'severity' => 'minor',
                'detail' => 'Some archetype impacts are unknown and need focused simulation interpretation.',
            ];
        }
        if ($down !== [] && count($down) >= 4) {
            $flags[] = [
                'risk' => 'broad_strategy_suppression',
                'severity' => 'major',
                'detail' => 'Four or more archetypes are expected to move down.',
            ];
        }
        return $flags;
    }

    private static function optimizerSearchConstraints(array $brief): array
    {
        $constraints = [];
        foreach ((array)$brief['config_key_status'] as $key => $status) {
            $constraints[] = [
                'key' => (string)$key,
                'status' => (string)$status,
                'optimizer_search_eligible' => $status === 'existing',
            ];
        }
        return $constraints;
    }
}
