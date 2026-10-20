<?php
// src/LabelCenter/Db/PgConnection.php

namespace LabelCenter\Db;

final class PgConnection
{
    private \PDO $pdo;
    
    public function __construct(string $dbName = 'labelcenter')
    {
        require_once CONF_PATH;
        
        if ($dbName === 'labelcenter') {
            $dsn = pglabelcenterconnect;
        } else {
            $dsn = pgconnect;
        }
        
        // Parse PostgreSQL DSN formátu: "host=X port=Y dbname=Z user=A password=B"
        $parts = [];
        parse_str(str_replace([' ', '='], ['&', '='], $dsn), $parts); // ne funkční — potřeba jiný přístup
        
        // Ruční parsování prosté string formátu
        preg_match('/host=([^\s]+)/', $dsn, $mHost);
        preg_match('/port=([^\s]+)/', $dsn, $mPort);
        preg_match('/dbname=([^\s]+)/', $dsn, $mDb);
        preg_match('/user=([^\s]+)/', $dsn, $mUser);
        preg_match('/password=([^\s]+)/', $dsn, $mPass);
        
        $host = $mHost[1] ?? 'localhost';
        $port = $mPort[1] ?? '5432';
        $dbname = $mDb[1] ?? $dbName;
        $user = $mUser[1] ?? 'spelman';
        $password = $mPass[1] ?? '';
        
        $pdoDsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
        
        try {
            $this->pdo = new \PDO($pdoDsn, $user, $password);
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new \RuntimeException('PostgreSQL connection failed: ' . $e->getMessage());
        }
    }
    
    // --- NOVÁ METODA ---
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }    
    public function query(string $sql, array $params = []): array
    {
        if (empty($params)) {
            $stmt = $this->pdo->query($sql);
        } else {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        }
        return $stmt->fetchAll();
    }
    
    public function exec(string $sql, array $params = []): int
    {
        if (empty($params)) {
            return $this->pdo->exec($sql);
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
    
    public function prepare(string $sql): \PDOStatement
    {
        return $this->pdo->prepare($sql);
    }
    
    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }
    
    public function commit(): bool
    {
        return $this->pdo->commit();
    }
    
    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }
    
    public function lastInsertId(?string $sequenceName = null): string
    {
        return $this->pdo->lastInsertId($sequenceName);
    }
}