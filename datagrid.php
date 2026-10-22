<?php
// /api/datagrid.php
// upravy pro alokaci afterinsert labelrow
require_once  '../../bootstrap.php';
require_once BASE_PATH . '/vendor/autoload.php';

use LabelCenter\Db\PgConnection;

header('Content-Type: application/json; charset=utf-8');

$grid = $_GET['grid'] ?? '';
$searchValue = trim($_GET['searchValue'] ?? '');
$skip = (int)($_GET['skip'] ?? 0);
$take = (int)($_GET['take'] ?? 10);
$filterType = $_GET['filterType'] ?? ''; 
$dxFilter = $_GET['filter'] ?? null;  
$dxSort = $_GET['sort'] ?? null;
$locateId = $_GET['locateId'] ?? null;

//a vyjimka pro treelist
if ($grid === "busTransactions") {
    $take = 1000;
}

function buildPgFilter($filter, &$params, $nameMapping) {
    if (empty($filter)) return "";

    // Základní trojice [pole, operátor, hodnota]
    if (is_array($filter) && count($filter) === 3 && is_string($filter[0])) {
        $field    = $filter[0]; 
        $operator = strtolower($filter[1]);
        $value    = $filter[2];

        $column = $nameMapping[$field] ?? "\"$field\""; // Postgres vyžaduje uvozovky pro case-sensitive názvy
        $pName = "p" . count($params);

        switch ($operator) {
            case "=": 
                $params[$pName] = $value; 
                return "$column::text = :$pName";
            case "contains": 
                $params[$pName] = $value; 
                return "$column::text ILIKE '%' || :$pName || '%'";
            case "startswith": 
                $params[$pName] = $value; 
                return "$column::text ILIKE :$pName || '%'";
            case "endswith": 
                $params[$pName] = $value; 
                return "$column::text ILIKE '%' || :$pName";
            case "in":
            case "notin":
                if (is_array($value) && !empty($value)) {
                    $inParams = [];
                    foreach ($value as $v) {
                        $pName = "p" . count($params);
                        $params[$pName] = $v;
                        $inParams[] = ":$pName";
                    }
                    $op = ($operator === 'in') ? 'IN' : 'NOT IN';
                    return "$column $op (" . implode(",", $inParams) . ")";
                }
                return "1=1";
            default: 
                $params[$pName] = $value;
                return "$column::text = :$pName";
        }
    }

    // Rekurzivní zpracování složených filtrů (AND / OR)
    $sqlParts = [];
    $logic = " AND ";
    foreach ($filter as $item) {
        if (is_array($item)) {
            $subSql = buildPgFilter($item, $params, $nameMapping);
            if ($subSql) $sqlParts[] = "(" . $subSql . ")";
        } else if (is_string($item)) {
            $uItem = strtoupper($item);
            if ($uItem === "OR") $logic = " OR ";
        }
    }
    return !empty($sqlParts) ? implode($logic, $sqlParts) : "";
}

// ... předchozí parametry ($grid, $skip, $take, atd.) zůstávají

try {
    $pg = new PgConnection('labelcenter');
    
    // Zde budeme volat konfigurační funkci (tu mi pošlete v další části)
    $config = getGridConfig($grid, $filterType);
    $nameMapping = $config['nameMapping'] ?? [];

    $sql = $config['sql'];
    $searchCols = $config['searchCols'];
    $params = [];
    
    // 1. Globální vyhledávání (SearchBox v DevExtreme)
    if ($searchValue !== '') {
        $searchCond = [];
        foreach ($searchCols as $col) {
            $fullCol = $nameMapping[$col] ?? "\"$col\"";
            $pName = "search_" . bin2hex(random_bytes(2));
            $searchCond[] = "$fullCol::text ILIKE :$pName";
            $params[$pName] = '%' . $searchValue . '%';
        }
        $sql .= ' AND (' . implode(' OR ', $searchCond) . ')';
    }

    // 2. Specifické filtry z řádků gridu
    if ($dxFilter) {
        $filterArray = json_decode($dxFilter, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $filterSql = buildPgFilter($filterArray, $params, $nameMapping);
            if (!empty($filterSql)) {
                $sql .= " AND ($filterSql)";
            }
        }
    }


    // 3. Řazení z DevExtreme
    // Výchozí řazení z konfigurace gridu
    $orderBy = $config['orderBy'];

    if ($dxSort) {

        $sortArray = json_decode($dxSort, true);

        if (
            json_last_error() === JSON_ERROR_NONE &&
            is_array($sortArray)
        ) {

            $sortParts = [];

            foreach ($sortArray as $sortItem) {

                $selector = $sortItem['selector'] ?? null;

                // Selector MUSÍ existovat v nameMapping.
                // Do SQL tedy nikdy neposíláme název sloupce
                // převzatý přímo z browseru.
                if (
                    $selector !== null &&
                    isset($nameMapping[$selector])
                ) {

                    $direction =
                        !empty($sortItem['desc'])
                            ? 'DESC'
                            : 'ASC';

                    $sortParts[] =
                        $nameMapping[$selector] .
                        ' ' .
                        $direction;
                }
            }

            if (!empty($sortParts)) {
                $orderBy = implode(', ', $sortParts);
            }
        }
    }
    // 3. Řazení z DevExtreme - KONEC


// Stabilní řazení.
// Při shodné hodnotě řazeného sloupce rozhodne ID.
if ($grid === 'labels') {
    $orderBy .= ', sll.id ASC';
}

if ($grid === 'templates') {
    $orderBy .= ', slt.id ASC';
}

// ---------------------------------------------------------
// LOCATE LABEL
// Zjistí skutečnou pozici labelu v aktuálně
// filtrovaném a seřazeném datasetu.
// ---------------------------------------------------------

// stara verze bez templates
/*
if ($locateId !== null && $grid === 'labels') {

    // Povolené názvy výstupních sloupců SELECTu.
    // Záměrně nepoužíváme hodnotu z browseru přímo v SQL.
    $locateSortMapping = [
        'id'       => 'id',
        'name'     => 'name',
        'tempname' => 'tempname',
        'tempid'   => 'tempid',
        'qpp'      => 'qpp',
        'scid'     => 'scid',
        'sccode'   => 'sccode',
        'scname'   => 'scname',
        'notice'   => 'notice'
    ];

    $locateSortParts = [];

    // Pokud uživatel grid explicitně seřadil,
    // použijeme stejné řazení.
    if ($dxSort) {

        $sortArray = json_decode($dxSort, true);

        if (
            json_last_error() === JSON_ERROR_NONE &&
            is_array($sortArray)
        ) {

            foreach ($sortArray as $sortItem) {

                $selector =
                    $sortItem['selector'] ?? null;

                if (
                    $selector !== null &&
                    isset($locateSortMapping[$selector])
                ) {

                    $direction =
                        !empty($sortItem['desc'])
                            ? 'DESC'
                            : 'ASC';

                    $locateSortParts[] =
                        $locateSortMapping[$selector] .
                        ' ' .
                        $direction;
                }
            }
        }
    }

    // Bez uživatelského sortu odpovídá
    // výchozímu řazení labels.
    if (empty($locateSortParts)) {
        $locateSortParts[] = 'name ASC';
    }

    // Stabilní pořadí při shodných hodnotách.
    // ID je unikátní.
    $locateSortParts[] = 'id ASC';

    $locateOrderBy =
        implode(', ', $locateSortParts);

    $locateSql = "
        SELECT row_index
        FROM (
            SELECT
                id,
                ROW_NUMBER() OVER (
                    ORDER BY $locateOrderBy
                ) - 1 AS row_index
            FROM ($sql) AS locate_source
        ) AS located
        WHERE id = :locateId
    ";

    $locateParams = $params;
    $locateParams['locateId'] = $locateId;

    $locateRes =
        $pg->query(
            $locateSql,
            $locateParams
        );

    echo json_encode([
        'rowIndex' =>
            isset($locateRes[0]['row_index'])
                ? (int)$locateRes[0]['row_index']
                : null,
        'locateOrderBy' => $locateOrderBy,
        'dataOrderBy'   => $orderBy                
    ], JSON_UNESCAPED_UNICODE);

    exit;
}
*/

// nova verze LOCATE bloku s doplnenymi templates
// ---------------------------------------------------------
// LOCATE ROW
// Zjistí skutečnou pozici řádku v aktuálně
// filtrovaném a seřazeném datasetu.
// ---------------------------------------------------------
if (
    $locateId !== null &&
    in_array($grid, ['labels', 'templates'], true)
) {

    // -----------------------------------------------------
    // Povolené výstupní sloupce jednotlivých gridů.
    // Nikdy nepoužíváme selector z browseru přímo v SQL.
    // -----------------------------------------------------
    if ($grid === 'labels') {

        $locateSortMapping = [
            'id'       => 'id',
            'name'     => 'name',
            'tempname' => 'tempname',
            'tempid'   => 'tempid',
            'qpp'      => 'qpp',
            'scid'     => 'scid',
            'sccode'   => 'sccode',
            'scname'   => 'scname',
            'notice'   => 'notice'
        ];

        $defaultLocateSort = 'name ASC';

    } else {

        // templates
        $locateSortMapping = [
            'id'          => 'id',
            'name'        => 'name',
            'btwfilename' => 'btwfilename',
            'printerid'   => 'printerid',
            'printername' => 'printername',
            'scid'        => 'scid',
            'sccode'      => 'sccode',
            'scname'      => 'scname',
            'btid'        => 'btid',
            'btcode'      => 'btcode',
            'btname'      => 'btname',
            'notice'      => 'notice'
        ];

        $defaultLocateSort = 'name ASC';
    }

    $locateSortParts = [];

    // -----------------------------------------------------
    // Uživatelský remote sort
    // -----------------------------------------------------
    if ($dxSort) {

        $sortArray = json_decode(
            $dxSort,
            true
        );

        if (
            json_last_error() === JSON_ERROR_NONE &&
            is_array($sortArray)
        ) {

            foreach ($sortArray as $sortItem) {

                $selector =
                    $sortItem['selector'] ?? null;

                if (
                    $selector !== null &&
                    isset(
                        $locateSortMapping[$selector]
                    )
                ) {

                    $direction =
                        !empty($sortItem['desc'])
                            ? 'DESC'
                            : 'ASC';

                    $locateSortParts[] =
                        $locateSortMapping[$selector] .
                        ' ' .
                        $direction;
                }
            }
        }
    }

    // -----------------------------------------------------
    // Výchozí sort
    // -----------------------------------------------------
    if (empty($locateSortParts)) {
        $locateSortParts[] =
            $defaultLocateSort;
    }

    // -----------------------------------------------------
    // Stabilní pořadí při shodných hodnotách.
    // -----------------------------------------------------
    $locateSortParts[] = 'id ASC';

    $locateOrderBy =
        implode(', ', $locateSortParts);

    // -----------------------------------------------------
    // Zjištění absolutní pozice řádku
    // -----------------------------------------------------
    $locateSql = "
        SELECT row_index
        FROM (
            SELECT
                id,
                ROW_NUMBER() OVER (
                    ORDER BY $locateOrderBy
                ) - 1 AS row_index
            FROM ($sql) AS locate_source
        ) AS located
        WHERE id = :locateId
    ";

    $locateParams = $params;
    $locateParams['locateId'] =
        $locateId;

    $locateRes =
        $pg->query(
            $locateSql,
            $locateParams
        );

    echo json_encode([
        'rowIndex' =>
            isset($locateRes[0]['row_index'])
                ? (int)$locateRes[0]['row_index']
                : null,

        // Zatím necháme diagnostiku.
        'locateOrderBy' =>
            $locateOrderBy,

        'dataOrderBy' =>
            $orderBy

    ], JSON_UNESCAPED_UNICODE);

    exit;
}

    // COUNT - Celkový počet pro pager
    $countSql = "SELECT COUNT(*) as cnt FROM ($sql) as sub";
    $countRes = $pg->query($countSql, $params);
    $totalCount = $countRes[0]['cnt'] ?? 0;

    // PAGING + ORDER BY (Postgres syntaxe)
    //$finalSql = $sql . " ORDER BY " . $config['orderBy'] . " LIMIT :take OFFSET :skip";
    $finalSql = $sql . " ORDER BY " . $orderBy . " LIMIT :take OFFSET :skip";
    
    // PDO vyžaduje, aby LIMIT/OFFSET byly int, ale PgConnection.php 
    // bere parametry jako stringy v execute. 
    // Pro zjednodušení je vložíme přímo do params:
    $params['take'] = $take;
    $params['skip'] = $skip;

    $data = $pg->query($finalSql, $params);

    // Zpracování JSONB a typů (Postgres PDO vrací stringy i u čísel)
    foreach ($data as &$row) {
        if (isset($row['metajson']) && is_string($row['metajson'])) {
            $row['metajson'] = json_decode($row['metajson'], true);
        }
        // DevExtreme preferuje u ID a hlevelů čísla ale pozor, id u cache tabulek je string!!
        if ($grid != "busTransactions" && $grid != "storecards") {
            if (isset($row['id'])) $row['id'] = (int)$row['id'];
            if (isset($row['hlevel'])) $row['hlevel'] = (int)$row['hlevel'];
        }
    }

    echo json_encode([
        'data' => $data, 
        'totalCount' => (int)$totalCount
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

function getGridConfig(string $grid, string $filterType = ''): array {
    return match($grid) {
        'printers' => [
            'sql' => "SELECT slp.id, slp.name, slp.sysname, slp.notice 
                      FROM public.spelman_lc_printers slp WHERE 1=1",
            'searchCols' => ['name', 'sysname', 'notice'],
            'filterCols' => ['name'],
            'orderBy' => 'slp.name',
            'nameMapping' => [
                'id'       => 'slp.id',
                'name'     => 'slp.name',
                'sysname'  => 'slp.sysname',
                'notice'   => 'slp.notice'
            ]               
        ],
        'templates' => [
            'sql' => "SELECT slt.id, slt.name, slt.btw_filename as btwfilename, 
                             slp.id as printerid, slp.name as printername, 
                             s.id as scid, s.code as sccode, s.name as scname,
                             b.id as btid, b.code as btcode, b.name as btname, 
                             slt.meta_json as metajson, slt.notice
                      FROM public.spelman_lc_templates slt
                      LEFT JOIN public.spelman_lc_printers slp ON slt.printer_id = slp.id
                      LEFT JOIN public.cache_storecards s ON slt.storecard_id = s.id
                      LEFT JOIN public.cache_bustransactions b ON slt.bustransaction_id = b.id 
                      WHERE 1=1",
            'searchCols' => ['name', 'btwfilename', 'printername', 'sccode', 'scname', 'btcode'],
            'filterCols' => ['name', 'btwfilename', 'printername', 'sccode', 'btcode'],
            'orderBy' => 'slt.name',
            'nameMapping' => [
                'id'          => 'slt.id',
                'name'        => 'slt.name',
                'btwfilename' => 'slt.btw_filename',
                'printerid'   => 'slp.id',
                'printername' => 'slp.name',
                'scid'        => 's.id',
                'sccode'      => 's.code',
                'scname'      => 's.name',
                'btid'        => 'b.id',
                'btcode'      => 'b.code',
                'btname'      => 'b.name',
                'metajson'    => 'slt.meta_json',
                'notice'      => 'slt.notice'                                
            ]               
        ],
        'labels' => [
            'sql' => "SELECT sll.id, sll.name, slt.name as tempname, sll.template_id as tempid, sll.qpp, 
                             s.id as scid, s.code as sccode, s.name as scname, sll.notice
                      FROM public.spelman_lc_labels sll 
                      LEFT JOIN public.spelman_lc_templates slt ON sll.template_id = slt.id
                      LEFT JOIN public.cache_storecards s ON sll.storecard_id = s.id 
                      WHERE 1=1",
            'searchCols' => ['name', 'tempname', 'sccode', 'scname'],
            'filterCols' => ['name', 'tempname', 'sccode'],
            'orderBy' => 'sll.name, sll.id',
            'nameMapping' => [
                'id'       => 'sll.id',
                'name'     => 'sll.name',
                'tempname' => 'slt.name',
                'tempid'   => 'sll.template_id',
                'scid'     => 's.id',
                'sccode'   => 's.code',
                'scname'   => 's.name',
                'notice'   => 'sll.notice',
                'qpp'      => 'sll.qpp'
            ]                      
        ],
        'storecards' => [
            'sql' => "SELECT  s.id, s.code, s.name 
                      FROM public.cache_storecards s 
                      WHERE " . ($filterType === 'product' ? "s.isproduct = 'A'" : "s.isproduct = 'N' AND s.x_matclass_code in ('9B2', '9B1') ") . " AND 1=1",
            'searchCols' => ['code', 'name'],
            'filterCols' => ['code', 'name'],
            'orderBy' => 's.code',
            'nameMapping' => [
                'id'   => 's.id',
                'code' => 's.code',                
                'name' => 's.name'
            ] 
        ],
        'busTransactions' => [
            'sql' => "SELECT b.id as id, b.parent_id as parentid, b.code as code, b.name as name, b.hlevel as hlevel
                      FROM public.cache_bustransactions b 
                      WHERE 1=1",
            'searchCols' => ['code', 'name'],
            'filterCols' => ['code', 'name'],
            'orderBy' => 'b.hlevel, b.code',
            'nameMapping' => [
                'id'       => 'b.id',
                'parentid' => 'b.parent_id',
                'code'     => 'b.code',                
                'name'     => 'b.name',
                'hlevel'   => 'b.hlevel'
            ] 
        ],
        'fields' => [
            'sql' => "SELECT f.id, f.label_id as labelid, f.field_name as name, f.field_content as content, f.notice 
                      FROM public.spelman_lc_fields f
                      WHERE 1=1",
            'searchCols' => ['name', 'content'],
            'filterCols' => ['id', 'content'],
            'orderBy' => 'f.field_name',
            'nameMapping' => [
                'id'      => 'f.id',
                'labelid' => 'f.label_id',                
                'name'    => 'f.field_name',
                'content' => 'f.field_content',
                'notice'  => 'f.notice',                 
            ] 
        ],        
        default => throw new Exception('Neznámý grid: ' . $grid)
    };
}





?>