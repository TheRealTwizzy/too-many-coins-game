<?php
require_once __DIR__ . '/auth.php';

class Permissions {
    public static function roleRank(?string $role): int {
        $role = (string)$role;
        if ($role === 'Admin') return 3;
        if ($role === 'Moderator') return 2;
        return 1;
    }

    public static function isStaff(array $player): bool {
        return self::roleRank($player['role'] ?? 'Player') >= 2;
    }

    public static function isAdmin(array $player): bool {
        return self::roleRank($player['role'] ?? 'Player') >= 3;
    }

    public static function requireStaff(): array {
        $player = Auth::requireAuth();
        if (!self::isStaff($player)) {
            http_response_code(403);
            echo json_encode(['error' => 'Staff permission required']);
            exit;
        }
        return $player;
    }

    public static function requireAdmin(): array {
        $player = Auth::requireAuth();
        if (!self::isAdmin($player)) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin permission required']);
            exit;
        }
        return $player;
    }

    public static function canActOnTarget(array $actor, array $target): bool {
        if (!self::isStaff($actor)) return false;
        $actorRank = self::roleRank($actor['role'] ?? 'Player');
        $targetRank = self::roleRank($target['role'] ?? 'Player');
        return $actorRank > $targetRank;
    }
}
