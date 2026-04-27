<?php

class CandidatePatchLoader
{
    public static function load(?string $path): array
    {
        if ($path === null || trim($path) === '') {
            return [];
        }

        if (!is_file($path)) {
            throw new InvalidArgumentException('Candidate patch not found: ' . $path);
        }

        $contents = (string)file_get_contents($path);
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Candidate patch must decode to a JSON object or array: ' . $path);
        }

        return $decoded;
    }
}
