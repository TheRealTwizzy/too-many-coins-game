<?php
/**
 * ParityLedger — structured parity-issue tracking for fresh-lifecycle runs.
 *
 * Milestone 4B: Provides the artifact format for recording discrepancies
 * between simulator behavior and production behavior.
 *
 * The ledger can be empty/defaulted on initial runs. The structure exists
 * so issues can be recorded as they are discovered during validation work.
 *
 * Each entry follows the schema defined in the plan:
 *   parity_issue_id, severity, classification, owner, status,
 *   linked_fix, expected_behavior, observed_behavior, plus discovery context.
 */

class ParityLedger
{
    /** Allowed severity levels. */
    public const SEVERITY_CRITICAL = 'Critical';
    public const SEVERITY_MAJOR    = 'Major';
    public const SEVERITY_MINOR    = 'Minor';

    /** Allowed classification values. */
    public const CLASS_PARITY_BUG       = 'parity_bug';
    public const CLASS_UNMODELED        = 'unmodeled_mechanic';

    /** Extended 5A bug-hunt classification values (beyond parity_bug / unmodeled_mechanic). */
    public const CLASS_SHARED_RUNTIME_BUG   = 'shared_runtime_bug';
    public const CLASS_SIMULATOR_ONLY_BUG   = 'simulator_only_bug';
    public const CLASS_ARTIFACT_ONLY_BUG    = 'artifact_only_bug';
    public const CLASS_PARITY_RISK_NO_FIX   = 'parity_risk_no_fix_yet';

    /** Allowed status values. */
    public const STATUS_OPEN       = 'open';
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_FIXED      = 'fixed';
    public const STATUS_WONTFIX    = 'wontfix';
    public const STATUS_DEFERRED   = 'deferred';

    /** @var array[] Ledger entries. */
    private array $entries = [];

    /**
     * Add a parity issue to the ledger.
     *
     * @param array $entry  Must contain at minimum: parity_issue_id, severity,
     *                      classification, expected_behavior, observed_behavior.
     *                      Optional: owner, status, linked_fix, discovered_at,
     *                      simulator_version, production_commit, seed,
     *                      cohort_size, cohort_mix, mechanic_area.
     * @return void
     */
    public function add(array $entry): void
    {
        $this->entries[] = self::normalize($entry);
    }

    /**
     * Get all ledger entries.
     *
     * @return array[]
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /**
     * Build the structured ledger artifact for inclusion in the run artifact.
     *
     * @param string|null $simulatorVersion
     * @param string|null $productionCommit
     * @param int|string|null $seed
     * @param int|null $cohortSize
     * @return array
     */
    public function buildArtifact(
        ?string $simulatorVersion = null,
        ?string $productionCommit = null,
        $seed = null,
        ?int $cohortSize = null
    ): array {
        return [
            'schema'            => 'tmc-parity-ledger.v1',
            'generated_at'      => gmdate('c'),
            'run_context'       => [
                'simulator_version'  => $simulatorVersion,
                'production_commit'  => $productionCommit,
                'seed'               => $seed,
                'cohort_size'        => $cohortSize,
            ],
            'total_issues'      => count($this->entries),
            'by_severity'       => self::countBySeverity($this->entries),
            'by_classification' => self::countByClassification($this->entries),
            'entries'           => $this->entries,
        ];
    }

    /**
     * Build a default empty ledger artifact (for runs with no known issues).
     *
     * @param string|null $simulatorVersion
     * @param string|null $productionCommit
     * @param int|string|null $seed
     * @param int|null $cohortSize
     * @return array
     */
    public static function buildEmpty(
        ?string $simulatorVersion = null,
        ?string $productionCommit = null,
        $seed = null,
        ?int $cohortSize = null
    ): array {
        $ledger = new self();
        return $ledger->buildArtifact($simulatorVersion, $productionCommit, $seed, $cohortSize);
    }

    /**
     * Create a template entry with all required fields defaulted.
     *
     * Useful for documentation or for pre-populating a new issue.
     *
     * @return array
     */
    public static function templateEntry(): array
    {
        return [
            'parity_issue_id'    => null,
            'discovered_at'      => null,
            'simulator_version'  => null,
            'production_commit'  => null,
            'seed'               => null,
            'cohort_size'        => null,
            'cohort_mix'         => null,
            'mechanic_area'      => null,
            'expected_behavior'  => null,
            'observed_behavior'  => null,
            'severity'           => null,
            'classification'     => null,
            'owner'              => null,
            'status'             => self::STATUS_OPEN,
            'linked_fix'         => null,
        ];
    }

    /**
     * Validate that an entry has the minimum required fields.
     *
     * @param array $entry
     * @return string[]  List of missing required fields (empty = valid).
     */
    public static function validateEntry(array $entry): array
    {
        $required = [
            'parity_issue_id',
            'severity',
            'classification',
            'expected_behavior',
            'observed_behavior',
        ];

        $missing = [];
        foreach ($required as $field) {
            if (!isset($entry[$field]) || $entry[$field] === null || $entry[$field] === '') {
                $missing[] = $field;
            }
        }

        if (isset($entry['severity']) && !in_array($entry['severity'], [
            self::SEVERITY_CRITICAL, self::SEVERITY_MAJOR, self::SEVERITY_MINOR,
        ], true)) {
            $missing[] = 'severity (invalid value)';
        }

        if (isset($entry['classification']) && !in_array($entry['classification'], [
            self::CLASS_PARITY_BUG, self::CLASS_UNMODELED,
            self::CLASS_SHARED_RUNTIME_BUG, self::CLASS_SIMULATOR_ONLY_BUG,
            self::CLASS_ARTIFACT_ONLY_BUG, self::CLASS_PARITY_RISK_NO_FIX,
        ], true)) {
            $missing[] = 'classification (invalid value)';
        }

        return $missing;
    }

    /**
     * Normalize an entry to ensure all fields exist with default values.
     */
    private static function normalize(array $entry): array
    {
        $template = self::templateEntry();
        return array_merge($template, $entry);
    }

    /**
     * Count entries by severity.
     */
    private static function countBySeverity(array $entries): array
    {
        $counts = [
            self::SEVERITY_CRITICAL => 0,
            self::SEVERITY_MAJOR    => 0,
            self::SEVERITY_MINOR    => 0,
        ];
        foreach ($entries as $e) {
            $sev = $e['severity'] ?? 'unknown';
            if (isset($counts[$sev])) {
                $counts[$sev]++;
            }
        }
        return $counts;
    }

    /**
     * Count entries by classification.
     */
    private static function countByClassification(array $entries): array
    {
        $counts = [
            self::CLASS_PARITY_BUG          => 0,
            self::CLASS_UNMODELED           => 0,
            self::CLASS_SHARED_RUNTIME_BUG  => 0,
            self::CLASS_SIMULATOR_ONLY_BUG  => 0,
            self::CLASS_ARTIFACT_ONLY_BUG   => 0,
            self::CLASS_PARITY_RISK_NO_FIX  => 0,
        ];
        foreach ($entries as $e) {
            $cls = $e['classification'] ?? 'unknown';
            if (isset($counts[$cls])) {
                $counts[$cls]++;
            }
        }
        return $counts;
    }
}
