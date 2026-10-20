<?php
/**
 * Datapumpa: Oracle BUSTRANSACTIONS -> PostgreSQL cache_bustransactions
 */

require_once  '../../bootstrap.php';
require_once BASE_PATH . '/vendor/autoload.php';

use LabelCenter\Db\OciConnection;
use LabelCenter\Db\PgConnection;

// --- KONFIGURACE ---
set_time_limit(120);
$startTime = microtime(true);
$isCli = (PHP_SAPI === 'cli');
$logMode = $isCli ? false : (isset($_GET['log']) && $_GET['log'] === 'yes');
$logData = [];

/**
 * Pomocná funkce pro logování
 */
function logMsg(string $message, bool $isCli, bool $logMode, array &$logData): void
{
    $logData[] = $message;
    if ($isCli || !$logMode) {
        echo "[ " . date('H:i:s') . " ] " . $message . PHP_EOL;
    }
}

logMsg("=== START cache_bustransactions ===", $isCli, $logMode, $logData);

try {
    // 1. Inicializace DB
    $oci = new OciConnection();
    $pg = new PgConnection('labelcenter');
    logMsg("DB spojení OK", $isCli, $logMode, $logData);

    // --- CLEANUP LOCKS (DBeaver Fix) ---
    $pg->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity 
               WHERE usename = current_user AND state = 'idle' AND pid <> pg_backend_pid()");
    $pg->exec("SET lock_timeout = '10s'");

    // 2. Extract (Recursive CTE z Oracle)
    $oracleSql = <<<SQL
        WITH btroot(ID, PARENTID, CODE, NAME, hlevel) AS 
            (SELECT b.ID, b.PARENT_ID, b.CODE, b.NAME, 1 AS hlevel 
             FROM BUSTRANSACTIONS b 
             WHERE b.PARENT_ID = '3K20000101' AND b.HIDDEN ='N' AND b.CLOSED = 'N'
             UNION ALL 
            SELECT b2.ID, b2.PARENT_ID, b2.CODE, b2.NAME, br.hlevel+1 AS hlevel
             FROM BUSTRANSACTIONS b2, btroot br
             WHERE b2.HIDDEN ='N' AND b2.CLOSED = 'N' AND b2.PARENT_ID = br.ID)
        SELECT ID, PARENTID, CODE, NAME, hlevel FROM btroot
        UNION ALL
        SELECT b3.ID, b3.PARENT_ID, b3.CODE, b3.NAME, 0 AS hlevel 
        FROM BUSTRANSACTIONS b3 
        WHERE b3.ID = '3K20000101' AND b3.HIDDEN ='N' AND b3.CLOSED = 'N'
        ORDER BY hlevel, CODE
SQL;

    $oracleData = $oci->query($oracleSql);
    $oracleData = array_map(fn($row) => array_change_key_case($row, CASE_LOWER), $oracleData);

    if (empty($oracleData)) {
        throw new RuntimeException('Oracle vrátil 0 řádků (BUSTRANSACTIONS).');
    }
    logMsg("Načteno " . count($oracleData) . " řádků z Oracle", $isCli, $logMode, $logData);

    // 3. Sync Process
    require_once CONF_PATH;
    $sourceSystem = ociname;

    $pg->beginTransaction();
    
    // Používáme TRUNCATE, pokud zámky dovolí (díky cleanupu nahoře by měly)
    $pg->exec("TRUNCATE TABLE public.cache_bustransactions RESTART IDENTITY");
    logMsg("Tabulka vyčištěna (TRUNCATE)", $isCli, $logMode, $logData);

    $insertSql = "INSERT INTO public.cache_bustransactions 
                    (id, parent_id, \"name\", code, hlevel, source_system, synced_at, is_deleted)
                  VALUES 
                    (:id, :parent_id, :name, :code, :hlevel, :source_system, :synced_at, false)";

    $stmt = $pg->prepare($insertSql);
    $syncedAt = date('Y-m-d H:i:s');
    $rowCount = 0;

    foreach ($oracleData as $row) {
        $stmt->execute([
            ':id' => $row['id'] ?? null,
            ':parent_id' => $row['parentid'] ?? null,
            ':name' => $row['name'] ?? null,
            ':code' => $row['code'] ?? null,
            ':hlevel' => isset($row['hlevel']) ? (int)$row['hlevel'] : null,
            ':source_system' => $sourceSystem,
            ':synced_at' => $syncedAt,
        ]);
        $rowCount++;
    }

    $pg->commit();
    $oci->close();
    
    $duration = round(microtime(true) - $startTime, 2);
    logMsg("=== DONE: $rowCount záznamů synchronizováno za {$duration} s ===", $isCli, $logMode, $logData);

    // Finální výstup pro Web
    if ($logMode) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'result' => 'ok', 
            'count' => $rowCount, 
            'duration' => $duration, 
            'logdata' => $logData
        ], JSON_UNESCAPED_UNICODE);
    }
    exit(0);

} catch (\Throwable $e) {
    if (isset($pg) && $pg->inTransaction()) {
        $pg->rollBack();
    }
    
    logMsg("ERROR: " . $e->getMessage(), $isCli, $logMode, $logData);

    if ($logMode) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['result' => 'error', 'message' => $e->getMessage(), 'logdata' => $logData], JSON_UNESCAPED_UNICODE);
    } else {
        fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
    }
    exit(1);
}
