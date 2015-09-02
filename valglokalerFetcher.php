<?php
header('Content-Type: text/html; charset=utf-8');

require('simple_html_dom.php');

// For creating machine readable data from valglokaler.no
// Livar Bergheim - 2015-08-28
// NB! Don

$valglokaleEndpoint = "http://valglokaler.no/wp-admin/admin-ajax.php";

// stemming på dagen
$pointsUrl = "http://valglokaler.no/wp-content/themes/valg-theme/js/valglokaler.js";

// stemming i forkant
// $pointsUrl = "http://valglokaler.no/wp-content/themes/valg-theme/js/valglokaler-pre.js";

$testdata = '<div class="lokale-preview">
				<h2><a href="http://valglokaler.no/valglokale/furulund/">Furulund</a></h2>
				<p class="address"> 1798 Aremark</p>
				<h3 class="times-heading">Opningstider:</h3>
				<p class="times"><strong>13.09.2015</strong>: kl 14:00&mdash;19:00<br /><strong>14.09.2015</strong>: kl 09:00&mdash;19:00</p>
				
			</div>';

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

$httpCalls = 1;
$pointsDataRaw = file_get_contents($pointsUrl);
$pointsDataRaw = substr($pointsDataRaw, 18);
// print_r($pointsDataRaw);
$pointsData = json_decode($pointsDataRaw, true);

// 68592

print ("id;lat;lon;name;address;openinghours;url;county;municipality;lastModified\n");

for ($i = 0; $i < count($pointsData); $i++) {
	set_time_limit(10); // set timeout på nytt for kvart valglokale

	parseValglokale(
		$i,
		fetchValglokale($pointsData[$i]["post_id"])
	);

	time_nanosleep(0, 200000000);

	getValglokaleDetails($i);

	time_nanosleep(0, 200000000);

	$vl = $pointsData[$i];
	print(
		$vl['post_id'] . ";"
		. $vl['lat'] . ";"
		. $vl['lon'] . ";"
		. "\"" . $vl['name'] . "\"" . ";"
		. "\"" . $vl['address'] . "\"" . ";"
		. "\"" . $vl['times'] . "\"" . ";"
		. $vl['url'] . ";"
		. $vl['county'] . ";"
		. $vl['municipality'] . ";"
		. $vl['lastModified']
		. "\n");

	time_nanosleep(0, 200000000);

	// ob_flush();
	flush();

	// if ($i == 50) break;
}

print("\n" . count($pointsData) . "\n");

print("\n" . $httpCalls . "\n\n");
// print_r($pointsData);
?>