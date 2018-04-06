<?php

/* Diese Skript darf nur über MediaWiki aufgerufen werden */
if(defined('MEDIAWIKI') == false) {
  echo("This is an extension to the MediaWiki package and cannot be run standalone.\n");
  die();
}

/* Konfiguration laden */
require_once("RoomState.config.php");

/* Versionsinformationen speichern */
$wgExtensionCredits['parserhook'][] = array(
	'name' => 'RoomState',
	'author' => 'Felix Rublack', 
	'url' => 'https://github.com/C3MA/RoomState', 
	'description' => 'MediaWiki-Plugin zum dynamischen Schalten einer Offen/Geschlossen-Anzeige'
);

/* MediaWiki-Hooks nutzen */
$wgHooks['ParserFirstCallInit'][] = 'roomStateParserInit';

function roomStateParserInit(&$parser) {
  $parser->setHook('RoomState', 'roomStateRender');
  return true;
}

function roomStateRender($input, $args, $parser) {
	global $roomStateDataPath;
	global $roomStateOpen;
	global $roomStateClosed;

	/* MediaWiki-Cache für diese Seite deaktivieren */
	$parser->disableCache();

	/* Argumente bearbeiten
	* Im ersten Schritt werden fehlende Werte auf den günstige Standards
	* gesetzt und diese Werte dann escaped.
	*/
	foreach(array('open', 'closed', 'failed') as $key) {
		if(isset($args[$key]) == false) {
			$args[$key] = "";
		}
		$args[$key] = htmlspecialchars($args[$key]);
	}
  

	if((isset($roomStateOpen) == false) || (isset($roomStateClosed) == false)) {
		/* Map der notwendigen MQTT-Topics */
		$topicTable = array(
			'/room/door/unlocked' => 'doorUnlocked',
			'/room/door/unlocked/timestamp' => 'doorTimestamp',
			'/room/motion/detected/until' => 'lastMotionDetected'
		);

		/* Map der in den Daten gesehenen MQTT-Topics */
		$seenTopics = array_fill_keys(array_keys($topicTable), false);

		/* Datei einlesen und Variablen nach Map zuweisen */
		$dataArray = @file($roomStateDataPath);
		foreach($dataArray as $dataLine) {
			list($dataTopic, $dataMessage) = explode("\t", trim($dataLine), 2);

			/* Prüfen, ob das MQTT-Topic relevant ist */
			if(isset($topicTable[$dataTopic]) == false) {
				continue;		
			}

			/* MQTT-Topic als gesehen markieren */
			$seenTopics[$dataTopic] = true;

			/* Zeitstempel verarbeiten */
			if(in_array(strrchr($dataTopic, '/'), array("/timestamp", "/since", "/until")) == true) {
				$timeParts = strptime($dataMessage, "%Y-%m-%dT%H:%M:%S");
				$dataMessage = mktime($timeParts['tm_hour'], $timeParts['tm_min'], $timeParts['tm_sec'],
					$timeParts['tm_mon'] + 1, $timeParts['tm_mday'], $timeParts['tm_year'] + 1900);
			}

			/* MQTT-Topic einer Variable zuweisen */
			${$topicTable[$dataTopic]} = $dataMessage;
		}

		/* Prüfen, ob die Daten vollständig und gültig sind */
		if((in_array(false, $seenTopics) == false) && (intval($doorTimestamp) > (time() - 7200))) {
			if($doorUnlocked == "true") {
				/* Tür auf -> Raum geöffnet */
				$roomStateOpen = true;
				$roomStateClosed = false;

				if(intval($lastMotionDetected) < (time() - 7200)) {
					/* Tür auf und seit 2 Stunden keine Bewegung erkannt -> Raum geschlossen */
					$roomStateOpen = false;
					$roomStateClosed = true;
				}
			}
			else {
				/* Tür zu -> Raum geschlossen */
				$roomStateOpen = false;
				$roomStateClosed = true;
			}
		}
	}

	/* Ausgabe für das Wiki anhand des Ergebnis erzeugen */
	$wikiOutput = $args['failed'];
	if($roomStateOpen == true) {
		$wikiOutput = $args['open'];
	}
	if($roomStateClosed == true) {
		$wikiOutput = $args['closed'];
	}
	
	/* Wiki-Code parsen */
	$wikiOutput = $parser->recursiveTagParse($wikiOutput);

	/* Ergebnis zurückgeben */
	return $wikiOutput;
}

?>
