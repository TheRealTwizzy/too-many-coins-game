<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/audit.php';

class AdminService {
    private const VALID_ROLES = ['Player', 'Moderator', 'Admin'];
    private const GLOBAL_RESET_CONFIRMATION = 'RESET GLOBAL ECONOMY';
    private const PLAYER_RESET_CONFIRMATION = 'RESET PLAYER ECONOMY';

    public static function updateRole(array $actor, int $targetId, string $role, string $reason): array {
        if (!Permissions::isAdmin($actor)) return ['error' => 'Admin permission required'];
        if (!in_array($role, self::VALID_ROLES, true)) return ['error' => 'Invalid role'];
        if ((int)$actor['player_id'] === $targetId && $role !== 'Admin') {
            return ['error' => 'Admins cannot demote themselves'];
        }

        $db = Database::getInstance();
        $target = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$targetId]);
        if (!$target) return ['error' => 'Player not found'];
        if (!empty($target['profile_deleted_at'])) return ['error' => 'Player is deleted'];

        $before = ['role' => (string)$target['role']];
        $db->query("UPDATE players SET role = ? WHERE player_id = ?", [$role, $targetId]);
        Audit::record((int)$actor['player_id'], $targetId, 'admin_role_update', self::cleanReason($reason, 'Admin role update'), $before, ['role' => $role]);

        return ['success' => true, 'role' => $role];
    }

    public static function globalEconomyReset(array $actor, string $confirmation, string $reason): array {
        if (!Permissions::isAdmin($actor)) return ['error' => 'Admin permission required'];
        if ($confirmation !== self::GLOBAL_RESET_CONFIRMATION) return ['error' => 'Invalid confirmation phrase'];

        $db = Database::getInstance();
        $pdo = $db->getConnection();
        $fkChanged = false;

        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $fkChanged = true;

            if (self::tableExists('players')) {
                $db->query(
                    "UPDATE players
                     SET global_stars = 0,
                         global_stars_fractional_fp = 0,
                         joined_season_id = NULL,
                         participation_enabled = 0,
                         idle_modal_active = 0,
                         activity_state = 'Active',
                         idle_since_tick = NULL,
                         last_activity_tick = NULL"
                );
            }

            foreach (self::globalEconomyTables() as $table) {
                self::truncateIfExists($table);
            }

            if (self::tableExists('server_state')) {
                $db->query("DELETE FROM server_state WHERE id = 1");
            }

            Audit::record((int)$actor['player_id'], null, 'admin_global_economy_reset', self::cleanReason($reason, 'Admin global economy reset'));
        } finally {
            if ($fkChanged) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
        }

        return ['success' => true];
    }

    public static function playerEconomyReset(array $actor, int $targetId, string $confirmation, string $reason): array {
        if (!Permissions::isAdmin($actor)) return ['error' => 'Admin permission required'];
        if ($confirmation !== self::PLAYER_RESET_CONFIRMATION) return ['error' => 'Invalid confirmation phrase'];

        $db = Database::getInstance();
        $target = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$targetId]);
        if (!$target) return ['error' => 'Player not found'];
        if (!empty($target['profile_deleted_at'])) return ['error' => 'Player is deleted'];

        try {
            $db->beginTransaction();

            self::deleteWhereIfExists('active_freezes', 'source_player_id = ? OR target_player_id = ?', [$targetId, $targetId]);
            self::deleteWhereIfExists('sigil_theft_attempts', 'attacker_player_id = ? OR target_player_id = ?', [$targetId, $targetId]);

            foreach (['active_boosts', 'sigil_drop_log', 'sigil_drop_tracking', 'player_season_vault', 'season_participation', 'economy_ledger', 'badges', 'player_cosmetics', 'pending_actions'] as $table) {
                self::deleteWhereIfExists($table, 'player_id = ?', [$targetId]);
            }

            if (self::tableExists('players')) {
                $db->query(
                    "UPDATE players
                     SET global_stars = 0,
                         global_stars_fractional_fp = 0,
                         joined_season_id = NULL,
                         participation_enabled = 0,
                         idle_modal_active = 0,
                         activity_state = 'Active',
                         idle_since_tick = NULL,
                         last_activity_tick = NULL
                     WHERE player_id = ?",
                    [$targetId]
                );
            }

            Audit::record((int)$actor['player_id'], $targetId, 'admin_player_economy_reset', self::cleanReason($reason, 'Admin player economy reset'), self::playerEconomySnapshot($target), ['reset_to_day_0' => true]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->getConnection()->inTransaction()) {
                $db->rollback();
            }
            throw $e;
        }

        return ['success' => true];
    }

    private static function globalEconomyTables(): array {
        return [
            'yearly_state',
            'active_freezes',
            'active_boosts',
            'sigil_drop_log',
            'sigil_drop_tracking',
            'player_season_vault',
            'season_vault',
            'season_participation',
            'trades',
            'sigil_theft_attempts',
            'economy_ledger',
            'pending_actions',
            'badges',
            'player_cosmetics',
        ];
    }

    private static function tableExists(string $table): bool {
        $row = Database::getInstance()->fetch(
            "SELECT COUNT(*) AS c
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?",
            [$table]
        );
        return (int)($row['c'] ?? 0) > 0;
    }

    private static function columnExists(string $table, string $column): bool {
        $row = Database::getInstance()->fetch(
            "SELECT COUNT(*) AS c
             FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
            [$table, $column]
        );
        return (int)($row['c'] ?? 0) > 0;
    }

    private static function truncateIfExists(string $table): void {
        if (!self::tableExists($table)) return;
        $safeTable = str_replace('`', '``', $table);
        Database::getInstance()->getConnection()->exec("TRUNCATE TABLE `{$safeTable}`");
    }

    private static function deleteWhereIfExists(string $table, string $whereSql, array $params): void {
        if (!self::tableExists($table)) return;
        if (!self::whereColumnsExist($table, $whereSql)) return;

        $safeTable = str_replace('`', '``', $table);
        Database::getInstance()->query("DELETE FROM `{$safeTable}` WHERE {$whereSql}", $params);
    }

    private static function whereColumnsExist(string $table, string $whereSql): bool {
        preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*\?/', $whereSql, $matches);
        foreach (array_unique($matches[1] ?? []) as $column) {
            if (!self::columnExists($table, $column)) return false;
        }
        return true;
    }

    private static function cleanReason(string $reason, string $fallback): string {
        $reason = trim($reason);
        if ($reason === '') return $fallback;
        return substr($reason, 0, 255);
    }

    private static function playerEconomySnapshot(array $player): array {
        return [
            'global_stars' => (int)($player['global_stars'] ?? 0),
            'global_stars_fractional_fp' => (int)($player['global_stars_fractional_fp'] ?? 0),
            'joined_season_id' => isset($player['joined_season_id']) ? (int)$player['joined_season_id'] : null,
            'participation_enabled' => (int)($player['participation_enabled'] ?? 0),
        ];
    }
}
