<?php

namespace Oweb\SearchInsights;

use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    protected string $dir;
    protected Database $db;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/search-insights-' . bin2hex(random_bytes(4));
        $this->db  = new Database($this->dir . '/logs/search-insights.sqlite');
    }

    protected function tearDown(): void
    {
        unset($this->db);
        gc_collect_cycles();

        foreach (glob($this->dir . '/logs/*') as $file) {
            @unlink($file);
        }

        @rmdir($this->dir . '/logs');
        @rmdir($this->dir);
    }

    protected function row(string $day, string $term, string $lang = ''): array|null
    {
        return $this->db->query('SELECT * FROM searches WHERE day = ? AND lang = ? AND term = ?', [$day, $lang, $term])[0] ?? null;
    }

    public function testCreatesFolderAndSchema(): void
    {
        $this->assertFalse($this->db->exists());

        $this->db->pdo();

        $this->assertTrue($this->db->exists());
        $this->assertSame(1, (int)$this->db->value('PRAGMA user_version'));
    }

    public function testAddSearch(): void
    {
        $this->db->addSearch('2026-11-01', 'en', 'ocean', 3);
        $this->db->addSearch('2026-11-01', 'en', 'ocean', 5);
        $this->db->addSearch('2026-11-01', 'en', 'ocean', 0);
        $this->db->addSearch('2026-11-01', 'en', 'ocean', null);
        $this->db->addSearch('2026-11-01', 'de', 'ocean', 1);

        $row = $this->row('2026-11-01', 'ocean', 'en');

        $this->assertSame(4, $row['searches']);
        $this->assertSame(1, $row['zero']);
        $this->assertSame(8, $row['results']);
        $this->assertSame(3, $row['counted']);
        $this->assertSame(1, $this->row('2026-11-01', 'ocean', 'de')['searches']);
    }

    public function testMaxTermsPerDay(): void
    {
        $this->assertSame('a1', $this->db->addSearch('2026-11-01', '', 'a1', 1, 2));
        $this->assertSame('a2', $this->db->addSearch('2026-11-01', '', 'a2', 1, 2));
        $this->assertSame(Database::OTHER, $this->db->addSearch('2026-11-01', '', 'a3', 1, 2));
        $this->assertSame(Database::OTHER, $this->db->addSearch('2026-11-01', '', 'a4', 1, 2));

        // known terms are still counted, a new day starts fresh
        $this->assertSame('a1', $this->db->addSearch('2026-11-01', '', 'a1', 1, 2));
        $this->assertSame('a3', $this->db->addSearch('2026-11-02', '', 'a3', 1, 2));

        $this->assertSame(2, $this->row('2026-11-01', Database::OTHER)['searches']);
    }

    public function testAddClick(): void
    {
        $this->db->addSearch('2026-11-01', '', 'ocean', 3);
        $this->db->addSearch('2026-11-01', '', 'ocean', 3);

        $this->assertTrue($this->db->addClick('2026-11-01', '', 'ocean', 'page://a', 1, 'n1'));
        $this->assertTrue($this->db->addClick('2026-11-01', '', 'ocean', 'page://b', 3, 'n1'));
        $this->assertTrue($this->db->addClick('2026-11-01', '', 'ocean', 'page://a', 1, 'n2'));

        $clicks = $this->db->query('SELECT page, clicks, positions FROM clicks ORDER BY page');

        $this->assertSame([
            ['page' => 'page://a', 'clicks' => 2, 'positions' => 2],
            ['page' => 'page://b', 'clicks' => 1, 'positions' => 3]
        ], $clicks);

        // two results pages, so two searches with a click
        $this->assertSame(2, $this->row('2026-11-01', 'ocean')['clicked']);
    }

    public function testClickLimitPerResultsPage(): void
    {
        $this->db->addSearch('2026-11-01', '', 'ocean', 3);

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($this->db->addClick('2026-11-01', '', 'ocean', 'page://a', 1, 'n1', 3));
        }

        $this->assertFalse($this->db->addClick('2026-11-01', '', 'ocean', 'page://a', 1, 'n1', 3));
        $this->assertSame(3, (int)$this->db->value('SELECT clicks FROM clicks'));
    }

    public function testClickedNeverExceedsSearches(): void
    {
        $this->db->addSearch('2026-11-01', '', 'ocean', 3);

        $this->db->addClick('2026-11-01', '', 'ocean', 'page://a', 1, 'n1');
        $this->db->addClick('2026-11-01', '', 'ocean', 'page://a', 1, 'n2');

        $this->assertSame(1, $this->row('2026-11-01', 'ocean')['clicked']);
    }

    public function testIgnoreAndRestore(): void
    {
        $this->db->addSearch('2026-11-01', '', 'casino', 0);
        $this->db->addClick('2026-11-01', '', 'casino', 'page://a', 1, 'n1');

        $this->db->ignore('casino');

        $this->assertTrue($this->db->isIgnored('casino'));
        $this->assertNull($this->row('2026-11-01', 'casino'));
        $this->assertSame(0, (int)$this->db->value('SELECT COUNT(*) FROM clicks'));
        $this->assertArrayHasKey('casino', $this->db->ignored());

        $this->db->restore('casino');

        $this->assertFalse($this->db->isIgnored('casino'));
    }

    public function testPrune(): void
    {
        $this->db->addSearch('2025-10-01', '', 'old', 1);
        $this->db->addSearch('2026-10-31', '', 'new', 1);
        $this->db->addClick('2025-10-01', '', 'old', 'page://a', 1, 'n-old');
        $this->db->addClick('2026-10-31', '', 'new', 'page://a', 1, 'n-new');

        $this->db->prune('2026-11-01', 365);

        $this->assertNull($this->row('2025-10-01', 'old'));
        $this->assertNotNull($this->row('2026-10-31', 'new'));
        $this->assertSame(1, (int)$this->db->value('SELECT COUNT(*) FROM clicks'));
        $this->assertSame(1, (int)$this->db->value('SELECT COUNT(*) FROM nonces'));
        $this->assertSame('2026-11-01', $this->db->meta('pruned'));

        // once a day only
        $this->db->addSearch('2020-01-01', '', 'ancient', 1);
        $this->db->prune('2026-11-01', 365);

        $this->assertNotNull($this->row('2020-01-01', 'ancient'));
    }

    public function testClear(): void
    {
        $this->db->addSearch('2026-11-01', '', 'ocean', 1);
        $this->db->ignore('casino');

        $this->db->clear();

        $this->assertSame(0, (int)$this->db->value('SELECT COUNT(*) FROM searches'));
        $this->assertTrue($this->db->isIgnored('casino'));
    }

    public function testSecret(): void
    {
        $secret = $this->db->secret();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $secret);
        $this->assertSame($secret, $this->db->secret());
        $this->assertSame($secret, (new Database($this->db->file()))->secret());
    }
}
