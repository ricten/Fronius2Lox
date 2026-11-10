<?php
/**
 * Wandelt die Antworten der Fronius Solar API in Topics um (relativ zu fronius/<name>/).
 * Grundlage: "Fronius Solar API V1 Operating Instructions" 42,0410,2012 (Dokumentversion 21).
 *
 * Vorzeichen wie bei den anderen Plugins (Senec2Lox):
 *   netz_w       + Bezug / - Einspeisung   (wie Fronius P_Grid)
 *   batterie_w   + Laden / - Entladen      (Fronius P_Akku ist umgekehrt: + Entladen, deshalb negiert)
 *   haus_w       immer positiv             (Fronius P_Load ist negativ, deshalb negiert)
 * Werte ohne gueltigen Inhalt (null, z. B. Wechselrichter schlaeft) werden nicht gesendet; nur pv_w wird dann 0.
 * Unsinnswerte (Betrag > 1e13, die Doku zeigt 8.4e252 bei einem GEN24 Primo) werden verworfen.
 */
final class FroniusMapper
{
    /** Zahl als Text mit Punkt, ohne Einheit und ohne ueberfluessige Nullen; null bei Nicht-Zahl. */
    public static function num(mixed $v): ?string
    {
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) {
            return null;
        }
        $f = (float)$v;
        if (!is_finite($f) || abs($f) > 1e13) {
            return null;
        }
        $s = rtrim(rtrim(sprintf('%.3F', $f), '0'), '.');
        return ($s === '' || $s === '-0') ? '0' : $s;
    }

    private static function f(mixed $v): ?float
    {
        if (!(is_int($v) || is_float($v) || (is_string($v) && is_numeric($v)))) {
            return null;
        }
        $f = (float)$v;
        return (is_finite($f) && abs($f) <= 1e13) ? $f : null;
    }

    /** Text bereinigen (eine Zeile, begrenzt). */
    private static function text(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $s = trim((string)preg_replace('/\s+/', ' ', $v));
        return $s === '' ? null : substr($s, 0, 200);
    }

    private static function put(array &$o, string $k, ?string $v): void
    {
        if ($v !== null) {
            $o[$k] = $v;
        }
    }

    /** Zahl (oder 0/1 bei bool) unter $k ablegen, wenn $v eine ist. */
    private static function putNum(array &$o, string $k, mixed $v): void
    {
        self::put($o, $k, self::num(is_array($v) ? self::dv($v) : $v));
    }

    /** Wert aus {"Unit":"W","Value":123} (Geraete-Scope) bzw. {"Unit":..,"Values":{"1":123}}; kW/kWh werden zu W/Wh. */
    private static function dv(mixed $x): ?float
    {
        if (!is_array($x)) {
            return self::f($x);
        }
        $v = $x['Value'] ?? (is_array($x['Values'] ?? null) ? reset($x['Values']) : null);
        $f = self::f($v);
        if ($f === null) {
            return null;
        }
        return in_array($x['Unit'] ?? '', ['kW', 'kWh'], true) ? $f * 1000 : $f;
    }

    /** Ziffern-Kennung (Geraete-/Kanalnummer) oder null. */
    private static function id(mixed $k): ?string
    {
        $s = (string)$k;
        return preg_match('/^\d{1,10}$/', $s) ? $s : null;     // Komponenten-IDs (z. B. OhmPilot 720897) haben mehr Stellen
    }

    /** Tabelle abarbeiten: Quellfeld => Topic-Name. */
    private static function table(array &$o, string $prefix, array $src, array $table): void
    {
        foreach ($table as $field => $name) {
            if (array_key_exists($field, $src)) {
                self::putNum($o, $prefix . $name, $src[$field]);
            }
        }
    }

    /** Kanal-Details (Hersteller, Modell, Seriennummer ...) als Text-Topics. */
    private static function details(array &$o, string $prefix, mixed $det, array $names = ['Manufacturer' => 'hersteller', 'Model' => 'modell', 'Serial' => 'seriennummer']): void
    {
        if (!is_array($det)) {
            return;
        }
        foreach ($names as $field => $name) {
            self::put($o, $prefix . 'info/' . $name, self::text($det[$field] ?? null));
        }
    }

    // ------------------------------------------------------------------ Texte nach der Doku

    /** Statuscode des Wechselrichters (Doku 4.6.5: 0-6 Startup, 7 Running, 8 Standby, 9 Bootloading, 10 Error, 11 Idle, 12 Ready, 13 Sleeping, 255 Unknown). */
    public static function statusText(int $code): string
    {
        return match (true) {
            $code === 7 => 'Betrieb',
            $code === 8 => 'Standby',
            $code === 9 => 'Bootloader',
            $code === 10 => 'Fehler',
            $code === 11 => 'Leerlauf',
            $code === 12 => 'Bereit',
            $code === 13 => 'Schlaeft',
            $code >= 0 && $code <= 6 => 'Startet',
            default => 'Unbekannt',
        };
    }

    /**
     * Einbauort eines Zaehlers (Doku 4.8.5/4.8.6: 0 Netzuebergabe, 1 Verbrauchspfad, 3 Fremderzeuger, 256-511 Unterverbraucher).
     * Die Tabelle 6.5 der Doku nennt 0 und 1 umgekehrt; massgeblich ist hier die ausfuehrliche Beschreibung in 4.8.
     */
    public static function meterLocationText(int $loc): string
    {
        return match (true) {
            $loc === 0 => 'Netzuebergabe',
            $loc === 1 => 'Verbrauchspfad',
            $loc === 3 => 'Fremderzeuger',
            $loc === 4 => 'Fremderzeuger mit Batterie',
            $loc >= 256 && $loc <= 511 => 'Unterverbraucher',
            default => 'Unbekannt',
        };
    }

    /** OhmPilot CodeOfState (Doku 4.10.5). */
    public static function ohmpilotStateText(int $code): string
    {
        return [0 => 'Betrieb', 1 => 'Mindesttemperatur halten', 2 => 'Legionellenschutz', 3 => 'Kritischer Fehler', 4 => 'Fehler', 5 => 'Boost'][$code] ?? 'Unbekannt';
    }

    /** Status_BatteryCell des Speicher-Controllers je Hersteller (Doku 4.9.8); sonst null. */
    public static function batteryStatusText(string $manufacturer, int $code): ?string
    {
        $m = strtolower($manufacturer);
        if (str_contains($m, 'byd')) {
            return [0 => 'Standby', 1 => 'Inaktiv', 2 => 'Darkstart', 3 => 'Aktiv', 4 => 'Fehler', 5 => 'Update'][$code] ?? null;
        }
        if (str_contains($m, 'lg')) {
            return [1 => 'Standby', 3 => 'Aktiviert', 5 => 'Fehler', 10 => 'Schlaf'][$code] ?? null;
        }
        return null;
    }

    /** Zellstatus eines Fronius-Solar-Battery-Moduls: hohes Nibble = aktuell, niedriges = vorher (Doku 4.9.8). */
    public static function cellStateText(int $nibble): string
    {
        return [0 => 'Reserviert', 1 => 'Vorladen', 2 => 'Initial', 3 => 'Normales Laden', 4 => 'Ladeende', 5 => 'Normales Entladen',
            6 => 'Ueberspannung', 7 => 'Tiefentladung', 8 => 'Reserviert', 9 => 'Uebertemperatur Laden', 10 => 'Ueberstrom Laden',
            11 => 'Uebertemperatur Entladen', 12 => 'Ueberstrom Entladen', 13 => 'Zellen unsymmetrisch', 14 => 'Laden pausiert', 15 => 'Reserviert'][$nibble] ?? 'Unbekannt';
    }

    /** Typ des Geraets aus der Typliste der Doku (6.2). */
    private static function typ(array &$o, string $prefix, mixed $dt): void
    {
        $n = self::f($dt);
        if ($n !== null && $n >= 0) {
            $o[$prefix . 'typ'] = self::num($n);
            $name = FroniusDevices::name((int)$n);
            if ($name !== '') {
                $o[$prefix . 'typ_name'] = $name;
            }
        }
    }

    // ------------------------------------------------------------------ Energiefluss

    /** GetPowerFlowRealtimeData: Site, Inverters, Smartloads, SecondaryMeters. @return array<string,string> */
    public static function powerflow(array $d): array
    {
        $o = [];
        $s = is_array($d['Site'] ?? null) ? $d['Site'] : [];
        // PV: nachts meldet der Wechselrichter null -> 0 (sonst bliebe in Loxone der letzte Tageswert stehen)
        self::put($o, 'pv_w', self::num(self::f($s['P_PV'] ?? null) ?? ($s ? 0 : null)));
        $load = self::f($s['P_Load'] ?? null);
        self::put($o, 'haus_w', $load !== null ? self::num(abs($load)) : null);
        $grid = self::f($s['P_Grid'] ?? null);
        if ($grid !== null) {
            $o['netz_w'] = self::num($grid);
            $o['netz_bezug_w'] = self::num(max(0.0, $grid));
            $o['netz_einspeisung_w'] = self::num(max(0.0, -$grid));
        }
        $akku = self::f($s['P_Akku'] ?? null);
        if ($akku !== null) {
            $o['batterie_w'] = self::num(-$akku);
            $o['batterie_laden_w'] = self::num(max(0.0, -$akku));
            $o['batterie_entladen_w'] = self::num(max(0.0, $akku));
        }
        self::table($o, '', $s, ['E_Day' => 'energie_tag_wh', 'E_Year' => 'energie_jahr_wh', 'E_Total' => 'energie_gesamt_wh',
            'rel_Autonomy' => 'autarkie', 'rel_SelfConsumption' => 'eigenverbrauch']);
        self::put($o, 'modus', self::text($s['Mode'] ?? null));
        self::put($o, 'zaehler_standort', self::text($s['Meter_Location'] ?? null));
        foreach (['BatteryStandby' => 'batterie_standby', 'BackupMode' => 'notstrom'] as $f => $name) {
            if (isset($s[$f]) && is_bool($s[$f])) {
                $o[$name] = $s[$f] ? '1' : '0';
            }
        }
        self::put($o, 'info/powerflow_version', self::text((string)($d['Version'] ?? '')) );
        // Wechselrichter: Leistung, Energie, Ladestand, Batteriemodus
        foreach (is_array($d['Inverters'] ?? null) ? $d['Inverters'] : [] as $key => $inv) {
            $id = self::id($key);
            if ($id === null || !is_array($inv)) {
                continue;
            }
            $p = "wr/$id/";
            self::table($o, $p, $inv, ['P' => 'leistung_ac_w', 'E_Day' => 'energie_tag_wh', 'E_Year' => 'energie_jahr_wh', 'E_Total' => 'energie_gesamt_wh', 'CID' => 'komponenten_id']);
            self::typ($o, $p . 'info/', $inv['DT'] ?? null);
            $soc = self::f($inv['SOC'] ?? null);
            if ($soc !== null && !self::batteryAbsent($inv, $s)) {
                $o['batterie_soc'] ??= self::num($soc);
                $o[$p . 'batterie_soc'] = self::num($soc);
                self::put($o, $p . 'batterie_modus', self::text($inv['Battery_Mode'] ?? null));
            }
        }
        // Smartloads: OhmPilot und OhmPilot Eco
        foreach (is_array($d['Smartloads']['Ohmpilots'] ?? null) ? $d['Smartloads']['Ohmpilots'] : [] as $cid => $x) {
            if (is_array($x) && ($id = self::id($cid)) !== null) {
                self::table($o, "ohmpilot/$id/", $x, ['P_AC_Total' => 'leistung_w', 'Temperature' => 'temperatur_c']);
                self::put($o, "ohmpilot/$id/status_text", self::text($x['State'] ?? null));
            }
        }
        foreach (is_array($d['Smartloads']['OhmpilotEcos'] ?? null) ? $d['Smartloads']['OhmpilotEcos'] : [] as $cid => $x) {
            if (is_array($x) && ($id = self::id($cid)) !== null) {
                self::table($o, "ohmpilot_eco/$id/", $x, ['P_AC_Total' => 'leistung_w', 'Temperature_1' => 'temperatur_1_c', 'Temperature_2' => 'temperatur_2_c']);
                self::put($o, "ohmpilot_eco/$id/status_heizstab1", self::text($x['State_HR1'] ?? null));
                self::put($o, "ohmpilot_eco/$id/status_heizstab2", self::text($x['State_HR2'] ?? null));
            }
        }
        // Zusatzzaehler (Unterverbraucher, Fremderzeuger)
        foreach (is_array($d['SecondaryMeters'] ?? null) ? $d['SecondaryMeters'] : [] as $cid => $x) {
            if (is_array($x) && ($id = self::id($cid)) !== null) {
                $p = "zusatzzaehler/$id/";
                self::putNum($o, $p . 'leistung_w', $x['P'] ?? null);
                $loc = self::f($x['MLoc'] ?? null);
                if ($loc !== null) {
                    $o[$p . 'standort_code'] = self::num($loc);
                    $o[$p . 'standort_text'] = self::meterLocationText((int)$loc);
                }
                self::put($o, $p . 'name', self::text($x['Label'] ?? null));
                self::put($o, $p . 'kategorie', self::text($x['Category'] ?? null));
            }
        }
        return $o;
    }

    /** GEN24 ohne Batterie meldet bis 1.17.x faelschlich SOC 0 und Battery_Mode "disabled" (Doku 4.11.5). */
    private static function batteryAbsent(array $inv, array $site): bool
    {
        return ($inv['Battery_Mode'] ?? '') === 'disabled' && ($site['P_Akku'] ?? null) === null;
    }

    /** Nummern der Wechselrichter aus der Energiefluss-Antwort. @return list<string> */
    public static function inverterIds(array $pf): array
    {
        $ids = [];
        foreach (is_array($pf['Inverters'] ?? null) ? array_keys($pf['Inverters']) : [] as $id) {
            if (($i = self::id($id)) !== null) {
                $ids[] = $i;
            }
        }
        return array_slice($ids, 0, 8);
    }

    // ------------------------------------------------------------------ Wechselrichter

    /** Cumulation-/CommonInverterData eines Wechselrichters (Geraete-Scope). @return array<string,string> */
    public static function inverter(string $id, array $d): array
    {
        $p = 'wr/' . Util::name($id) . '/';
        $o = [];
        $t = ['PAC' => 'leistung_ac_w', 'SAC' => 'scheinleistung_va', 'UAC' => 'spannung_ac_v', 'IAC' => 'strom_ac_a', 'FAC' => 'frequenz_hz',
            'UDC' => 'spannung_dc_v', 'IDC' => 'strom_dc_a', 'DAY_ENERGY' => 'energie_tag_wh',
            'YEAR_ENERGY' => 'energie_jahr_wh', 'TOTAL_ENERGY' => 'energie_gesamt_wh'];
        for ($i = 2; $i <= 4; $i++) {          // weitere MPP-Tracker (nur GEN24/Tauro/Verto)
            $t["UDC_$i"] = "spannung_dc_{$i}_v";
            $t["IDC_$i"] = "strom_dc_{$i}_a";
        }
        self::table($o, $p, $d, $t);
        // DC-Leistung je MPP-Tracker: Fronius liefert nur Spannung und Strom, die Leistung wird daraus berechnet (U x I).
        // Tracker 2 bis 4 meldet laut Doku nur GEN24/Tauro/Verto; was das Geraet nicht liefert, fehlt auch hier.
        $summe = 0.0;
        $anzahl = 0;
        for ($i = 1; $i <= 4; $i++) {
            $sfx = $i === 1 ? '' : "_$i";
            $u = array_key_exists("UDC$sfx", $d) ? self::dv($d["UDC$sfx"]) : null;
            $a = array_key_exists("IDC$sfx", $d) ? self::dv($d["IDC$sfx"]) : null;
            if ($u === null || $a === null) {
                continue;
            }
            self::put($o, $p . ($i === 1 ? 'leistung_dc_w' : "leistung_dc_{$i}_w"), self::num(round($u * $a, 1)));
            $summe += $u * $a;
            $anzahl++;
        }
        if ($anzahl >= 2) {
            self::put($o, $p . 'leistung_dc_summe_w', self::num(round($summe, 1)));
        }
        $st = is_array($d['DeviceStatus'] ?? null) ? $d['DeviceStatus'] : [];
        $code = self::f($st['StatusCode'] ?? null);
        if ($code !== null) {
            $o[$p . 'status_code'] = self::num($code);
            $o[$p . 'status_text'] = self::statusText((int)$code);
        }
        self::put($o, $p . 'zustand', self::text($st['InverterState'] ?? null));      // GEN24: "Running" ...
        self::table($o, $p, $st, ['ErrorCode' => 'fehler_code', 'LEDColor' => 'led_farbe_code', 'LEDState' => 'led_zustand_code',
            'MgmtTimerRemainingTime' => 'timer_restzeit_s', 'StateToReset' => 'fehler_quittierbar']);
        return $o;
    }

    /**
     * DC-Werte je MPP-Tracker aus Modbus (SunSpec-Modell 160). Gleiche Topic-Namen wie bei der Solar API (Tracker 1 ohne Nummer,
     * Tracker 2 bis 4 mit Nummer); sie ersetzen die Werte der Solar API, weil diese beim Symo nur einen Tracker kennt.
     * @param list<array{name:string,spannung_v:?float,strom_a:?float,leistung_w:?float}> $trackers
     * @return array<string,string>
     */
    public static function mppt(string $id, array $trackers): array
    {
        $p = 'wr/' . Util::name($id) . '/';
        $o = [$p . 'mppt_anzahl' => (string)count($trackers)];
        $summe = 0.0;
        $hat = 0;
        foreach (array_values($trackers) as $i => $t) {
            $k = $i + 1;
            $sfx = $k === 1 ? '' : "_$k";
            self::put($o, $p . "spannung_dc{$sfx}_v", self::num($t['spannung_v'] === null ? null : round($t['spannung_v'], 1)));
            self::put($o, $p . "strom_dc{$sfx}_a", self::num($t['strom_a'] === null ? null : round($t['strom_a'], 2)));
            $w = $t['leistung_w'] ?? (($t['spannung_v'] !== null && $t['strom_a'] !== null) ? $t['spannung_v'] * $t['strom_a'] : null);
            if ($w !== null) {
                self::put($o, $p . "leistung_dc{$sfx}_w", self::num(round($w, 1)));
                $summe += $w;
                $hat++;
            }
            if (($t['name'] ?? '') !== '') {
                self::put($o, $p . "mppt_{$k}_name", self::text($t['name']));
            }
        }
        if ($hat >= 2) {
            self::put($o, $p . 'leistung_dc_summe_w', self::num(round($summe, 1)));
        }
        return $o;
    }

    /** 3PInverterData (nur dreiphasige Wechselrichter). @return array<string,string> */
    public static function inverter3p(string $id, array $d): array
    {
        $p = 'wr/' . Util::name($id) . '/';
        $o = [];
        $t = ['T_AMBIENT' => 'temperatur_umgebung_c', 'ROTATION_SPEED_FAN_FL' => 'luefter_vorne_links', 'ROTATION_SPEED_FAN_FR' => 'luefter_vorne_rechts',
            'ROTATION_SPEED_FAN_BL' => 'luefter_hinten_links', 'ROTATION_SPEED_FAN_BR' => 'luefter_hinten_rechts'];
        for ($i = 1; $i <= 3; $i++) {
            $t["IAC_L$i"] = "strom_l{$i}_a";
            $t["UAC_L$i"] = "spannung_l{$i}_v";
        }
        self::table($o, $p, $d, $t);
        return $o;
    }

    /**
     * Spannung, Strom und Leistung von String 1 und 2 aus den Archivkanaelen Voltage_DC_String_<k> und Current_DC_String_<k>
     * (GetArchiveData; Symo/Primo/Hybrid, nicht GEN24). Das sind Werte des Datenloggers im Aufzeichnungsintervall (5 bis 30 Minuten),
     * keine Echtzeitwerte; deshalb eigene Topic-Namen string_<k>_*. Die Leistung wird aus Spannung x Strom berechnet.
     * Werte, die aelter als 45 Minuten sind, werden nicht gesendet.
     */
    private static function archiveStrings(array &$o, string $p, array $ch, int $start, ?int $now): void
    {
        for ($k = 1; $k <= 2; $k++) {
            $u = is_array($ch["Voltage_DC_String_$k"]['Values'] ?? null) ? $ch["Voltage_DC_String_$k"]['Values'] : [];
            $i = is_array($ch["Current_DC_String_$k"]['Values'] ?? null) ? $ch["Current_DC_String_$k"]['Values'] : [];
            $best = null;
            foreach ($u as $off => $uv) {
                $off = (string)$off;
                if (!ctype_digit($off) || !array_key_exists($off, $i)) {
                    continue;
                }
                $uf = self::f($uv);
                $if = self::f($i[$off]);
                if ($uf !== null && $if !== null && ($best === null || (int)$off > $best[0])) {
                    $best = [(int)$off, $uf, $if];
                }
            }
            if ($best === null || ($now !== null && $start > 0 && $now - ($start + $best[0]) > 2700)) {
                continue;
            }
            $o[$p . "string_{$k}_spannung_v"] = self::num(round($best[1], 1)) ?? '0';
            $o[$p . "string_{$k}_strom_a"] = self::num(round($best[2], 2)) ?? '0';
            $o[$p . "string_{$k}_leistung_w"] = self::num(round($best[1] * $best[2], 1)) ?? '0';
            if ($start > 0) {
                $o[$p . "string_{$k}_zeit"] = (string)($start + $best[0]);
            }
        }
    }

    /** MinMaxInverterData: Tages-, Jahres- und Gesamtextremwerte. @return array<string,string> */
    public static function inverterMinMax(string $id, array $d): array
    {
        $p = 'wr/' . Util::name($id) . '/';
        $o = [];
        $t = [];
        foreach (['DAY' => 'tag', 'YEAR' => 'jahr', 'TOTAL' => 'gesamt'] as $per => $name) {
            $t["{$per}_PMAX"] = "{$name}_leistung_max_w";
            $t["{$per}_UACMAX"] = "{$name}_spannung_ac_max_v";
            $t["{$per}_UACMIN"] = "{$name}_spannung_ac_min_v";
            $t["{$per}_UDCMAX"] = "{$name}_spannung_dc_max_v";
        }
        self::table($o, $p, $d, $t);
        return $o;
    }

    /** Stammdaten und Status aller Wechselrichter (GetInverterInfo). @return array<string,string> */
    public static function info(array $inverterInfo): array
    {
        $o = [];
        foreach ($inverterInfo as $key => $i) {
            if (!is_array($i) || ($id = self::id($key)) === null) {
                continue;
            }
            $p = "wr/$id/info/";
            // Datamanager und Symo Hybrid liefern den Namen HTML-kodiert, GEN24 als UTF-8
            $name = isset($i['CustomName']) && is_string($i['CustomName']) ? html_entity_decode($i['CustomName'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
            self::put($o, $p . 'name', self::text($name));
            self::typ($o, $p, $i['DT'] ?? null);
            self::put($o, $p . 'seriennummer', self::text(isset($i['UniqueID']) ? (string)$i['UniqueID'] : null));
            self::table($o, $p, $i, ['PVPower' => 'pv_nennleistung_wp', 'Show' => 'sichtbar']);
            $ec = self::f($i['ErrorCode'] ?? null);
            if ($ec !== null && $ec >= 0) {          // -1 = kein gueltiger Fehlercode
                $o[$p . 'fehler_code'] = self::num($ec);
            }
            $sc = $i['StatusCode'] ?? null;
            if (self::f($sc) !== null) {
                $o[$p . 'status_code'] = self::num($sc);
                $o[$p . 'status_text'] = self::statusText((int)$sc);
            } else {
                self::put($o, $p . 'status_text', self::text($sc));      // GEN24 aelter als 1.13.5: Text statt Zahl
            }
            self::put($o, $p . 'zustand', self::text($i['InverterState'] ?? null));
        }
        return $o;
    }

    // ------------------------------------------------------------------ Zaehler, Speicher, OhmPilot

    /** Smart Meter (GetMeterRealtimeData, Scope=System). @return array<string,string> */
    public static function meter(array $d): array
    {
        $o = [];
        $map = ['PowerReal_P_Sum' => 'leistung_w', 'PowerApparent_S_Sum' => 'scheinleistung_va', 'PowerReactive_Q_Sum' => 'blindleistung_var',
            'PowerFactor_Sum' => 'leistungsfaktor', 'Frequency_Phase_Average' => 'frequenz_hz', 'Current_AC_Sum' => 'strom_summe_a',
            'Voltage_AC_Phase_Average' => 'spannung_mittel_v',
            'EnergyReal_WAC_Sum_Consumed' => 'energie_bezug_wh', 'EnergyReal_WAC_Sum_Produced' => 'energie_einspeisung_wh',
            'EnergyReal_WAC_Plus_Absolute' => 'energie_plus_absolut_wh', 'EnergyReal_WAC_Minus_Absolute' => 'energie_minus_absolut_wh',
            'EnergyReactive_VArAC_Sum_Consumed' => 'blindenergie_verbraucht_varh', 'EnergyReactive_VArAC_Sum_Produced' => 'blindenergie_erzeugt_varh',
            'EnergyReactive_VArAC_Phase_1_Consumed' => 'blindenergie_l1_verbraucht_varh', 'EnergyReactive_VArAC_Phase_1_Produced' => 'blindenergie_l1_erzeugt_varh',
            'Voltage_AC_PhaseToPhase_12' => 'spannung_l1_l2_v', 'Voltage_AC_PhaseToPhase_23' => 'spannung_l2_l3_v', 'Voltage_AC_PhaseToPhase_31' => 'spannung_l3_l1_v',
            'TimeStamp' => 'zeitstempel'];
        for ($ph = 1; $ph <= 3; $ph++) {
            $map["PowerReal_P_Phase_$ph"] = "leistung_l{$ph}_w";
            $map["PowerApparent_S_Phase_$ph"] = "scheinleistung_l{$ph}_va";
            $map["PowerReactive_Q_Phase_$ph"] = "blindleistung_l{$ph}_var";
            $map["PowerFactor_Phase_$ph"] = "leistungsfaktor_l$ph";
            $map["Voltage_AC_Phase_$ph"] = "spannung_l{$ph}_v";
            $map["Current_AC_Phase_$ph"] = "strom_l{$ph}_a";
            $map["EnergyReal_WAC_Phase_{$ph}_Consumed"] = "energie_l{$ph}_verbraucht_wh";
            $map["EnergyReal_WAC_Phase_{$ph}_Produced"] = "energie_l{$ph}_erzeugt_wh";
        }
        foreach ($d as $key => $m) {
            if (!is_array($m) || ($id = self::id($key)) === null) {
                continue;
            }
            $p = "zaehler/$id/";
            self::details($o, $p, $m['Details'] ?? null);
            self::table($o, $p, $m, ['Enable' => 'aktiv', 'Visible' => 'sichtbar']);
            if (self::f($m['Visible'] ?? 1) === 0.0) {
                continue;               // Doku: Visible 0 = unvollstaendige oder veraltete Werte -> nicht senden
            }
            self::table($o, $p, $m, $map);
            $loc = self::f($m['Meter_Location_Current'] ?? null);
            if ($loc !== null) {
                $o[$p . 'standort_code'] = self::num($loc);
                $o[$p . 'standort_text'] = self::meterLocationText((int)$loc);
            }
        }
        return $o;
    }

    /** Batteriespeicher (GetStorageRealtimeData, Scope=System): Controller und Module. @return array<string,string> */
    public static function storage(array $d): array
    {
        $o = [];
        $ctl = ['StateOfCharge_Relative' => 'soc', 'Voltage_DC' => 'spannung_v', 'Current_DC' => 'strom_a', 'Temperature_Cell' => 'temperatur_c',
            'Capacity_Maximum' => 'kapazitaet_wh', 'DesignedCapacity' => 'nennkapazitaet_wh', 'Enable' => 'aktiv', 'TimeStamp' => 'zeitstempel',
            'Voltage_DC_Maximum_Cell' => 'spannung_zelle_max_v', 'Voltage_DC_Minimum_Cell' => 'spannung_zelle_min_v',
            'CycleCount_BatteryCell' => 'zyklen', 'Temperature_Cell_Maximum' => 'temperatur_max_c', 'Temperature_Cell_Minimum' => 'temperatur_min_c'];
        foreach ($d as $key => $m) {
            if (!is_array($m) || ($id = self::id($key)) === null) {
                continue;
            }
            $p = "speicher/$id/";
            $c = is_array($m['Controller'] ?? null) ? $m['Controller'] : [];
            self::table($o, $p, $c, $ctl);
            self::details($o, $p, $c['Details'] ?? null);
            $sc = self::f($c['Status_BatteryCell'] ?? null);
            if ($sc !== null) {
                $o[$p . 'status_code'] = self::num($sc);
                $txt = self::batteryStatusText((string)($c['Details']['Manufacturer'] ?? ''), (int)$sc);
                self::put($o, $p . 'status_text', $txt);
            }
            $n = 0;
            foreach (is_array($m['Modules'] ?? null) ? $m['Modules'] : [] as $mod) {
                $n++;
                if (!is_array($mod)) {
                    continue;
                }
                $mp = $p . "modul/$n/";
                self::table($o, $mp, $mod, $ctl);
                self::details($o, $mp, $mod['Details'] ?? null);
                $ms = self::f($mod['Status_BatteryCell'] ?? null);
                if ($ms !== null) {                 // Fronius Solar Battery: 0xYX, Y = aktuell, X = vorher
                    $o[$mp . 'status_code'] = self::num($ms);
                    $o[$mp . 'status_aktuell_text'] = self::cellStateText(((int)$ms >> 4) & 0xF);
                    $o[$mp . 'status_vorher_text'] = self::cellStateText((int)$ms & 0xF);
                }
            }
            if ($n > 0) {
                $o[$p . 'module_anzahl'] = (string)$n;
            }
        }
        return $o;
    }

    /** OhmPilot (GetOhmPilotRealtimeData, Scope=System). @return array<string,string> */
    public static function ohmpilot(array $d): array
    {
        $o = [];
        foreach ($d as $key => $m) {
            if (!is_array($m) || ($id = self::id($key)) === null) {
                continue;
            }
            $p = "ohmpilot/$id/";
            self::details($o, $p, $m['Details'] ?? null, ['Manufacturer' => 'hersteller', 'Model' => 'modell', 'Serial' => 'seriennummer',
                'Software' => 'software', 'Hardware' => 'hardware']);
            self::table($o, $p, $m, ['EnergyReal_WAC_Sum_Consumed' => 'energie_verbraucht_wh', 'PowerReal_PAC_Sum' => 'leistung_w',
                'Temperature_Channel_1' => 'temperatur_c', 'CodeOfError' => 'fehler_code']);
            $cs = self::f($m['CodeOfState'] ?? null);
            if ($cs !== null) {
                $o[$p . 'status_code'] = self::num($cs);
                $o[$p . 'status_text'] = self::ohmpilotStateText((int)$cs);
            }
        }
        return $o;
    }

    // ------------------------------------------------------------------ Sensorkarte, String Control

    /** NowSensorData (Kanalnummer => Wert) bzw. MinMaxSensorData. @return array<string,string> */
    public static function sensor(string $id, array $d, bool $minmax = false): array
    {
        $o = [];
        $p = 'sensor/' . Util::name($id) . '/';
        foreach ($d as $ch => $v) {
            if (!is_array($v) || ($n = self::id($ch)) === null) {
                continue;
            }
            $k = $p . 'kanal_' . $n;
            if (!$minmax) {
                self::putNum($o, $k, $v);
                self::put($o, $k . '_einheit', self::text($v['Unit'] ?? null));
                continue;
            }
            if (isset($v['SensorActive']) && is_bool($v['SensorActive'])) {
                $o[$k . '_aktiv'] = $v['SensorActive'] ? '1' : '0';
            }
            foreach (['Day' => 'tag', 'Month' => 'monat', 'Year' => 'jahr', 'Total' => 'gesamt'] as $per => $pn) {
                foreach (['Max' => 'max', 'Min' => 'min'] as $mm => $mn) {
                    if (is_array($v[$per][$mm] ?? null)) {
                        self::putNum($o, "{$k}_{$pn}_{$mn}", $v[$per][$mm]);
                    }
                }
            }
        }
        return $o;
    }

    /**
     * String Control: Now (Kanalstroeme), CurrentSum (Tag/Jahr/Gesamt in Ah) oder LastError.
     * @param string $kind now | sum | fehler    @param string $period tag | jahr | gesamt (nur sum)
     * @return array<string,string>
     */
    public static function stringControl(string $id, array $d, string $kind, string $period = ''): array
    {
        $o = [];
        $p = 'string/' . Util::name($id) . '/';
        if ($kind === 'fehler') {
            self::put($o, $p . 'fehler_zeit', self::text($d['TimeOfError'] ?? null));
            self::putNum($o, $p . 'fehler_mittelwert', $d['StringAverage'] ?? null);
            foreach (is_array($d['Channels'] ?? null) ? $d['Channels'] : [] as $ch => $v) {
                if (is_array($v) && ($n = self::id($ch)) !== null) {
                    self::putNum($o, $p . "fehler_kanal_{$n}_abweichung_prozent", $v['Deviation'] ?? null);
                    self::putNum($o, $p . "fehler_kanal_{$n}_summe_ah", $v['Sum'] ?? null);
                }
            }
            return $o;
        }
        foreach ($d as $ch => $v) {
            if (is_array($v) && ($n = self::id($ch)) !== null) {
                self::putNum($o, $kind === 'now' ? $p . "strom_$n" : $p . "summe_{$period}_{$n}_ah", $v);
            }
        }
        return $o;
    }

    // ------------------------------------------------------------------ Datenlogger

    /** GetLoggerInfo (Body.LoggerInfo). @return array<string,string> */
    public static function loggerInfo(array $d): array
    {
        $o = [];
        $p = 'logger/';
        foreach (['UniqueID' => 'seriennummer', 'ProductID' => 'produkt', 'PlatformID' => 'plattform', 'HWVersion' => 'hw_version', 'SWVersion' => 'sw_version',
            'TimezoneLocation' => 'zeitzone_ort', 'TimezoneName' => 'zeitzone_name', 'DefaultLanguage' => 'sprache',
            'CashCurrency' => 'waehrung', 'CO2Unit' => 'co2_einheit'] as $f => $name) {
            self::put($o, $p . $name, self::text(isset($d[$f]) ? (string)$d[$f] : null));
        }
        self::table($o, $p, $d, ['UTCOffset' => 'utc_offset_s', 'DeliveryFactor' => 'einspeisefaktor', 'CashFactor' => 'kostenfaktor', 'CO2Factor' => 'co2_faktor']);
        return $o;
    }

    /** GetLoggerLEDInfo: Farbe und Zustand der LEDs. @return array<string,string> */
    public static function loggerLeds(array $d): array
    {
        $o = [];
        foreach (['PowerLED' => 'power', 'SolarNetLED' => 'solarnet', 'SolarWebLED' => 'solarweb', 'WLANLED' => 'wlan'] as $f => $name) {
            if (is_array($d[$f] ?? null)) {
                self::put($o, "logger/led_{$name}_farbe", self::text($d[$f]['Color'] ?? null));
                self::put($o, "logger/led_{$name}_zustand", self::text($d[$f]['State'] ?? null));
            }
        }
        return $o;
    }

    // ------------------------------------------------------------------ Geraeteerkennung

    /**
     * GetActiveDeviceInfo (DeviceClass=System): welche Geraete sind gerade online?
     * @return array{ids:array<string,list<string>>,topics:array<string,string>,channels:array<string,list<string>>}
     */
    public static function devices(array $d): array
    {
        $classes = ['inverter' => 'Inverter', 'meter' => 'Meter', 'storage' => 'Storage', 'ohmpilot' => 'Ohmpilot', 'sensorcard' => 'SensorCard', 'stringcontrol' => 'StringControl'];
        $prefix = ['Inverter' => 'wr', 'Meter' => 'zaehler', 'Storage' => 'speicher', 'Ohmpilot' => 'ohmpilot', 'SensorCard' => 'sensor', 'StringControl' => 'string'];
        $count = ['Inverter' => 'wechselrichter', 'Meter' => 'zaehler', 'Storage' => 'speicher', 'Ohmpilot' => 'ohmpilot', 'SensorCard' => 'sensorkarten', 'StringControl' => 'stringcontrol'];
        $ids = array_fill_keys(array_values($classes), []);
        $o = [];
        foreach ($d as $cls => $devs) {
            $c = $classes[strtolower((string)$cls)] ?? null;
            if ($c === null || !is_array($devs)) {
                continue;
            }
            foreach ($devs as $key => $dev) {
                if (($id = self::id($key)) === null || !is_array($dev)) {
                    continue;
                }
                $ids[$c][] = $id;
                $p = $prefix[$c] . "/$id/info/";
                self::put($o, $p . 'seriennummer', self::text(isset($dev['Serial']) ? (string)$dev['Serial'] : null));
                if ($c === 'Inverter' || $c === 'SensorCard' || $c === 'StringControl') {
                    $dt = self::f($dev['DT'] ?? null);
                    if ($dt !== null && $dt >= 0) {
                        self::typ($o, $p, $dt);
                    }
                }
                if (is_array($dev['ChannelNames'] ?? null)) {
                    self::put($o, $p . 'kanalnamen', self::text(implode(', ', array_map('strval', $dev['ChannelNames']))));
                }
            }
        }
        foreach ($count as $c => $name) {
            $o['info/anzahl_' . $name] = (string)count($ids[$c]);
        }
        return ['ids' => $ids, 'topics' => $o];
    }

    // ------------------------------------------------------------------ Archiv: Fehler und Ereignisse

    /**
     * GetArchiveData (Channel InverterErrors / InverterEvents, heutiger Tag, Scope=System): letzter Fehler und letztes Ereignis.
     * Die Fehler- und Ereigniscodes selbst erklaeren die Fronius-Betriebsanleitungen, nicht die Solar-API-Doku.
     * @return array<string,string>
     */
    public static function archive(array $d, ?int $now = null): array
    {
        $o = [];
        $errTotal = 0;
        $evTotal = 0;
        foreach ($d as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            [$kind, $id] = explode('/', (string)$key, 2) + ['', ''];
            $p = ($kind === 'inverter' && self::id($id) !== null) ? "wr/$id/" : '';
            $start = strtotime((string)($entry['Start'] ?? '')) ?: 0;
            $ch = is_array($entry['Data'] ?? null) ? $entry['Data'] : [];
            $errs = is_array($ch['InverterErrors']['Values'] ?? null) ? $ch['InverterErrors']['Values'] : [];
            $evs = is_array($ch['InverterEvents']['Values'] ?? null) ? $ch['InverterEvents']['Values'] : [];
            if (isset($ch['InverterErrors'])) {
                $errTotal += count($errs);
                if ($p !== '') {
                    $o[$p . 'fehler_anzahl_heute'] = (string)count($errs);
                }
                if ($errs) {
                    $last = max(array_keys($errs));
                    $e = $errs[$last];
                    if ($p !== '' && is_array($e)) {
                        self::putNum($o, $p . 'fehler_letzter_code', $e['#'] ?? null);
                        $o[$p . 'fehler_letzter_zeit'] = (string)($start + (int)$last);
                        self::put($o, $p . 'fehler_letzter_flags', self::text(is_array($e['flags'] ?? null) ? implode(',', $e['flags']) : null));
                    }
                }
            }
            if ($p !== '') {
                self::archiveStrings($o, $p, $ch, $start, $now);
            }
            if (isset($ch['InverterEvents'])) {
                $evTotal += count($evs);
                if ($evs) {
                    $last = max(array_keys($evs));
                    $e = $evs[$last];
                    if (is_array($e)) {
                        self::putNum($o, $p . 'ereignis_letzter_code', $e['#'] ?? null);
                        self::put($o, $p . 'ereignis_letzter_text', self::text($e['desc'] ?? null));
                        $o[$p . 'ereignis_letzte_zeit'] = (string)($start + (int)$last);
                    }
                }
            }
        }
        $o['fehler_anzahl_heute'] = (string)$errTotal;
        $o['ereignis_anzahl_heute'] = (string)$evTotal;
        return $o;
    }

    // ------------------------------------------------------------------ Rohwerte

    /**
     * Alle Felder einer Antwort als Topics (Option "Rohwerte"): <prefix>/<pfad_in_kleinbuchstaben>.
     * @return array<string,string>
     */
    public static function raw(string $prefix, mixed $data, int $depth = 0): array
    {
        $o = [];
        if ($depth > 7 || !is_array($data)) {
            return $o;
        }
        foreach ($data as $k => $v) {
            $seg = Util::name((string)$k);
            if ($seg === '') {
                continue;
            }
            $topic = $prefix . '/' . $seg;
            if (is_array($v)) {
                $o += self::raw($topic, $v, $depth + 1);
            } elseif (is_string($v)) {
                if (($t = self::text($v)) !== null) {
                    $o[$topic] = is_numeric($t) ? (self::num($t) ?? $t) : $t;
                }
            } elseif (($n = self::num($v)) !== null) {
                $o[$topic] = $n;
            }
            if (count($o) > 800) {
                break;
            }
        }
        return $o;
    }
}
