<?php
/*
|--------------------------------------------------------------------------
| fuxa_select.php
|--------------------------------------------------------------------------
| Sélectionne l'UI active pour la télécommande universelle FUXA.
| Exemple : http://10.0.0.39:8085/fuxa_select.php?ui=1
*/

$stateFile = __DIR__ . '/fuxa_selected_ui.json';

$ui = isset($_GET['ui']) ? (int)$_GET['ui'] : 0;

if ($ui <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Parametre ui manquant ou invalide. Exemple: fuxa_select.php?ui=1'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$data = [
    'selected_ui' => $ui,
    'updated_at' => date('c')
];

file_put_contents($stateFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

echo json_encode([
    'ok' => true,
    'selected_ui' => $ui
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
