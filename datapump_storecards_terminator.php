<?php
/**
 * Datapumpa: Oracle STORECARDS -> PostgreSQL cache_storecards
 */

$startTime = microtime(true);

require_once  '../../bootstrap.php';
require_once BASE_PATH . '/vendor/autoload.php';

use LabelCenter\Db\OciConnection;
use LabelCenter\Db\PgConnection;

// --- KONFIGURACE PROSTŘEDÍ ---
set_time_limit(120);
$isCli = (PHP_SAPI === 'cli');
$logMode = $isCli ? false : (isset($_GET['log']) && $_GET['log'] === 'yes');
$logData = [];

/**
 * Pomocná funkce pro logování - v CLI vypisuje hned, ve webu sbírá pro JSON
 */
function logMsg(string $message, bool $isCli, bool $logMode, array &$logData): void
{
    $logData[] = $message;
    // Vypisujeme do konzole pokud jsme v CLI, nebo pokud nejsme v JSON log módu
    if ($isCli || !$logMode) {
        echo "[ " . date('H:i:s') . " ] " . $message . PHP_EOL;
    }
}

logMsg("=== START cache_storecards ===", $isCli, $logMode, $logData);

try {
    // 1. Oracle & Postgres Init
    $oci = new OciConnection();
    $pg = new PgConnection('labelcenter');
    logMsg("Spojení s DB navázána", $isCli, $logMode, $logData);

    // --- AGRESIVNÍ ČIŠTĚNÍ LOCKŮ (DBeaver fix) ---
    // Sestřelíme idle session stejného uživatele, aby neblokovaly DELETE/TRUNCATE
    $pg->exec("SELECT pg_terminate_backend(pid) 
               FROM pg_stat_activity 
               WHERE usename = current_user 
                 AND state = 'idle' 
                 AND pid <> pg_backend_pid()");
    
    // Nastavíme limit na čekání na zámek
    $pg->exec("SET lock_timeout = '10s'");
    logMsg("Cleanup sessions & lock_timeout nastaven", $isCli, $logMode, $logData);


    // 2. Extract z Oracle
    $oracleSql = "SELECT s.ID, s.CODE, s.NAME, s.ISPRODUCT, s.X_MATCLASS AS X_MATCLASS_ID,
                 (SELECT d2.CODE FROM DEFROLLDATA d2 WHERE d2.id = s.X_MATCLASS AND d2.CLSID = 'SKHQAIFBY0143GYTYOHMW3VGIG') AS X_MATCLASS_CODE,
                 (SELECT d3.NAME FROM DEFROLLDATA d3 WHERE d3.id = s.X_MATCLASS AND d3.CLSID = 'SKHQAIFBY0143GYTYOHMW3VGIG') AS X_MATCLASS_NAME
                 FROM STORECARDS s WHERE s.HIDDEN = 'N'";

    $oracleData = $oci->query($oracleSql);
    $oracleData = array_map(fn($row) => array_change_key_case($row, CASE_LOWER), $oracleData);

    if (empty($oracleData)) {
        throw new RuntimeException('Oracle vrátil 0 řádků.');
    }
    logMsg("Načteno " . count($oracleData) . " řádků z Oracle", $isCli, $logMode, $logData);


    // 3. Sync Process
    require_once CONF_PATH;
    $sourceSystem = ociname;

    $pg->beginTransaction();
    logMsg("Zahajuji promazání cílové tabulky...", $isCli, $logMode, $logData);
    
    // DELETE může být pomalý u 50k+ řádků, TRUNCATE je bleskový, pokud projde přes zámky
    $pg->exec("DELETE FROM public.cache_storecards");

    //$pg->exec("TRUNCATE TABLE public.cache_storecards RESTART IDENTITY");

    $insertSql = "INSERT INTO public.cache_storecards
                  (id, code, \"name\", isproduct, x_matclass_id, x_matclass_code, x_matclass_name, source_system, synced_at, is_deleted)
                  VALUES (:id, :code, :name, :isproduct, :x_matclass_id, :x_matclass_code, :x_matclass_name, :source_system, :synced_at, false)";

    $stmt = $pg->prepare($insertSql);
    $syncedAt = date('Y-m-d H:i:s');
    $rowCount = 0;

    foreach ($oracleData as $row) {
        $stmt->execute([
            ':id' => $row['id'] ?? null,
            ':code' => $row['code'] ?? null,
            ':name' => $row['name'] ?? null,
            ':isproduct' => $row['isproduct'] ?? null,
            ':x_matclass_id' => $row['x_matclass_id'] ?? null,
            ':x_matclass_code' => $row['x_matclass_code'] ?? null,
            ':x_matclass_name' => $row['x_matclass_name'] ?? null,
            ':source_system' => $sourceSystem,
            ':synced_at' => $syncedAt,
        ]);
        $rowCount++;
        if ($rowCount % 5000 === 0) logMsg("> Progress: $rowCount", $isCli, $logMode, $logData);
    }

    $pg->commit();
    $oci->close();
    
    $duration = round(microtime(true) - $startTime, 2);
    logMsg("=== DONE cache_storecards ===", $isCli, $logMode, $logData);
    logMsg("Synchronizováno {$rowCount} záznamů za {$duration} sekund.", $isCli, $logMode, $logData);

    // JSON výstup pro Web
    if ($logMode) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'result' => 'ok', 
            'count' => $rowCount,
            'duration_sec' => $duration, // Přidáno do JSONu 
            'logdata' => $logData
        ], JSON_UNESCAPED_UNICODE);
    }
    exit(0);

} catch (\Throwable $e) {
    if (isset($pg) && $pg->inTransaction()) {
        $pg->rollBack();
    }
    
    logMsg("CHYBA: " . $e->getMessage(), $isCli, $logMode, $logData);

    if ($logMode) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['result' => 'error', 'message' => $e->getMessage(), 'logdata' => $logData], JSON_UNESCAPED_UNICODE);
    } else {
        fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
    }
    exit(1);
}
