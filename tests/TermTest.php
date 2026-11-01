<?php

namespace Oweb\SearchInsights;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TermTest extends TestCase
{
    public static function normalizeProvider(): array
    {
        return [
            'case and spaces'  => ["  Return   Policy ", 'return policy'],
            'tabs, new lines'  => ["return\t\npolicy", 'return policy'],
            'unicode spaces'   => ["return\u{00A0}policy\u{2003}", 'return policy'],
            'control chars'    => ["return\u{0000}policy\u{200B}", 'return policy'],
            'turkish'          => ['İSTANBUL Şehir', 'i̇stanbul şehir'],
            'german'           => ['RÜCKGABE', 'rückgabe'],
            'nfc'              => ["cafe\u{0301}", "caf\u{00E9}"],
            'invalid utf-8'    => ["ocean\xC3", 'ocean?'],
            'empty'            => ['   ', '']
        ];
    }

    #[DataProvider('normalizeProvider')]
    public function testNormalize(string $query, string $expected): void
    {
        $this->assertSame($expected, Term::normalize($query));
    }

    public function testNormalizeMaxLength(): void
    {
        $this->assertSame('abcde', Term::normalize('abcdefgh', 5));
        $this->assertSame('ab', Term::normalize('ab   cdef', 3));
        $this->assertSame('ğüş', Term::normalize('ğüşiöç', 3));
    }

    public static function similarProvider(): array
    {
        $candidates = [
            'return policy' => 184,
            'espresso'      => 133,
            'shipping'      => 162,
            'grinder'       => 81,
            'decaf'         => 118,
            'versand'       => 56,
            'plants'        => 15,
            'waterfalls'    => 105,
            'tea'           => 4
        ];

        return [
            'transposition'   => ['retrun policy', $candidates, 'return policy'],
            'one letter'      => ['expresso', $candidates, 'espresso'],
            'missing letter'  => ['shiping', $candidates, 'shipping'],
            'short word'      => ['grider', $candidates, 'grinder'],
            'extra letter'    => ['decaff', $candidates, 'decaf'],
            'swapped letters' => ['versnad', $candidates, 'versand'],
            'too different'   => ['prints', $candidates, null],
            'too short'       => ['tee', $candidates, null],
            'no candidates'   => ['watefalls', [], null],
            'same term'       => ['espresso', ['espresso' => 10], null]
        ];
    }

    #[DataProvider('similarProvider')]
    public function testSimilar(string $term, array $candidates, string|null $expected): void
    {
        $this->assertSame($expected, Term::similar($term, $candidates));
    }

    public function testSimilarPrefersMoreSearches(): void
    {
        $this->assertSame('cake', Term::similar('cakx', ['cafe' => 1, 'cake' => 50, 'cave' => 3]));
    }

    public function testSimilarWithNumericTerms(): void
    {
        $this->assertSame('2024', Term::similar('2025', [2024 => 10, 'other' => 1]));
    }
}
