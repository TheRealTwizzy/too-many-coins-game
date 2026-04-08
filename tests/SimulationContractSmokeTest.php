<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/SimulationSeason.php';
require_once __DIR__ . '/../scripts/simulation/MetricsCollector.php';
require_once __DIR__ . '/../scripts/simulation/ContractSimulator.php';

class SimulationContractSmokeTest extends TestCase
{
    public function testContractSimulatorReturnsPassingContractSet(): void
    {
        $payload = ContractSimulator::run('test-seed');

        $this->assertSame('tmc-sim-phase1.v1', $payload['schema_version']);
        $this->assertSame('contract-simulator', $payload['simulator']);
        $this->assertTrue((bool)$payload['summary']['all_passed']);
        $this->assertNotEmpty($payload['checks']);
    }
}
