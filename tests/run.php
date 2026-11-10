<?php
/**
 * Test-Runner ohne Abhaengigkeiten: php tests/run.php
 * Teil 1: Unit-Tests (Konfiguration, Zahlenformat, Fronius-Client + Mapper gegen Fake-Wechselrichter, MQTT-Client)
 * Teil 2: Ende-zu-Ende: der Dienst (froniusd.php im Entwicklungsmodus) gegen Fake-Wechselrichter und Fake-Broker.
 * Nicht getestet werden koennen hier: LoxBerry-Bibliotheken, Weboberflaeche, echte Wechselrichter (Gen24, Symo, Primo).
 */
$root = dirname(__DIR__);
date_default_timezone_set('Europe/Berlin');
// Gemeinsamer Code direkt aus ../common (im ZIP liegt er in bin/lib)
foreach (['Util', 'Logger', 'MqttClient'] as $c) {
    require_once $root . '/../common/php/' . $c . '.php';
}
foreach (['FroniusConfig', 'FroniusClient', 'FroniusModbus', 'FroniusDevices', 'FroniusMapper', 'FroniusNight', 'AppVersion'] as $c) {
    require_once $root . '/fronius2lox/bin/lib/' . $c . '.php';
}

$fails = 0;
$count = 0;
function check(string $name, $actual, $expected): void
{
    global $fails, $count;
    $count++;
    if ($actual !== $expected) {
        $fails++;
        echo "FAIL $name: erwartet " . var_export($expected, true) . ", erhalten " . var_export($actual, true) . "\n";
    }
}
function waitFor(callable $f, float $timeout = 10.0): bool
{
    $end = microtime(true) + $timeout;
    while (microtime(true) < $end) {
        if ($f()) {
            return true;
        }
        usleep(100000);
    }
    return false;
}
function startProc(array $cmd, array $env = []): array
{
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/fro_test_out.txt', 'a'], 2 => ['file', sys_get_temp_dir() . '/fro_test_err.txt', 'a']], $pipes, null, $env ? array_merge(getenv(), $env) : null);
    return [$p, $pipes];
}
function stopProc(array $pp): void
{
    @proc_terminate($pp[0]);
    foreach ($pp[1] as $h) {
        @fclose($h);
    }
    @proc_close($pp[0]);
}

$tmp = __DIR__ . '/tmp';
@mkdir($tmp, 0777, true);
$dir = $tmp . '/t' . getmypid();
@mkdir($dir, 0777, true);

// ---------------------------------------------------------------- Unit: Zahlenformat
check('num ganzzahl', FroniusMapper::num(2500), '2500');
check('num Float', FroniusMapper::num(2500.5), '2500.5');
check('num Float ohne Nullen', FroniusMapper::num(12.0), '12');
check('num negativ', FroniusMapper::num(-1000.5), '-1000.5');
check('num minus null', FroniusMapper::num(-0.0), '0');
check('num gerundet', FroniusMapper::num(1.23456), '1.235');
check('num null', FroniusMapper::num(null), null);
check('num Text', FroniusMapper::num('abc'), null);
check('num Zahltext', FroniusMapper::num('12.5'), '12.5');
check('num bool', FroniusMapper::num(true), '1');
check('num klein', FroniusMapper::num(0.0004), '0');
check('statusText Betrieb', FroniusMapper::statusText(7), 'Betrieb');
check('statusText Fehler', FroniusMapper::statusText(10), 'Fehler');
check('statusText Start', FroniusMapper::statusText(3), 'Startet');
check('statusText unbekannt', FroniusMapper::statusText(99), 'Unbekannt');

// Mapper mit Einzelfaellen
$o = FroniusMapper::powerflow(['Site' => ['P_PV' => null, 'P_Grid' => 300.0, 'P_Load' => -300.0, 'P_Akku' => 250.0], 'Inverters' => []]);
check('Nacht: pv_w 0', $o['pv_w'], '0');
check('Entladen: batterie_w negativ', $o['batterie_w'], '-250');
check('Entladen: batterie_entladen_w', $o['batterie_entladen_w'], '250');
check('Entladen: batterie_laden_w 0', $o['batterie_laden_w'], '0');
check('Bezug', $o['netz_bezug_w'], '300');
check('keine Einspeisung', $o['netz_einspeisung_w'], '0');
check('haus_w positiv', $o['haus_w'], '300');
check('powerflow ohne Site: leer', FroniusMapper::powerflow([]), []);
check('ohne Zaehler/Speicher: nichts erfunden', array_keys(FroniusMapper::powerflow(['Site' => ['P_PV' => 100, 'P_Load' => null, 'P_Grid' => null, 'P_Akku' => null]])), ['pv_w']);
check('inverterIds', FroniusMapper::inverterIds(['Inverters' => ['1' => [], '2' => [], 'x' => []]]), ['1', '2']);
$o = FroniusMapper::inverter('1', ['PAC' => ['Unit' => 'kW', 'Value' => 2.5], 'DAY_ENERGY' => ['Unit' => 'Wh', 'Value' => null], 'UAC' => ['Unit' => 'V', 'Values' => ['1' => 230]]]);
check('kW wird W', $o['wr/1/leistung_ac_w'], '2500');
check('null Wert fehlt', isset($o['wr/1/energie_tag_wh']), false);
check('Values (System-Scope)', $o['wr/1/spannung_ac_v'], '230');
$r = FroniusMapper::raw('raw/x', ['Site' => ['P_PV' => 5.5, 'Flag' => true, 'Text' => 'a  b', 'Leer' => null], 'Liste' => [1, 2]]);
check('raw Zahl', $r['raw/x/site/p_pv'], '5.5');
check('raw bool', $r['raw/x/site/flag'], '1');
check('raw Text', $r['raw/x/site/text'], 'a b');
check('raw null fehlt', isset($r['raw/x/site/leer']), false);
check('raw Liste', $r['raw/x/liste/1'], '2');

// ---------------------------------------------------------------- Unit: Konfiguration
check('cleanHost URL', FroniusConfig::cleanHost(' http://192.168.1.50/solar_api '), '192.168.1.50');
check('cleanHost Sonderzeichen', FroniusConfig::cleanHost('wr 1;rm'), 'wr1rm');
$d = FroniusConfig::defaults();
check('Vorgabe: 4 Geraete', count($d['geraete']), 4);
check('Vorgabe: kein Geraet aktiv', FroniusConfig::geraete($d), []);
$n = FroniusConfig::fromPost($d, [
    'intervall' => '1', 'wechselrichter' => '1', 'rohwerte' => '1',
    'geraet' => [
        0 => ['aktiv' => '1', 'alias' => 'Haus WR', 'host' => 'http://10.0.0.5/', 'port' => '99999'],
        1 => ['aktiv' => '1', 'alias' => 'haus_wr', 'host' => '10.0.0.6', 'port' => '8080'],
        2 => ['aktiv' => '1', 'alias' => '', 'host' => '10.0.0.7', 'port' => ''],
        3 => ['aktiv' => '1', 'alias' => 'x', 'host' => '', 'port' => '80'],
    ],
]);
check('post intervall min (Fronius: mind. 4 s zwischen Abfragen)', $n['intervall'], 5);
check('post zaehler aus (nicht angehakt)', $n['zaehler'], false);
check('post rohwerte an', $n['rohwerte'], true);
check('post alias', $n['geraete'][0]['alias'], 'haus_wr');
check('post host bereinigt', $n['geraete'][0]['host'], '10.0.0.5');
check('post port max', $n['geraete'][0]['port'], 65535);
$g = FroniusConfig::geraete($n);
check('Geraete: Host leer faellt weg', count($g), 3);
check('Geraete: doppelter Alias nummeriert', $g[1]['alias'], 'haus_wr_2');
check('Geraete: Standardname wr<Nr>', $g[2]['alias'], 'wr3');
check('Geraete: Port Standard 80', $g[2]['port'], 80);
$onlyMqtt = FroniusConfig::fromPost($n, ['mqtt' => ['eigener_broker' => '1', 'host' => ' b.lan ', 'port' => '8883', 'benutzer' => 'u', 'passwort' => 'pw']]);
check('nur MQTT gepostet: Geraete bleiben', $onlyMqtt['geraete'][0]['host'], '10.0.0.5');
check('nur MQTT gepostet: Intervall bleibt', $onlyMqtt['intervall'], 5);
check('ownBroker', FroniusConfig::ownBroker($onlyMqtt), ['b.lan', 8883, 'u', 'pw']);
$keep = FroniusConfig::fromPost($onlyMqtt, ['mqtt' => ['eigener_broker' => '1', 'host' => 'b.lan', 'passwort' => '']]);
check('Broker-Passwort bleibt bei leerem Feld', $keep['mqtt']['passwort'], 'pw');
check('ownBroker standardmaessig aus', FroniusConfig::ownBroker($d), null);
$cfgFile = $dir . '/fronius.json';
check('speichern', Util::jsonSave($cfgFile, $n), true);
if (DIRECTORY_SEPARATOR === '/') {
    check('Rechte 0600', substr(sprintf('%o', fileperms($cfgFile)), -4), '0600');
}
check('laden', FroniusConfig::load($cfgFile)['geraete'][1]['host'], '10.0.0.6');

// ---------------------------------------------------------------- Unit: Texte nach der Fronius-Doku
check('statusText Bereit', FroniusMapper::statusText(12), 'Bereit');
check('statusText Schlaeft', FroniusMapper::statusText(13), 'Schlaeft');
check('statusText Bootloader', FroniusMapper::statusText(9), 'Bootloader');
check('statusText 255', FroniusMapper::statusText(255), 'Unbekannt');
check('Zaehlerstandort 0', FroniusMapper::meterLocationText(0), 'Netzuebergabe');
check('Zaehlerstandort 1', FroniusMapper::meterLocationText(1), 'Verbrauchspfad');
check('Zaehlerstandort 3', FroniusMapper::meterLocationText(3), 'Fremderzeuger');
check('Zaehlerstandort 300', FroniusMapper::meterLocationText(300), 'Unterverbraucher');
check('Zaehlerstandort 2 reserviert', FroniusMapper::meterLocationText(2), 'Unbekannt');
check('OhmPilot Zustand 2', FroniusMapper::ohmpilotStateText(2), 'Legionellenschutz');
check('Batterie BYD', FroniusMapper::batteryStatusText('BYD', 3), 'Aktiv');
check('Batterie LG', FroniusMapper::batteryStatusText('LG Chem', 10), 'Schlaf');
check('Batterie unbekannter Hersteller', FroniusMapper::batteryStatusText('Fronius', 3), null);
check('Zellstatus 3', FroniusMapper::cellStateText(3), 'Normales Laden');
check('Zellstatus E', FroniusMapper::cellStateText(14), 'Laden pausiert');
check('Typliste: Symo 15.0-3-M', FroniusDevices::name(115), 'Fronius Symo 15.03M');
check('Typliste: Symo Advanced', FroniusDevices::name(45), 'Symo Advanced 15.03M');
check('Typliste: GEN24', str_starts_with(FroniusDevices::name(1), 'any Fronius GEN24'), true);
check('Typliste: 208/240 ergaenzt', FroniusDevices::name(97), 'Fronius Symo 12.03 208/240');
check('Typliste: Luecke', FroniusDevices::name(60), '');
check('Typliste: Vollstaendig (199 Eintraege)', count(FroniusDevices::TYPEN), 199);
check('Unsinnswert verworfen', FroniusMapper::num(8.4296154682294417e+252), null);
$o = FroniusMapper::powerflow(['Site' => ['P_PV' => 1, 'P_Akku' => null], 'Inverters' => ['1' => ['Battery_Mode' => 'disabled', 'SOC' => 0.0, 'DT' => 1]]]);
check('GEN24 ohne Batterie: kein SOC 0', isset($o['batterie_soc']), false);
check('Typ im Energiefluss', $o['wr/1/info/typ_name'], 'any Fronius GEN24/Tauro/Verto');
$m = FroniusMapper::meter(['0' => ['Visible' => 0, 'Enable' => 1, 'PowerReal_P_Sum' => 5, 'Meter_Location_Current' => 0]]);
check('Zaehler unsichtbar: keine Werte', isset($m['zaehler/0/leistung_w']), false);
check('Zaehler unsichtbar: Flag', $m['zaehler/0/sichtbar'], '0');
$a = FroniusMapper::archive([]);
check('Archiv leer: keine Fehler heute', $a['fehler_anzahl_heute'], '0');
check('Archiv leer: keine Ereignisse heute', $a['ereignis_anzahl_heute'], '0');

// Nachtbetrieb
$nb = ['aktiv' => true, 'schwelle_w' => 100, 'breite' => '52.52', 'laenge' => '13.405'];
$tn = fn(int $h, int $mi = 0) => mktime($h, $mi, 0, 6, 1, 2026);
check('Nacht: geringe Leistung vor Ausfall = erwartet', FroniusNight::expected($nb, 20.0, $tn(12)), true);
check('Nacht: hohe Leistung vor Ausfall = Fehler', FroniusNight::expected($nb, 3000.0, $tn(2)), false);
check('Nacht: Schwelle genau', FroniusNight::expected($nb, 100.0, $tn(12)), true);
check('Nacht: unbekannte Leistung, Sonne weg = erwartet', FroniusNight::expected($nb, null, $tn(2)), true);
check('Nacht: unbekannte Leistung, Mittag = Fehler', FroniusNight::expected($nb, null, $tn(12)), false);
check('Nacht: unbekannt, spaet abends (Juni 21:00)', FroniusNight::expected($nb, null, $tn(21)), true);
check('Nacht: unbekannt, Abend vor Toleranz (Juni 19:00)', FroniusNight::expected($nb, null, $tn(19)), false);
check('Nacht: unbekannt, frueher Morgen (Juni 05:00)', FroniusNight::expected($nb, null, $tn(5)), true);
check('Nacht: unbekannt, Vormittag (Juni 08:00)', FroniusNight::expected($nb, null, $tn(8)), false);
check('Nacht: ohne Koordinaten und ohne Leistung = Fehler', FroniusNight::expected(['breite' => '', 'laenge' => ''] + $nb, null, $tn(2)), false);
check('Nacht: ausgeschaltet', FroniusNight::expected(['aktiv' => false] + $nb, 0.0, $tn(2)), false);
$polar = ['breite' => '78.2', 'laenge' => '15.6'] + $nb;
check('Polartag (Juni, Spitzbergen): nie Nacht', FroniusNight::sunDown($polar, $tn(2)), false);
check('Polarnacht (Dez., Spitzbergen): immer Nacht', FroniusNight::sunDown($polar, mktime(12, 0, 0, 12, 21, 2026)), true);
$cn = FroniusConfig::cleanNacht(['aktiv' => 1, 'schwelle_w' => '-5', 'breite' => '51,5', 'laenge' => '200']);
check('Nacht-Konfig: Schwelle min 0', $cn['schwelle_w'], 0);
check('Nacht-Konfig: Komma', $cn['breite'], '51.5');
check('Nacht-Konfig: ungueltige Laenge leer', $cn['laenge'], '');
$pn = FroniusConfig::fromPost(FroniusConfig::defaults(), ['intervall' => '10', 'nacht_aktiv' => '1', 'nacht_schwelle' => '50', 'nacht_breite' => '48.1', 'nacht_laenge' => '11.6']);
check('Nacht per Post', [$pn['nacht']['aktiv'], $pn['nacht']['schwelle_w'], $pn['nacht']['breite']], [true, 50, '48.1']);
check('Nacht ohne Haken aus', FroniusConfig::fromPost(FroniusConfig::defaults(), ['intervall' => '10'])['nacht']['aktiv'], false);
check('Optionen per Post', [FroniusConfig::fromPost(FroniusConfig::defaults(), ['intervall' => '10', 'archiv' => '1'])['archiv'], FroniusConfig::fromPost(FroniusConfig::defaults(), ['intervall' => '10'])['archiv']], [true, false]);
check('Vorgabe: Nachtmodus an, Archiv an', [FroniusConfig::defaults()['nacht']['aktiv'], FroniusConfig::defaults()['archiv']], [true, true]);

// ---------------------------------------------------------------- Prozesse
$php = PHP_BINARY;
$mqPort = 18832;
$f1 = 18951;
$f2 = 18952;
$mqProc = startProc([$php, __DIR__ . '/fake_mqtt.php', (string)$mqPort, $dir]);
$fProc1 = startProc([$php, __DIR__ . '/fake_fronius.php', (string)$f1, $dir]);
$fProc2 = startProc([$php, __DIR__ . '/fake_fronius.php', (string)$f2, $dir]);
foreach ([$mqPort, $f1, $f2] as $pt) {
    waitFor(fn() => @stream_socket_client("tcp://127.0.0.1:$pt", $e, $es, 1) !== false, 5);
}
$setMode = fn(int $port, string $m) => file_put_contents("$dir/fro_mode_$port.txt", $m);
$froLog = fn(int $port) => (string)@file_get_contents("$dir/fro_$port.log");

// ---------------------------------------------------------------- Unit: Client + Mapper gegen Fake-Wechselrichter
$cl = new FroniusClient('127.0.0.1', $f1);
$pf = $cl->get('v1/GetPowerFlowRealtimeData.fcgi');
check('Powerflow geladen', is_array($pf), true);
$o = FroniusMapper::powerflow((array)$pf);
check('pv_w', $o['pv_w'], '2500.5');
check('haus_w', $o['haus_w'], '1000');
check('netz_w', $o['netz_w'], '-1000.5');
check('netz_einspeisung_w', $o['netz_einspeisung_w'], '1000.5');
check('batterie_w (+ Laden)', $o['batterie_w'], '500');
check('batterie_soc', $o['batterie_soc'], '55.5');
check('modus', $o['modus'], 'bidirectional');
check('notstrom 0', $o['notstrom'], '0');
check('autarkie', $o['autarkie'], '100');
check('energie_tag_wh', $o['energie_tag_wh'], '12345.6');
check('zaehler_standort', $o['zaehler_standort'], 'grid');
check('wr/1/batterie_modus', $o['wr/1/batterie_modus'], 'normal');
$inv = FroniusMapper::inverter('1', (array)$cl->get('v1/GetInverterRealtimeData.cgi', ['Scope' => 'Device', 'DeviceId' => '1', 'DataCollection' => 'CommonInverterData']));
check('WR Leistung', $inv['wr/1/leistung_ac_w'], '2500');
check('WR Spannung', $inv['wr/1/spannung_ac_v'], '231.2');
check('WR Frequenz', $inv['wr/1/frequenz_hz'], '50.01');
check('WR Jahr aus kWh', $inv['wr/1/energie_jahr_wh'], '123456.7');
check('WR Status', $inv['wr/1/status_text'], 'Betrieb');
check('WR Fehlercode', $inv['wr/1/fehler_code'], '0');
$mt = FroniusMapper::meter((array)$cl->get('v1/GetMeterRealtimeData.cgi', ['Scope' => 'System']));
check('Zaehler Leistung', $mt['zaehler/0/leistung_w'], '-1000.5');
check('Zaehler L2', $mt['zaehler/0/leistung_l2_w'], '-350.5');
check('Zaehler Spannung L1', $mt['zaehler/0/spannung_l1_v'], '230.1');
check('Zaehler Strom L3', $mt['zaehler/0/strom_l3_a'], '1.4');
check('Zaehler Bezug', $mt['zaehler/0/energie_bezug_wh'], '1234567');
check('Zaehler Einspeisung', $mt['zaehler/0/energie_einspeisung_wh'], '7654321');
check('Zaehler Modell', $mt['zaehler/0/info/modell'], 'Smart Meter TS 65A-3');
$sp = FroniusMapper::storage((array)$cl->get('v1/GetStorageRealtimeData.cgi', ['Scope' => 'System']));
check('Speicher SOC', $sp['speicher/0/soc'], '55.5');
check('Speicher Strom', $sp['speicher/0/strom_a'], '-1.5');
check('Speicher Temperatur', $sp['speicher/0/temperatur_c'], '22.5');
check('Speicher Kapazitaet', $sp['speicher/0/kapazitaet_wh'], '10000');
check('Speicher Hersteller', $sp['speicher/0/info/hersteller'], 'BYD');
$in = FroniusMapper::info((array)$cl->get('v1/GetInverterInfo.cgi'));
check('Info Name', $in['wr/1/info/name'], 'Symo GEN24');
check('Info Nennleistung', $in['wr/1/info/pv_nennleistung_wp'], '8000');
check('check erreichbar', FroniusClient::check('127.0.0.1', $f1), ['ok', 'erreichbar, Solar API Version 1']);
check('check nicht erreichbar', FroniusClient::check('127.0.0.1', 1)[0], 'fehler');
$setMode($f1, 'nacht');
check('Head-Fehler: null', $cl->get('v1/GetInverterRealtimeData.cgi', ['Scope' => 'Device', 'DeviceId' => '1', 'DataCollection' => 'CommonInverterData']), null);
check('Head-Fehler: Text', $cl->error, 'Fronius meldet Fehler 12: Device not available');
$setMode($f1, '404');
check('404 ohne Freigabe', $cl->get('v1/GetPowerFlowRealtimeData.fcgi'), null);
check('404 Text', str_contains($cl->error, 'freigeschaltet'), true);
$setMode($f1, 'kaputt');
check('kein JSON', $cl->get('v1/GetPowerFlowRealtimeData.fcgi'), null);
check('kein JSON Text', $cl->error, 'keine gueltige JSON-Antwort');
$setMode($f1, 'ok');

// ---------------------------------------------------------------- Unit: Symo (Non-Hybrid) gegen Fake-Wechselrichter
$setMode($f1, 'symo');
$dv = FroniusMapper::devices((array)$cl->get('v1/GetActiveDeviceInfo.cgi', ['DeviceClass' => 'System']));
check('Geraeteliste: Wechselrichter', $dv['ids']['Inverter'], ['1']);
check('Geraeteliste: Zaehler', $dv['ids']['Meter'], ['0']);
check('Geraeteliste: kein Speicher', $dv['ids']['Storage'], []);
check('Geraeteliste: OhmPilot', $dv['ids']['Ohmpilot'], ['0']);
check('Geraeteliste: Sensorkarte', $dv['ids']['SensorCard'], ['1']);
check('Geraeteliste: String Control', $dv['ids']['StringControl'], ['8']);
check('Geraeteliste: Typ', $dv['topics']['wr/1/info/typ_name'], 'Fronius Symo 15.03M');
check('Geraeteliste: Kanalnamen', $dv['topics']['sensor/1/info/kanalnamen'], 'Temperature 1, Temperature 2, Irradiation, Digital 1, Digital 2, Current');
check('Geraeteliste: Anzahl Speicher 0', $dv['topics']['info/anzahl_speicher'], '0');
$o = FroniusMapper::powerflow((array)$cl->get('v1/GetPowerFlowRealtimeData.fcgi'));
check('Symo: pv_w', $o['pv_w'], '6000.5');
check('Symo: haus_w', $o['haus_w'], '7200.5');
check('Symo: Bezug', $o['netz_bezug_w'], '1200');
check('Symo: Modus meter', $o['modus'], 'meter');
check('Symo: kein batterie_w', isset($o['batterie_w']), false);
check('Symo: kein batterie_soc', isset($o['batterie_soc']), false);
check('Symo: Wechselrichter Energie aus Energiefluss', $o['wr/1/energie_tag_wh'], '20000.5');
check('Symo: Komponenten-ID', $o['wr/1/komponenten_id'], '524288');
check('OhmPilot im Energiefluss', $o['ohmpilot/720897/leistung_w'], '2635');
check('OhmPilot Zustand im Energiefluss', $o['ohmpilot/720897/status_text'], 'normal');
check('Zusatzzaehler Leistung', $o['zusatzzaehler/65536/leistung_w'], '-850.5');
check('Zusatzzaehler Standort', $o['zusatzzaehler/65536/standort_text'], 'Unterverbraucher');
check('Zusatzzaehler Name', $o['zusatzzaehler/65536/name'], 'Waermepumpe');
check('Zusatzzaehler Kategorie', $o['zusatzzaehler/65536/kategorie'], 'METER_CAT_HEATPUMP');
check('Energiefluss-Version', $o['info/powerflow_version'], '12');
$q3 = FroniusMapper::inverter3p('1', (array)$cl->get('v1/GetInverterRealtimeData.cgi', ['Scope' => 'Device', 'DeviceId' => '1', 'DataCollection' => '3PInverterData']));
check('3P: Spannung L1', $q3['wr/1/spannung_l1_v'], '233.9');
check('3P: Strom L3', $q3['wr/1/strom_l3_a'], '8.6');
check('3P: Umgebungstemperatur', $q3['wr/1/temperatur_umgebung_c'], '31');
$mm = FroniusMapper::inverterMinMax('1', (array)$cl->get('v1/GetInverterRealtimeData.cgi', ['Scope' => 'Device', 'DeviceId' => '1', 'DataCollection' => 'MinMaxInverterData']));
check('MinMax: Tagesmaximum Leistung', $mm['wr/1/tag_leistung_max_w'], '9000');
check('MinMax: Jahr AC min', $mm['wr/1/jahr_spannung_ac_min_v'], '215');
check('MinMax: gesamt DC max', $mm['wr/1/gesamt_spannung_dc_max_v'], '920');
$ci = FroniusMapper::inverter('1', (array)$cl->get('v1/GetInverterRealtimeData.cgi', ['Scope' => 'Device', 'DeviceId' => '1', 'DataCollection' => 'CommonInverterData']));
check('Common: Scheinleistung', $ci['wr/1/scheinleistung_va'], '2520');
check('Common: LED-Farbe', $ci['wr/1/led_farbe_code'], '2');
check('Common: Fehler quittierbar 0', $ci['wr/1/fehler_quittierbar'], '0');
check('Common: Timer', $ci['wr/1/timer_restzeit_s'], '-1');
check('Common Symo: kein MPPT 2', isset($ci['wr/1/spannung_dc_2_v']), false);
check('Common Symo: DC-Leistung Tracker 1 = U x I', $ci['wr/1/leistung_dc_w'], '2520.5');
check('Common Symo: keine DC-Leistung Tracker 2', isset($ci['wr/1/leistung_dc_2_w']), false);
check('Common Symo: keine Summe bei nur einem Tracker', isset($ci['wr/1/leistung_dc_summe_w']), false);
$ohneI = FroniusMapper::inverter('1', ['UDC' => ['Unit' => 'V', 'Value' => 500], 'IDC' => ['Unit' => 'A', 'Value' => null]]);
check('DC-Leistung: ohne Strom keine Leistung', isset($ohneI['wr/1/leistung_dc_w']), false);
$kw = FroniusMapper::inverter('1', ['UDC' => ['Unit' => 'V', 'Value' => 400], 'IDC' => ['Unit' => 'A', 'Value' => 5], 'UDC_3' => ['Unit' => 'V', 'Value' => 300], 'IDC_3' => ['Unit' => 'A', 'Value' => 2]]);
check('DC-Leistung: Tracker 1 und 3, Tracker 2 fehlt', [$kw['wr/1/leistung_dc_w'], isset($kw['wr/1/leistung_dc_2_w']), $kw['wr/1/leistung_dc_3_w'], $kw['wr/1/leistung_dc_summe_w']], ['2000', false, '600', '2600']);
$inf = FroniusMapper::info((array)$cl->get('v1/GetInverterInfo.cgi'));
check('Info: Name HTML-dekodiert', $inf['wr/1/info/name'], 'Symo 15.0-3-M');
check('Info: Typ', $inf['wr/1/info/typ_name'], 'Fronius Symo 15.03M');
check('Info: Status', $inf['wr/1/info/status_text'], 'Betrieb');
check('Info: sichtbar', $inf['wr/1/info/sichtbar'], '1');
$op = FroniusMapper::ohmpilot((array)$cl->get('v1/GetOhmPilotRealtimeData.cgi', ['Scope' => 'System']));
check('OhmPilot API: Leistung', $op['ohmpilot/0/leistung_w'], '2635');
check('OhmPilot API: Status', $op['ohmpilot/0/status_text'], 'Legionellenschutz');
check('OhmPilot API: Fehlercode', $op['ohmpilot/0/fehler_code'], '926');
check('OhmPilot API: Software', $op['ohmpilot/0/info/software'], '1.0.19-1');
check('OhmPilot API: Energie', $op['ohmpilot/0/energie_verbraucht_wh'], '2964307');
$sn = FroniusMapper::sensor('1', (array)$cl->get('v1/GetSensorRealtimeData.cgi', ['Scope' => 'Device', 'DeviceId' => '1', 'DataCollection' => 'NowSensorData']));
check('Sensor: Temperatur', $sn['sensor/1/kanal_0'], '-9');
check('Sensor: Einstrahlung', $sn['sensor/1/kanal_2'], '589');
check('Sensor: Einheit', $sn['sensor/1/kanal_2_einheit'], 'W/m2');
$sm = FroniusMapper::sensor('1', (array)$cl->get('v1/GetSensorRealtimeData.cgi', ['Scope' => 'Device', 'DeviceId' => '1', 'DataCollection' => 'MinMaxSensorData']), true);
check('Sensor MinMax: Tag max', $sm['sensor/1/kanal_0_tag_max'], '66');
check('Sensor MinMax: gesamt min', $sm['sensor/1/kanal_0_gesamt_min'], '-35');
check('Sensor MinMax: aktiv', $sm['sensor/1/kanal_0_aktiv'], '1');
$sn2 = FroniusMapper::stringControl('8', (array)$cl->get('v1/GetStringRealtimeData.cgi', ['Scope' => 'Device', 'DeviceId' => '8', 'DataCollection' => 'NowStringControlData']), 'now');
check('String Control: Strom 1', $sn2['string/8/strom_1'], '4.5');
$ss = FroniusMapper::stringControl('8', (array)$cl->get('v1/GetStringRealtimeData.cgi', ['Scope' => 'Device', 'DeviceId' => '8', 'DataCollection' => 'CurrentSumStringControlData', 'TimePeriod' => 'Day']), 'sum', 'tag');
check('String Control: Tagessumme', $ss['string/8/summe_tag_1_ah'], '12.5');
$se = FroniusMapper::stringControl('8', (array)$cl->get('v1/GetStringRealtimeData.cgi', ['Scope' => 'Device', 'DeviceId' => '8', 'DataCollection' => 'LastErrorStringControlData']), 'fehler');
check('String Control: Abweichung', $se['string/8/fehler_kanal_2_abweichung_prozent'], '-12.6');
check('String Control: Fehlerzeit', $se['string/8/fehler_zeit'], '2026-05-30T13:00:00+02:00');
$lb = $cl->body('v1/GetLoggerInfo.cgi');
$li = FroniusMapper::loggerInfo((array)($lb['LoggerInfo'] ?? []));
check('Logger: Produkt', $li['logger/produkt'], 'fronius-datamanager-card');
check('Logger: UTC-Offset', $li['logger/utc_offset_s'], '7200');
check('Logger: Waehrung', $li['logger/waehrung'], 'EUR');
check('Logger: Einspeisefaktor', $li['logger/einspeisefaktor'], '0.25');
$ll = FroniusMapper::loggerLeds((array)$cl->get('v1/GetLoggerLEDInfo.cgi'));
check('LED WLAN Farbe', $ll['logger/led_wlan_farbe'], 'red');
check('LED SolarWeb Zustand', $ll['logger/led_solarweb_zustand'], 'blinking');
$ar = FroniusMapper::archive((array)$cl->get('v1/GetArchiveData.cgi', 'Scope=System&StartDate=1.6.2026&EndDate=1.6.2026&Channel=InverterErrors&Channel=InverterEvents'));
check('Archiv: letzter Fehler (hoechster Zeitversatz)', $ar['wr/1/fehler_letzter_code'], '731');
check('Archiv: Fehler heute (Wechselrichter)', $ar['wr/1/fehler_anzahl_heute'], '2');
check('Archiv: Fehler heute (gesamt)', $ar['fehler_anzahl_heute'], '2');
check('Archiv: Fehlerzeit', $ar['wr/1/fehler_letzter_zeit'], (string)(strtotime('today') + 41000));
check('Archiv: Fehlerflags', $ar['wr/1/fehler_letzter_flags'], 'official');
check('Archiv: Ereignis des Wechselrichters', $ar['wr/1/ereignis_letzter_code'], '9');
check('Archiv: Ereignis allgemein (broadcast)', $ar['ereignis_letzter_text'], 'Power limitation 20%');
check('Archiv: Ereignisse gesamt', $ar['ereignis_anzahl_heute'], '2');
$arD = (array)$cl->get('v1/GetArchiveData.cgi', 'Scope=System&StartDate=1.6.2026&EndDate=1.6.2026&Channel=Voltage_DC_String_2');
$as = FroniusMapper::archive($arD, time());
check('Archiv: String 1 Spannung (letzter Wert)', $as['wr/1/string_1_spannung_v'], '541.5');
check('Archiv: String 1 Strom', $as['wr/1/string_1_strom_a'], '4.5');
check('Archiv: String 1 Leistung = U x I', $as['wr/1/string_1_leistung_w'], '2436.8');
check('Archiv: String 2 Spannung', $as['wr/1/string_2_spannung_v'], '403.5');
check('Archiv: String 2 Strom', $as['wr/1/string_2_strom_a'], '3.2');
check('Archiv: String 2 Leistung = U x I', $as['wr/1/string_2_leistung_w'], '1291.2');
check('Archiv: String-Zeit ist aktuell (Aufzeichnungszeitpunkt)', abs((int)$as['wr/1/string_2_zeit'] - time()) <= 600, true);
check('Archiv: veraltete String-Werte (> 45 min) werden nicht gesendet', isset(FroniusMapper::archive($arD, time() + 4000)['wr/1/string_2_spannung_v']), false);
check('Archiv: ohne Zeitangabe keine Alterspruefung', isset(FroniusMapper::archive($arD)['wr/1/string_2_spannung_v']), true);
$nurU = ['inverter/1' => ['Data' => ['Voltage_DC_String_2' => ['Values' => ['0' => 400.0]]], 'Start' => date('c', strtotime('today'))]];
check('Archiv: Spannung ohne Strom = kein String-Wert', isset(FroniusMapper::archive($nurU, time())['wr/1/string_2_spannung_v']), false);
$zm = FroniusMapper::meter((array)$cl->get('v1/GetMeterRealtimeData.cgi', ['Scope' => 'System']));
check('Zaehler: Standort Text', $zm['zaehler/0/standort_text'], 'Netzuebergabe');
check('Zaehler: Plus absolut', $zm['zaehler/0/energie_plus_absolut_wh'], '1234567');
check('Zaehler: Minus absolut', $zm['zaehler/0/energie_minus_absolut_wh'], '7654321');
check('Zaehler: Spannung L1-L2', $zm['zaehler/0/spannung_l1_l2_v'], '402.6');
check('Zaehler: Strom Summe', $zm['zaehler/0/strom_summe_a'], '3.9');
check('Zaehler: Blindenergie', $zm['zaehler/0/blindenergie_verbraucht_varh'], '9156420');
check('Zaehler: Spannung Mittel', $zm['zaehler/0/spannung_mittel_v'], '231.1');
check('Zaehler: Zeitstempel', $zm['zaehler/0/zeitstempel'], '1561364909');
check('Zaehler: aktiv', $zm['zaehler/0/aktiv'], '1');
check('Storage beim Symo: Fehler im Kopf', $cl->get('v1/GetStorageRealtimeData.cgi', ['Scope' => 'System']), null);
$setMode($f1, 'ok');
$sp2 = FroniusMapper::storage((array)$cl->get('v1/GetStorageRealtimeData.cgi', ['Scope' => 'System']));
check('Speicher: Status Text (BYD)', $sp2['speicher/0/status_text'], 'Aktiv');
check('Speicher: Modul-Status aktuell', $sp2['speicher/0/modul/1/status_aktuell_text'], 'Normales Laden');
check('Speicher: Modul-Status vorher', $sp2['speicher/0/modul/1/status_vorher_text'], 'Normales Entladen');
check('Speicher: Modulanzahl', $sp2['speicher/0/module_anzahl'], '1');
check('Speicher: Zyklen', $sp2['speicher/0/modul/1/zyklen'], '255');
check('GEN24: Zustandstext', FroniusMapper::inverter('1', (array)$cl->get('v1/GetInverterRealtimeData.cgi', ['Scope' => 'Device', 'DeviceId' => '1', 'DataCollection' => 'CommonInverterData']))['wr/1/zustand'], 'Running');
check('GEN24: MPPT 2', FroniusMapper::inverter('1', (array)$cl->get('v1/GetInverterRealtimeData.cgi', ['Scope' => 'Device', 'DeviceId' => '1', 'DataCollection' => 'CommonInverterData']))['wr/1/spannung_dc_2_v'], '555.5');
$g24 = FroniusMapper::inverter('1', (array)$cl->get('v1/GetInverterRealtimeData.cgi', ['Scope' => 'Device', 'DeviceId' => '1', 'DataCollection' => 'CommonInverterData']));
check('GEN24: DC-Leistung Tracker 1 und 2', [$g24['wr/1/leistung_dc_w'], $g24['wr/1/leistung_dc_2_w']], ['2520.5', '2444.2']);
check('GEN24: DC-Summe beider Tracker', $g24['wr/1/leistung_dc_summe_w'], '4964.7');
check('GEN24: kein Archiv (404)', $cl->get('v1/GetArchiveData.cgi', 'Scope=System&StartDate=1.6.2026&EndDate=1.6.2026&Channel=InverterErrors'), null);
$setMode($f1, 'aus');
check('Abgeschaltet: nicht erreichbar', $cl->get('v1/GetPowerFlowRealtimeData.fcgi'), null);
check('Abgeschaltet: Text', $cl->error, 'nicht erreichbar');
$setMode($f1, 'ok');

// Logger maskiert Secrets
$lf = $dir . '/test.log';
$lg = new Logger($lf, 'Test', 'Test');
$lg->setLevel('debug');
$lg->addSecret('brokerpw');
$lg->info('Passwort brokerpw benutzt');
check('Logger maskiert', str_contains((string)file_get_contents($lf), 'brokerpw'), false);

// ---------------------------------------------------------------- Ende-zu-Ende: Dienst
$e2e = $dir . '/e2e';
@mkdir($e2e, 0777, true);
$conf = FroniusConfig::load($e2e . '/fronius.json');
$conf['intervall'] = 2;
$conf['mqtt'] = ['eigener_broker' => true, 'host' => '127.0.0.1', 'port' => $mqPort, 'benutzer' => '', 'passwort' => ''];
$conf['geraete'][0] = ['aktiv' => true, 'alias' => 'wr1', 'host' => '127.0.0.1', 'port' => $f1];
$conf['geraete'][1] = ['aktiv' => true, 'alias' => 'garage', 'host' => '127.0.0.1', 'port' => $f2];
Util::jsonSave($e2e . '/fronius.json', $conf);
$setMode($f2, 'ohne');
@unlink($dir . '/mqtt.log');
$svc = startProc([$php, dirname(__DIR__) . '/fronius2lox/bin/froniusd.php'], ['FRO_DEV_DIR' => $e2e, 'FRO_DEV_LOGLEVEL' => 'debug', 'FRO_DEV_RETRY' => '2']);
$mlog = fn() => (string)@file_get_contents($dir . '/mqtt.log');
$has = fn(string $topic, string $val) => str_contains($mlog(), "$topic\t$val\n");

check('Dienst veroeffentlicht pv_w', waitFor(fn() => $has('fronius/wr1/pv_w', '2500.5')), true);
check('Client-ID und Will', str_contains($mlog(), "CONNECT\tfronius_fronius2lox\tfronius/status/ok"), true);
check('kein Abo noetig', str_contains($mlog(), "SUB\t"), false);
check('haus_w', $has('fronius/wr1/haus_w', '1000'), true);
check('netz_bezug_w', $has('fronius/wr1/netz_bezug_w', '0'), true);
check('batterie_laden_w', $has('fronius/wr1/batterie_laden_w', '500'), true);
check('batterie_soc', $has('fronius/wr1/batterie_soc', '55.5'), true);
check('modus Text', $has('fronius/wr1/modus', 'bidirectional'), true);
check('verbunden 1', $has('fronius/wr1/verbunden', '1'), true);
check('WR-Details', waitFor(fn() => $has('fronius/wr1/wr/1/leistung_ac_w', '2500')), true);
check('WR-Details: DC-Leistung je Tracker kommt per MQTT an', waitFor(fn() => $has('fronius/wr1/wr/1/leistung_dc_w', '2520.5')), true);
check('WR Status Text', $has('fronius/wr1/wr/1/status_text', 'Betrieb'), true);
check('Zaehler', $has('fronius/wr1/zaehler/0/leistung_w', '-1000.5'), true);
check('Speicher', $has('fronius/wr1/speicher/0/temperatur_c', '22.5'), true);
check('Info Name', $has('fronius/wr1/wr/1/info/name', 'Symo GEN24'), true);
check('Info API-Version', $has('fronius/wr1/info/api_version', '1'), true);
check('Lebenszeichen', waitFor(fn() => $has('fronius/status/zaehler', '1')), true);
check('Zeitstempel', (bool)preg_match('/fronius\/status\/ts\t\d{10}/', $mlog()), true);
check('status/ok 1', waitFor(fn() => $has('fronius/status/ok', '1')), true);
check('zweites Geraet ohne Zaehler: pv_w', waitFor(fn() => $has('fronius/garage/pv_w', '2500.5')), true);
check('zweites Geraet: Modus', $has('fronius/garage/modus', 'produce-only'), true);
check('ohne Zaehler: kein netz_w', str_contains($mlog(), "fronius/garage/netz_w\t"), false);
$g2 = $froLog($f2);
check('ohne Zaehler: Zaehler wird nicht abgefragt', str_contains($g2, 'GetMeterRealtimeData'), false);
check('ohne Speicher: Speicher wird nicht abgefragt', str_contains($g2, 'GetStorageRealtimeData'), false);
check('Wechselrichter wird abgefragt', str_contains($g2, 'GetInverterRealtimeData.cgi?Scope=Device&DeviceId=1&DataCollection=CommonInverterData'), true);
check('keine Rohwerte ohne Option', str_contains($mlog(), '/raw/'), false);
check('status.json', Util::jsonLoad($e2e . '/run/status.json')['service'] ?? '', 'running');
check('status.json: Geraet ok', Util::jsonLoad($e2e . '/run/status.json')['geraete']['garage']['ok'] ?? null, true);

// Nacht: PV 0, Wechselrichter-Details fehlen, aber alles andere laeuft
$setMode($f1, 'nacht');
@unlink($dir . '/mqtt.log');
check('Nacht: pv_w 0', waitFor(fn() => $has('fronius/wr1/pv_w', '0')), true);
check('Nacht: Bezug', $has('fronius/wr1/netz_bezug_w', '300'), true);
check('Nacht: kein batterie_w', str_contains($mlog(), "fronius/wr1/batterie_w\t"), false);
check('Nacht: status/ok bleibt 1', waitFor(fn() => $has('fronius/status/ok', '1')), true);
check('Nacht: Wechselrichter-Fehler nicht fatal im Log', str_contains((string)@file_get_contents($e2e . '/fronius.log'), 'Wechselrichter 1 nicht verfuegbar'), true);

// Ausfall: Solar API nicht freigeschaltet -> verbunden 0, status/ok 0, Lebenszeichen laeuft
$setMode($f1, '404');
@unlink($dir . '/mqtt.log');
check('Ausfall: verbunden 0', waitFor(fn() => $has('fronius/wr1/verbunden', '0')), true);
check('Ausfall: status/ok 0', waitFor(fn() => $has('fronius/status/ok', '0')), true);
check('Ausfall: anderes Geraet laeuft weiter', $has('fronius/garage/verbunden', '1') || waitFor(fn() => $has('fronius/garage/verbunden', '1')), true);
check('Ausfall: Zaehler laeuft weiter', waitFor(fn() => (bool)preg_match('/fronius\/status\/zaehler\t\d+/', $mlog())), true);
check('Ausfall: Fehlertext im Log', str_contains((string)@file_get_contents($e2e . '/fronius.log'), 'Solar API nicht gefunden'), true);
// Erholung
$setMode($f1, 'ok');
@unlink($dir . '/mqtt.log');
check('Erholung: verbunden 1', waitFor(fn() => $has('fronius/wr1/verbunden', '1')), true);
check('Erholung: status/ok 1', waitFor(fn() => $has('fronius/status/ok', '1')), true);

// Rohwerte per Konfiguration zuschalten (wird im Betrieb neu geladen), Zaehler abschalten
sleep(1);
$conf['rohwerte'] = true;
$conf['zaehler'] = false;
Util::jsonSave($e2e . '/fronius.json', $conf);
@unlink($dir . '/mqtt.log');
check('Rohwerte: Powerflow-Feld', waitFor(fn() => $has('fronius/wr1/raw/powerflow/site/p_pv', '2500.5'), 12), true);
check('Rohwerte: Wechselrichter-Feld', waitFor(fn() => $has('fronius/wr1/raw/wechselrichter/1/pac/value', '2500')), true);
@unlink($dir . '/mqtt.log');
$before = substr_count($froLog($f1), 'GetMeterRealtimeData');
sleep(5);
check('Rohwerte nur bei Aenderung (nicht jede Runde)', str_contains($mlog(), 'fronius/wr1/raw/powerflow/site/p_pv'), false);
check('Normale Werte kommen weiter jede Runde', str_contains($mlog(), 'fronius/wr1/pv_w'), true);
check('Zaehler abgeschaltet: keine weiteren Abfragen', substr_count($froLog($f1), 'GetMeterRealtimeData'), $before);

// ---------------------------------------------------------------- Symo (Non-Hybrid): Tagesausfall, Nacht, Aufwachen
$conf['rohwerte'] = false;
$conf['zaehler'] = true;
Util::jsonSave($e2e . '/fronius.json', $conf);
sleep(1);
$flog = fn() => (string)@file_get_contents($e2e . '/fronius.log');
$setMode($f1, 'symo');
@unlink($dir . '/mqtt.log');
check('Symo: pv_w', waitFor(fn() => $has('fronius/wr1/pv_w', '6000.5'), 15), true);

// Ausfall am Tag (hohe Leistung vor dem Ausfall): das ist ein Fehler
$setMode($f1, 'aus');
@unlink($dir . '/mqtt.log');
check('Tagesausfall: verbunden 0', waitFor(fn() => $has('fronius/wr1/verbunden', '0'), 15), true);
check('Tagesausfall: status/ok 0', waitFor(fn() => $has('fronius/status/ok', '0'), 15), true);
check('Tagesausfall: schlaeft 0', $has('fronius/wr1/schlaeft', '0'), true);
check('Tagesausfall: Fehler im Log', str_contains($flog(), 'Abruf fehlgeschlagen: nicht erreichbar'), true);
check('Tagesausfall: nicht als Nacht gewertet', str_contains($flog(), 'abgeschaltet (Nacht)'), false);
check('Tagesausfall: status.json nicht ok', Util::jsonLoad($e2e . '/run/status.json')['geraete']['wr1']['ok'] ?? null, false);
$setMode($f1, 'symo');
@unlink($dir . '/mqtt.log');
check('Wieder erreichbar: verbunden 1', waitFor(fn() => $has('fronius/wr1/verbunden', '1'), 15), true);
check('Wieder erreichbar: status/ok 1', waitFor(fn() => $has('fronius/status/ok', '1'), 15), true);
check('Wieder erreichbar im Log', str_contains($flog(), '[wr1] wieder erreichbar'), true);

// Daemmerung: Leistung faellt auf 20 W, danach schaltet der Wechselrichter ab -> Nacht, kein Fehler
$setMode($f1, 'symo_abend');
@unlink($dir . '/mqtt.log');
check('Abend: pv_w 20', waitFor(fn() => $has('fronius/wr1/pv_w', '20.0') || $has('fronius/wr1/pv_w', '20'), 15), true);
$setMode($f1, 'aus');
@unlink($dir . '/mqtt.log');
$logPos = strlen($flog());
check('Nacht: schlaeft 1', waitFor(fn() => $has('fronius/wr1/schlaeft', '1'), 15), true);
check('Nacht: verbunden 0', $has('fronius/wr1/verbunden', '0'), true);
check('Nacht: pv_w 0', $has('fronius/wr1/pv_w', '0'), true);
check('Nacht: Wechselrichterleistung 0', $has('fronius/wr1/wr/1/leistung_ac_w', '0'), true);
check('Nacht: Status Offline', $has('fronius/wr1/wr/1/status_text', 'Offline'), true);
check('Nacht: Lebenszeichen laeuft', waitFor(fn() => (bool)preg_match('/fronius\/status\/zaehler\t\d+/', $mlog())), true);
check('Nacht: status/ok bleibt 1', waitFor(fn() => $has('fronius/status/ok', '1'), 15), true);
sleep(3);
check('Nacht: nie status/ok 0', str_contains($mlog(), "fronius/status/ok\t0\n"), false);
$nachtLog = substr($flog(), $logPos);
check('Nacht: Hinweis im Log (Info)', str_contains($nachtLog, 'abgeschaltet (Nacht), kein Fehler'), true);
check('Nacht: kein Fehler im Log', str_contains($nachtLog, 'Abruf fehlgeschlagen'), false);
check('Nacht: status.json ok + schlaeft', [Util::jsonLoad($e2e . '/run/status.json')['geraete']['wr1']['ok'] ?? null, Util::jsonLoad($e2e . '/run/status.json')['geraete']['wr1']['schlaeft'] ?? null], [true, true]);

// Morgen: der Wechselrichter wacht auf, alles wird neu erkannt und geholt
$gStorage = substr_count($froLog($f1), 'GetStorageRealtimeData');
$setMode($f1, 'symo');
@unlink($dir . '/mqtt.log');
check('Morgen: verbunden 1', waitFor(fn() => $has('fronius/wr1/verbunden', '1'), 15), true);
check('Morgen: schlaeft 0', $has('fronius/wr1/schlaeft', '0'), true);
check('Morgen: Hinweis im Log', str_contains($flog(), '[wr1] wieder erreichbar'), true);
check('Morgen: Typ aus der Typliste', waitFor(fn() => $has('fronius/wr1/wr/1/info/typ_name', 'Fronius Symo 15.03M'), 10), true);
check('Morgen: Name entkodiert', $has('fronius/wr1/wr/1/info/name', 'Symo 15.0-3-M'), true);
check('Morgen: Wechselrichter AC-Leistung', $has('fronius/wr1/wr/1/leistung_ac_w', '6000'), true);
check('Morgen: Status Betrieb', $has('fronius/wr1/wr/1/status_text', 'Betrieb'), true);
check('Morgen: 3-Phasen-Spannung', $has('fronius/wr1/wr/1/spannung_l1_v', '233.9'), true);
check('Morgen: Extremwerte', $has('fronius/wr1/wr/1/tag_leistung_max_w', '9000'), true);
check('Morgen: Zaehler', $has('fronius/wr1/zaehler/0/standort_text', 'Netzuebergabe'), true);
check('Morgen: OhmPilot', $has('fronius/wr1/ohmpilot/0/status_text', 'Legionellenschutz'), true);
check('Morgen: Zusatzzaehler', $has('fronius/wr1/zusatzzaehler/65536/name', 'Waermepumpe'), true);
check('Morgen: Sensorkarte', $has('fronius/wr1/sensor/1/kanal_2', '589'), true);
check('Morgen: Sensorkarte Extremwert', $has('fronius/wr1/sensor/1/kanal_0_tag_max', '66'), true);
check('Morgen: String Control', $has('fronius/wr1/string/8/strom_2', '4.25'), true);
check('Morgen: String-Tagessumme', $has('fronius/wr1/string/8/summe_tag_1_ah', '12.5'), true);
check('Morgen: String-Fehler', $has('fronius/wr1/string/8/fehler_kanal_1_abweichung_prozent', '5.7'), true);
check('Morgen: Datenlogger', $has('fronius/wr1/logger/produkt', 'fronius-datamanager-card'), true);
check('Morgen: Logger-LED', $has('fronius/wr1/logger/led_wlan_farbe', 'red'), true);
check('Morgen: API-Version', $has('fronius/wr1/info/api_version', '1'), true);
check('Morgen: Geraeteanzahl OhmPilot', $has('fronius/wr1/info/anzahl_ohmpilot', '1'), true);
check('Morgen: Geraeteanzahl Speicher 0', $has('fronius/wr1/info/anzahl_speicher', '0'), true);
check('Morgen: Archiv letzter Fehler', waitFor(fn() => $has('fronius/wr1/wr/1/fehler_letzter_code', '731'), 10), true);
check('Morgen: Archiv Ereignis', $has('fronius/wr1/ereignis_letzter_text', 'Power limitation 20%'), true);
check('Morgen: Archiv Anzahl', $has('fronius/wr1/fehler_anzahl_heute', '2'), true);
check('Morgen: Archiv String 2 Leistung per MQTT', $has('fronius/wr1/wr/1/string_2_leistung_w', '1291.2'), true);
check('Morgen: Archiv String 1 Spannung per MQTT', $has('fronius/wr1/wr/1/string_1_spannung_v', '541.5'), true);
check('Morgen: Speicher wird nicht abgefragt (nicht gemeldet)', substr_count($froLog($f1), 'GetStorageRealtimeData'), $gStorage);
check('Morgen: Archiv-Abfrage mit zwei Kanaelen', (bool)preg_match('/GetArchiveData\.cgi\?Scope=System&StartDate=\d+\.\d+\.\d{4}&EndDate=\d+\.\d+\.\d{4}&Channel=InverterErrors&Channel=InverterEvents/', $froLog($f1)), true);
check('Morgen: Dienst laeuft', Util::jsonLoad($e2e . '/run/status.json')['service'] ?? '', 'running');

// ---------------------------------------------------------------- Modbus TCP: MPP-Tracker (SunSpec-Modell 160)
$mbPort = 18961;
$mbProc = startProc([$php, __DIR__ . '/fake_modbus.php', (string)$mbPort, $dir]);
waitFor(fn() => @stream_socket_client("tcp://127.0.0.1:$mbPort", $e, $es, 1) !== false, 5);
$mbMode = fn(string $m) => file_put_contents("$dir/mb_mode_$mbPort.txt", $m);
$mb = new FroniusModbus('127.0.0.1', $mbPort, 2.0);
check('Modbus: SunSpec-Kennung lesbar', $mb->read(1, 40000, 2), [0x5375, 0x6E53]);
check('Modbus: falsche Unit-ID = Ausnahme 11', [$mb->read(2, 40000, 2), str_contains($mb->error, 'Ausnahme 11')], [null, true]);
check('Modbus: unbekannte Adresse = Ausnahme 2', [$mb->read(1, 100, 2), str_contains($mb->error, 'Ausnahme 2')], [null, true]);
check('Modbus: ungueltige Anzahl abgelehnt', $mb->read(1, 40000, 200), null);
check('SunSpec: Modell 160 gefunden (Adresse, Laenge)', FroniusSunSpec::find($mb, 1, 160), ['addr' => 40121, 'len' => 48]);
check('SunSpec: Modell 103 gefunden', FroniusSunSpec::find($mb, 1, 103)['addr'] ?? null, 40069);
$cache = null;
$tr = FroniusSunSpec::mppt($mb, 1, $cache);
check('SunSpec: Adresse gemerkt', $cache['addr'] ?? null, 40121);
check('SunSpec: zwei Tracker', count($tr ?? []), 2);
check('SunSpec: Tracker 1 Name', $tr[0]['name'] ?? null, 'String 1');
check('SunSpec: Tracker 1 Werte (V, A, W)', [round($tr[0]['spannung_v'], 3), round($tr[0]['strom_a'], 3), round($tr[0]['leistung_w'], 3)], [541.5, 4.5, 2437.0]);
check('SunSpec: Tracker 2 Werte (V, A, W)', [round($tr[1]['spannung_v'], 3), round($tr[1]['strom_a'], 3), round($tr[1]['leistung_w'], 3)], [403.5, 3.2, 1291.0]);
$tp = FroniusMapper::mppt('1', $tr);
check('Mapper Modbus: Tracker 1 ohne Nummer', [$tp['wr/1/spannung_dc_v'], $tp['wr/1/strom_dc_a'], $tp['wr/1/leistung_dc_w']], ['541.5', '4.5', '2437']);
check('Mapper Modbus: Tracker 2 mit Nummer', [$tp['wr/1/spannung_dc_2_v'], $tp['wr/1/strom_dc_2_a'], $tp['wr/1/leistung_dc_2_w']], ['403.5', '3.2', '1291']);
check('Mapper Modbus: Summe, Anzahl, Namen', [$tp['wr/1/leistung_dc_summe_w'], $tp['wr/1/mppt_anzahl'], $tp['wr/1/mppt_1_name'], $tp['wr/1/mppt_2_name']], ['3728', '2', 'String 1', 'String 2']);
$mbMode('ein_tracker');
$cache = null;
$t1 = FroniusSunSpec::mppt($mb, 1, $cache);
check('SunSpec: nicht implementierter Tracker 2 = keine Werte', [$t1[1]['spannung_v'], $t1[1]['strom_a'], $t1[1]['leistung_w']], [null, null, null]);
$tp1 = FroniusMapper::mppt('1', $t1);
check('Mapper Modbus: ohne Werte kein Topic fuer Tracker 2 (und keine Summe)', [isset($tp1['wr/1/spannung_dc_2_v']), isset($tp1['wr/1/leistung_dc_summe_w']), $tp1['wr/1/leistung_dc_w']], [false, false, '2437']);
$mbMode('kein160');
$cache = null;
check('SunSpec: ohne Modell 160 = Fehlertext', [FroniusSunSpec::mppt($mb, 1, $cache), str_contains($mb->error, 'fehlt in der SunSpec-Liste')], [null, true]);
$mbMode('ohne_sunspec');
$cache = null;
check('SunSpec: ohne Kennung = Hinweis auf Modbus-Einstellung', [FroniusSunSpec::mppt($mb, 1, $cache), str_contains($mb->error, 'Modbus TCP im Datamanager eingeschaltet')], [null, true]);
$mbMode('aus');
$cache = null;
check('SunSpec: Verbindung wird geschlossen = Fehler ohne Absturz', [FroniusSunSpec::mppt($mb, 1, $cache), $mb->error !== ''], [null, true]);
$mbMode('ok');
$mbClosed = new FroniusModbus('127.0.0.1', 1, 1.0);
check('Modbus: Port zu = nicht erreichbar', [$mbClosed->read(1, 40000, 2), str_contains($mbClosed->error, 'nicht erreichbar')], [null, true]);
$mb->close();
check('FroniusConfig: Modbus standardmaessig aus, Port 502', [FroniusConfig::defaults()['modbus']], [['aktiv' => false, 'port' => 502]]);
check('FroniusConfig: Modbus-Port ungueltig -> 502', FroniusConfig::cleanModbus(['aktiv' => 1, 'port' => 'x']), ['aktiv' => true, 'port' => 502]);
check('FroniusConfig: Modbus aus Formular', FroniusConfig::fromPost(FroniusConfig::defaults(), ['intervall' => 10, 'modbus_aktiv' => '1', 'modbus_port' => '1502'])['modbus'], ['aktiv' => true, 'port' => 1502]);
check('FroniusConfig: Modbus ohne Haken = aus', FroniusConfig::fromPost(FroniusConfig::defaults(), ['intervall' => 10])['modbus']['aktiv'], false);

// Dienst mit Modbus: ersetzt die DC-Werte der Solar API und liefert Tracker 2
$conf = FroniusConfig::load($e2e . '/fronius.json');
$conf['modbus'] = ['aktiv' => true, 'port' => $mbPort];
Util::jsonSave($e2e . '/fronius.json', $conf);
@unlink($dir . '/mqtt.log');
check('Dienst+Modbus: Tracker 2 Spannung', waitFor(fn() => $has('fronius/wr1/wr/1/spannung_dc_2_v', '403.5'), 20), true);
check('Dienst+Modbus: Tracker 2 Strom', $has('fronius/wr1/wr/1/strom_dc_2_a', '3.2'), true);
check('Dienst+Modbus: Tracker 2 Leistung', $has('fronius/wr1/wr/1/leistung_dc_2_w', '1291'), true);
check('Dienst+Modbus: Tracker 1 Spannung', $has('fronius/wr1/wr/1/spannung_dc_v', '541.5'), true);
check('Dienst+Modbus: Summe', $has('fronius/wr1/wr/1/leistung_dc_summe_w', '3728'), true);
check('Dienst+Modbus: modbus_ok 1', $has('fronius/wr1/wr/1/modbus_ok', '1'), true);
@unlink($dir . '/mqtt.log');
check('Dienst+Modbus: Solar API laeuft weiter (AC-Leistung)', waitFor(fn() => $has('fronius/wr1/wr/1/leistung_ac_w', '6000'), 20), true);
check('Dienst+Modbus: Solar-API-Wert (560.1) ersetzt, nicht mehr gesendet', str_contains($mlog(), "wr/1/spannung_dc_v\t560.1\n"), false);
check('Dienst+Modbus: stattdessen der Modbus-Wert im selben Durchgang', $has('fronius/wr1/wr/1/spannung_dc_v', '541.5'), true);
$mbMode('ausnahme');
@unlink($dir . '/mqtt.log');
$logPos = strlen($flog());
check('Dienst+Modbus: Ausfall = modbus_ok 0', waitFor(fn() => $has('fronius/wr1/wr/1/modbus_ok', '0'), 20), true);
check('Dienst+Modbus: Ausfall stoert die Solar API nicht (status/ok bleibt 1)', [waitFor(fn() => $has('fronius/status/ok', '1'), 10), str_contains($mlog(), "fronius/status/ok\t0\n")], [true, false]);
check('Dienst+Modbus: Fehlergrund im Log (Warnung)', str_contains(substr($flog(), $logPos), 'Modbus (Wechselrichter 1): Modbus-Ausnahme 11'), true);
$mbMode('ok');
stopProc($mbProc);
@unlink($dir . '/mqtt.log');
$conf['modbus']['aktiv'] = false;
Util::jsonSave($e2e . '/fronius.json', $conf);

stopProc($svc);
$logtext = (string)@file_get_contents($e2e . '/fronius.log');
check('Dienstlog vorhanden', str_contains($logtext, 'gestartet'), true);

stopProc($fProc1);
stopProc($fProc2);
stopProc($mqProc);
// ---------------------------------------------------------------- Healthcheck (LoxBerry-Healthcheck-Seite)
$hcDir = $dir . '/hc';
@mkdir($hcDir . '/run', 0777, true);
$hc = function (string $arg, ?array $status) use ($php, $root, $hcDir): array {
    $f = $hcDir . '/run/status.json';
    @unlink($f);
    if ($status !== null) {
        file_put_contents($f, json_encode($status));
    }
    $p = proc_open([$php, $root . '/fronius2lox/bin/healthcheck', $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['FRO_DEV_DIR' => $hcDir]));
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($p);
    return [$out, $err, $rc];
};
$basis = ['service' => 'running', 'time' => time(), 'mqtt' => 1, 'mqtt_error' => '', 'version' => AppVersion::CURRENT,
    'geraete' => ['wr1' => ['ok' => true, 'error' => '', 'werte' => 90, 'schlaeft' => false]]];
[$o, $e, $rc] = $hc('title', null);
check('Healthcheck title', trim($o), 'Fronius2Lox: Dienst, MQTT-Broker und Wechselrichter');
check('Healthcheck title: Exitcode 0, keine Fehlerausgabe', [$rc, $e], [0, '']);
check('Healthcheck ohne Argument: keine Ausgabe', $hc('', null)[0], '');
$res = function (?array $st) use ($hc): array {
    [$o, $e, $rc] = $hc('check', $st);
    $j = json_decode($o, true);
    return [is_array($j) ? $j : ['status' => -1, 'result' => $o], $rc, $e];
};
[$j, $rc, $e] = $res(null);
check('Healthcheck: ohne Status = Fehler 3', $j['status'], 3);
check('Healthcheck: Exitcode 0, keine Fehlerausgabe', [$rc, $e], [0, '']);
check('Healthcheck: veralteter Status = Fehler 3', $res(['time' => time() - 120] + $basis)[0]['status'], 3);
$j = $res(['mqtt' => 0, 'mqtt_error' => 'Verbindung abgelehnt'] + $basis)[0];
check('Healthcheck: Broker weg = Warnung 4', $j['status'], 4);
check('Healthcheck: Broker-Grund im Text', str_contains($j['result'], 'Verbindung abgelehnt'), true);
check('Healthcheck: nichts eingerichtet = Info 6', $res(['geraete' => []] + $basis)[0]['status'], 6);
$j = $res(['geraete' => ['wr1' => ['ok' => false, 'error' => 'nicht erreichbar', 'werte' => 0, 'schlaeft' => false]]] + $basis)[0];
check('Healthcheck: Wechselrichter ausgefallen = Warnung 4', $j['status'], 4);
check('Healthcheck: Wechselrichter im Text', str_contains($j['result'], 'wr1 (nicht erreichbar)'), true);
$j = $res(['geraete' => ['wr1' => ['ok' => true, 'error' => '', 'werte' => 0, 'schlaeft' => true]]] + $basis)[0];
check('Healthcheck: Nacht (abgeschaltet) = OK 5, keine Warnung', $j['status'], 5);
check('Healthcheck: Nacht im Text', str_contains($j['result'], 'abgeschaltet, Nacht: wr1'), true);
$j = $res($basis)[0];
check('Healthcheck: alles gut = OK 5', $j['status'], 5);
check('Healthcheck: Text zaehlt Geraete und nennt Version', $j['result'], '1 Wechselrichter in Ordnung, Version ' . AppVersion::CURRENT);
check('Healthcheck: ohne Logdatei kein logfile', isset($j['logfile']), false);
file_put_contents($hcDir . '/fronius.log', "x\n");
check('Healthcheck: mit Logdatei der Pfad', $res($basis)[0]['logfile'] ?? null, $hcDir . '/fronius.log');
check('Healthcheck: Ausgabe ist genau eine JSON-Zeile', substr_count(rtrim($hc('check', $basis)[0], "\n"), "\n"), 0);

// ---------------------------------------------------------------- Weboberflaeche mit Attrappen der LoxBerry-Bibliotheken
// Rendert jeden Reiter (ohne echtes LoxBerry): findet undefinierte Variablen, Syntax- und Laufzeitfehler und prueft Menueleiste,
// Gateway-Abo und UDP-Port. Nicht pruefbar: das echte Aussehen in LoxBerry.
$uiDir = $dir . '/ui';
foreach (['config', 'log', 'data', 'sys', 'dev'] as $sub) {
    @mkdir("$uiDir/$sub", 0777, true);
}
@mkdir("$uiDir/sess", 0777, true);
$uiPage = function (string $tab, array $env = []) use ($php, $root, $uiDir): array {
    $e = array_merge(getenv(), [
        'STUB_INDEX' => $root . '/fronius2lox/webfrontend/htmlauth/index.php', 'STUB_BIN' => $root . '/fronius2lox/bin', 'STUB_CONFIG' => "$uiDir/config",
        'STUB_LOG' => "$uiDir/log", 'STUB_DATA' => "$uiDir/data", 'STUB_SYSCONFIG' => "$uiDir/sys", 'FRO_DEV_DIR' => "$uiDir/dev",
    ], $env);
    $cmd = [$php, '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'include_path=' . $root . '/tests/stubs', '-d', 'session.save_path=' . "$uiDir/sess",
        $root . '/tests/ui_page.php', $tab];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $e);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    return [$out, $err];
};
$bad = fn(string $o, string $e) => (bool)preg_match('/(Warning|Notice|Fatal error|Deprecated|Parse error|Uncaught)/', $o . $e);
file_put_contents("$uiDir/sys/general.json", json_encode(['Mqtt' => ['Gatewayversion' => 2, 'Udpinport' => '12345']]));
file_put_contents("$uiDir/sys/subscriptions.json", json_encode(['Subscriptions' => [['Id' => 'shellies/#']]]));
file_put_contents("$uiDir/log/test.log", "2026-01-01 <INFO> Testzeile\n");
foreach (['einstellungen', 'mqtt', 'loxone', 'test', 'logs'] as $tab) {
    [$o, $e] = $uiPage($tab, ['STUB_UDP' => '12345']);
    check("UI $tab: ohne PHP-Warnungen und -Fehler", [$bad($o, $e), $e], [false, '']);
    check("UI $tab: Kopf und Fuss", str_contains($o, '<!--HEADER:') && str_contains($o, '<!--FOOTER-->'), true);
    preg_match('/<!--NAVBAR:(.*?)-->/', $o, $m);
    $nav = json_decode($m[1] ?? '[]', true) ?: [];
    check("UI $tab: Menueleiste mit 5 Eintraegen", count($nav), 5);
    check("UI $tab: genau ein aktiver Eintrag, und zwar dieser", array_values(array_filter($nav, fn($n) => !empty($n['active'])))[0]['URL'] ?? null, '?tab=' . $tab);
    check("UI $tab: nur ein aktiver Eintrag", count(array_filter($nav, fn($n) => !empty($n['active']))), 1);
    check("UI $tab: kein eigener Tab-Block mehr", str_contains($o, '-tabs"'), false);
}
[$o] = $uiPage('mqtt', ['STUB_UDP' => '12345']);
check('UI mqtt: Gateway V2, Abo fehlt, mit Anleitung', str_contains($o, 'Gateway V2: Das Abo „fronius/#“ fehlt'), true);
check('UI mqtt: nichts abonniert = rotes Feld', str_contains($o, 'gw-abo-fehler') && !str_contains($o, 'gw-abo-ok'), true);
check('UI mqtt: rotes Feld ohne Topic-Liste', str_contains($o, 'gw-abo-fehler') && !str_contains($o, '<ul style="margin:4px 0 0 18px'), true);
file_put_contents("$uiDir/sys/subscriptions.json", json_encode(['Subscriptions' => [['Id' => 'fronius/#']]]));
[$o] = $uiPage('mqtt', ['STUB_UDP' => '12345']);
check('UI mqtt: Gateway V2, Abo eingetragen', str_contains($o, 'Gateway V2: eingetragen (fronius/#)'), true);
check('UI mqtt: vollstaendig abonniert = gruenes Feld mit Topic-Liste', str_contains($o, 'gw-abo-ok') && str_contains($o, '<li><code>fronius/#</code></li>') && !str_contains($o, 'gw-abo-fehler'), true);
file_put_contents("$uiDir/sys/subscriptions.json", json_encode(['Subscriptions' => [['Id' => 'fronius/status/#']]]));
[$o] = $uiPage('mqtt');
check('UI mqtt: nur ein Teil abonniert = gruen (nicht rot)', str_contains($o, 'gw-abo-ok') && !str_contains($o, 'gw-abo-fehler'), true);
check('UI mqtt: Teil-Abo: Hinweis und Topic aufgelistet', str_contains($o, 'teilweise abonniert (fronius/status/#)') && str_contains($o, '<li><code>fronius/status/#</code></li>'), true);
file_put_contents("$uiDir/sys/general.json", json_encode(['Mqtt' => ['Gatewayversion' => 1]]));
file_put_contents("$uiDir/config/mqtt_subscriptions.cfg", "fronius/#\n");
[$o] = $uiPage('mqtt');
check('UI mqtt: Gateway V1, Abo in der Datei = gruen', str_contains($o, 'Gateway V1: eingetragen (fronius/#)') && str_contains($o, 'gw-abo-ok'), true);
@unlink("$uiDir/config/mqtt_subscriptions.cfg");
[$o] = $uiPage('mqtt');
check('UI mqtt: Gateway V1, Abo-Datei fehlt', str_contains($o, 'Gateway V1: Das Abo'), true);

[$o] = $uiPage('logs');
check('UI logs: zeigt die Logdatei', str_contains($o, 'Testzeile'), true);

echo "\n$count Pruefungen, $fails Fehler\n";
exit($fails ? 1 : 0);
