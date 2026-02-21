<?php

namespace App\Controllers;

use App\Config\Env;
use App\Db\Pg;
use App\Db\Mysql;
use App\Cache\RedisCache;

class HealthController
{
    public function handle(): void
    {
        $startedAt = microtime(true);
        $dbEngine = $this->resolveDbEngine();

        $details = [
            $dbEngine => ['ok' => false, 'latency_ms' => null],
            'redis' => ['ok' => false, 'latency_ms' => null],
            'overall_latency_ms' => null
        ];

        try {
            $dbStart = microtime(true);
            if ($dbEngine === 'mysql') {
                Mysql::connection()->query('SELECT 1');
            } else {
                Pg::ping();
            }
            $details[$dbEngine]['ok'] = true;
            $details[$dbEngine]['latency_ms'] = (int) round((microtime(true) - $dbStart) * 1000);
        } catch (\Throwable $e) {
            $details[$dbEngine]['ok'] = false;
        }

        try {
            $redisStart = microtime(true);
            RedisCache::ping();
            $details['redis']['ok'] = true;
            $details['redis']['latency_ms'] = (int) round((microtime(true) - $redisStart) * 1000);
        } catch (\Throwable $e) {
            $details['redis']['ok'] = false;
        }

        $details['overall_latency_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
        $ok = $details[$dbEngine]['ok'] && $details['redis']['ok'];

        http_response_code($ok ? 200 : 503);
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['status' => $ok ? 'ok' : 'error', 'details' => $details]);
    }

    private function resolveDbEngine(): string
    {
        $mysqlDsn = Env::get('MYSQL_DSN', '');
        if ($mysqlDsn !== '') {
            return 'mysql';
        }

        $dsn = strtolower((string) Env::get('DATABASE_URL', ''));
        if (str_starts_with($dsn, 'mysql:')) {
            return 'mysql';
        }

        return 'postgres';
    }
}
