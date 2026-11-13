#!/usr/bin/env php
<?php
/**
 * Too Many Coins - Operator CLI
 *
 *   php tools/admin.php            (help: every command and what it does)
 *
 * Two things cannot be done from inside the game, and both previously meant
 * hand-writing SQL in a container with no mysql client:
 *
 *   - The first Admin. admin_role_update requires an existing Admin, so the
 *     first one has to be made from outside.
 *   - Break-glass on the maintenance gate, for when no Admin can sign in.
 *
 * Hand-written SQL for those is not just awkward, it is silent: an UPDATE
 * matching zero rows reports success, and nothing lands in the audit log. Every
 * command that writes reports what actually changed and records an audit row
 * with a NULL actor, which is the honest representation - a change made by
 * whoever had shell access, not by a player.
 *
 * Prefer the in-game staff screen once an Admin exists. It does the same work
 * with a real actor attached.
 *
 * This file only dispatches. Each command lives in tools/admin/commands/<name>.php
 * (the shape is documented in tools/admin/lib.php), so adding one is adding a
 * file. tools/admin_selfcheck.php checks every command file without a database.
 *
 * Exit: 0 on success, 1 on failure or a refused operation.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/admin/lib.php';

$commands = adminLoadCommands();

[$args, $flags] = parseArgs($argv);
$command = array_shift($args);

if ($command === null || isset($flags['help'])) {
    fwrite(STDERR, adminUsageText($commands));
    exit($command === null ? 1 : 0);
}

if (!isset($commands[$command])) {
    fwrite(STDERR, "Unknown command \"{$command}\".\n\n");
    fwrite(STDERR, adminUsageText($commands));
    exit(1);
}

$spec = $commands[$command];
$db = Database::getInstance();
if ($spec['readOnly']) {
    $db = new AdminReadOnlyDb($db);
}

exit(($spec['run'])($db, $args, $flags));
