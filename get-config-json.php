<?php
require_once '../../bootstrap.php';
// Jednoduchý proxy skript pro načtení konfiguračních JSONů
$file = $_GET['file'] ?? '';
$path = realpath('../../../settings/' . $file . '.JSON');

if ($path && file_exists($path)) {
    header('Content-Type: application/json');
    echo file_get_contents($path);
} else {
    http_response_code(404);
}
