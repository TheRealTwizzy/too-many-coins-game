<?php

use PHPUnit\Framework\TestCase;

/**
 * Proves that the test bootstrap prevents config.php from binding DB
 * constants to inherited remote/tunneled environment variables.
 *
 * This is the verification layer for the fresh-run test DB isolation fix.
 * If this test fails, it means DB_HOST fell through to HOSTINGER_DB_HOST
 * or another platform alias instead of binding to the safe stub value.
 */
class TestConfigIsolationTest extends TestCase
{
    /**
     * Verify that DB_HOST is bound to the stub value set by tests/bootstrap.php,
     * proving that env_first() in config.php did NOT fall through to
     * HOSTINGER_DB_HOST or other remote/tunneled aliases.
     */
    public function testDbHostConstantIsSafeStub(): void
    {
        // Load config.php — if not already loaded by another test in this process,
        // it will define DB_HOST from the stub env var set by tests/bootstrap.php.
        require_once __DIR__ . '/../includes/config.php';

        // DB_HOST must be 'stub' (set by bootstrap) or an explicitly local value
        // passed via DB_HOST env var by the test runner. It must NEVER be a remote host.
        $remotePatterns = [
            'hstgr.cloud',
            'hostinger',
            'rds.amazonaws.com',
            'database.azure.com',
        ];

        foreach ($remotePatterns as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                defined('DB_HOST') ? DB_HOST : '',
                sprintf('DB_HOST constant contains remote host pattern "%s" — bootstrap isolation failed', $pattern)
            );
        }

        // The expected value is 'stub' from bootstrap.php, unless a test runner
        // explicitly set DB_HOST to a local address.
        $safeValues = ['stub', '127.0.0.1', 'localhost', '::1', ''];
        $this->assertContains(
            defined('DB_HOST') ? DB_HOST : '',
            $safeValues,
            sprintf(
                'DB_HOST constant is "%s" which is not a safe test value. '
                . 'Expected one of: %s. '
                . 'This means tests/bootstrap.php failed to prevent env fallthrough.',
                defined('DB_HOST') ? DB_HOST : '(undefined)',
                implode(', ', $safeValues)
            )
        );
    }

    /**
     * Prove that even when HOSTINGER_DB_HOST is set in the environment,
     * DB_HOST does not resolve to it (because DB_HOST env var was pre-set
     * by the bootstrap).
     */
    public function testHostingerEnvDoesNotLeakIntoDbConstants(): void
    {
        // The bootstrap pre-sets DB_HOST=stub via putenv(). config.php's
        // env_first() checks DB_HOST first, so HOSTINGER_DB_HOST is never
        // reached. We verify the constant is not a remote value.
        require_once __DIR__ . '/../includes/config.php';

        // Even if HOSTINGER_DB_HOST happens to be set in this process,
        // DB_HOST constant must not match it.
        $hostingerHost = getenv('HOSTINGER_DB_HOST');
        if ($hostingerHost !== false && $hostingerHost !== '' && $hostingerHost !== 'stub') {
            $this->assertNotSame(
                $hostingerHost,
                DB_HOST,
                'DB_HOST constant must not resolve to HOSTINGER_DB_HOST — bootstrap isolation failed'
            );
        } else {
            // HOSTINGER_DB_HOST is not set — still verify DB_HOST is safe
            $this->assertNotEmpty(DB_HOST, 'DB_HOST constant should be set by bootstrap');
            $this->assertSame('stub', DB_HOST,
                'DB_HOST should be "stub" when no explicit DB_HOST is provided');
        }
    }
}
