<?php
// Weboberflaeche Fronius2Lox: Einstellungen · MQTT · Einbindung in Loxone · Test · Logdateien
// Achtung: LBWeb::lbheader() und mqtt_connectiondetails() ueberschreiben die globale Variable $cfg -> eigene Konfiguration heisst $fro.

require_once 'loxberry_system.php';
require_once 'loxberry_web.php';
require_once 'loxberry_io.php';
require_once LBPBINDIR . '/lib/bootstrap.php';

session_start();
if (empty($_SESSION['fro_token'])) {
    $_SESSION['fro_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['fro_token'];

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Schalter im Stil des LoxBerry Design Systems (Checkbox mit lb-toggle); $text darf feste HTML-Auszeichnung enthalten. */
function schalter(string $name, bool $an, string $text = ''): string
{
    return '<label class="lb-toggle"><input type="checkbox" name="' . h($name) . '" value="1"' . ($an ? ' checked' : '') . '><span class="lb-toggle-slider"></span></label>'
        . ($text !== '' ? ' <span class="fro-schalter-text">' . $text . '</span>' : '');
}

$paths = Paths::detect();
$fro = FroniusConfig::load($paths->config);
$tab = $_GET['tab'] ?? 'einstellungen';
if (!in_array($tab, ['einstellungen', 'mqtt', 'loxone', 'test', 'logs'], true)) {
    $tab = 'einstellungen';
}
$meldungen = [];

// ------------------------------------------------------------ Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($token, (string)($_POST['token'] ?? ''))) {
        http_response_code(403);
        exit('Ungueltiges Formular-Token, Seite neu laden.');
    }
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'speichern') {
        $neu = FroniusConfig::fromPost($fro, $_POST);
        if (Util::jsonSave($paths->config, $neu)) {
            $meldungen[] = 'Einstellungen gespeichert.';
        } else {
            $meldungen[] = 'FEHLER: Einstellungen konnten nicht gespeichert werden.';
        }
        $fro = $neu;
    } elseif ($aktion === 'neustart') {
        exec(escapeshellarg(LBPBINDIR . '/stop.sh') . ' 2>&1');
        exec(escapeshellarg(LBPBINDIR . '/start.sh') . ' 2>&1');
        $meldungen[] = 'Dienst neu gestartet.';
    }
}

$status = Util::jsonLoad($paths->statusFile());
$alter = isset($status['time']) ? time() - (int)$status['time'] : null;
$laeuft = $alter !== null && $alter <= 10;
$basis = '?tab=';

$tabs = ['einstellungen' => 'Einstellungen', 'mqtt' => 'MQTT', 'loxone' => 'Einbindung in Loxone', 'test' => 'Test', 'logs' => 'Logdateien'];
// Menüleiste der LoxBerry-Oberfläche: $navbar muss vor lbheader() definiert sein
$navbar = [];
$nr = 10;
foreach ($tabs as $k => $t) {
    $navbar[$nr] = ['Name' => $t, 'URL' => $basis . $k] + ($tab === $k ? ['active' => true] : []);
    $nr += 10;
}
// UDP-Port des Gateways für Befehle aus Loxone (einstellbar, steht in den MQTT-Verbindungsdaten)
$udp = GatewayInfo::udpPort(function_exists('mqtt_connectiondetails') ? mqtt_connectiondetails() : null, GatewayInfo::general());

// 4. Parameter true: LoxBerry Design System (lb-*-Klassen) statt jQuery Mobile
LBWeb::lbheader('Fronius2Lox ' . AppVersion::CURRENT, '', '', true);
?>
<style>
 .fro pre { background:var(--lb-gray-100,#f4f4f4); color:var(--lb-text,#222); padding:8px; overflow:auto; max-height:420px; border-radius:var(--lb-radius-sm,4px); }
 .fro .ok { color:var(--lb-success,#2a8a2a); font-weight:bold; } .fro .fehler { color:var(--lb-danger,#c0392b); font-weight:bold; }
 .fro .hinweis { color:var(--lb-text-muted,#666); font-size:90%; }
 .fro .lb-card { margin:12px 0; padding:4px 16px 12px 16px; }
 .fro .fro-opt { margin:6px 0; display:flex; align-items:flex-start; gap:8px; }
 .fro .fro-opt .lb-toggle { flex:none; }
 .fro .lb-table td .lb-input, .fro .lb-table td .lb-select { width:auto; max-width:100%; }
 .fro .fro-schmal { width:90px !important; } .fro .fro-mittel { width:150px !important; }
 .fro-led { display:inline-block; width:18px; height:18px; border-radius:3px; vertical-align:middle; border:1px solid rgba(0,0,0,.35); }
 .fro-led-ok { background:var(--lb-success,#2ecc40); } .fro-led-fehler { background:var(--lb-danger,#e74c3c); } .fro-led-aus { background:var(--lb-gray-400,#bbb); }
</style>
<div class="lb-content fro">
<?php foreach ($meldungen as $m) { echo '<div class="lb-callout' . (str_starts_with($m, 'FEHLER') ? ' lb-callout-warning' : '') . '"><span class="' . (str_starts_with($m, 'FEHLER') ? 'fehler' : 'ok') . '">' . h($m) . '</span></div>'; } ?>

<?php if ($tab === 'einstellungen') { ?>
<form method="post" action="<?= $basis ?>einstellungen">
<input type="hidden" name="token" value="<?= h($token) ?>">

<div class="lb-card">
<h3>Allgemein</h3>
<div class="lb-form-row"><label class="lb-form-label" for="intervall">Abfrageintervall (Sekunden, 5–300)</label>
<div class="lb-form-field"><input class="lb-input fro-schmal" type="number" id="intervall" name="intervall" min="5" max="300" value="<?= (int)$fro['intervall'] ?>"></div></div>
<p class="hinweis">Fronius verlangt mindestens 4 Sekunden zwischen zwei Abfragen. Selten ändernde Daten (Geräteliste, Stammdaten, Extremwerte) holt das Plugin nur alle 60 s, das Fehlerprotokoll alle 5 Minuten.</p>
<div class="fro-opt"><?= schalter('wechselrichter', (bool)($fro['wechselrichter']), 'Details je Wechselrichter (Spannung, Strom, Frequenz, Status, Phasenwerte, Extremwerte)') ?></div>
<div class="fro-opt"><?= schalter('zaehler', (bool)($fro['zaehler']), 'Smart Meter (Phasenwerte, Zählerstände)') ?></div>
<div class="fro-opt"><?= schalter('speicher', (bool)($fro['speicher']), 'Batteriespeicher (Ladestand, Temperatur, Kapazität, Module)') ?></div>
<div class="fro-opt"><?= schalter('weitere', (bool)($fro['weitere']), 'Weitere Geräte: OhmPilot, Sensorkarte, String Control') ?></div>
<div class="fro-opt"><?= schalter('logger', (bool)($fro['logger']), 'Datenlogger: Version, Zeitzone, LEDs, API-Version') ?></div>
<div class="fro-opt"><?= schalter('archiv', (bool)($fro['archiv']), 'Archiv: Fehler- und Ereignisprotokoll des Tages sowie Spannung/Strom/Leistung von String 1 und 2 (Datenlogger-Werte, nicht bei GEN24)') ?></div>
<div class="fro-opt"><?= schalter('rohwerte', (bool)($fro['rohwerte']), 'Rohwerte: alle Felder der Antworten zusätzlich unter <code>fronius/&lt;name&gt;/raw/…</code>') ?></div>
<p class="hinweis">Welche Geräte es gibt, fragt das Plugin beim Wechselrichter selbst ab (GetActiveDeviceInfo). Zähler, Speicher, OhmPilot usw. werden nur abgefragt, wenn der Wechselrichter sie meldet. Rohwerte werden nur bei Änderung gesendet.</p>
</div>

<div class="lb-card">
<h3>Nachtbetrieb</h3>
<div class="fro-opt"><?= schalter('nacht_aktiv', (bool)($fro['nacht']['aktiv']), 'Der Wechselrichter schaltet nachts ab: Nichterreichbarkeit nach geringer PV-Leistung ist normal, kein Fehler') ?></div>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Geringe Leistung bis</th><td><input class="lb-input fro-schmal" type="number" name="nacht_schwelle" min="0" max="100000" value="<?= (int)$fro['nacht']['schwelle_w'] ?>"> W</td></tr>
<tr><th>Breitengrad / Längengrad (optional)</th><td><input class="lb-input fro-mittel" type="text" name="nacht_breite" value="<?= h($fro['nacht']['breite']) ?>" placeholder="z. B. 51.0"> / <input class="lb-input fro-mittel" type="text" name="nacht_laenge" value="<?= h($fro['nacht']['laenge']) ?>" placeholder="z. B. 9.0"></td></tr>
</table></div>
<p class="hinweis">Bei Fronius Symo/Primo hängt der Datamanager am Wechselrichter und ist nachts ohne Strom. Fällt das Gerät aus, nachdem die PV-Leistung zuletzt höchstens den eingestellten Wert hatte (abends), gilt das als Nacht:
<code>fronius/&lt;name&gt;/schlaeft</code> = 1, <code>pv_w</code> = 0, <code>status/ok</code> bleibt 1. Fällt es dagegen bei hoher Leistung aus (tagsüber), ist es ein Fehler.
Die Koordinaten brauchen Sie nur, damit auch nach einem Neustart des Plugins mitten in der Nacht der Ausfall als normal erkannt wird (Sonnenstand mit 90 Minuten Toleranz).</p>
</div>

<div class="lb-card">
<h3>MPP-Tracker (Modbus TCP)</h3>
<div class="fro-opt"><?= schalter('modbus_aktiv', (bool)($fro['modbus']['aktiv']), 'Spannung, Strom und Leistung je MPP-Tracker (String) per Modbus TCP lesen') ?></div>
<div class="lb-form-row"><label class="lb-form-label" for="modbus_port">Port</label>
<div class="lb-form-field"><input class="lb-input fro-schmal" type="number" id="modbus_port" name="modbus_port" min="1" max="65535" value="<?= (int)$fro['modbus']['port'] ?>"></div></div>
<p class="hinweis">Die Solar API kennt Tracker 2 bis 4 nur beim GEN24/Tauro/Verto. Beim Symo steht die Aufteilung auf die Tracker nur in der Modbus-Schnittstelle des Datamanagers (SunSpec-Modell 160, nur lesend). Dort muss Modbus eingeschaltet sein: Weboberfläche des Datamanagers → <b>Einstellungen → Modbus</b>: Datenausgabe über Modbus <b>tcp</b>, SunSpec-Modelltyp <b>int + SF</b>, Port 502. Die Unit-ID ist die Wechselrichter-Nummer (beim ersten Wechselrichter 1). Die Werte erscheinen als <code>wr/&lt;Nr&gt;/spannung_dc_v</code>, <code>strom_dc_a</code>, <code>leistung_dc_w</code> (Tracker 1) und <code>…_dc_2_…</code> (Tracker 2) und ersetzen dann die DC-Werte der Solar API. <code>wr/&lt;Nr&gt;/modbus_ok</code> zeigt, ob die Abfrage klappt; Fehlergründe stehen im Log.</p>
</div>

<div class="lb-card">
<h3>Wechselrichter</h3>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Status</th><th>Aktiv</th><th>Name im Topic</th><th>Host/IP</th><th>Port</th></tr>
<?php foreach ($fro['geraete'] as $i => $g) { ?>
<tr>
 <td><?php
    [$led, $tip] = $g['host'] === '' ? ['aus', 'kein Host eingetragen'] : FroniusClient::check($g['host'], (int)$g['port'] ?: 80);
    if ($led !== 'ok' && $g['host'] !== '') {      // nachts ist ein Ausfall normal: Status des Dienstes fragen
        foreach (FroniusConfig::geraete($fro) as $dev) {
            if ($dev['host'] === $g['host'] && $dev['port'] === (int)$g['port'] && !empty($status['geraete'][$dev['alias']]['schlaeft'])) {
                [$led, $tip] = ['aus', 'abgeschaltet (Nacht) - kein Fehler'];
            }
        }
    }
    ?>
 <span class="fro-led fro-led-<?= $led ?>" title="<?= h($tip) ?>"></span> <span class="hinweis"><?= h($tip) ?></span></td>
 <td><?= schalter('geraet[' . $i . '][aktiv]', !empty($g['aktiv'])) ?></td>
 <td><input class="lb-input fro-mittel" type="text" name="geraet[<?= $i ?>][alias]" value="<?= h($g['alias']) ?>" placeholder="wr<?= $i + 1 ?>"></td>
 <td><input class="lb-input fro-mittel" type="text" name="geraet[<?= $i ?>][host]" value="<?= h($g['host']) ?>"></td>
 <td><input class="lb-input fro-schmal" type="number" name="geraet[<?= $i ?>][port]" value="<?= (int)$g['port'] ?>"></td>
</tr>
<?php } ?>
</table></div>
<p class="hinweis">Bis zu vier Fronius-Systeme (je ein Wechselrichter oder Datamanager im Netz). Der Name steckt im Topic (<code>fronius/&lt;name&gt;/…</code>; nur a–z, 0–9, _ und -), leer = wr1, wr2 …
<b>Bei Gen24 muss die Solar API freigeschaltet sein:</b> Weboberfläche des Wechselrichters → Kommunikation → Solar API → „Solar API aktivieren“. Ältere Geräte (Symo, Primo mit Datamanager) liefern sie von Haus aus.
Bei mehreren Wechselrichtern an <i>einem</i> Datamanager genügt dessen Adresse; alle Geräte erscheinen dann unter <code>wr/&lt;Nr&gt;/…</code>.</p>
</div>

<p><button class="lb-btn lb-btn-primary" type="submit" name="aktion" value="speichern">Speichern</button></p>
</form>

<?php } elseif ($tab === 'mqtt') {
    $own = FroniusConfig::ownBroker($fro);
    $d = $own !== null ? ['brokerhost' => $own[0], 'brokerport' => $own[1]] : (function_exists('mqtt_connectiondetails') ? mqtt_connectiondetails() : []);
    $gw = GatewayInfo::subscription('fronius/#', GatewayInfo::general());
    ?>
<h3>MQTT</h3>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th><?= $own !== null ? 'Broker (eigener, aus den Plugin-Einstellungen)' : 'Broker (aus der LoxBerry-Konfiguration)' ?></th><td><?= h(($d['brokerhost'] ?? '?') . ':' . ($d['brokerport'] ?? '?')) ?></td></tr>
<tr><th>Gateway-Abo</th><td><?= GatewayInfo::feldHtml($gw, 'fronius/#') ?></td></tr>
<tr><th>Dienst</th><td><?= $laeuft ? '<span class="ok">läuft</span> (Version ' . h($status['version'] ?? '?') . ')' : '<span class="fehler">läuft nicht</span> (der Wächter startet ihn spätestens nach einer Minute)' ?></td></tr>
<tr><th>Broker-Verbindung des Dienstes</th><td><?= !empty($status['mqtt']) ? '<span class="ok">verbunden</span>' : '<span class="fehler">getrennt</span> ' . h($status['mqtt_error'] ?? '') ?></td></tr>
<tr><th>status/ok</th><td><?= isset($status['ok']) ? (int)$status['ok'] : '-' ?></td></tr>
<tr><th>status/zaehler</th><td><?= isset($status['zaehler']) ? (int)$status['zaehler'] : '-' ?></td></tr>
<tr><th>status/ts</th><td><?= !empty($status['ts']) ? h(date('d.m.Y H:i:s', (int)$status['ts'])) : '-' ?></td></tr>
</table></div>
<?php foreach (($status['geraete'] ?? []) as $a => $r) {
    echo '<p>' . h($a) . ': ' . (!empty($r['schlaeft']) ? '<span class="ok">abgeschaltet (Nacht)</span> - kein Fehler' : (!empty($r['ok']) ? '<span class="ok">erreichbar</span> (' . (int)($r['werte'] ?? 0) . ' Werte)' : '<span class="fehler">' . h($r['error'] ?? 'Fehler') . '</span>')) . '</p>';
} ?>
<form method="post" action="<?= $basis ?>mqtt">
<input type="hidden" name="token" value="<?= h($token) ?>">
<h3>MQTT-Broker</h3>
<?php $mq = $fro['mqtt']; ?>
<div class="fro-opt"><?= schalter('mqtt[eigener_broker]', !empty($mq['eigener_broker']), 'Eigenen MQTT-Broker verwenden (statt dem Broker des LoxBerry)') ?></div>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Host/IP</th><td><input class="lb-input" type="text" name="mqtt[host]" value="<?= h($mq['host']) ?>"></td>
<th>Port</th><td><input class="lb-input fro-schmal" type="number" name="mqtt[port]" value="<?= (int)$mq['port'] ?>"></td></tr>
<tr><th>Benutzer</th><td><input class="lb-input fro-mittel" type="text" name="mqtt[benutzer]" value="<?= h($mq['benutzer']) ?>" autocomplete="off"></td>
<th>Passwort</th><td><input class="lb-input fro-mittel" type="password" name="mqtt[passwort]" value="" autocomplete="new-password" placeholder="<?= $mq['passwort'] !== '' ? '(gesetzt)' : '' ?>"></td></tr>
</table></div>
<p class="hinweis">Ohne Haken verwendet das Plugin den MQTT-Broker aus der LoxBerry-Konfiguration (empfohlen, Zugangsdaten verwaltet LoxBerry). Mit eigenem Broker erreichen die Werte den Miniserver nur, wenn das MQTT-Gateway diesen Broker abonniert. Ein leeres Passwortfeld behält das gespeicherte Passwort.</p>
<p><button class="lb-btn lb-btn-primary" type="submit" name="aktion" value="speichern">Speichern</button></p>
</form>

<?php } elseif ($tab === 'loxone') {
    $aliase = array_map(fn($g) => $g['alias'], FroniusConfig::geraete($fro)) ?: ['wr1'];
    // [Topic (relativ zu fronius/<name>/), Einheit, Bedeutung, Gruppe]
    $werte = [
        ['pv_w', 'W', 'PV-Leistung (nachts 0)', 'Energiefluss'],
        ['haus_w', 'W', 'Hausverbrauch (nur mit Smart Meter)', 'Energiefluss'],
        ['netz_w', 'W', 'Netzleistung: + Bezug / − Einspeisung', 'Energiefluss'],
        ['netz_bezug_w | netz_einspeisung_w', 'W', 'Netzbezug / Einspeisung (je ≥ 0)', 'Energiefluss'],
        ['batterie_w', 'W', 'Batterieleistung: + Laden / − Entladen (wie Senec2Lox; nur mit Speicher)', 'Energiefluss'],
        ['batterie_laden_w | batterie_entladen_w', 'W', 'Lade- / Entladeleistung (je ≥ 0)', 'Energiefluss'],
        ['batterie_soc', '%', 'Ladestand des Speichers', 'Energiefluss'],
        ['batterie_standby | notstrom', '0/1', 'Speicher im Standby / Notstrombetrieb', 'Energiefluss'],
        ['energie_tag_wh | energie_jahr_wh | energie_gesamt_wh', 'Wh', 'Erzeugung heute / dieses Jahr / gesamt (GEN24: nur gesamt)', 'Energiefluss'],
        ['autarkie | eigenverbrauch', '%', 'Autarkiegrad / Eigenverbrauchsquote (nur mit Smart Meter)', 'Energiefluss'],
        ['modus', 'Text', 'Betriebsart (produce-only, meter, bidirectional, ac-coupled …)', 'Energiefluss'],
        ['zaehler_standort', 'Text', 'Einbauort des Smart Meters (grid, load, unknown)', 'Energiefluss'],
        ['verbunden', '0/1', 'Wechselrichter erreichbar', 'Status'],
        ['schlaeft', '0/1', '1 = Wechselrichter abgeschaltet (Nacht), kein Fehler', 'Status'],
        ['wr/<Nr>/leistung_ac_w | scheinleistung_va', 'W, VA', 'AC-Leistung / Scheinleistung', 'Wechselrichter'],
        ['wr/<Nr>/spannung_ac_v | strom_ac_a | frequenz_hz', 'V, A, Hz', 'AC-Spannung, -Strom, Netzfrequenz', 'Wechselrichter'],
        ['wr/<Nr>/spannung_dc_v | strom_dc_a', 'V, A', 'DC-Spannung, -Strom (GEN24: je MPP-Tracker auch spannung_dc_2_v … 4)', 'Wechselrichter'],
        ['wr/<Nr>/string_1_spannung_v | string_1_strom_a | string_1_leistung_w | string_1_zeit (ebenso string_2_…)', 'V, A, W, Unix-Zeit', 'String 1 und 2 aus dem Archiv des Datenloggers (GetArchiveData, Symo/Primo/Hybrid, nicht GEN24): Wert des letzten Aufzeichnungszeitpunkts (5–30 min Raster), Leistung berechnet; Werte über 45 min Alter werden nicht gesendet', 'Wechselrichter'],
        ['wr/<Nr>/mppt_anzahl | mppt_<k>_name | modbus_ok', '-', 'Nur mit Modbus: Zahl der MPP-Tracker, Name des Trackers (z. B. String 1), 1/0 = Modbus-Abfrage klappt', 'Wechselrichter'],
        ['wr/<Nr>/leistung_dc_w | leistung_dc_2_w … 4 | leistung_dc_summe_w', 'W', 'DC-Leistung je MPP-Tracker: mit Modbus gemessen (SunSpec 160), sonst berechnet aus Spannung × Strom (Tracker 2–4 nur, wenn Modbus aktiv ist oder der Wechselrichter sie meldet; Summe ab 2 Trackern)', 'Wechselrichter'],
        ['wr/<Nr>/energie_tag_wh | energie_jahr_wh | energie_gesamt_wh', 'Wh', 'Erzeugung (Fronius: bei Non-Hybrid ungenau)', 'Wechselrichter'],
        ['wr/<Nr>/status_code | status_text', '', 'Status: 7 Betrieb, 8 Standby, 10 Fehler, 11 Leerlauf, 12 Bereit, 13 Schläft, 0–6 Startet, 9 Bootloader', 'Wechselrichter'],
        ['wr/<Nr>/zustand', 'Text', 'Zustand als Text (GEN24: Running …)', 'Wechselrichter'],
        ['wr/<Nr>/fehler_code', '', 'Fehlercode (0 = kein Fehler)', 'Wechselrichter'],
        ['wr/<Nr>/led_farbe_code | led_zustand_code', '', 'LED des Wechselrichters (Codes)', 'Wechselrichter'],
        ['wr/<Nr>/timer_restzeit_s | fehler_quittierbar', 's, 0/1', 'Management-Timer, Fehler quittierbar', 'Wechselrichter'],
        ['wr/<Nr>/strom_l1_a … l3 | spannung_l1_v … l3', 'A, V', 'Strom und Spannung je Phase (nur dreiphasig)', 'Wechselrichter'],
        ['wr/<Nr>/temperatur_umgebung_c', '°C', 'Umgebungstemperatur (nur wenige Geräte)', 'Wechselrichter'],
        ['wr/<Nr>/luefter_vorne_links | …_rechts | luefter_hinten_links | …_rechts', '', 'Lüfterdrehzahl (nur CL, XL, IG 500/400)', 'Wechselrichter'],
        ['wr/<Nr>/tag_ | jahr_ | gesamt_ + leistung_max_w | spannung_ac_max_v | spannung_ac_min_v | spannung_dc_max_v', 'W, V', 'Extremwerte (Minimum/Maximum) je Zeitraum, alle 60 s', 'Wechselrichter'],
        ['wr/<Nr>/batterie_soc | batterie_modus', '%, Text', 'Ladestand / Batteriemodus je Hybrid-Wechselrichter', 'Wechselrichter'],
        ['wr/<Nr>/komponenten_id', '', 'Komponenten-ID (CID)', 'Wechselrichter'],
        ['wr/<Nr>/info/name | typ | typ_name | seriennummer', 'Text', 'Stammdaten; typ_name aus der Typliste der Fronius-Doku', 'Wechselrichter'],
        ['wr/<Nr>/info/pv_nennleistung_wp | sichtbar | status_code | status_text | fehler_code | zustand', '', 'weitere Stammdaten und Status (alle 60 s)', 'Wechselrichter'],
        ['wr/<Nr>/fehler_letzter_code | fehler_letzter_zeit | fehler_letzter_flags | fehler_anzahl_heute', '', 'Letzter Fehler des Tages (Archiv), Zeit als Unix-Zeit', 'Fehler und Ereignisse'],
        ['wr/<Nr>/ereignis_letzter_code | ereignis_letzter_text | ereignis_letzte_zeit', '', 'Letztes Ereignis des Wechselrichters (Archiv)', 'Fehler und Ereignisse'],
        ['fehler_anzahl_heute | ereignis_anzahl_heute', '', 'Anzahl Fehler / Ereignisse heute (alle Geräte)', 'Fehler und Ereignisse'],
        ['ereignis_letzter_code | ereignis_letzter_text | ereignis_letzte_zeit', '', 'Letztes allgemeines Ereignis (z. B. Leistungsbegrenzung)', 'Fehler und Ereignisse'],
        ['zaehler/<Nr>/leistung_w | scheinleistung_va | blindleistung_var | leistungsfaktor', 'W, VA, var', 'Smart Meter: Gesamtwerte (Vorzeichen je nach Einbauort)', 'Smart Meter'],
        ['zaehler/<Nr>/leistung_l1_w … l3 | scheinleistung_l1_va … | blindleistung_l1_var … | leistungsfaktor_l1 …', '', 'Smart Meter: Werte je Phase', 'Smart Meter'],
        ['zaehler/<Nr>/spannung_l1_v … l3 | spannung_l1_l2_v | l2_l3 | l3_l1 | spannung_mittel_v', 'V', 'Smart Meter: Spannungen', 'Smart Meter'],
        ['zaehler/<Nr>/strom_l1_a … l3 | strom_summe_a | frequenz_hz', 'A, Hz', 'Smart Meter: Ströme, Netzfrequenz', 'Smart Meter'],
        ['zaehler/<Nr>/energie_bezug_wh | energie_einspeisung_wh', 'Wh', 'Zählerstände (Sicht des Zählers)', 'Smart Meter'],
        ['zaehler/<Nr>/energie_plus_absolut_wh | energie_minus_absolut_wh', 'Wh', 'Absolute Zähler (Sicht von Solar.web: Bezug / Einspeisung)', 'Smart Meter'],
        ['zaehler/<Nr>/energie_l1_verbraucht_wh | …_erzeugt_wh | blindenergie_*_varh', 'Wh, varh', 'Energie je Phase, Blindenergie', 'Smart Meter'],
        ['zaehler/<Nr>/aktiv | sichtbar | zeitstempel', '0/1, s', 'Zähler aktiv; sichtbar (0 = Werte unvollständig, dann werden keine Messwerte gesendet); Zeit der Daten', 'Smart Meter'],
        ['zaehler/<Nr>/standort_code | standort_text', '', '0 Netzübergabe, 1 Verbrauchspfad, 3 Fremderzeuger, 256–511 Unterverbraucher', 'Smart Meter'],
        ['zaehler/<Nr>/info/hersteller | modell | seriennummer', 'Text', 'Stammdaten des Zählers', 'Smart Meter'],
        ['zusatzzaehler/<ID>/leistung_w | standort_code | standort_text | name | kategorie', 'W', 'Zusatzzähler (Unterverbraucher, Fremderzeuger) aus dem Energiefluss', 'Smart Meter'],
        ['speicher/<Nr>/soc | spannung_v | strom_a | temperatur_c', '%, V, A, °C', 'Speicher: Ladestand, Spannung, Strom (+ Laden), Zelltemperatur', 'Speicher'],
        ['speicher/<Nr>/kapazitaet_wh | nennkapazitaet_wh | aktiv | zeitstempel', 'Wh', 'Speicher: aktuelle und Nennkapazität', 'Speicher'],
        ['speicher/<Nr>/status_code | status_text', '', 'Speicherstatus (Text nur für BYD und LG)', 'Speicher'],
        ['speicher/<Nr>/modul/<n>/…', '', 'Fronius Solar Battery: Werte je Modul, status_aktuell_text / status_vorher_text, zyklen', 'Speicher'],
        ['speicher/<Nr>/info/hersteller | modell | seriennummer', 'Text', 'Stammdaten des Speichers', 'Speicher'],
        ['ohmpilot/<Nr>/leistung_w | energie_verbraucht_wh | temperatur_c', 'W, Wh, °C', 'OhmPilot', 'Weitere Geräte'],
        ['ohmpilot/<Nr>/status_code | status_text | fehler_code', '', 'OhmPilot: 0 Betrieb, 1 Mindesttemperatur, 2 Legionellenschutz, 3 kritischer Fehler, 4 Fehler, 5 Boost', 'Weitere Geräte'],
        ['ohmpilot/<ID>/leistung_w | temperatur_c | status_text', '', 'OhmPilot aus dem Energiefluss (Komponenten-ID)', 'Weitere Geräte'],
        ['ohmpilot_eco/<ID>/leistung_w | temperatur_1_c | temperatur_2_c | status_heizstab1 | status_heizstab2', '', 'OhmPilot Eco (nur GEN24)', 'Weitere Geräte'],
        ['sensor/<Nr>/kanal_<n> | kanal_<n>_einheit', '', 'Sensorkarte: Messwert je Kanal (Kanalnamen unter info/kanalnamen)', 'Weitere Geräte'],
        ['sensor/<Nr>/kanal_<n>_tag_max | _tag_min | _monat_ | _jahr_ | _gesamt_ …', '', 'Sensorkarte: Extremwerte je Kanal', 'Weitere Geräte'],
        ['string/<Nr>/strom_<Kanal>', 'A', 'String Control: Strom je Kanal', 'Weitere Geräte'],
        ['string/<Nr>/summe_tag_<k>_ah | summe_jahr_<k>_ah | summe_gesamt_<k>_ah', 'Ah', 'String Control: Stromsummen', 'Weitere Geräte'],
        ['string/<Nr>/fehler_zeit | fehler_mittelwert | fehler_kanal_<k>_abweichung_prozent | fehler_kanal_<k>_summe_ah', '', 'String Control: letzter Fehler', 'Weitere Geräte'],
        ['logger/seriennummer | produkt | plattform | hw_version | sw_version', 'Text', 'Datenlogger (nicht GEN24)', 'Datenlogger'],
        ['logger/zeitzone_ort | zeitzone_name | utc_offset_s | sprache', '', 'Zeitzone und Sprache des Loggers', 'Datenlogger'],
        ['logger/einspeisefaktor | kostenfaktor | waehrung | co2_faktor | co2_einheit', '', 'Tarif- und CO₂-Faktoren des Loggers', 'Datenlogger'],
        ['logger/led_power_ | led_solarnet_ | led_solarweb_ | led_wlan_ + farbe | zustand', 'Text', 'LEDs des Datenloggers (Farbe, Zustand)', 'Datenlogger'],
        ['info/api_version | api_kompatibel', '', 'Solar-API-Version (alle 60 s)', 'Datenlogger'],
        ['info/anzahl_wechselrichter | zaehler | speicher | ohmpilot | sensorkarten | stringcontrol', '', 'Anzahl der gerade online gemeldeten Geräte (GetActiveDeviceInfo)', 'Datenlogger'],
        ['info/powerflow_version', '', 'Version des Energiefluss-Formats', 'Datenlogger'],
    ];
    ?>
<h3>Einbindung in Loxone</h3>
<p>Der Eingangsname ist das Topic mit _ statt /. Die Werte kommen über das MQTT-Gateway (Abo fronius/#) als virtuelle Eingänge, z. B. <code>fronius_wr1_pv_w</code>.
Werte ohne Inhalt (z. B. Smart Meter fehlt) werden nicht gesendet. Mit der Option <i>Rohwerte</i> kommen alle Felder der Antworten zusätzlich unter <code>fronius/&lt;name&gt;/raw/…</code>.</p>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Gruppe</th><th>Topic</th><th>Eingangsname</th><th>Einheit</th><th>Bedeutung</th><th>retained</th></tr>
<?php
$einzel = fn(string $t) => !preg_match('/[|…<]|_ \+ | \+ /', $t);     // genau ein festes Topic?
foreach ($aliase as $a) {
    foreach ($werte as $w) {
        $topic = 'fronius/' . $a . '/' . $w[0];
        $name = $einzel($w[0]) ? str_replace('/', '_', $topic) : '(Topic mit _ statt /)';
        echo '<tr><td>' . h($w[3]) . '</td><td>' . h($topic) . '</td><td>' . h($name) . '</td><td>' . h($w[1]) . '</td><td>' . h($w[2]) . '</td><td>nein</td></tr>';
    }
}
foreach ([['fronius/status/ok', '0/1', 'Alle Geräte liefern Werte oder sind nachts abgeschaltet (No Cache)'], ['fronius/status/zaehler', '0–999', 'Lebenszeichen, zählt je Durchgang (No Cache)'],
    ['fronius/status/ts', 's', 'Unix-Zeitstempel des letzten Durchgangs']] as $w) {
    echo '<tr><td>Status</td><td>' . h($w[0]) . '</td><td>' . h(str_replace('/', '_', $w[0])) . '</td><td>' . h($w[1]) . '</td><td>' . h($w[2]) . '</td><td>nein</td></tr>';
}
?>
</table></div>
<p class="hinweis">Beim Absturz des Dienstes setzt der Broker fronius/status/ok per Letzter-Wille-Nachricht auf 0 (retained). Das Plugin hat keine Befehle: Die Solar API ist nur lesend. Eine Kennzahl für Loxone, die nachts weiterhilft: <code>fronius/&lt;name&gt;/schlaeft</code> (1 = Wechselrichter abgeschaltet).</p>

<?php } elseif ($tab === 'test') { ?>
<h3>Test</h3>
<form method="post" action="<?= $basis ?>test">
<input type="hidden" name="token" value="<?= h($token) ?>">
<button class="lb-btn lb-btn-primary" type="submit" name="aktion" value="abruf">Sofort abrufen</button>
<button class="lb-btn" type="submit" name="aktion" value="selbst">Selbstprüfung</button>
<button class="lb-btn lb-btn-danger" type="submit" name="aktion" value="neustart">Dienst neu starten</button>
</form>
<?php
    $a = (string)($_POST['aktion'] ?? '');
    $geraete = FroniusConfig::geraete($fro);
    if ($a === 'abruf') {
        if (!$geraete) {
            echo '<p class="fehler">Kein Wechselrichter konfiguriert.</p>';
        }
        foreach ($geraete as $dev) {
            echo '<h4>' . h($dev['alias']) . ' (' . h($dev['host'] . ':' . $dev['port']) . ')</h4>';
            $c = new FroniusClient($dev['host'], $dev['port']);
            $pf = $c->get('v1/GetPowerFlowRealtimeData.fcgi');
            if ($pf === null) {
                echo '<p class="fehler">' . h($c->error) . '</p>';
                continue;
            }
            $out = FroniusMapper::powerflow($pf);
            foreach (FroniusMapper::inverterIds($pf) as $id) {
                $d = $c->get('v1/GetInverterRealtimeData.cgi', ['Scope' => 'Device', 'DeviceId' => $id, 'DataCollection' => 'CommonInverterData']);
                if ($d !== null) {
                    $out += FroniusMapper::inverter($id, $d);
                }
            }
            if ((string)($pf['Site']['Meter_Location'] ?? 'unknown') !== 'unknown') {
                $d = $c->get('v1/GetMeterRealtimeData.cgi', ['Scope' => 'System']);
                if ($d !== null) {
                    $out += FroniusMapper::meter($d);
                }
            }
            if (isset($out['batterie_w']) || isset($out['batterie_soc'])) {
                $d = $c->get('v1/GetStorageRealtimeData.cgi', ['Scope' => 'System']);
                if ($d !== null) {
                    $out += FroniusMapper::storage($d);
                }
            }
            echo '<div class="lb-table-scroll"><table class="lb-table"><tr><th>Topic</th><th>Wert</th></tr>';
            foreach ($out as $k => $v) {
                echo '<tr><td>fronius/' . h($dev['alias'] . '/' . $k) . '</td><td>' . h($v) . '</td></tr>';
            }
            echo '</table></div>';
        }
    } elseif ($a === 'selbst') {
        $zeile = function (string $name, bool $ok, string $text): void {
            echo '<tr><td>' . h($name) . '</td><td class="' . ($ok ? 'ok' : 'fehler') . '">' . ($ok ? 'OK' : 'Fehler') . '</td><td>' . h($text) . '</td></tr>';
        };
        echo '<div class="lb-table-scroll"><table class="lb-table"><tr><th>Prüfung</th><th>Ergebnis</th><th>Details</th></tr>';
        $zeile('Konfiguration', count($geraete) > 0, count($geraete) . ' Wechselrichter konfiguriert');
        foreach ($geraete as $dev) {
            [$led, $tip] = FroniusClient::check($dev['host'], $dev['port']);
            $zeile('Solar API ' . $dev['alias'], $led === 'ok', $tip);
            if ($led === 'ok') {
                $c = new FroniusClient($dev['host'], $dev['port']);
                $pf = $c->get('v1/GetPowerFlowRealtimeData.fcgi');
                $zeile('Energiefluss ' . $dev['alias'], $pf !== null, $pf !== null ? count(FroniusMapper::powerflow($pf)) . ' Werte, Wechselrichter: ' . (implode(', ', FroniusMapper::inverterIds($pf)) ?: '-') . ', Smart Meter: ' . ($pf['Site']['Meter_Location'] ?? '?') : $c->error);
            }
        }
        $own = FroniusConfig::ownBroker($fro);
        $d = $own !== null ? ['brokerhost' => $own[0], 'brokerport' => $own[1], 'brokeruser' => $own[2], 'brokerpass' => $own[3]] : (function_exists('mqtt_connectiondetails') ? mqtt_connectiondetails() : []);
        $mq = new MqttClient((string)($d['brokerhost'] ?? 'localhost'), (int)($d['brokerport'] ?? 1883), (string)($d['brokeruser'] ?? ''), (string)($d['brokerpass'] ?? ''), 'fronius_test_' . $paths->folder, 'fronius/status/test');
        $okm = $mq->connect();
        $zeile('Broker', $okm, $okm ? 'Verbindung möglich' : $mq->lastError());
        $mq->close();
        $gw = GatewayInfo::subscription('fronius/#', GatewayInfo::general());
        $zeile('Gateway-Abo', $gw['state'] === 'ok', $gw['text']);
        $zeile('Dienst', $laeuft, $laeuft ? 'läuft, letzte Statusmeldung vor ' . $alter . ' s' : 'keine aktuelle Statusmeldung');
        echo '</table></div>';
    }
} elseif ($tab === 'logs') {
    echo '<h3>Logdateien</h3>';
    $dateien = glob(LBPLOGDIR . '/*.log') ?: [];
    if (!$dateien) {
        echo '<p>Noch keine Logdatei vorhanden.</p>';
    }
    foreach ($dateien as $f) {
        $size = (int)filesize($f);
        $text = (string)@file_get_contents($f, false, null, max(0, $size - 20000));   // nur das Ende lesen
        $zeilen = array_slice(explode("\n", $text), -200);
        echo '<h4>' . h(basename($f)) . ' (letzte Zeilen)</h4><pre>' . h(implode("\n", $zeilen)) . '</pre>';
    }
}
?>
</div>
<?php LBWeb::lbfooter(); ?>
