<?php
require 'vendor/autoload.php';

// --- FORZA L'OUTPUT IMMEDIATO ---
ob_implicit_flush(true);
while (ob_get_level()) ob_end_flush();

// --- CONFIGURAZIONE ANONIMIZZATA ---
$spreadsheetId      = getenv('GOOGLE_SHEET_ID');
$url_lista_impianti = getenv('URL_LISTA_IMPIANTI');
$creds_json         = getenv('GOOGLE_CREDS');
$nomeFoglio         = 'Foglio1'; 
$dimensione_lotto   = 500; 

// --- VERIFICA IP CORRENTE (Per tuo controllo) ---
$current_ip = file_get_contents('https://api.ipify.org');
echo "Esecuzione avviata con IP: $current_ip\n\n";

// 1. CONNESSIONE GOOGLE
$client = new \Google\Client();
$client->setAuthConfig(json_decode($creds_json, true));
$client->addScope(\Google\Service\Sheets::SPREADSHEETS);
$service = new \Google\Service\Sheets($client);

// 2. RECUPERO INDICE
try {
    $resIndex = $service->spreadsheets_values->get($spreadsheetId, $nomeFoglio . '!Z1');
    $values = $resIndex->getValues();
    $ultimo_indice = isset($values[0][0]) ? (int)$values[0][0] : 0;
} catch (Exception $e) { $ultimo_indice = 0; }

// 3. RECUPERO LISTA
$json_lista = file_get_contents($url_lista_impianti);
$impianti_totali = json_decode($json_lista, true);
$totale_assoluto = count($impianti_totali);

if ($ultimo_indice >= $totale_assoluto) {
    echo "Fine lista raggiunta. Svuoto il foglio...\n";
    $service->spreadsheets_values->clear($spreadsheetId, $nomeFoglio . '!A2:F', new \Google\Service\Sheets\ClearValuesRequest());
    $ultimo_indice = 0;
}

$lotto = array_slice($impianti_totali, $ultimo_indice, $dimensione_lotto);
echo "Inizio analisi lotto: da $ultimo_indice a " . ($ultimo_indice + count($lotto)) . " su $totale_assoluto\n";

$rows = [];
$contatore_ok = 0;

// 4. CICLO CRAWLING
foreach ($lotto as $idx => $id) {
    $url = "https://carburanti.mise.gov.it/ospzApi/registry/servicearea/" . $id;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
