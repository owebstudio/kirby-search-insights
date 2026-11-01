<?php

namespace Oweb\SearchInsights;

use DateInterval;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

/**
 * SQLite storage with daily aggregates. No single searches,
 * no visitor data: only day, language, term and counters.
 */
class Database
{
    /**
     * Stored instead of new terms once a day has `maxTermsPerDay` terms
     */
    public const OTHER = '';

    public const SCHEMA = 1;

    protected PDO|null $pdo = null;
    protected array|null $ignored = null;

    public function __construct(
        protected string $file
    ) {
    }

    public static function isAvailable(): bool
    {
        return class_exists(PDO::class) === true && in_array('sqlite', PDO::getAvailableDrivers(), true) === true;
    }

    /**
     * Counts a click on a result and, for the first click of a results page,
     * the search as clicked. Returns `false` when the results page reached its limit.
     */
    public function addClick(
        string $day,
        string $lang,
        string $term,
        string $page,
        int $position,
        string $nonce,
        int $limit = 10
    ): bool {
        return $this->transaction(function () use ($day, $lang, $term, $page, $position, $nonce, $limit) {
            $this->execute('INSERT OR IGNORE INTO nonces (nonce, day) VALUES (?, ?)', [$nonce, $day]);

            $clicks = (int)$this->value('SELECT clicks FROM nonces WHERE nonce = ?', [$nonce]);

            if ($clicks >= $limit) {
                return false;
            }

            $this->execute('UPDATE nonces SET clicks = clicks + 1 WHERE nonce = ?', [$nonce]);
            $this->execute('INSERT OR IGNORE INTO clicks (day, lang, term, page) VALUES (?, ?, ?, ?)', [$day, $lang, $term, $page]);
            $this->execute(
                'UPDATE clicks SET clicks = clicks + 1, positions = positions + ? WHERE day = ? AND lang = ? AND term = ? AND page = ?',
                [max(1, $position), $day, $lang, $term, $page]
            );

            if ($clicks === 0) {
                $this->execute(
                    'UPDATE searches SET clicked = clicked + 1 WHERE day = ? AND lang = ? AND term = ? AND clicked < searches',
                    [$day, $lang, $term]
                );
            }

            return true;
        });
    }

    /**
     * Counts a search. `$results` is `null` when the number of results is unknown.
     * Returns the stored term, which is `Database::OTHER` when the day is full.
     */
    public function addSearch(
        string $day,
        string $lang,
        string $term,
        int|null $results,
        int $maxTermsPerDay = 1000
    ): string {
        return $this->transaction(function () use ($day, $lang, $term, $results, $maxTermsPerDay) {
            $exists = $this->value('SELECT 1 FROM searches WHERE day = ? AND lang = ? AND term = ?', [$day, $lang, $term]);

            if (
                $exists === null &&
                $maxTermsPerDay > 0 &&
                (int)$this->value('SELECT COUNT(*) FROM searches WHERE day = ?', [$day]) >= $maxTermsPerDay
            ) {
                $term = static::OTHER;
            }

            $this->execute('INSERT OR IGNORE INTO searches (day, lang, term) VALUES (?, ?, ?)', [$day, $lang, $term]);
            $this->execute(
                'UPDATE searches SET searches = searches + 1, zero = zero + ?, results = results + ?, counted = counted + ? WHERE day = ? AND lang = ? AND term = ?',
                [
                    $results === 0 ? 1 : 0,
                    max(0, $results ?? 0),
                    $results === null ? 0 : 1,
                    $day,
                    $lang,
                    $term
                ]
            );

            return $term;
        });
    }

    /**
     * Removes all searches and clicks; ignored terms stay ignored
     */
    public function clear(): void
    {
        $this->transaction(function () {
            $this->execute('DELETE FROM searches');
            $this->execute('DELETE FROM clicks');
            $this->execute('DELETE FROM nonces');
        });

        $this->pdo()->exec('VACUUM');
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        return $statement->rowCount();
    }

    public function exists(): bool
    {
        return is_file($this->file) === true;
    }

    public function file(): string
    {
        return $this->file;
    }

    /**
     * Deletes all data of a term and stops tracking it
     */
    public function ignore(string $term): void
    {
        $this->transaction(function () use ($term) {
            $this->execute('INSERT OR REPLACE INTO ignored (term, created) VALUES (?, ?)', [$term, date('Y-m-d')]);
            $this->execute('DELETE FROM searches WHERE term = ?', [$term]);
            $this->execute('DELETE FROM clicks WHERE term = ?', [$term]);
        });

        $this->ignored = null;
    }

    /**
     * @return array<string, string> term => date it was ignored
     */
    public function ignored(): array
    {
        return $this->ignored ??= array_column(
            $this->query('SELECT term, created FROM ignored ORDER BY created DESC, term ASC'),
            'created',
            'term'
        );
    }

    public function isIgnored(string $term): bool
    {
        return isset($this->ignored()[$term]) === true;
    }

    public function meta(string $key): string|null
    {
        $value = $this->value('SELECT value FROM meta WHERE key = ?', [$key]);

        return $value === null ? null : (string)$value;
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        if (static::isAvailable() === false) {
            throw new RuntimeException('The PDO SQLite driver (pdo_sqlite) is not installed');
        }

        $dir = dirname($this->file);

        if (is_dir($dir) === false && @mkdir($dir, 0755, true) === false && is_dir($dir) === false) {
            throw new RuntimeException('The folder for the database could not be created: ' . $dir);
        }

        $pdo = new PDO('sqlite:' . $this->file, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 5
        ]);

        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');

        $this->pdo = $pdo;
        $this->migrate();

        return $pdo;
    }

    /**
     * Deletes days older than the retention period, at most once a day
     */
    public function prune(string $today, int $retention): void
    {
        if ($retention <= 0 || $this->meta('pruned') === $today) {
            return;
        }

        $date   = new DateTimeImmutable($today);
        $cutoff = $date->sub(new DateInterval('P' . $retention . 'D'))->format('Y-m-d');

        $this->transaction(function () use ($date, $cutoff, $today) {
            $this->execute('DELETE FROM searches WHERE day < ?', [$cutoff]);
            $this->execute('DELETE FROM clicks WHERE day < ?', [$cutoff]);
            $this->execute('DELETE FROM nonces WHERE day < ?', [$date->sub(new DateInterval('P1D'))->format('Y-m-d')]);
            $this->setMeta('pruned', $today);
        });
    }

    public function query(string $sql, array $bindings = []): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll();
    }

    public function restore(string $term): void
    {
        $this->execute('DELETE FROM ignored WHERE term = ?', [$term]);
        $this->ignored = null;
    }

    /**
     * Random secret to sign the click tokens, created on first use
     */
    public function secret(): string
    {
        if ($secret = $this->meta('secret')) {
            return $secret;
        }

        $this->execute('INSERT OR IGNORE INTO meta (key, value) VALUES (?, ?)', ['secret', bin2hex(random_bytes(32))]);

        return (string)$this->meta('secret');
    }

    public function setMeta(string $key, string $value): void
    {
        $this->execute('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)', [$key, $value]);
    }

    /**
     * Size of the database file including the write-ahead log, in bytes
     */
    public function size(): int
    {
        $size = 0;

        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($this->file . $suffix) === true) {
                $size += (int)filesize($this->file . $suffix);
            }
        }

        return $size;
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();

        if ($pdo->inTransaction() === true) {
            return $callback();
        }

        $pdo->exec('BEGIN IMMEDIATE');

        try {
            $result = $callback();
            $pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    public function value(string $sql, array $bindings = []): mixed
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    protected function migrate(): void
    {
        if ((int)$this->pdo->query('PRAGMA user_version')->fetchColumn() >= static::SCHEMA) {
            return;
        }

        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS searches (
                day      TEXT    NOT NULL,
                lang     TEXT    NOT NULL,
                term     TEXT    NOT NULL,
                searches INTEGER NOT NULL DEFAULT 0,
                zero     INTEGER NOT NULL DEFAULT 0,
                results  INTEGER NOT NULL DEFAULT 0,
                counted  INTEGER NOT NULL DEFAULT 0,
                clicked  INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (day, lang, term)
            );
            CREATE INDEX IF NOT EXISTS searches_term ON searches (term, day);
            CREATE TABLE IF NOT EXISTS clicks (
                day       TEXT    NOT NULL,
                lang      TEXT    NOT NULL,
                term      TEXT    NOT NULL,
                page      TEXT    NOT NULL,
                clicks    INTEGER NOT NULL DEFAULT 0,
                positions INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (day, lang, term, page)
            );
            CREATE INDEX IF NOT EXISTS clicks_page ON clicks (page, day);
            CREATE INDEX IF NOT EXISTS clicks_term ON clicks (term, day);
            CREATE TABLE IF NOT EXISTS nonces (
                nonce  TEXT    NOT NULL PRIMARY KEY,
                day    TEXT    NOT NULL,
                clicks INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS ignored (
                term    TEXT NOT NULL PRIMARY KEY,
                created TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS meta (
                key   TEXT NOT NULL PRIMARY KEY,
                value TEXT NOT NULL
            );
            PRAGMA user_version = 1;
            SQL);
    }
}
