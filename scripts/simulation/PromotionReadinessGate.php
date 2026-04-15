<?php

class PromotionReadinessGate
{
    public const QUALIFICATION_COMPARATOR_REQUIRED_DISPOSITION = 'non-reject';

    public static function evaluateQualificationComparatorScenario(array $scenarioReport): array
    {
        $regressionFlags = array_values((array)($scenarioReport['regression_flags'] ?? []));
        $actualDisposition = (string)($scenarioReport['recommended_disposition'] ?? 'unknown');

        return [
            'passes' => ($actualDisposition !== 'reject' && $regressionFlags === []),
            'required_disposition' => self::QUALIFICATION_COMPARATOR_REQUIRED_DISPOSITION,
            'actual_disposition' => $actualDisposition,
            'wins' => (int)($scenarioReport['wins'] ?? 0),
            'losses' => (int)($scenarioReport['losses'] ?? 0),
            'mixed_tradeoffs' => (int)($scenarioReport['mixed_tradeoffs'] ?? 0),
            'regression_flags' => $regressionFlags,
            'cross_simulator_regression_flags' => array_values((array)($scenarioReport['cross_simulator_regression_flags'] ?? [])),
            'rejection_attribution' => (array)($scenarioReport['rejection_attribution'] ?? []),
        ];
    }
}
