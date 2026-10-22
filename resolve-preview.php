<?php
require_once '../../bootstrap.php';
require_once BASE_PATH . '/vendor/autoload.php';

use LabelCenter\Db\PgConnection;
use LabelCenter\Placeholders\PlaceholderResolver;
use LabelCenter\Placeholders\ExpressionEvaluator;

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) throw new Exception("Invalid input");

    $pg = new PgConnection('labelcenter');
    
    // 1. Získáme kontextová data z DB (pokud nejsou v inputu)
    // Tady bys mohl dotáhnout detaily o storecard nebo obchodním případu pro resolver
    $context = [
        'UCT.JO.VENDOR' => $input['bus_transaction']['code'] ?? '---',
        'UNI.DT.YYcw'   => date('Y-m-d'), // Live čas
        'UNI.DT.cwYY'   => date('Y-m-d'),
    ];

    // Pokud potřebujeme data z konkrétní storecard (např. pro SCC resolvery)
    if (!empty($input['storecard_id'])) {
        $card = $pg->query("SELECT code FROM cache_storecards WHERE id = :id", [':id' => $input['storecard_id']]);
        if ($card) {
            $context['UCT.SCC.P1'] = $card[0]['code']; // Resolver si to přebere
            $context['UCT.SCC.P2'] = $card[0]['code'];
            $context['UCT.SCC.REV'] = $card[0]['code'];
        }
    }

    // 2. Inicializace tvého Evaluatoru
    $resolver = new PlaceholderResolver();
    $evaluator = new ExpressionEvaluator($resolver);
    
    $results = [];

    // 3. Projdeme pole poslaná z gridu a resolvujeme jejich obsah
    foreach ($input['fields'] as $field) {
        $name = $field['name'];    // např. "stext1"
        $expr = $field['content']; // např. "{UCT.JO.VENDOR}-{UNI.DT.YYcw}"
        
        // Použijeme tvůj evaluator
        $results[$name] = $evaluator->evaluate($expr, $context);
    }

    echo json_encode($results);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
