<?php
require_once __DIR__ . '/../../common/php/Util.php';    // Rueckfaelle fuer PHP 7.4 (str_contains usw.)
/**
 * Fake-Fronius-Wechselrichter fuer Tests: php tests/fake_fronius.php <port> <verzeichnis>
 * Schreibt jede Anfrage (Pfad mit Query) nach <verzeichnis>/fro_<port>.log.
 * Verhalten steuert die Datei <verzeichnis>/fro_mode_<port>.txt:
 *   ok (Standard)  Gen24 mit Speicher und Smart Meter (kein MinMax, kein Datenlogger, kein Archiv - wie in der Doku)
 *   symo           Symo Non-Hybrid (DT 115) mit Smart Meter, OhmPilot, Sensorkarte, String Control, Datenlogger, Archiv; kein Speicher
 *   symo_abend     wie symo, aber PV-Leistung nur noch 20 W (Dammerung)
 *   aus            Wechselrichter abgeschaltet: Verbindung wird sofort ohne Antwort geschlossen
 *   nacht          Gen24: PV null, Speicher meldet null, Wechselrichter-Details nicht verfuegbar (Head-Fehler)
 *   ohne           kein Zaehler (Meter_Location unknown), kein Speicher
 *   404            Solar API nicht freigeschaltet (alle Pfade HTTP 404)
 *   kaputt         keine JSON-Antwort
 * Die Antworten sind nach der Fronius-Doku (Solar API V1, 42,0410,2012) nachgebaut, nicht von einem echten Geraet mitgeschnitten.
 */
date_default_timezone_set('Europe/Berlin');     // wie das Plugin (Archiv-Startzeit = Mitternacht in dieser Zeitzone)
$port = (int)($argv[1] ?? 18950);
$dir = $argv[2] ?? sys_get_temp_dir();
$srv = stream_socket_server("tcp://127.0.0.1:$port", $e, $es);
if (!$srv) {
    fwrite(STDERR, "listen: $es\n");
    exit(1);
}
echo "listening $port\n";

function head(int $code = 0, string $reason = ''): array
{
    return ['RequestArguments' => new stdClass(), 'Status' => ['Code' => $code, 'Reason' => $reason, 'UserMessage' => ''], 'Timestamp' => '2026-06-01T12:00:00+02:00'];
}

function reply(string $path, string $mode): array
{
    $p = (string)parse_url($path, PHP_URL_PATH);
    parse_str((string)parse_url($path, PHP_URL_QUERY), $q);
    if ($mode === '404') {
        return [404, 'Not Found', 'not found'];
    }
    if ($mode === 'kaputt') {
        return [200, 'OK', '<html>kein JSON</html>'];
    }
    $symo = in_array($mode, ['symo', 'symo_abend'], true);
    $json = fn($data, $code = 0, $reason = '') => [200, 'OK', json_encode(['Body' => ['Data' => $data], 'Head' => head($code, $reason)])];
    $err = fn($code, $reason) => [200, 'OK', json_encode(['Body' => ['Data' => new stdClass()], 'Head' => head($code, $reason)])];
    $u = fn($unit, $v) => ['Unit' => $unit, 'Value' => $v];
    $ends = fn(string $x) => str_ends_with($p, $x);

    if ($ends('GetAPIVersion.cgi')) {
        return [200, 'OK', json_encode(['APIVersion' => 1, 'BaseURL' => '/solar_api/v1/', 'CompatibilityRange' => '1.6-9'])];
    }
    if ($ends('GetPowerFlowRealtimeData.fcgi')) {
        $site = ['BackupMode' => false, 'BatteryStandby' => false, 'E_Day' => 12345.6, 'E_Total' => 9876543.2, 'E_Year' => 123456.7,
            'Meter_Location' => 'grid', 'Mode' => 'bidirectional', 'P_Akku' => -500.0, 'P_Grid' => -1000.5, 'P_Load' => -1000.0,
            'P_PV' => 2500.5, 'rel_Autonomy' => 100.0, 'rel_SelfConsumption' => 40.0];
        $inv = ['Battery_Mode' => 'normal', 'DT' => 1, 'E_Day' => 12345.6, 'E_Total' => 9876543.2, 'E_Year' => 123456.7, 'P' => 2500, 'SOC' => 55.5];
        $extra = [];
        if ($mode === 'nacht') {
            $site['P_PV'] = null;
            $site['P_Akku'] = null;
            $site['P_Grid'] = 300.0;
            $site['P_Load'] = -300.0;
            $inv = ['DT' => 1, 'E_Day' => 12345.6, 'E_Total' => 9876543.2, 'E_Year' => 123456.7, 'P' => 1];
        } elseif ($mode === 'ohne') {
            $site['Meter_Location'] = 'unknown';
            $site['P_Akku'] = null;
            $site['P_Load'] = null;
            $site['P_Grid'] = null;
            $site['Mode'] = 'produce-only';
            $inv = ['DT' => 1, 'E_Day' => 12345.6, 'E_Total' => 9876543.2, 'E_Year' => 123456.7, 'P' => 2500];
        } elseif ($symo) {
            $pv = $mode === 'symo_abend' ? 20.0 : 6000.5;
            $site = ['E_Day' => 20000.5, 'E_Total' => 55000000.0, 'E_Year' => 9000000.0, 'Meter_Location' => 'grid', 'Mode' => 'meter',
                'P_Grid' => 1200.0, 'P_Load' => -($pv + 1200.0), 'P_Akku' => null, 'P_PV' => $pv, 'rel_Autonomy' => 62.5, 'rel_SelfConsumption' => 100.0];
            $inv = ['CID' => 524288, 'DT' => 115, 'E_Day' => 20000.5, 'E_Total' => 55000000.0, 'E_Year' => 9000000.0, 'P' => (int)$pv];
            $extra = [
                'Smartloads' => ['Ohmpilots' => ['720897' => ['P_AC_Total' => 2635, 'State' => 'normal', 'Temperature' => 30.7]]],
                'SecondaryMeters' => ['65536' => ['Category' => 'METER_CAT_HEATPUMP', 'Label' => 'Waermepumpe', 'MLoc' => 256, 'P' => -850.5]],
            ];
        }
        return $json(['Inverters' => ['1' => $inv], 'Site' => $site, 'Version' => '12'] + $extra);
    }
    if ($ends('GetActiveDeviceInfo.cgi')) {
        $devs = ['Inverter' => ['1' => ['DT' => $symo ? 115 : 1, 'Serial' => '29091000975090007']], 'Meter' => ['0' => ['DT' => -1, 'Serial' => '15160189']],
            'Storage' => ['0' => ['DT' => -1, 'Serial' => 'P0123']]];
        if ($symo) {
            unset($devs['Storage']);
            $devs['Ohmpilot'] = ['0' => ['DT' => -1, 'Serial' => '28136344']];
            $devs['SensorCard'] = ['1' => ['DT' => 254, 'ChannelNames' => ['Temperature 1', 'Temperature 2', 'Irradiation', 'Digital 1', 'Digital 2', 'Current']]];
            $devs['StringControl'] = ['8' => ['DT' => 233]];
        } elseif ($mode === 'ohne') {
            unset($devs['Meter'], $devs['Storage']);
        }
        return $json($devs);
    }
    if ($ends('GetInverterRealtimeData.cgi')) {
        if ($mode === 'nacht') {
            return $err(12, 'Device not available');
        }
        $coll = $q['DataCollection'] ?? '';
        if ($coll === 'MinMaxInverterData') {
            return $symo ? $json(['DAY_PMAX' => $u('W', 9000), 'DAY_UACMAX' => $u('V', 241.5), 'DAY_UACMIN' => $u('V', 228.1), 'DAY_UDCMAX' => $u('V', 810),
                'YEAR_PMAX' => $u('W', 14000), 'YEAR_UACMAX' => $u('V', 250), 'YEAR_UACMIN' => $u('V', 215), 'YEAR_UDCMAX' => $u('V', 900),
                'TOTAL_PMAX' => $u('W', 14900), 'TOTAL_UACMAX' => $u('V', 252), 'TOTAL_UACMIN' => $u('V', 210), 'TOTAL_UDCMAX' => $u('V', 920)])
                : $err(11, 'NotSupported');
        }
        if ($coll === '3PInverterData') {
            return $json(['IAC_L1' => $u('A', 8.7), 'IAC_L2' => $u('A', 8.8), 'IAC_L3' => $u('A', 8.6), 'UAC_L1' => $u('V', 233.9), 'UAC_L2' => $u('V', 233.0), 'UAC_L3' => $u('V', 230.7)]
                + ($symo ? ['T_AMBIENT' => $u('C', 31)] : []));
        }
        $status = $symo ? ['ErrorCode' => 0, 'LEDColor' => 2, 'LEDState' => 0, 'MgmtTimerRemainingTime' => -1, 'StateToReset' => false, 'StatusCode' => 7]
            : ['ErrorCode' => 0, 'InverterState' => 'Running', 'StatusCode' => 7];
        return $json(['DAY_ENERGY' => $symo ? $u('Wh', 20000.5) : $u('Wh', 12345.6), 'DeviceStatus' => $status,
            'FAC' => $u('Hz', 50.01), 'IAC' => $u('A', 3.62), 'IDC' => $u('A', 4.5), 'PAC' => $u('W', $mode === 'symo_abend' ? 20 : ($symo ? 6000 : 2500)),
            'SAC' => $u('VA', 2520), 'TOTAL_ENERGY' => $u('Wh', 9876543), 'UAC' => $u('V', 231.2), 'UDC' => $u('V', 560.1), 'YEAR_ENERGY' => $u('kWh', 123.4567)]
            + ($symo ? [] : ['IDC_2' => $u('A', 4.4), 'UDC_2' => $u('V', 555.5)]));
    }
    if ($ends('GetMeterRealtimeData.cgi')) {
        return $json(['0' => ['Current_AC_Phase_1' => 1.2, 'Current_AC_Phase_2' => 1.3, 'Current_AC_Phase_3' => 1.4, 'Current_AC_Sum' => 3.9,
            'Details' => ['Manufacturer' => 'Fronius', 'Model' => 'Smart Meter TS 65A-3', 'Serial' => '12345678'], 'Enable' => 1,
            'EnergyReactive_VArAC_Sum_Consumed' => 9156420, 'EnergyReactive_VArAC_Sum_Produced' => 87894450,
            'EnergyReal_WAC_Minus_Absolute' => 7654321, 'EnergyReal_WAC_Plus_Absolute' => 1234567,
            'EnergyReal_WAC_Sum_Consumed' => 1234567, 'EnergyReal_WAC_Sum_Produced' => 7654321, 'Frequency_Phase_Average' => 50,
            'Meter_Location_Current' => 0, 'PowerApparent_S_Phase_1' => 172.4, 'PowerApparent_S_Sum' => 1100, 'PowerFactor_Sum' => 0.99, 'PowerReactive_Q_Sum' => -20,
            'PowerReal_P_Phase_1' => -300, 'PowerReal_P_Phase_2' => -350.5, 'PowerReal_P_Phase_3' => -350, 'PowerReal_P_Sum' => -1000.5,
            'TimeStamp' => 1561364909, 'Visible' => 1, 'Voltage_AC_PhaseToPhase_12' => 402.6, 'Voltage_AC_Phase_1' => 230.1, 'Voltage_AC_Phase_2' => 231.1,
            'Voltage_AC_Phase_3' => 232.1, 'Voltage_AC_Phase_Average' => 231.1]]);
    }
    if ($ends('GetStorageRealtimeData.cgi')) {
        if ($symo) {
            return $err(255, 'UnknownError');
        }
        return $json(['0' => ['Controller' => ['Capacity_Maximum' => 10000, 'Current_DC' => -1.5, 'DesignedCapacity' => 10240,
            'Details' => ['Manufacturer' => 'BYD', 'Model' => 'BYD Battery-Box Premium HV', 'Serial' => 'P0123'], 'Enable' => 1,
            'StateOfCharge_Relative' => 55.5, 'Status_BatteryCell' => 3, 'Temperature_Cell' => 22.5, 'Voltage_DC' => 380.2],
            'Modules' => [['Capacity_Maximum' => 1200, 'CycleCount_BatteryCell' => 255, 'Details' => ['Manufacturer' => 'Sony', 'Model' => 'unknown', 'Serial' => 'S01'],
                'StateOfCharge_Relative' => 55, 'Status_BatteryCell' => 53, 'Temperature_Cell_Maximum' => 27.75]]]]);
    }
    if ($ends('GetOhmPilotRealtimeData.cgi')) {
        return $json(['0' => ['CodeOfError' => 926, 'CodeOfState' => 2, 'Details' => ['Hardware' => '3', 'Manufacturer' => 'Fronius', 'Model' => 'Ohmpilot',
            'Serial' => '28136344', 'Software' => '1.0.19-1'], 'EnergyReal_WAC_Sum_Consumed' => 2964307, 'PowerReal_PAC_Sum' => 2635, 'Temperature_Channel_1' => 30.7]]);
    }
    if ($ends('GetSensorRealtimeData.cgi')) {
        if (($q['DataCollection'] ?? '') === 'MinMaxSensorData') {
            return $json(['0' => ['Day' => ['Max' => $u('C', 66), 'Min' => $u('C', 46)], 'Month' => ['Max' => $u('C', 85), 'Min' => $u('C', 0)],
                'SensorActive' => true, 'Total' => ['Max' => $u('C', 85), 'Min' => $u('C', -35)], 'Year' => ['Max' => $u('C', 85), 'Min' => $u('C', 0)]]]);
        }
        return $json(['0' => $u('C', -9), '1' => $u('C', 24), '2' => $u('W/m2', 589)]);
    }
    if ($ends('GetStringRealtimeData.cgi')) {
        $coll = $q['DataCollection'] ?? '';
        if ($coll === 'LastErrorStringControlData') {
            return $json(['Channels' => ['1' => ['Deviation' => $u('%', 5.7), 'Sum' => $u('Ah', 0.85)], '2' => ['Deviation' => $u('%', -12.6), 'Sum' => $u('Ah', 0.7)]],
                'StringAverage' => $u('Ah', 0.8), 'TimeOfError' => '2026-05-30T13:00:00+02:00']);
        }
        if ($coll === 'CurrentSumStringControlData') {
            return $json(['1' => $u('Ah', 12.5), '2' => $u('Ah', 11.25)]);
        }
        return $json(['1' => $u('A', 4.5), '2' => $u('A', 4.25)]);
    }
    if ($ends('GetInverterInfo.cgi')) {
        return $json(['1' => ['CustomName' => $symo ? 'Symo&#32;15.0-3-M' : 'Symo GEN24', 'DT' => $symo ? 115 : 1, 'ErrorCode' => 0, 'PVPower' => 8000,
            'Show' => 1, 'StatusCode' => 7, 'UniqueID' => '123456']]);
    }
    if ($ends('GetLoggerInfo.cgi')) {
        if (!$symo) {
            return $err(255, 'UnknownError');
        }
        return [200, 'OK', json_encode(['Body' => ['LoggerInfo' => ['CO2Factor' => 0.53, 'CO2Unit' => 'kg', 'CashCurrency' => 'EUR', 'CashFactor' => 0.12,
            'DefaultLanguage' => 'de', 'DeliveryFactor' => 0.25, 'HWVersion' => '2.4D', 'PlatformID' => 'wilma', 'ProductID' => 'fronius-datamanager-card',
            'SWVersion' => '3.14.1-2', 'TimezoneLocation' => 'Berlin', 'TimezoneName' => 'CEST', 'UTCOffset' => 7200, 'UniqueID' => '240.107620']], 'Head' => head()])];
    }
    if ($ends('GetLoggerLEDInfo.cgi')) {
        if (!$symo) {
            return $err(255, 'UnknownError');
        }
        return $json(['PowerLED' => ['Color' => 'green', 'State' => 'on'], 'SolarNetLED' => ['Color' => 'green', 'State' => 'on'],
            'SolarWebLED' => ['Color' => 'green', 'State' => 'blinking'], 'WLANLED' => ['Color' => 'red', 'State' => 'on']]);
    }
    if ($ends('GetArchiveData.cgi')) {
        if (!$symo) {
            return [404, 'Not Found', 'not found'];
        }
        $heute = strtotime('today');
        $jetzt = (int)floor((time() - $heute) / 300) * 300;       // letzter Aufzeichnungszeitpunkt (5-Minuten-Raster)
        $werte = fn(float $a, float $b) => ['Unit' => '1', 'Values' => [(string)max(0, $jetzt - 300) => $a, (string)$jetzt => $b]];
        return $json(['inverter/1' => ['Data' => [
            'Voltage_DC_String_1' => $werte(540.0, 541.5), 'Current_DC_String_1' => $werte(4.0, 4.5),
            'Voltage_DC_String_2' => $werte(402.0, 403.5), 'Current_DC_String_2' => $werte(3.0, 3.2),
            'InverterErrors' => ['Unit' => 'Object', 'Values' => ['23240' => ['flags' => ['fatal', 'official'], '#' => 482], '41000' => ['flags' => ['official'], '#' => 731]]],
            'InverterEvents' => ['Unit' => 'Object', 'Values' => ['30000' => ['#' => 9, 'desc' => 'Component Specific StateCode', 'flags' => ['send']]]]],
            'DeviceType' => 115, 'Start' => date('c', $heute)],
            'broadcast/' => ['Data' => ['InverterEvents' => ['Unit' => 'Object', 'Values' => ['42060' => ['#' => 3, 'desc' => 'Power limitation 20%', 'flags' => ['send'], 'attr' => ['Power' => '20 [%]']]]]],
                'Start' => '2026-06-01T00:00:00+02:00']]);
    }
    return [404, 'Not Found', 'not found'];
}

while (true) {
    $c = @stream_socket_accept($srv, 1);
    if (!$c) {
        continue;
    }
    stream_set_timeout($c, 2);
    $head = '';
    while (!str_contains($head, "\r\n\r\n")) {
        $d = fread($c, 1);
        if ($d === '' || $d === false) {
            break;
        }
        $head .= $d;
    }
    [$method, $path] = explode(' ', $head, 3) + [1 => '', 2 => ''];
    file_put_contents("$dir/fro_$port.log", $path . "\n", FILE_APPEND);
    $mode = trim((string)@file_get_contents("$dir/fro_mode_$port.txt")) ?: 'ok';
    if ($mode === 'aus') {
        fclose($c);                 // abgeschaltet: keine Antwort
        continue;
    }
    [$code, $text, $out] = reply($path, $mode);
    fwrite($c, "HTTP/1.1 $code $text\r\nContent-Type: application/json\r\nContent-Length: " . strlen($out) . "\r\nConnection: close\r\n\r\n$out");
    fclose($c);
}
