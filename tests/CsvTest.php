<?php

namespace Oweb\SearchInsights;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CsvTest extends TestCase
{
    public static function cellProvider(): array
    {
        return [
            'plain'        => ['ocean', 'ocean'],
            'number'       => [12, '12'],
            'comma'        => ['a, b', '"a, b"'],
            'quote'        => ['say "hi"', '"say ""hi"""'],
            'formula'      => ['=HYPERLINK("x")', '"\'=HYPERLINK(""x"")"'],
            'plus'         => ['+1', "'+1"],
            'minus'        => ['-2+3', "'-2+3"],
            'at'           => ['@SUM(A1)', "'@SUM(A1)"],
            'tab'          => ["\tx", "'\tx"],
            'line break'   => ["a\nb", "\"a\nb\""],
            'inner equals' => ['a=b', 'a=b']
        ];
    }

    #[DataProvider('cellProvider')]
    public function testCell(mixed $value, string $expected): void
    {
        $this->assertSame($expected, Reports::csvCell($value));
    }
}
