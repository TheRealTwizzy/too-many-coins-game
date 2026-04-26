<?php

require_once __DIR__ . '/ApprovalManifest.php';
require_once __DIR__ . '/FeaturePatchClassifier.php';

class ExperienceGate
{
    public static function evaluate(array $experimentBrief, ?array $approvalManifest = null, ?string $bundleHash = null): array
    {
        $changes = array_values((array)($experimentBrief['experience_changes'] ?? []));
        if ($changes === []) {
            return self::report('pass', [], []);
        }

        $issues = [];
        $paths = [];
        foreach ($changes as $index => $change) {
            $path = self::normalizePath((string)($change['path'] ?? ''));
            $paths[] = $path;

            if (empty($change['reversible'])) {
                $issues[] = self::issue('experience_changes[' . $index . '].reversible', 'experience_change_not_reversible', 'Experience changes must be reversible.');
            }

            if ((string)($change['economy_behavior'] ?? 'none') !== 'none') {
                $issues[] = self::issue('experience_changes[' . $index . '].economy_behavior', 'experience_change_alters_economy_behavior', 'QoL experience changes cannot alter economy behavior.');
            }

            if (count((array)($change['qa_evidence'] ?? [])) === 0) {
                $issues[] = self::issue('experience_changes[' . $index . '].qa_evidence', 'experience_change_missing_qa_evidence', 'Experience changes need at least one QA evidence label.');
            }
        }

        $classification = FeaturePatchClassifier::classifyPaths($paths);
        foreach ((array)$classification['paths'] as $row) {
            $class = (string)($row['class'] ?? 'unknown');
            if (in_array($class, ['runtime', 'database', 'deployment', 'unknown'], true)) {
                $issues[] = self::issue((string)$row['path'], 'experience_core_loop_path_requires_dual_lane', 'Capsule experience changes cannot touch runtime, database, deployment, or unknown paths.');
            }
        }

        if (self::hasIssue($issues, 'experience_change_not_reversible') || self::hasIssue($issues, 'experience_change_alters_economy_behavior') || self::hasIssue($issues, 'experience_change_missing_qa_evidence')) {
            return self::report('fail', $issues, $classification);
        }

        if (self::hasIssue($issues, 'experience_core_loop_path_requires_dual_lane')) {
            return self::report('split_to_dual_lane', $issues, $classification);
        }

        if ($approvalManifest === null) {
            foreach ((array)$classification['paths'] as $row) {
                $issues[] = self::issue((string)$row['path'], 'experience_path_requires_approval', 'Player-facing experience path needs approval before implementation.');
            }
            return self::report('pending_approval', $issues, $classification);
        }

        $approvalFailures = ApprovalManifest::validateForPaths(
            $approvalManifest,
            (string)($experimentBrief['mechanic_brief']['mechanic_id'] ?? ''),
            (string)$bundleHash,
            $classification
        );

        foreach ($approvalFailures as $failure) {
            $issues[] = $failure;
        }

        return self::report($issues === [] ? 'pass' : 'fail', $issues, $classification);
    }

    private static function report(string $status, array $issues, array $classification): array
    {
        return [
            'schema_version' => 'tmc-experience-gate-report.v1',
            'status' => $status,
            'issues' => array_values($issues),
            'classification' => $classification,
        ];
    }

    private static function issue(string $path, string $code, string $detail): array
    {
        return [
            'path' => $path,
            'reason_code' => $code,
            'reason_detail' => $detail,
        ];
    }

    private static function hasIssue(array $issues, string $code): bool
    {
        foreach ($issues as $issue) {
            if (($issue['reason_code'] ?? '') === $code) {
                return true;
            }
        }
        return false;
    }

    private static function normalizePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
}
