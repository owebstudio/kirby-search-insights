<?php

namespace Oweb\SearchInsights;

use DateInterval;
use DateTimeImmutable;

/**
 * A date range of whole days, e.g. the last 30 days
 */
class Range
{
    public const PRESETS = [
        '7d'  => 7,
        '30d' => 30,
        '90d' => 90,
        '12m' => 365
    ];

    public const MAX_DAYS = 3660;

    public function __construct(
        public readonly string $id,
        public readonly string $from,
        public readonly string $to
    ) {
    }

    /**
     * Creates a range from a preset id or from two dates (`custom`)
     */
    public static function from(
        string|null $id = null,
        string|null $from = null,
        string|null $to = null,
        string|null $today = null
    ): static {
        $today = static::date($today) ?? new DateTimeImmutable('today');

        if ($id === 'custom') {
            $start = static::date($from);
            $end   = static::date($to);

            if ($start !== null && $end !== null) {
                if ($start > $end) {
                    [$start, $end] = [$end, $start];
                }

                if ($end > $today) {
                    $end   = $today;
                    $start = min($start, $end);
                }

                if ($start->diff($end)->days >= static::MAX_DAYS) {
                    $start = $end->sub(new DateInterval('P' . (static::MAX_DAYS - 1) . 'D'));
                }

                return new static('custom', $start->format('Y-m-d'), $end->format('Y-m-d'));
            }
        }

        $id   = array_key_exists((string)$id, static::PRESETS) === true ? $id : '30d';
        $days = static::PRESETS[$id];

        return new static(
            $id,
            $today->sub(new DateInterval('P' . ($days - 1) . 'D'))->format('Y-m-d'),
            $today->format('Y-m-d')
        );
    }

    /**
     * Splits the range into days, or into weeks for long ranges
     *
     * @return array<int, array{from: string, to: string}>
     */
    public function buckets(): array
    {
        $size    = $this->days() > 90 ? 7 : 1;
        $buckets = [];
        $start   = new DateTimeImmutable($this->from);
        $end     = new DateTimeImmutable($this->to);

        while ($start <= $end) {
            $last = $start->add(new DateInterval('P' . ($size - 1) . 'D'));

            $buckets[] = [
                'from' => $start->format('Y-m-d'),
                'to'   => min($last, $end)->format('Y-m-d')
            ];

            $start = $last->add(new DateInterval('P1D'));
        }

        return $buckets;
    }

    public function days(): int
    {
        return (new DateTimeImmutable($this->from))->diff(new DateTimeImmutable($this->to))->days + 1;
    }

    /**
     * Splits the range into `$count` parts of about the same length,
     * used for the trend lines in the tables
     *
     * @return array<int, array{from: string, to: string}>
     */
    public function parts(int $count): array
    {
        $days  = $this->days();
        $count = max(1, min($count, $days));
        $start = new DateTimeImmutable($this->from);
        $parts = [];

        for ($i = 0; $i < $count; $i++) {
            $first = intdiv($i * $days, $count);
            $last  = intdiv(($i + 1) * $days, $count) - 1;

            $parts[] = [
                'from' => $start->add(new DateInterval('P' . $first . 'D'))->format('Y-m-d'),
                'to'   => $start->add(new DateInterval('P' . $last . 'D'))->format('Y-m-d')
            ];
        }

        return $parts;
    }

    /**
     * The range of the same length right before this one
     */
    public function previous(): static
    {
        $end   = (new DateTimeImmutable($this->from))->sub(new DateInterval('P1D'));
        $start = $end->sub(new DateInterval('P' . ($this->days() - 1) . 'D'));

        return new static($this->id, $start->format('Y-m-d'), $end->format('Y-m-d'));
    }

    public function toArray(): array
    {
        return [
            'id'   => $this->id,
            'from' => $this->from,
            'to'   => $this->to,
            'days' => $this->days()
        ];
    }

    protected static function date(string|null $value): DateTimeImmutable|null
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
