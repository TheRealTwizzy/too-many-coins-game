<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/ParityLedger.php';

/**
 * Tests for ParityLedger (Milestone 4B).
 */
class ParityLedgerTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Empty/default ledger
    // -----------------------------------------------------------------------

    public function testBuildEmptyHasRequiredStructure(): void
    {
        $ledger = ParityLedger::buildEmpty('0.4.1-alpha', 'abc123', 42, 10);

        $this->assertArrayHasKey('schema', $ledger);
        $this->assertArrayHasKey('generated_at', $ledger);
        $this->assertArrayHasKey('run_context', $ledger);
        $this->assertArrayHasKey('total_issues', $ledger);
        $this->assertArrayHasKey('by_severity', $ledger);
        $this->assertArrayHasKey('by_classification', $ledger);
        $this->assertArrayHasKey('entries', $ledger);
    }

    public function testBuildEmptySchemaVersion(): void
    {
        $ledger = ParityLedger::buildEmpty();
        $this->assertSame('tmc-parity-ledger.v1', $ledger['schema']);
    }

    public function testBuildEmptyHasZeroIssues(): void
    {
        $ledger = ParityLedger::buildEmpty();
        $this->assertSame(0, $ledger['total_issues']);
        $this->assertEmpty($ledger['entries']);
    }

    public function testBuildEmptyRunContext(): void
    {
        $ledger = ParityLedger::buildEmpty('0.4.1-alpha', 'abc123', 42, 10);
        $ctx = $ledger['run_context'];

        $this->assertSame('0.4.1-alpha', $ctx['simulator_version']);
        $this->assertSame('abc123', $ctx['production_commit']);
        $this->assertSame(42, $ctx['seed']);
        $this->assertSame(10, $ctx['cohort_size']);
    }

    public function testBuildEmptySeverityCounts(): void
    {
        $ledger = ParityLedger::buildEmpty();
        $this->assertSame(0, $ledger['by_severity']['Critical']);
        $this->assertSame(0, $ledger['by_severity']['Major']);
        $this->assertSame(0, $ledger['by_severity']['Minor']);
    }

    public function testBuildEmptyClassificationCounts(): void
    {
        $ledger = ParityLedger::buildEmpty();
        $this->assertSame(0, $ledger['by_classification']['parity_bug']);
        $this->assertSame(0, $ledger['by_classification']['unmodeled_mechanic']);
    }

    // -----------------------------------------------------------------------
    // Adding entries
    // -----------------------------------------------------------------------

    public function testAddEntryAndBuildArtifact(): void
    {
        $ledger = new ParityLedger();
        $ledger->add([
            'parity_issue_id'   => 'TMC-SIM-001',
            'severity'          => ParityLedger::SEVERITY_MAJOR,
            'classification'    => ParityLedger::CLASS_PARITY_BUG,
            'expected_behavior' => 'Boost activation reflected in tick N+1.',
            'observed_behavior' => 'Boost activation is not modeled; no active_boosts created.',
            'mechanic_area'     => 'boost_activation',
            'owner'             => 'sim-team',
            'status'            => ParityLedger::STATUS_OPEN,
        ]);

        $artifact = $ledger->buildArtifact('0.4.1-alpha', 'abc123', 42, 10);

        $this->assertSame(1, $artifact['total_issues']);
        $this->assertSame(1, $artifact['by_severity']['Major']);
        $this->assertSame(1, $artifact['by_classification']['parity_bug']);
        $this->assertCount(1, $artifact['entries']);

        $entry = $artifact['entries'][0];
        $this->assertSame('TMC-SIM-001', $entry['parity_issue_id']);
        $this->assertSame('Major', $entry['severity']);
        $this->assertSame('parity_bug', $entry['classification']);
    }

    public function testAddedEntryIsNormalized(): void
    {
        $ledger = new ParityLedger();
        $ledger->add([
            'parity_issue_id'   => 'TMC-SIM-002',
            'severity'          => ParityLedger::SEVERITY_MINOR,
            'classification'    => ParityLedger::CLASS_UNMODELED,
            'expected_behavior' => 'Self-melt available.',
            'observed_behavior' => 'Self-melt not triggered.',
        ]);

        $entries = $ledger->getEntries();
        $this->assertCount(1, $entries);

        $entry = $entries[0];
        // All template fields should be present.
        $template = ParityLedger::templateEntry();
        foreach (array_keys($template) as $field) {
            $this->assertArrayHasKey($field, $entry, "Missing field after normalization: $field");
        }
        // Defaults applied.
        $this->assertSame(ParityLedger::STATUS_OPEN, $entry['status']);
        $this->assertNull($entry['linked_fix']);
    }

    // -----------------------------------------------------------------------
    // Template entry
    // -----------------------------------------------------------------------

    public function testTemplateEntryHasAllRequiredFields(): void
    {
        $template = ParityLedger::templateEntry();

        $requiredFields = [
            'parity_issue_id', 'discovered_at', 'simulator_version',
            'production_commit', 'seed', 'cohort_size', 'cohort_mix',
            'mechanic_area', 'expected_behavior', 'observed_behavior',
            'severity', 'classification', 'owner', 'status', 'linked_fix',
        ];

        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $template, "Template missing: $field");
        }
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    public function testValidateEntryPassesForCompleteEntry(): void
    {
        $errors = ParityLedger::validateEntry([
            'parity_issue_id'   => 'TMC-SIM-001',
            'severity'          => 'Major',
            'classification'    => 'parity_bug',
            'expected_behavior' => 'X',
            'observed_behavior' => 'Y',
        ]);
        $this->assertEmpty($errors);
    }

    public function testValidateEntryReportsMissingFields(): void
    {
        $errors = ParityLedger::validateEntry([]);
        $this->assertContains('parity_issue_id', $errors);
        $this->assertContains('severity', $errors);
        $this->assertContains('classification', $errors);
        $this->assertContains('expected_behavior', $errors);
        $this->assertContains('observed_behavior', $errors);
    }

    public function testValidateEntryRejectsInvalidSeverity(): void
    {
        $errors = ParityLedger::validateEntry([
            'parity_issue_id'   => 'X',
            'severity'          => 'NotASeverity',
            'classification'    => 'parity_bug',
            'expected_behavior' => 'X',
            'observed_behavior' => 'Y',
        ]);
        $this->assertNotEmpty($errors);
        $this->assertTrue(
            in_array('severity (invalid value)', $errors, true),
            'Should reject invalid severity'
        );
    }

    public function testValidateEntryRejectsInvalidClassification(): void
    {
        $errors = ParityLedger::validateEntry([
            'parity_issue_id'   => 'X',
            'severity'          => 'Minor',
            'classification'    => 'not_a_class',
            'expected_behavior' => 'X',
            'observed_behavior' => 'Y',
        ]);
        $this->assertNotEmpty($errors);
        $this->assertTrue(
            in_array('classification (invalid value)', $errors, true),
            'Should reject invalid classification'
        );
    }

    // -----------------------------------------------------------------------
    // Multiple entries
    // -----------------------------------------------------------------------

    public function testMultipleEntriesCountCorrectly(): void
    {
        $ledger = new ParityLedger();
        $ledger->add([
            'parity_issue_id' => 'A', 'severity' => 'Critical',
            'classification' => 'parity_bug', 'expected_behavior' => 'X', 'observed_behavior' => 'Y',
        ]);
        $ledger->add([
            'parity_issue_id' => 'B', 'severity' => 'Minor',
            'classification' => 'unmodeled_mechanic', 'expected_behavior' => 'X', 'observed_behavior' => 'Y',
        ]);
        $ledger->add([
            'parity_issue_id' => 'C', 'severity' => 'Major',
            'classification' => 'parity_bug', 'expected_behavior' => 'X', 'observed_behavior' => 'Y',
        ]);

        $artifact = $ledger->buildArtifact();
        $this->assertSame(3, $artifact['total_issues']);
        $this->assertSame(1, $artifact['by_severity']['Critical']);
        $this->assertSame(1, $artifact['by_severity']['Major']);
        $this->assertSame(1, $artifact['by_severity']['Minor']);
        $this->assertSame(2, $artifact['by_classification']['parity_bug']);
        $this->assertSame(1, $artifact['by_classification']['unmodeled_mechanic']);
    }
}
