<?php

namespace Oweb\SearchInsights;

use PHPUnit\Framework\TestCase;

class RangeTest extends TestCase
{
    public function testPresets(): void
    {
        $range = Range::from('7d', today: '2026-11-01');

        $this->assertSame(['id' => '7d', 'from' => '2026-10-26', 'to' => '2026-11-01', 'days' => 7], $range->toArray());
        $this->assertSame('2026-10-03', Range::from('30d', today: '2026-11-01')->from);
        $this->assertSame('2025-11-02', Range::from('12m', today: '2026-11-01')->from);
    }

    public function testFallback(): void
    {
        $this->assertSame('30d', Range::from(null, today: '2026-11-01')->id);
        $this->assertSame('30d', Range::from('1000y', today: '2026-11-01')->id);
        $this->assertSame('30d', Range::from('custom', 'nope', '2026-10-01', '2026-11-01')->id);
        $this->assertSame('30d', Range::from('custom', '2026-02-30', '2026-03-01', '2026-11-01')->id);
    }

    public function testCustom(): void
    {
        $range = Range::from('custom', '2026-09-01', '2026-09-30', '2026-11-01');

        $this->assertSame(['id' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30', 'days' => 30], $range->toArray());
    }

    public function testCustomSwappedAndFuture(): void
    {
        $range = Range::from('custom', '2026-12-31', '2026-10-01', '2026-11-01');

        $this->assertSame('2026-10-01', $range->from);
        $this->assertSame('2026-11-01', $range->to);

        $future = Range::from('custom', '2027-01-01', '2027-02-01', '2026-11-01');

        $this->assertSame('2026-11-01', $future->from);
        $this->assertSame(1, $future->days());
    }

    public function testPrevious(): void
    {
        $previous = Range::from('7d', today: '2026-11-01')->previous();

        $this->assertSame('2026-10-19', $previous->from);
        $this->assertSame('2026-10-25', $previous->to);
    }

    public function testBuckets(): void
    {
        $days = Range::from('7d', today: '2026-11-01')->buckets();

        $this->assertCount(7, $days);
        $this->assertSame(['from' => '2026-10-26', 'to' => '2026-10-26'], $days[0]);

        $weeks = Range::from('12m', today: '2026-11-01')->buckets();

        $this->assertCount(53, $weeks);
        $this->assertSame(['from' => '2025-11-02', 'to' => '2025-11-08'], $weeks[0]);
        $this->assertSame(['from' => '2026-11-01', 'to' => '2026-11-01'], $weeks[52]);
    }

    public function testParts(): void
    {
        $parts = Range::from('30d', today: '2026-11-01')->parts(12);

        $this->assertCount(12, $parts);
        $this->assertSame('2026-10-03', $parts[0]['from']);
        $this->assertSame('2026-11-01', $parts[11]['to']);

        // every day belongs to exactly one part
        $days = 0;

        foreach ($parts as $part) {
            $days += (new \DateTimeImmutable($part['from']))->diff(new \DateTimeImmutable($part['to']))->days + 1;
        }

        $this->assertSame(30, $days);
        $this->assertCount(7, Range::from('7d', today: '2026-11-01')->parts(12));
    }
}
