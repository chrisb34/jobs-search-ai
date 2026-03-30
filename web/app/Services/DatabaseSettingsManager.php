<?php

namespace App\Services;

use PDO;
use RuntimeException;

class DatabaseSettingsManager
{
    public function current(): array
    {
        return [
            'connection' => env('DB_CONNECTION', 'sqlite'),
            'database' => env('DB_DATABASE', '../data/jobs.db'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', ''),
            'username' => env('DB_USERNAME', ''),
            'password' => env('DB_PASSWORD', ''),
        ];
    }

    public function validateAndSave(array $settings): void
    {
        $this->testConnection($settings);
        $this->writeEnvValues([
            'DB_CONNECTION' => (string) $settings['connection'],
            'DB_DATABASE' => (string) ($settings['database'] ?? ''),
            'DB_HOST' => (string) ($settings['host'] ?? ''),
            'DB_PORT' => (string) ($settings['port'] ?? ''),
            'DB_USERNAME' => (string) ($settings['username'] ?? ''),
            'DB_PASSWORD' => (string) ($settings['password'] ?? ''),
        ]);
    }

    public function testConnection(array $settings): void
    {
        $connection = (string) ($settings['connection'] ?? 'sqlite');

        if ($connection === 'sqlite') {
            $database = (string) ($settings['database'] ?? '');
            if ($database === '') {
                throw new RuntimeException('SQLite database path is required.');
            }

            $resolvedPath = $this->resolveSqlitePath($database);
            $directory = dirname($resolvedPath);
            if (! is_dir($directory)) {
                throw new RuntimeException('SQLite database directory does not exist.');
            }

            new PDO('sqlite:'.$resolvedPath);

            return;
        }

        $host = (string) ($settings['host'] ?? '');
        $port = (string) ($settings['port'] ?? '');
        $database = (string) ($settings['database'] ?? '');
        $username = (string) ($settings['username'] ?? '');
        $password = (string) ($settings['password'] ?? '');

        if ($host === '' || $database === '' || $username === '') {
            throw new RuntimeException('Host, database name, and username are required for this driver.');
        }

        $dsn = match ($connection) {
            'mysql', 'mariadb' => sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port !== '' ? $port : '3306', $database),
            'pgsql' => sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port !== '' ? $port : '5432', $database),
            default => throw new RuntimeException('Unsupported database driver.'),
        };

        new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    private function writeEnvValues(array $values): void
    {
        $envPath = base_path('.env');
        $contents = file_exists($envPath) ? file_get_contents($envPath) ?: '' : '';

        foreach ($values as $key => $value) {
            $escapedValue = $this->escapeEnvValue($value);
            $pattern = "/^".preg_quote($key, '/')."=.*/m";
            $line = $key.'='.$escapedValue;

            if (preg_match($pattern, $contents)) {
                $contents = preg_replace($pattern, $line, $contents) ?? $contents;
                continue;
            }

            $contents = rtrim($contents).PHP_EOL.$line.PHP_EOL;
        }

        file_put_contents($envPath, $contents);
    }

    private function escapeEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/\s|#|=/', $value)) {
            return '"'.addcslashes($value, '"').'"';
        }

        return $value;
    }

    private function resolveSqlitePath(string $database): string
    {
        if ($database === ':memory:' || str_starts_with($database, DIRECTORY_SEPARATOR)) {
            return $database;
        }

        return base_path($database);
    }
}
