<?php

namespace App\Controllers;

use App\Db\Mysql;

class MysqlHealthController
{
    public function handle(bool $includeTables = false): void
    {
        $startedAt = microtime(true);

        $details = [
            'mysql' => ['ok' => false, 'latency_ms' => null],
            'overall_latency_ms' => null
        ];

        if ($includeTables) {
            $details['tables'] = [];
        }

        try {
            $mysqlStart = microtime(true);
            $pdo = Mysql::connection();
            $details['mysql']['ok'] = true;
            $details['mysql']['latency_ms'] = (int) round((microtime(true) - $mysqlStart) * 1000);

            if ($includeTables) {
                $stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name");
                $details['tables'] = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            }
        } catch (\Throwable $e) {
            $details['mysql']['ok'] = false;
            $details['mysql']['error'] = $e->getMessage();
        }

        $details['overall_latency_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
        $ok = $details['mysql']['ok'];

        http_response_code($ok ? 200 : 503);
        header('Content-Type: application/json');
        echo json_encode(['status' => $ok ? 'ok' : 'error', 'details' => $details]);
    }
}
