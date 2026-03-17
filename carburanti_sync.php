<?php
require 'vendor/autoload.php';

// --- FORZA L'OUTPUT IMMEDIATO ---
ob_implicit_flush(true);
while (ob_get_level()) ob_end_flush();

$spreadsheetId = getenv('GOOGLE_SHEET_ID');
$nomeFoglio = 'Foglio1'; 
$url_lista_impianti = getenv('URL_LISTA_IMPIANTI');
$dimensione_lotto = 500; 

// 1. CONNESSIONE GOOGLE
$creds_json = getenv('GOOGLE_CREDS');
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

// 4. CICLO CON CRAWLING AGGRESSIVO
foreach ($lotto as $idx => $id) {
    $url = "https://carburanti.mise.gov.it/ospzApi/registry/servicearea/" . $id;
    
    // Usiamo CURL invece di file_get_contents per gestire i timeout
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Non aspettare più di 5 secondi
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/122.0.0.0',
        'Origin: https://carburanti.mise.gov.it',
        'Referer: https://carburanti.mise.gov.it/'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $dati = json_decode($response, true);
        if (isset($dati['fuels'])) {
            foreach ($dati['fuels'] as $f) {
                $rows[] = [$id, $f['name'], $f['price'], (!empty($f['isSelf'])) ? 'Sì' : 'No', $f['validityDate'], date('Y-m-d H:i:s')];
            }
            $contatore_ok++;
            echo "($id:OK) "; // Ti stampa l'ID appena lo trova!
        } else {
            echo "."; 
        }
    } else {
        echo "x"; 
    }

    // Salva ogni 20 impianti trovati (circa ogni 60 righe) per non perdere dati
    if (count($rows) >= 60) {
        echo "\n[Scrittura parziale su Google Sheets...]\n";
        $body = new \Google\Service\Sheets\ValueRange(['values' => $rows]);
        $service->spreadsheets_values->append($spreadsheetId, $nomeFoglio . '!A2', $body, ['valueInputOption' => 'RAW']);
        $rows = [];
    }

    usleep(200000); // 0.2 secondi di pausa
}

// 5. CHIUSURA
if (!empty($rows)) {
    $body = new \Google\Service\Sheets\ValueRange(['values' => $rows]);
    $service->spreadsheets_values->append($spreadsheetId, $nomeFoglio . '!A2', $body, ['valueInputOption' => 'RAW']);
}

$nuovo_indice = $ultimo_indice + count($lotto);
if ($nuovo_indice >= $totale_assoluto) $nuovo_indice = $totale_assoluto + 1;

$service->spreadsheets_values->update($spreadsheetId, $nomeFoglio . '!Z1', new \Google\Service\Sheets\ValueRange(['values' => [[$nuovo_indice]]]), ['valueInputOption' => 'RAW']);

echo "\nFatto! Impianti validi aggiunti: $contatore_ok. Prossimo indice: $nuovo_indice\n";
