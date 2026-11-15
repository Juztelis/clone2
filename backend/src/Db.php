<?php
declare(strict_types=1);
namespace App;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            [$host, $port, $name, $user, $pass, $ssl] = self::settings();
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s', $host, $port, $name, $ssl);
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // fuzzy-search thresholds used by the % and <% trigram operators (see ProductRepository)
            self::$pdo->exec("SET pg_trgm.similarity_threshold = 0.3; SET pg_trgm.word_similarity_threshold = 0.3");
        }
        return self::$pdo;
    }

    /**
     * Connection settings. A DATABASE_URL (what Neon/Vercel inject) wins over the separate DB_* variables.
     * The unpooled URL is preferred: this app sets per-connection search thresholds, which a transaction pooler would drop.
     * @return array{0:string,1:string,2:string,3:string,4:string,5:string} host, port, database, user, password, sslmode
     */
    private static function settings(): array
    {
        $url = Config::get('DATABASE_URL_UNPOOLED', '') ?: Config::get('DATABASE_URL', '');
        if ($url !== '') {
            $p = parse_url($url);
            if ($p === false || empty($p['host'])) throw new \RuntimeException('DATABASE_URL is not a valid connection string');
            parse_str($p['query'] ?? '', $q);
            return [$p['host'], (string) ($p['port'] ?? 5432), ltrim($p['path'] ?? '', '/'),
                    urldecode($p['user'] ?? ''), urldecode($p['pass'] ?? ''), (string) ($q['sslmode'] ?? 'require')];
        }
        return [Config::get('DB_HOST', '127.0.0.1'), Config::get('DB_PORT', '5432'), Config::get('DB_NAME', 'keystore'),
                Config::get('DB_USER', 'keystore'), Config::get('DB_PASS', 'keystore'), Config::get('DB_SSLMODE', 'prefer')];
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        return self::all($sql, $params)[0] ?? null;
    }

    public static function scalar(string $sql, array $params = []): mixed
    {
        $row = self::one($sql, $params);
        return $row ? reset($row) : null;
    }

    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }
}
