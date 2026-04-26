<?php

require_once __DIR__ . '/FeatureFactoryException.php';
require_once __DIR__ . '/MechanicBrief.php';

class ExperimentBrief
{
    private const MODES = ['capsule', 'dual_lane'];
    private const CHANGE_INTENTS = ['mechanic_addition', 'removal', 'disable', 'nerf', 'rebalance', 'qol', 'combined'];
    private const MECHANIC_PROPOSAL_FIELDS = [
        'mechanic_id',
        'title',
        'summary',
        'player_fantasy',
        'affected_systems',
        'primary_strategy',
        'secondary_strategies',
        'counterplay',
        'failure_modes',
        'tunable_parameters',
        'proposed_new_config_keys',
        'required_metrics',
        'archetype_expectations',
    ];

    public static function fromProposal(array $proposal): array
    {
        $failures = self::validateExperimentFields($proposal);
        $mechanicFailures = MechanicBrief::validate($proposal);
        foreach ($mechanicFailures as $failure) {
            $failure['path'] = 'mechanic.' . (string)($failure['path'] ?? 'unknown');
            $failures[] = $failure;
        }

        if ($failures !== []) {
            throw new FeatureFactoryException('Experiment brief validation failed', $failures);
        }

        $mechanicProposal = self::mechanicProposal($proposal);
        $mechanicBrief = MechanicBrief::fromProposal($mechanicProposal);
        $experienceChanges = self::normalizeExperienceChanges((array)($proposal['experience_changes'] ?? []));
        $modeDecisionReasons = self::modeDecisionReasons((string)($proposal['mode_preference'] ?? 'capsule'), $experienceChanges);

        return [
            'schema_version' => 'tmc-experiment-brief.v1',
            'experiment_id' => self::cleanString($proposal['experiment_id'] ?? $proposal['mechanic_id']),
            'mode_preference' => self::cleanString($proposal['mode_preference'] ?? 'capsule'),
            'recommended_mode' => $modeDecisionReasons === [] ? self::cleanString($proposal['mode_preference'] ?? 'capsule') : 'dual_lane',
            'mode_decision_reasons' => $modeDecisionReasons,
            'change_intent' => self::cleanString($proposal['change_intent'] ?? 'mechanic_addition'),
            'economy_hypothesis' => self::cleanString($proposal['economy_hypothesis']),
            'player_facing_intent' => self::cleanString($proposal['player_facing_intent'] ?? ''),
            'rollback_notes' => self::cleanString($proposal['rollback_notes']),
            'removal_target' => self::normalizeRemovalTarget((array)($proposal['removal_target'] ?? [])),
            'experience_changes' => $experienceChanges,
            'mechanic_proposal' => $mechanicProposal,
            'mechanic_brief' => $mechanicBrief,
        ];
    }

    private static function validateExperimentFields(array $proposal): array
    {
        $failures = [];
        $experimentId = self::cleanString($proposal['experiment_id'] ?? $proposal['mechanic_id'] ?? '');
        if ($experimentId === '' || !preg_match('/^[a-z][a-z0-9_]{2,79}$/', $experimentId)) {
            $failures[] = self::failure('experiment_id', 'invalid_experiment_id', 'Use lowercase snake_case, 3-80 characters.');
        }

        $mode = self::cleanString($proposal['mode_preference'] ?? 'capsule');
        if (!in_array($mode, self::MODES, true)) {
            $failures[] = self::failure('mode_preference', 'invalid_mode_preference', 'Mode preference must be capsule or dual_lane.');
        }

        $intent = self::cleanString($proposal['change_intent'] ?? 'mechanic_addition');
        if (!in_array($intent, self::CHANGE_INTENTS, true)) {
            $failures[] = self::failure('change_intent', 'invalid_change_intent', 'Change intent is not supported by the hybrid workflow.');
        }

        if (self::cleanString($proposal['economy_hypothesis'] ?? '') === '') {
            $failures[] = self::failure('economy_hypothesis', 'missing_economy_hypothesis', 'Every experiment needs an economy hypothesis.');
        }

        if (self::cleanString($proposal['rollback_notes'] ?? '') === '') {
            $failures[] = self::failure('rollback_notes', 'missing_rollback_notes', 'Every experiment needs rollback notes.');
        }

        if (in_array($intent, ['removal', 'disable', 'nerf'], true)) {
            $target = (array)($proposal['removal_target'] ?? []);
            if (self::cleanString($target['name'] ?? '') === '') {
                $failures[] = self::failure('removal_target.name', 'missing_removal_target', 'Removal, disable, and nerf experiments need a target name.');
            }
            if (self::cleanString($target['suspected_harm'] ?? '') === '') {
                $failures[] = self::failure('removal_target.suspected_harm', 'missing_removal_harm', 'Removal, disable, and nerf experiments need suspected harm.');
            }
        }

        foreach ((array)($proposal['experience_changes'] ?? []) as $index => $change) {
            if (!is_array($change)) {
                $failures[] = self::failure('experience_changes[' . $index . ']', 'invalid_experience_change', 'Experience change must be an object.');
                continue;
            }
            if (self::cleanString($change['path'] ?? '') === '') {
                $failures[] = self::failure('experience_changes[' . $index . '].path', 'missing_experience_path', 'Experience change path is required.');
            }
            if (self::cleanString($change['summary'] ?? '') === '') {
                $failures[] = self::failure('experience_changes[' . $index . '].summary', 'missing_experience_summary', 'Experience change summary is required.');
            }
        }

        return $failures;
    }

    private static function mechanicProposal(array $proposal): array
    {
        $mechanicProposal = [];
        foreach (self::MECHANIC_PROPOSAL_FIELDS as $field) {
            if (array_key_exists($field, $proposal)) {
                $mechanicProposal[$field] = $proposal[$field];
            }
        }
        return $mechanicProposal;
    }

    private static function normalizeExperienceChanges(array $changes): array
    {
        $normalized = [];
        foreach ($changes as $change) {
            $normalized[] = [
                'path' => self::normalizePath((string)($change['path'] ?? '')),
                'change_type' => self::cleanString($change['change_type'] ?? 'quality_of_life'),
                'summary' => self::cleanString($change['summary'] ?? ''),
                'economy_behavior' => self::cleanString($change['economy_behavior'] ?? 'none'),
                'reversible' => (bool)($change['reversible'] ?? false),
                'qa_evidence' => array_values((array)($change['qa_evidence'] ?? [])),
            ];
        }
        return $normalized;
    }

    private static function normalizeRemovalTarget(array $target): array
    {
        if ($target === []) {
            return [];
        }

        return [
            'name' => self::cleanString($target['name'] ?? ''),
            'target_type' => self::cleanString($target['target_type'] ?? 'mechanic'),
            'suspected_harm' => self::cleanString($target['suspected_harm'] ?? ''),
            'replacement_behavior' => self::cleanString($target['replacement_behavior'] ?? ''),
        ];
    }

    private static function modeDecisionReasons(string $modePreference, array $experienceChanges): array
    {
        $reasons = [];
        if ($modePreference === 'capsule' && count($experienceChanges) > 3) {
            $reasons[] = self::failure('experience_changes', 'too_many_experience_changes_for_capsule', 'Capsule mode allows at most three tiny experience changes.');
        }
        return $reasons;
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

    private static function normalizePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
}
