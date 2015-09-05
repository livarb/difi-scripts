<?php
header('Content-Type: text/html; charset=utf-8');

require('simple_html_dom.php');

// For creating machine readable data from valglokaler.no
// Livar Bergheim - 2015-08-28
// NB! Don

$time_start = microtime(true); // start measuring run-time of script

$valglokaleEndpoint = "http://valglokaler.no/wp-admin/admin-ajax.php";

$pointsUrl = "http://valglokaler.no/wp-content/themes/valg-theme/js/valglokaler.js"; // stemming på dagen
// $pointsUrl = "http://valglokaler.no/wp-content/themes/valg-theme/js/valglokaler-pre.js"; // stemming i forkant

$testdata = '<div class="lokale-preview">
				<h2><a href="http://valglokaler.no/valglokale/furulund/">Furulund</a></h2>
				<p class="address"> 1798 Aremark</p>
				<h3 class="times-heading">Opningstider:</h3>
				<p class="times"><strong>13.09.2015</strong>: kl 14:00&mdash;19:00<br /><strong>14.09.2015</strong>: kl 09:00&mdash;19:00</p>
				
			</div>';

function nap() {
	// time_nanosleep(0, 200000000);
}

// http://stackoverflow.com/a/6609181/2252177
function fetchValglokale($id) {
	$data = array(
			'action' => 'get_valglokale',
			 'post_id' => $id,
			 'lang' => 'nn'
			);	

	$options = array(
	    'http' => array(
	        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
	        'method'  => 'POST',
	        'content' => http_build_query($data),
	    ),
	);
	$context  = stream_context_create($options);
	$result = file_get_contents($GLOBALS['valglokaleEndpoint'], false, $context);
	$GLOBALS['httpCalls']++;

	$html = new simple_html_dom();
	$html->load($result);

	return $html;
}

function parseValglokale($arrayPos, $valglokale) {
	fixCoords($arrayPos);

	// Parse data
	$GLOBALS['pointsData'][$arrayPos]['name'] = html_entity_decode($valglokale->find('h2', 0)->children(0)->innertext);
	$GLOBALS['pointsData'][$arrayPos]['url'] = $valglokale->find('h2', 0)->children(0)->href;
	$address = $valglokale->find('p[class=address]', 0)->innertext;
	$address = str_replace('<br/>', ',', $address);
	$address = trim($address);
	$GLOBALS['pointsData'][$arrayPos]['address'] = $address;
	$times = $valglokale->find('p[class=times]', 0);
	// print($valglokale);
	if ($times) {
		$times = $times->innertext;
		$times = str_replace('<br />', ', ', $times);
		$times = strip_tags($times);
		// $times = htmlspecialchars_decode($times);
		$times = str_replace('&mdash;', '-', $times);
		$GLOBALS['pointsData'][$arrayPos]['times'] = $times;
	} else {
		$times = $valglokale->find('div[class=intotext]', 0);
		if ($times) { // pre-valglokale
			$times = trim($times->innertext);
			$GLOBALS['pointsData'][$arrayPos]['times'] = $times;
		} else { // opningstid ikkje angitt
			// print($valglokale . "\n\n");
			$GLOBALS['pointsData'][$arrayPos]['times'] = "";
		}
	}
}

function fixCoords($arrayPos) {
	$lat = trim($GLOBALS['pointsData'][$arrayPos]['latlon']['lat']);	
	$lon = trim($GLOBALS['pointsData'][$arrayPos]['latlon']['lon']);
	if (!(is_numeric($lat) && is_numeric($lon))) {
		$lat = "";
		$lon = "";
	}
	$GLOBALS['pointsData'][$arrayPos]['lat'] = $lat;
	$GLOBALS['pointsData'][$arrayPos]['lon'] = $lon;
}

function getValglokaleDetails($arrayPos) {
	$url = $GLOBALS['pointsData'][$arrayPos]['url'];
	$rawHTML = file_get_contents($url);
	$GLOBALS['httpCalls']++;

	$html = new simple_html_dom();
	$html->load($rawHTML);	

	$fylke = $html->find('div[id=breadcrumbs]', 0)->children(6)->innertext;
	$kommune = $html->find('div[id=breadcrumbs]', 0)->children(8)->innertext;
	$lastModified = $html->find('time', 0)->datetime;
	if (strlen($lastModified) == 9) {
		$lastModified = substr($lastModified, 0, -1) . "0" . substr($lastModified, -1);
	}
	$GLOBALS['pointsData'][$arrayPos]['county'] = $fylke;
	$GLOBALS['pointsData'][$arrayPos]['municipality'] = $kommune;
	$GLOBALS['pointsData'][$arrayPos]['lastModified'] = $lastModified;
}

function getKommuneNames() {
	$url = "http://hotell.difi.no/download/difi/geo/kommune";
	$dataCSV = file($url);
	$kommunar = array();
	for ($i = 1; $i < count($dataCSV); $i++) {
		$kommunar[] = str_getcsv($dataCSV[$i], ";")[2];
	}
	return $kommunar;
}

function getFylkeNames() {
	$url = "http://hotell.difi.no/download/difi/geo/fylke";
	$dataCSV = file($url);
	$fylke = array();
	for ($i = 1; $i < count($dataCSV)-2; $i++) { // NB. Hoppar over to siste; "Kontinentalsokkelen" og "Uoppgitt"
		$fylke[] = str_getcsv($dataCSV[$i], ";")[1];
	}

	// Kompenserar for verdiar som avvik frå standard namn på fylker
	$fylke[] = "Troms Romsa";
	$fylke[] = "Finnmark Finnmárku";

	return $fylke;
}

function verifyCountyAndMunicipality($valglokale) {
	if ($valglokale['county']) {
		if (!in_array($valglokale['county'], $GLOBALS['fylke'])) {
			print("FEIL! " . $valglokale['county'] . " er ikkje eit fylke.\n");
		}
	}
	if ($valglokale['municipality']) {
		if ($valglokale['county'] != "Oslo") { // fordi Oslo brukar bydelar
			if (!in_array($valglokale['municipality'], $GLOBALS['kommunar'])) {
				print("FEIL! " . $valglokale['municipality'] . " er ikkje eit kommunenamn.\n");
			}
		}
	}
}

// Corrects known bugs
function fixCountyAndMunicipality($id) {
	// "Nesodden bedehus"
	if ($GLOBALS['pointsData'][$id]['post_id'] == 69690) { // fylke(Hordaland) er dobbelt-opp
		$GLOBALS['pointsData'][$id]['municipality'] = "Samnanger";
	// "Fiane skole"
	} else if ($GLOBALS['pointsData'][$id]['post_id'] == 69629) { // fylke(Aust-Agder) er dobbelt-opp
		$GLOBALS['pointsData'][$id]['municipality'] = "Gjerstad";
	// "Salangen Kulturhus"
	} else if ($GLOBALS['pointsData'][$id]['post_id'] == 69223) { // kommune og fylke er omvendt i breadcrumbs
		$GLOBALS['pointsData'][$id]['municipality'] = "Salangen";
		$GLOBALS['pointsData'][$id]['county'] = "Troms Romsa";
	// "Rønholt skole"
	} else if ($GLOBALS['pointsData'][$id]['post_id'] == 69608) { // kommune og fylke er omvendt i breadcrumbs
		$GLOBALS['pointsData'][$id]['municipality'] = "Bamble";
		$GLOBALS['pointsData'][$id]['county'] = "Telemark";		
	}
}

$kommunar = getKommuneNames();
$fylke = getFylkeNames();

$httpCalls = 1;
$pointsDataRaw = file_get_contents($pointsUrl);
$pointsDataRaw = substr($pointsDataRaw, 18);
$pointsData = json_decode($pointsDataRaw, true);

print ("id;navn;fylke;kommune;addresse;aapningstider;lat;lon;url;sistEndret\n");

for ($i = 0; $i < count($pointsData); $i++) {
	set_time_limit(10); // set timeout på nytt for kvart valglokale

	parseValglokale(
		$i,
		fetchValglokale($pointsData[$i]["post_id"])
	);

	nap();

	getValglokaleDetails($i);

	fixCountyAndMunicipality($i);

	$vl = $pointsData[$i];
	print(
		$vl['post_id'] . ";"
		. "\"" . $vl['name'] . "\"" . ";"
		. $vl['county'] . ";"
		. $vl['municipality'] . ";"		
		. "\"" . $vl['address'] . "\"" . ";"
		. "\"" . $vl['times'] . "\"" . ";"
		. $vl['lat'] . ";"
		. $vl['lon'] . ";"		
		. $vl['url'] . ";"
		. $vl['lastModified']
		. "\n");

	verifyCountyAndMunicipality($vl);

	flush();

	nap();

	// if ($i == 10) break;
}

getKommuneNames();

$time_end = microtime(true);
$time = number_format($time_end - $time_start, 2);

print("\nValglokaler: " . count($pointsData) . "\n");

print("HTTP-kall: " . $httpCalls . "\n");
print("Køyretid: " . $time . " sekund\n" . number_format($httpCalls / $time, 1) . " kall/sekund." . "\n");
?>