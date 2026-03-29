<?php
require 'vendor/autoload.php';
ob_implicit_flush(true);
while(ob_get_level())ob_end_flush();
$spreadsheetId=getenv('GOOGLE_SHEET_ID');
$url_lista_impianti=getenv('URL_LISTA_IMPIANTI');
$creds_json=getenv('GOOGLE_CREDS');
$nomeFoglio='Foglio2';
$dimensione_lotto=500;
$client=new \Google\Client();
$client->setAuthConfig(json_decode($creds_json,true));
$client->addScope(\Google\Service\Sheets::SPREADSHEETS);
$service=new \Google\Service\Sheets($client);
try{
$res=$service->spreadsheets_values->get($spreadsheetId,$nomeFoglio.'!Z1');
$v=$res->getValues();
$ultimo_indice=isset($v[0][0])?(int)$v[0][0]:0;
}catch(Exception $e){$ultimo_indice=0;}
$json_lista=file_get_contents($url_lista_impianti);
$impianti_totali=json_decode($json_lista,true);
$totale_assoluto=count($impianti_totali);
if($ultimo_indice>=$totale_assoluto){
// Controlla quante righe ha il foglio prima di fare clear
$sheetMeta=$service->spreadsheets->get($spreadsheetId);
$righe=1;
foreach($sheetMeta->getSheets() as $sheet){
if($sheet->getProperties()->getTitle()===$nomeFoglio){
$righe=$sheet->getProperties()->getGridProperties()->getRowCount();
break;
}
}
if($righe>1){
$service->spreadsheets_values->clear($spreadsheetId,$nomeFoglio.'!A2:F',new \Google\Service\Sheets\ClearValuesRequest());
}
$ultimo_indice=0;
}
$lotto=array_slice($impianti_totali,$ultimo_indice,$dimensione_lotto);
echo "Lotto: $ultimo_indice-".($ultimo_indice+count($lotto))."\n";
$rows=[];
foreach($lotto as $id){
$ch=curl_init("https://carburanti.mise.gov.it/ospzApi/registry/servicearea/".$id);
curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
curl_setopt($ch,CURLOPT_TIMEOUT,5);
curl_setopt($ch,CURLOPT_HTTPHEADER,['User-Agent:Mozilla/5.0','Origin:https://carburanti.mise.gov.it','Referer:https://carburanti.mise.gov.it/']);
$res=curl_exec($ch);
$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
curl_close($ch);
if($code===200){
$d=json_decode($res,true);
if(isset($d['fuels'])){
foreach($d['fuels'] as $f){
$rows[]=[$id,$f['name'],$f['price'],(!empty($f['isSelf']))?'Sì':'No',$f['validityDate'],date('Y-m-d H:i:s')];
}
echo "$id ";
}}
if(count($rows)>=60){
$body=new \Google\Service\Sheets\ValueRange(['values'=>$rows]);
$service->spreadsheets_values->append($spreadsheetId,$nomeFoglio.'!A2',$body,['valueInputOption'=>'RAW']);
$rows=[];
}
usleep(200000);
}
if(!empty($rows)){
$body=new \Google\Service\Sheets\ValueRange(['values'=>$rows]);
$service->spreadsheets_values->append($spreadsheetId,$nomeFoglio.'!A2',$body,['valueInputOption'=>'RAW']);
}
$nuovo=$ultimo_indice+count($lotto);
if($nuovo>=$totale_assoluto)$nuovo=$totale_assoluto+1;
$service->spreadsheets_values->update($spreadsheetId,$nomeFoglio.'!Z1',new \Google\Service\Sheets\ValueRange(['values'=>[[$nuovo]]]),['valueInputOption'=>'RAW']);
echo "\nOK: $nuovo\n";
