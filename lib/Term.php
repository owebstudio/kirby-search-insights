<?php

namespace Oweb\SearchInsights;

use Normalizer;

/**
 * Normalizes search terms and finds similar terms
 */
class Term
{
    /**
     * Turns a raw query into the stored term: valid UTF-8, Unicode NFC,
     * lowercase, without control characters and repeated whitespace
     */
    public static function normalize(string $query, int $maxLength = 100): string
    {
        $term = mb_scrub($query, 'UTF-8');
        $term = preg_replace('/[\p{Cc}\p{Cf}\p{Z}\s]+/u', ' ', $term) ?? '';

        if (class_exists(Normalizer::class) === true) {
            $term = Normalizer::normalize($term, Normalizer::FORM_C) ?: $term;
        }

        $term = trim(mb_strtolower($term, 'UTF-8'));

        return trim(mb_substr($term, 0, $maxLength, 'UTF-8'));
    }

    /**
     * Finds the closest term among the candidates, e.g. a term with results
     * for a misspelled term without results
     *
     * @param array<string, int> $candidates term => number of searches
     */
    public static function similar(string $term, array $candidates): string|null
    {
        $length    = mb_strlen($term, 'UTF-8');
        $threshold = match (true) {
            $length < 4   => 0,
            $length <= 6  => 1,
            $length <= 12 => 2,
            default       => 3
        };

        if ($threshold === 0) {
            return null;
        }

        $best = null;
        $bestDistance = PHP_INT_MAX;
        $bestSearches = 0;

        foreach ($candidates as $candidate => $searches) {
            $candidate = (string)$candidate;

            if ($candidate === $term || abs(strlen($candidate) - strlen($term)) > $threshold) {
                continue;
            }

            $distance = levenshtein($term, $candidate);

            if ($distance > $threshold) {
                continue;
            }

            if ($distance < $bestDistance || ($distance === $bestDistance && $searches > $bestSearches)) {
                $best = $candidate;
                $bestDistance = $distance;
                $bestSearches = $searches;
            }
        }

        return $best;
    }
}
