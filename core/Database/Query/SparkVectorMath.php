<?php

final class SparkVectorMath
{
    public static function score(array $candidate, array $query, string $metric): float
    {
        $length = min(count($candidate), count($query));

        if ($length === 0) {
            return 0.0;
        }

        $candidate = array_slice($candidate, 0, $length);
        $query = array_slice($query, 0, $length);

        return match ($metric) {
            'cosine' => self::cosineSimilarity($candidate, $query),
            'l2' => 1 / (1 + self::euclideanDistance($candidate, $query)),
            'inner_product' => self::innerProduct($candidate, $query),
            default => 0.0,
        };
    }

    public static function cosineSimilarity(array $candidate, array $query): float
    {
        $dot = self::innerProduct($candidate, $query);
        $leftNorm = sqrt(self::innerProduct($candidate, $candidate));
        $rightNorm = sqrt(self::innerProduct($query, $query));

        if ($leftNorm == 0.0 || $rightNorm == 0.0) {
            return 0.0;
        }

        return $dot / ($leftNorm * $rightNorm);
    }

    public static function euclideanDistance(array $candidate, array $query): float
    {
        $sum = 0.0;

        foreach ($candidate as $index => $value) {
            $delta = $value - $query[$index];
            $sum += $delta * $delta;
        }

        return sqrt($sum);
    }

    public static function innerProduct(array $candidate, array $query): float
    {
        $sum = 0.0;

        foreach ($candidate as $index => $value) {
            $sum += $value * $query[$index];
        }

        return $sum;
    }

    public static function parseVectorValue(mixed $value): ?array
    {
        if (is_array($value)) {
            return array_map(static fn(mixed $dimension): float => (float) $dimension, array_values($value));
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $trimmed = trim($value);
        $decoded = json_decode($trimmed, true);

        if (is_array($decoded)) {
            return array_map(static fn(mixed $dimension): float => (float) $dimension, array_values($decoded));
        }

        if (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) {
            $trimmed = trim($trimmed, '[]');
        }

        $parts = array_filter(array_map('trim', explode(',', $trimmed)), static fn(string $part): bool => $part !== '');
        if ($parts === []) {
            return null;
        }

        return array_map(static fn(string $dimension): float => (float) $dimension, $parts);
    }

    public static function formatFloat(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 12, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
