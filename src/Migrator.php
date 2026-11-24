<?php

declare(strict_types=1);

final class Migrator
{
    public function __construct(private readonly PDO $db) {}

    public function run(string $directory): array
    {
        $schema = (string) $this->db->query('SELECT DATABASE()')->fetchColumn();
        if ($schema === '') {
            throw new RuntimeException('Select a database before running migrations.');
        }
        $lock = 'commerce:' . substr(hash('sha256', $schema), 0, 48);
        $acquire = $this->db->prepare('SELECT GET_LOCK(?, 30)');
        $acquire->execute([$lock]);
        if ((int) $acquire->fetchColumn() !== 1) {
            throw new RuntimeException('Could not acquire the schema migration lock.');
        }
        try {
            return $this->apply($directory);
        } finally {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lock]);
        }
    }

    private function apply(string $directory): array
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(100) PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB');
        $completed = $this->db->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $record = $this->db->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
        $applied = [];
        if (!in_array('0001_baseline', $completed, true)) {
            $schema = file_get_contents($directory . '/schema.sql');
            if ($schema === false) {
                throw new RuntimeException('Cannot read the baseline schema.');
            }
            foreach (explode(';', $schema) as $statement) {
                if (trim($statement) !== '') {
                    $this->db->exec($statement);
                }
            }
            $record->execute(['0001_baseline']);
            $applied[] = '0001_baseline';
        }
        $files = glob($directory . '/migrations/*.php');
        if ($files === false) {
            throw new RuntimeException('Cannot list schema migrations.');
        }
        sort($files, SORT_STRING);
        foreach ($files as $path) {
            $version = basename($path, '.php');
            if (in_array($version, $completed, true)) {
                continue;
            }
            // MySQL commits DDL implicitly. A migration must tolerate retry after any statement.
            $migration = require $path;
            $migration($this->db, $this);
            $record->execute([$version]);
            $applied[] = $version;
        }
        return $applied;
    }

    public function hasColumn(string $table, string $column): bool
    {
        $check = $this->db->prepare('SELECT 1 FROM information_schema.columns
            WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
        $check->execute([$table, $column]);
        return (bool) $check->fetchColumn();
    }
}
