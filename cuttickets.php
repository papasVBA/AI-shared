<?php
    include("../apps/settings/conf.php");   
    include("../apps/settings/errlog.php"); 


        function exceptions_error_handler($severity, $message, $filename, $lineno) {
        throw new ErrorException($message, 0, $severity, $filename, $lineno);
        logto(basename(__FILE__).">> ".$message);
        $out = array("errmess"=>$message);
        //echo('{"status":{"result":"NOK"}, "data":['.json_encode($out).']}'); 
        echo('{"status":{"result":"NOK"}, "data":['.json_encode($out).']}'); 
        die();
    }


    set_error_handler('exceptions_error_handler');


// načtení parametru scid z URL
$sccode = isset($_GET['sccode']) ? trim($_GET['sccode']) : '';

if ($sccode === '') {
    echo 'Parametr sccode není zadán.';
    exit;
}


$sql = <<<SQL
SELECT COALESCE (c.CISLO_VODICE, ' ') AS CISLO_VODICE , COALESCE (c.DELKA_STRIHU, 0) AS DELKA_STRIHU , COALESCE (c.JEDNOTKA_STRIHU, ' ') AS JEDNOTKA_STRIHU , 
COALESCE (c.TYP_VODICE, ' ') AS TYP_VODICE , COALESCE (c.JEDNOTKA_ODHOLENI, ' ') AS JEDNOTKA_ODHOLENI ,
COALESCE (c.XX_ODHOLENI, 0) AS XX_ODHOLENI , COALESCE (c.XX_TERMINAL, ' ') AS XX_TERMINAL , COALESCE (c.XX_SPOJENI, ' ') AS XX_SPOJENI , 
COALESCE (c.XX_POTISK, ' ') AS XX_POTISK , COALESCE (c.XX_POZNAMKA, ' ') AS XX_POZNAMKA ,
COALESCE (c.YY_ODHOLENI, 0) AS YY_ODHOLENI , COALESCE (c.YY_TERMINAL, ' ') AS YY_TERMINAL , COALESCE (c.YY_SPOJENI, ' ') AS YY_SPOJENI , 
COALESCE (c.YY_POTISK, ' ') AS YY_POTISK , COALESCE (c.YY_POZNAMKA, ' ') AS YY_POZNAMKA
FROM CONDUCTORS c 
WHERE c.FINAL = '{$sccode}'
ORDER BY c.CISLO_VODICE
SQL;


        if ($c = oci_connect(ociname, ocipass, dboci, 'AL32UTF8')){
            //
            $stdid = oci_parse($c,$sql);
            $result = oci_execute($stdid);

$rows = [];
while (($row = oci_fetch_array($stdid, OCI_ASSOC | OCI_RETURN_NULLS)) !== false) {
    $rows[] = $row;
}

            //a zavreme spojeni
            oci_free_statement($stdid);
            oci_close($c);  
        } else {
            echo 'Chyba připojení k databázi.';
            exit;
        }

//var_dump($rows);

?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title>Střižné lístky <?php echo htmlspecialchars($sccode, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        table { border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 4px 8px; }
        th { background: #eee; }
        .lvl0 { font-weight: bold; }
        .xsite { background: beige;}
        .ysite { background: bisque;}
    </style>
</head>
<body>
<h1>Střižné lístky: <?php echo htmlspecialchars($sccode, ENT_QUOTES, 'UTF-8'); ?></h1>

<?php 
if (count($rows) === 0) {
    echo 'Střižné lístky: ' . htmlspecialchars($sccode, ENT_QUOTES, 'UTF-8') . ' nebyly nalezeny.';
    exit;
}

?>
<table>
    <thead>
    <tr>
        <th>WNR</th>
        <th>Střih</th>
        <th>MU střih</th>
        <th>Vodič</th>
        <th>MU odhol.</th>

        <th class="xsite">X_ODHOL</th>
        <th class="xsite">X_TERM</th>
        <th class="xsite">X_SPOJ</th>
        <th class="xsite">X_POT</th>
        <th class="xsite">X_POZN</th>

        <th class="ysite">Y_ODHOL</th>
        <th class="ysite">Y_TERM</th>
        <th class="ysite">Y_SPOJ</th>
        <th class="ysite">Y_POT</th>
        <th class="ysite">Y_POZN</th>

    </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr class="lvl">
            <td><?php echo htmlspecialchars($r['CISLO_VODICE']); ?></td>
            <td><?php echo htmlspecialchars($r['DELKA_STRIHU']); ?></td>
            <td><?php echo htmlspecialchars($r['JEDNOTKA_STRIHU']); ?></td>
            <td><?php echo htmlspecialchars($r['TYP_VODICE']); ?></td>
            <td><?php echo htmlspecialchars($r['JEDNOTKA_ODHOLENI']); ?></td>

            <td><?php echo htmlspecialchars($r['XX_ODHOLENI']); ?></td>
            <td><?php echo htmlspecialchars($r['XX_TERMINAL']); ?></td>
            <td><?php echo htmlspecialchars($r['XX_SPOJENI']); ?></td>
            <td><?php echo htmlspecialchars($r['XX_POTISK']); ?></td>
            <td><?php echo htmlspecialchars($r['XX_POZNAMKA']); ?></td>

            <td><?php echo htmlspecialchars($r['YY_ODHOLENI']); ?></td>
            <td><?php echo htmlspecialchars($r['YY_TERMINAL']); ?></td>
            <td><?php echo htmlspecialchars($r['YY_SPOJENI']); ?></td>
            <td><?php echo htmlspecialchars($r['YY_POTISK']); ?></td>
            <td><?php echo htmlspecialchars($r['YY_POZNAMKA']); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>
