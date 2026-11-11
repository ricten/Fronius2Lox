<?php
/** Konfiguration des Plugins (fronius.json, Rechte 0600). Die Solar API braucht keine Anmeldung, es gibt also keine Zugangsdaten ausser denen eines eigenen MQTT-Brokers. */
final class FroniusConfig
{
    public const MAX_GERAETE = 4;
    /** Ein/Aus-Optionen der Weboberflaeche (alle ausser rohwerte standardmaessig an). */
    public const OPTIONEN = ['wechselrichter', 'zaehler', 'speicher', 'weitere', 'logger', 'archiv', 'rohwerte'];

    public static function defaultGeraet(string $alias = ''): array
    {
        return ['aktiv' => false, 'alias' => $alias, 'host' => '', 'port' => 80];
    }

    public static function defaults(): array
    {
        $g = [self::defaultGeraet('wr1')];
        while (count($g) < self::MAX_GERAETE) {
            $g[] = self::defaultGeraet();
        }
        return [
            'intervall' => 10,           // Sekunden (Fronius verlangt mindestens 4 s zwischen zwei Abfragen)
            'wechselrichter' => true,    // Details je Wechselrichter (Common-, 3P- und MinMax-Daten)
            'zaehler' => true,           // Smart Meter
            'speicher' => true,          // Batteriespeicher
            'weitere' => true,           // OhmPilot, Sensorkarte, String Control (nur wenn der Wechselrichter sie meldet)
            'logger' => true,            // Datenlogger-Infos, LEDs, Geraeteliste
            'archiv' => true,            // Fehler- und Ereignisprotokoll des Tages (GetArchiveData, nicht GEN24)
            'rohwerte' => false,         // alle Felder der Antworten zusaetzlich unter fronius/<name>/raw/...
            // Der Wechselrichter schaltet nachts ab (der Datamanager haengt an ihm): Nichterreichbarkeit nach geringer PV-Leistung ist dann normal
            'nacht' => ['aktiv' => true, 'schwelle_w' => 100, 'breite' => '', 'laenge' => ''],
            // Modbus TCP (nur lesen) fuer die Aufteilung auf die MPP-Tracker; im Datamanager muss Modbus TCP eingeschaltet sein
            'modbus' => ['aktiv' => false, 'port' => 502],
            'mqtt' => ['eigener_broker' => false, 'host' => '', 'port' => 1883, 'benutzer' => '', 'passwort' => ''],
            'geraete' => $g,
        ];
    }

    public static function load(string $file): array
    {
        $def = self::defaults();
        $c = Util::jsonLoad($file);
        $out = array_merge($def, $c);
        $g = [];
        foreach (is_array($c['geraete'] ?? null) ? $c['geraete'] : $def['geraete'] as $x) {
            $g[] = array_merge(self::defaultGeraet(), is_array($x) ? $x : []);
        }
        while (count($g) < self::MAX_GERAETE) {
            $g[] = self::defaultGeraet();
        }
        $out['geraete'] = array_slice($g, 0, self::MAX_GERAETE);
        $out['intervall'] = max(getenv('FRO_DEV_DIR') ? 1 : 5, min(300, (int)$out['intervall']));   // Fronius: mind. 4 s zwischen Abfragen
        foreach (self::OPTIONEN as $k) {
            $out[$k] = (bool)$out[$k];
        }
        $out['nacht'] = self::cleanNacht(array_merge($def['nacht'], is_array($c['nacht'] ?? null) ? $c['nacht'] : []));
        $out['modbus'] = self::cleanModbus(is_array($c['modbus'] ?? null) ? $c['modbus'] : []);
        $out['mqtt'] = array_merge($def['mqtt'], is_array($c['mqtt'] ?? null) ? $c['mqtt'] : []);
        return $out;
    }

    /** Nachtmodus pruefen: Schwelle 0-100000 W, Koordinaten nur gueltig oder leer. */
    public static function cleanNacht(array $n): array
    {
        $coord = fn($v, float $max) => (is_numeric(str_replace(',', '.', trim((string)$v))) && abs((float)str_replace(',', '.', trim((string)$v))) <= $max)
            ? rtrim(rtrim(sprintf('%.4F', (float)str_replace(',', '.', trim((string)$v))), '0'), '.') : '';
        return [
            'aktiv' => (bool)$n['aktiv'],
            'schwelle_w' => max(0, min(100000, (int)$n['schwelle_w'])),
            'breite' => $coord($n['breite'], 90.0),
            'laenge' => $coord($n['laenge'], 180.0),
        ];
    }

    /** Modbus-Einstellung pruefen: Port 1-65535 (Standard 502). */
    public static function cleanModbus(array $m): array
    {
        $port = (int)($m['port'] ?? 502);
        return ['aktiv' => !empty($m['aktiv']), 'port' => $port > 0 ? min(65535, $port) : 502];
    }

    /** Host: Name oder IP, ohne Schema und Pfad. */
    public static function cleanHost(string $h): string
    {
        $h = trim($h);
        $h = (string)preg_replace('#^[a-z]+://#i', '', $h);
        $h = explode('/', $h, 2)[0];
        return (string)preg_replace('/[^A-Za-z0-9_.-]/', '', $h);
    }

    /** Eingaben der Weboberflaeche pruefen und mit der bisherigen Konfiguration zusammenfuehren. */
    public static function fromPost(array $old, array $post): array
    {
        $new = $old;
        // Jeder Reiter der Oberflaeche sendet nur seine Abschnitte; fehlende bleiben unveraendert.
        if (isset($post['intervall'])) {
            $new['intervall'] = max(5, min(300, (int)$post['intervall']));   // Fronius: mind. 4 s zwischen Abfragen
            foreach (self::OPTIONEN as $k) {
                $new[$k] = !empty($post[$k]);
            }
            $new['nacht'] = self::cleanNacht([
                'aktiv' => !empty($post['nacht_aktiv']),
                'schwelle_w' => $post['nacht_schwelle'] ?? $old['nacht']['schwelle_w'],
                'breite' => $post['nacht_breite'] ?? '',
                'laenge' => $post['nacht_laenge'] ?? '',
            ]);
        }
        if (isset($post['intervall'])) {
            $new['modbus'] = self::cleanModbus(['aktiv' => !empty($post['modbus_aktiv']), 'port' => $post['modbus_port'] ?? 502]);
        }
        if (is_array($post['geraet'] ?? null)) {
            for ($i = 0; $i < self::MAX_GERAETE; $i++) {
                $p = is_array($post['geraet'][$i] ?? null) ? $post['geraet'][$i] : [];
                $port = (int)($p['port'] ?? 80);
                $new['geraete'][$i] = [
                    'aktiv' => !empty($p['aktiv']),
                    'alias' => Util::name((string)($p['alias'] ?? '')),
                    'host' => self::cleanHost((string)($p['host'] ?? '')),
                    'port' => $port > 0 ? min(65535, $port) : 80,     // leeres Feld = Standardport
                ];
            }
        }
        if (is_array($post['mqtt'] ?? null)) {
            $m = $post['mqtt'];
            $mpw = (string)($m['passwort'] ?? '');
            $new['mqtt'] = [
                'eigener_broker' => !empty($m['eigener_broker']),
                'host' => (string)preg_replace('/[^A-Za-z0-9_.:-]/', '', trim((string)($m['host'] ?? ''))),
                'port' => max(1, min(65535, (int)($m['port'] ?? 1883))),
                'benutzer' => trim((string)($m['benutzer'] ?? '')),
                'passwort' => $mpw !== '' ? $mpw : (string)$old['mqtt']['passwort'],
            ];
        }
        return $new;
    }

    /**
     * Abzufragende Geraete (aktiv, mit Host). Der Name im Topic ist der Alias, sonst wr<Nummer>; doppelte Namen bekommen eine Nummer.
     * @return list<array{alias:string,host:string,port:int}>
     */
    public static function geraete(array $cfg): array
    {
        $out = [];
        $used = [];
        foreach ($cfg['geraete'] as $i => $g) {
            if (empty($g['aktiv']) || trim((string)$g['host']) === '') {
                continue;
            }
            $alias = Util::name((string)$g['alias']);
            $alias = $alias !== '' ? $alias : 'wr' . ($i + 1);
            $base = $alias;
            for ($n = 2; isset($used[$alias]); $n++) {
                $alias = $base . '_' . $n;
            }
            $used[$alias] = true;
            $port = (int)$g['port'];
            $out[] = ['alias' => $alias, 'host' => trim((string)$g['host']), 'port' => $port > 0 ? $port : 80];
        }
        return $out;
    }

    /**
     * Eigener MQTT-Broker aus der Konfiguration (statt dem LoxBerry-Broker).
     * @return array{0:string,1:int,2:string,3:string}|null  [host, port, user, pass]; null = LoxBerry-Broker verwenden
     */
    public static function ownBroker(array $cfg): ?array
    {
        $m = $cfg['mqtt'];
        if (empty($m['eigener_broker']) || trim((string)$m['host']) === '') {
            return null;
        }
        return [trim((string)$m['host']), (int)$m['port'] > 0 ? (int)$m['port'] : 1883, (string)$m['benutzer'], (string)$m['passwort']];
    }
}
