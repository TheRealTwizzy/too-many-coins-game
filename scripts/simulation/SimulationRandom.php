<?php

class SimulationRandom
{
    public static function seedHash(string $seed, array $parts = []): string
    {
        return hash('sha256', $seed . '|' . implode('|', array_map('strval', $parts)), true);
    }

    public static function float01(string $seed, array $parts = []): float
    {
        $hash = self::seedHash($seed, $parts);
        $value = unpack('N', substr($hash, 0, 4))[1] ?? 0;
        return $value / 4294967295;
    }

    public static function intRange(string $seed, int $min, int $max, array $parts = []): int
    {
        if ($max <= $min) {
            return $min;
        }

        $hash = self::seedHash($seed, $parts);
        $value = unpack('N', substr($hash, 4, 4))[1] ?? 0;
        $span = ($max - $min) + 1;
        return $min + ($value % $span);
    }

    public static function chance(string $seed, float $probability, array $parts = []): bool
    {
        if ($probability <= 0.0) {
            return false;
        }
        if ($probability >= 1.0) {
            return true;
        }

        return self::float01($seed, $parts) < $probability;
    }
}
