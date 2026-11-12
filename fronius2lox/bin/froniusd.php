<?php
/** Fronius-Dienst: fragt die Solar API der Wechselrichter ab und veroeffentlicht die Werte per MQTT. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (!getenv('FRO_DEV_DIR')) {
    require_once 'loxberry_system.php';
    require_once 'loxberry_io.php';
    require_once 'loxberry_log.php';
}
require_once __DIR__ . '/lib/bootstrap.php';

const T = 'fronius';    // Topic-Wurzel

$paths = Paths::detect();

// Nur eine Instanz
$lock = fopen($paths->lockFile(), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
file_put_contents($paths->pidFile(), (string)getmypid());

$log = new Logger($paths->logfile, 'Dienst', 'Fronius-Dienst');
/** Loglevel aus der LoxBerry-Pluginverwaltung (im Test FRO_DEV_LOGLEVEL). Wird im Betrieb regelmaessig neu gelesen. */
function applyLogLevel(Logger $log): void
{
    if (getenv('FRO_DEV_DIR')) {
        $log->setLevel(getenv('FRO_DEV_LOGLEVEL') ?: 'debug');
    } elseif (class_exists('LBSystem') && method_exists('LBSystem', 'pluginloglevel')) {
        $lvl = (int)LBSystem::pluginloglevel();
        if ($lvl > 0) {
            $log->setLoxberryLevel($lvl);
        }
    }
}
applyLogLevel($log);
$logLevelCheck = 0;
register_shutdown_function(function () use ($log) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $log->error('Dienst abgestuerzt: ' . $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')');
    }
});
$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function () use (&$running) {
        $running = false;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

/** Broker-Daten: eigener Broker aus den Einstellungen, sonst LoxBerry-Konfiguration; im Test FRO_DEV_MQTT=host:port. */
function brokerDetails(Logger $log, array $fro): ?array
{
    $dev = getenv('FRO_DEV_MQTT');
    if ($dev) {
        [$h, $p] = explode(':', $dev, 2) + [1 => '1883'];
        return [$h, (int)$p, '', ''];
    }
    $own = FroniusConfig::ownBroker($fro);
    if ($own !== null) {
        return $own;
    }
    if (!function_exists('mqtt_connectiondetails')) {
        $log->error('LoxBerry-Funktion mqtt_connectiondetails() nicht verfuegbar', 'mqtt-details');
        return null;
    }
    $d = mqtt_connectiondetails();     // ueberschreibt die globale Variable $cfg - die Dienstkonfiguration heisst deshalb $fro
    $host = is_array($d) ? (string)($d['brokerhost'] ?? '') : '';
    $port = is_array($d) ? (int)($d['brokerport'] ?? 0) : 0;
    if (($host === '' || $port === 0) && is_array($d) && !empty($d['brokeraddress']) && str_contains((string)$d['brokeraddress'], ':')) {
        [$h2, $p2] = explode(':', (string)$d['brokeraddress'], 2);
        $host = $host !== '' ? $host : $h2;
        $port = $port ?: (int)$p2;
    }
    if ($host === '') {
        $log->error('Keine Broker-Daten in der LoxBerry-Konfiguration (MQTT eingerichtet?)', 'mqtt-details');
        return null;
    }
    return [$host, $port ?: 1883, (string)($d['brokeruser'] ?? ''), (string)($d['brokerpass'] ?? '')];
}

/**
 * Ein Geraet abfragen und veroeffentlichen.
 *
 * Jeder Durchgang: Energiefluss (Pflicht) + schnelle Daten (Wechselrichter, Zaehler, Speicher, OhmPilot, Sensorkarte, String Control).
 * Alle 60 s zusaetzlich langsame Daten (Geraeteliste, Stammdaten, Datenlogger, Extremwerte, String-Summen), das Fehler-/Ereignisarchiv
 * hoechstens alle 5 Minuten (Fronius verlangt 120 s Abstand). Welche Geraete es gibt, fragt der Dienst beim Wechselrichter selbst ab
 * (GetActiveDeviceInfo). Ist der Wechselrichter nachts abgeschaltet, ist das kein Fehler (siehe FroniusNight).
 *
 * @return array{ok:bool,error:string,werte:int,schlaeft:bool}
 */
function pollDevice(array $dev, array $fro, MqttClient $mqtt, Logger $log): array
{
    static $state = [];
    $alias = $dev['alias'];
    $now = time();
    $state[$alias] ??= ['ids' => [], 'disc' => false, 'lastSlow' => 0, 'lastArchive' => 0, 'lastPv' => lastPvLoad($alias), 'online' => null,
        'offlineNext' => 0, 'last' => null, 'skip' => [], 'fails' => [], 'rawSeen' => [], 'pvSaved' => 0];
    $st = &$state[$alias];

    // Nicht erreichbar: nicht bei jedem Durchgang neu versuchen (spart Wartezeit und Log), sondern nach einer Pause
    if ($st['online'] === false && $now < $st['offlineNext'] && $st['last'] !== null) {
        return $st['last'];
    }
    $c = new FroniusClient($dev['host'], (int)$dev['port']);
    $pf = $c->get('v1/GetPowerFlowRealtimeData.fcgi');
    if ($pf === null) {
        $err = $c->error;
        $st['online'] = false;
        $st['offlineNext'] = $now + (int)(getenv('FRO_DEV_RETRY') ?: 60);
        $nacht = str_starts_with($err, 'nicht erreichbar') && FroniusNight::expected($fro['nacht'], $st['lastPv'], $now);
        $mqtt->publish(T . "/$alias/verbunden", '0');
        if ($nacht) {
            // Abgeschaltet (Nacht): kein Fehler. PV-Leistung 0, damit Loxone nicht den letzten Tageswert behaelt.
            $log->info("[$alias] nicht erreichbar - Wechselrichter ist abgeschaltet (Nacht), kein Fehler", "night-$alias");
            $mqtt->publish(T . "/$alias/schlaeft", '1');
            $mqtt->publish(T . "/$alias/pv_w", '0');
            foreach ($st['ids']['Inverter'] ?? ['1'] as $id) {
                $mqtt->publish(T . "/$alias/wr/$id/leistung_ac_w", '0');
                $mqtt->publish(T . "/$alias/wr/$id/status_text", 'Offline');
            }
            return $st['last'] = ['ok' => true, 'error' => '', 'werte' => 0, 'schlaeft' => true];
        }
        $log->error("[$alias] Abruf fehlgeschlagen: $err", "poll-$alias-" . md5($err));      // anderer Fehlertext = neue Meldung
        $mqtt->publish(T . "/$alias/schlaeft", '0');
        return $st['last'] = ['ok' => false, 'error' => $err, 'werte' => 0, 'schlaeft' => false];
    }
    if ($st['online'] === false) {
        $log->info("[$alias] wieder erreichbar");
        $st['lastSlow'] = 0;            // nach dem Aufwachen Geraeteliste und Stammdaten sofort neu holen
        $st['lastArchive'] = 0;
        $st['skip'] = [];
        $st['fails'] = [];
    }
    $st['online'] = true;

    $out = FroniusMapper::powerflow($pf);
    $out['schlaeft'] = '0';
    $raw = $fro['rohwerte'] ? FroniusMapper::raw('raw/powerflow', $pf) : [];
    if (isset($out['pv_w'])) {
        $st['lastPv'] = (float)$out['pv_w'];
        if ($now - $st['pvSaved'] >= 60) {       // ueberlebt einen Neustart des Dienstes (liegt im RAM)
            $st['pvSaved'] = $now;
            lastPvSave($alias, (float)$out['pv_w']);
        }
    }

    // Zusatzabfrage. Fehler sind nicht schlimm (nicht jedes Geraet kann alles). Wiederholt scheiternde Abfragen
    // werden zehn Minuten ausgesetzt, damit der Wechselrichter nicht mit sinnlosen Anfragen belastet wird.
    $extra = function (string $name, string $path, $params, callable $map, string $rawName, ?string $bodyKey = null) use ($c, $log, $alias, &$out, &$raw, &$st, $fro): ?array {
        if (($st['skip'][$name] ?? 0) > time()) {
            return null;
        }
        usleep(150000);                 // Fronius: Abfragen nicht im Sekundentakt hintereinander
        if ($bodyKey !== null) {
            $b = $c->body($path, $params);
            $d = $b === null ? null : (is_array($b[$bodyKey] ?? null) ? $b[$bodyKey] : []);
        } else {
            $d = $c->get($path, $params);
        }
        if ($d === null) {
            $log->debug("[$alias] $name nicht verfuegbar: {$c->error}", "extra-$alias-$name");
            $st['fails'][$name] = ($st['fails'][$name] ?? 0) + 1;
            if ($st['fails'][$name] >= 3) {
                $st['skip'][$name] = time() + 600;
                $st['fails'][$name] = 0;
            }
            return null;
        }
        $st['fails'][$name] = 0;
        $out = $map($d) + $out;
        if ($fro['rohwerte']) {
            $raw += FroniusMapper::raw("raw/$rawName", $d);
        }
        return $d;
    };

    // --- langsame Daten (alle 60 s)
    $slow = $now - $st['lastSlow'] >= 60;
    if ($slow) {
        $st['lastSlow'] = $now;
        $dd = $extra('Geraeteliste', 'v1/GetActiveDeviceInfo.cgi', ['DeviceClass' => 'System'], fn(array $d) => FroniusMapper::devices($d)['topics'], 'geraete');
        if ($dd !== null) {
            $st['ids'] = FroniusMapper::devices($dd)['ids'];
            $st['disc'] = true;
        }
        $extra('Geraeteinfo', 'v1/GetInverterInfo.cgi', [], fn(array $d) => FroniusMapper::info($d), 'info');
        if ($fro['logger']) {
            $v = $c->raw('GetAPIVersion.cgi');
            if (isset($v['APIVersion'])) {
                $out['info/api_version'] = (string)$v['APIVersion'];
                if (isset($v['CompatibilityRange'])) {
                    $out['info/api_kompatibel'] = (string)$v['CompatibilityRange'];
                }
            }
            $extra('Datenlogger', 'v1/GetLoggerInfo.cgi', [], fn(array $d) => FroniusMapper::loggerInfo($d), 'logger', 'LoggerInfo');
            $extra('Logger-LEDs', 'v1/GetLoggerLEDInfo.cgi', [], fn(array $d) => FroniusMapper::loggerLeds($d), 'logger_led');
        }
    }
    $ids = $st['ids'];
    $inv = $st['disc'] ? ($ids['Inverter'] ?? []) : [];
    $inv = $inv ?: FroniusMapper::inverterIds($pf);
    $loc = (string)($pf['Site']['Meter_Location'] ?? 'unknown');
    $has = fn(string $class, bool $fallback) => $st['disc'] ? !empty($ids[$class]) : $fallback;

    // --- Wechselrichter-Details
    if ($fro['wechselrichter']) {
        $mb = null;
        foreach ($inv as $id) {
            $extra("Wechselrichter $id", 'v1/GetInverterRealtimeData.cgi', ['Scope' => 'Device', 'DeviceId' => $id, 'DataCollection' => 'CommonInverterData'],
                fn(array $d) => FroniusMapper::inverter($id, $d), "wechselrichter/$id");
            $extra("3-Phasen-Daten $id", 'v1/GetInverterRealtimeData.cgi', ['Scope' => 'Device', 'DeviceId' => $id, 'DataCollection' => '3PInverterData'],
                fn(array $d) => FroniusMapper::inverter3p($id, $d), "wechselrichter_3p/$id");
            if ($slow) {
                $extra("Extremwerte $id", 'v1/GetInverterRealtimeData.cgi', ['Scope' => 'Device', 'DeviceId' => $id, 'DataCollection' => 'MinMaxInverterData'],
                    fn(array $d) => FroniusMapper::inverterMinMax($id, $d), "wechselrichter_minmax/$id");
            }
            // MPP-Tracker per Modbus (SunSpec 160): ersetzt die DC-Werte der Solar API; Fehler stoeren den Durchgang nicht
            if (!empty($fro['modbus']['aktiv']) && ctype_digit((string)$id) && (int)$id >= 1 && (int)$id <= 247) {
                $key = "Modbus $id";
                if (($st['skip'][$key] ?? 0) <= time()) {
                    $mb ??= new FroniusModbus($dev['host'], (int)$fro['modbus']['port'], (float)(getenv('FRO_DEV_MODBUS_TIMEOUT') ?: 3));
                    $tr = FroniusSunSpec::mppt($mb, (int)$id, $st['sunspec'][$id]);
                    if ($tr !== null) {
                        $st['fails'][$key] = 0;
                        $out = FroniusMapper::mppt($id, $tr) + ['wr/' . Util::name($id) . '/modbus_ok' => '1'] + $out;
                    } else {
                        $log->warn("[$alias] Modbus (Wechselrichter $id): {$mb->error}", "modbus-$alias-$id-" . md5($mb->error));
                        $out['wr/' . Util::name($id) . '/modbus_ok'] = '0';
                        $st['fails'][$key] = ($st['fails'][$key] ?? 0) + 1;
                        if ($st['fails'][$key] >= 3) {         // nicht dauernd gegen einen geschlossenen Port laufen
                            $st['skip'][$key] = time() + 300;
                            $st['fails'][$key] = 0;
                        }
                    }
                } else {
                    $out['wr/' . Util::name($id) . '/modbus_ok'] = '0';
                }
            }
        }
        if (isset($mb)) {
            $mb->close();
        }
    }
    // --- Zaehler, Speicher, OhmPilot
    if ($fro['zaehler'] && $has('Meter', $loc !== 'unknown')) {
        $extra('Zaehler', 'v1/GetMeterRealtimeData.cgi', ['Scope' => 'System'], fn(array $d) => FroniusMapper::meter($d), 'zaehler');
    }
    if ($fro['speicher'] && $has('Storage', isset($out['batterie_w']) || isset($out['batterie_soc']))) {
        $extra('Speicher', 'v1/GetStorageRealtimeData.cgi', ['Scope' => 'System'], fn(array $d) => FroniusMapper::storage($d), 'speicher');
    }
    if ($fro['weitere']) {
        if ($has('Ohmpilot', false)) {
            $extra('OhmPilot', 'v1/GetOhmPilotRealtimeData.cgi', ['Scope' => 'System'], fn(array $d) => FroniusMapper::ohmpilot($d), 'ohmpilot');
        }
        foreach ($ids['SensorCard'] ?? [] as $id) {
            $extra("Sensorkarte $id", 'v1/GetSensorRealtimeData.cgi', ['Scope' => 'Device', 'DeviceId' => $id, 'DataCollection' => 'NowSensorData'],
                fn(array $d) => FroniusMapper::sensor($id, $d), "sensor/$id");
            if ($slow) {
                $extra("Sensorkarte $id Extremwerte", 'v1/GetSensorRealtimeData.cgi', ['Scope' => 'Device', 'DeviceId' => $id, 'DataCollection' => 'MinMaxSensorData'],
                    fn(array $d) => FroniusMapper::sensor($id, $d, true), "sensor_minmax/$id");
            }
        }
        foreach ($ids['StringControl'] ?? [] as $id) {
            $extra("String Control $id", 'v1/GetStringRealtimeData.cgi', ['Scope' => 'Device', 'DeviceId' => $id, 'DataCollection' => 'NowStringControlData'],
                fn(array $d) => FroniusMapper::stringControl($id, $d, 'now'), "string/$id");
            if ($slow) {
                foreach (['Day' => 'tag', 'Year' => 'jahr', 'Total' => 'gesamt'] as $per => $name) {
                    $extra("String Control $id Summe $per", 'v1/GetStringRealtimeData.cgi',
                        ['Scope' => 'Device', 'DeviceId' => $id, 'DataCollection' => 'CurrentSumStringControlData', 'TimePeriod' => $per],
                        fn(array $d) => FroniusMapper::stringControl($id, $d, 'sum', $name), "string_summe_$name/$id");
                }
                $extra("String Control $id Fehler", 'v1/GetStringRealtimeData.cgi', ['Scope' => 'Device', 'DeviceId' => $id, 'DataCollection' => 'LastErrorStringControlData'],
                    fn(array $d) => FroniusMapper::stringControl($id, $d, 'fehler'), "string_fehler/$id");
            }
        }
    }
    // --- Fehler- und Ereignisprotokoll des Tages (Archiv; nicht GEN24). Fronius verlangt 120 s Abstand, wir nehmen 300 s.
    if ($fro['archiv'] && $now - $st['lastArchive'] >= (int)(getenv('FRO_DEV_ARCHIV') ?: 300)) {
        $st['lastArchive'] = $now;
        $day = date('j.n.Y', $now);
        $extra('Archiv', 'v1/GetArchiveData.cgi', "Scope=System&StartDate=$day&EndDate=$day&Channel=InverterErrors&Channel=InverterEvents"
            . '&Channel=Voltage_DC_String_1&Channel=Current_DC_String_1&Channel=Voltage_DC_String_2&Channel=Current_DC_String_2',
            fn(array $d) => FroniusMapper::archive($d, time()), 'archiv');
    }

    foreach ($out as $k => $val) {
        $mqtt->publish(T . "/$alias/$k", $val);
    }
    // Rohwerte nur bei Aenderung (und alle 5 Minuten zur Auffrischung)
    foreach ($raw as $k => $val) {
        if (!isset($st['rawSeen'][$k]) || $st['rawSeen'][$k][0] !== $val || $now - $st['rawSeen'][$k][1] >= 300) {
            $st['rawSeen'][$k] = [$val, $now];
            $mqtt->publish(T . "/$alias/$k", $val);
        }
    }
    $mqtt->publish(T . "/$alias/verbunden", '1');
    $log->info("[$alias] erreichbar, " . count($out) . ' Werte' . ($raw ? ', ' . count($raw) . ' Rohwerte' : ''), "up-$alias");
    return $st['last'] = ['ok' => true, 'error' => '', 'werte' => count($out), 'schlaeft' => false];
}

/** Letzte PV-Leistung vor einem Ausfall: bleibt in /dev/shm erhalten, auch wenn der Dienst neu startet. */
function lastPvLoad(string $alias): ?float
{
    $j = Util::jsonLoad($GLOBALS['paths']->run . '/lastpv.json');
    return isset($j[$alias]) && is_numeric($j[$alias]) ? (float)$j[$alias] : null;
}

function lastPvSave(string $alias, float $pv): void
{
    $f = $GLOBALS['paths']->run . '/lastpv.json';
    $j = Util::jsonLoad($f);
    $j[$alias] = $pv;
    Util::jsonSave($f, $j);
}

// ------------------------------------------------------------------ Hauptschleife
$fro = FroniusConfig::load($paths->config);
$cfgMtime = -1;
$mqtt = null;
$mqttErr = '';
$mqttBackoff = 2;
$mqttNext = 0;
$mqttSince = 0;
$zaehler = 0;
$nextPoll = 0;
$lastPoll = 0;
$lastStatus = 0;
$ok = 0;
$results = [];
$started = time();
$log->info('Fronius-Dienst ' . AppVersion::CURRENT . ' gestartet (PID ' . getmypid() . ')');

while ($running) {
    try {
        $now = time();
        if ($now >= $logLevelCheck) {      // Loglevel-Aenderung der Pluginverwaltung ohne Neustart uebernehmen
            $logLevelCheck = $now + 30;
            applyLogLevel($log);
        }

        // Konfiguration neu laden, wenn sich die Datei geaendert hat
        clearstatcache(true, $paths->config);
        $mt = is_file($paths->config) ? (int)filemtime($paths->config) : 0;
        if ($mt !== $cfgMtime) {
            $cfgMtime = $mt;
            $oldMqtt = $fro['mqtt'];
            $fro = FroniusConfig::load($paths->config);
            if ($oldMqtt != $fro['mqtt'] && $mqtt !== null) {      // Broker geaendert: neu verbinden
                $mqtt->close();
                $mqtt = null;
                $mqttNext = 0;
                $mqttBackoff = 2;
            }
            $log->addSecret((string)$fro['mqtt']['passwort']);
            $nextPoll = 0;
            $log->info('Konfiguration geladen: ' . count(FroniusConfig::geraete($fro)) . ' Geraete, Intervall ' . $fro['intervall'] . ' s');
        }

        // MQTT verbinden (mit wachsendem Abstand bei Fehlern)
        if (($mqtt === null || !$mqtt->connected()) && $now >= $mqttNext) {
            $bd = brokerDetails($log, $fro);
            if ($bd === null) {
                $mqttErr = 'Keine Broker-Daten';
                $mqttNext = $now + 30;
            } else {
                $mqtt = new MqttClient($bd[0], $bd[1], $bd[2], $bd[3], 'fronius_' . $paths->folder, T . '/status/ok');
                $log->addSecret($bd[3]);
                if ($mqtt->connect()) {
                    $log->info("MQTT verbunden ({$bd[0]}:{$bd[1]})");
                    $mqttErr = '';
                    $mqttSince = $now;
                    $mqttBackoff = 2;
                } else {
                    $mqttErr = $mqtt->lastError();
                    $log->error('MQTT: ' . $mqttErr, 'mqtt-connect');
                    $mqttNext = $now + $mqttBackoff;
                    $mqttBackoff = min(60, $mqttBackoff * 2);
                }
            }
        }
        if ($mqtt !== null && $mqtt->connected() && !$mqtt->loop()) {
            $mqttErr = $mqtt->lastError();
            $log->warn('MQTT getrennt: ' . $mqttErr, 'mqtt-lost');
        }

        // Abrufdurchgang
        if ($now >= $nextPoll) {
            $nextPoll = $now + $fro['intervall'];
            $lastPoll = $now;
            $devs = FroniusConfig::geraete($fro);
            $out = ($mqtt !== null && $mqtt->connected()) ? $mqtt : new MqttClient('', 0, '', '', '', '');   // ohne Broker: Abruf trotzdem pruefen
            $results = [];
            foreach ($devs as $dev) {
                $results[$dev['alias']] = pollDevice($dev, $fro, $out, $log);
                if ($mqtt !== null && $mqtt->connected()) {
                    $mqtt->loop();
                }
            }
            $ok = $results && !array_filter($results, fn($r) => !$r['ok']) ? 1 : 0;
            if (!$devs) {
                $log->warn('Kein Geraet konfiguriert', 'no-device');
            }
            $zaehler = ($zaehler + 1) % 1000;
            if ($mqtt !== null && $mqtt->connected()) {
                $mqtt->publish(T . '/status/ok', (string)$ok);
                $mqtt->publish(T . '/status/zaehler', (string)$zaehler);
                $mqtt->publish(T . '/status/ts', (string)time());
            }
            $log->rotateCheck();
        }

        if ($now !== $lastStatus) {      // Status hoechstens einmal pro Sekunde (liegt im RAM, /dev/shm)
            $lastStatus = $now;
            Util::jsonSave($paths->statusFile(), [
                'service' => 'running', 'time' => $now, 'started' => $started,
                'ok' => $ok, 'zaehler' => $zaehler, 'ts' => $lastPoll,
                'mqtt' => ($mqtt !== null && $mqtt->connected()) ? 1 : 0, 'mqtt_error' => $mqttErr, 'mqtt_seit' => $mqttSince,
                'geraete' => $results, 'version' => AppVersion::CURRENT,
            ]);
        }
    } catch (Throwable $e) {
        $log->error('Fehler in der Hauptschleife: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'loop');
    }
    usleep(200000);
}

if ($mqtt !== null && $mqtt->connected()) {
    $mqtt->publish(T . '/status/ok', '0');
    $mqtt->close();
}
$log->info('Fronius-Dienst beendet');
$log->end();
@unlink($paths->pidFile());
