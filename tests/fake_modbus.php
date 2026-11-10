<?php
/**
 * Fake-Modbus-TCP-Server (Fronius Datamanager, SunSpec) fuer Tests: php tests/fake_modbus.php <port> <verzeichnis>
 * Nur Funktion 3 (Halteregister lesen). Unit-ID 1 ist der Wechselrichter, alle anderen Units antworten mit Ausnahme 11.
 * Verhalten steuert die Datei <verzeichnis>/mb_mode_<port>.txt:
 *   ok (Standard)  SunSpec ab Adresse 40000: Modell 1, 103, 160 (zwei Tracker), Ende
 *   kein160        wie ok, aber ohne Modell 160
 *   ohne_sunspec   Adresse 40000 und 0 liefern keine SunSpec-Kennung
 *   aus            Verbindung wird sofort geschlossen
 *   ausnahme       jede Anfrage endet mit Modbus-Ausnahme 11
 *   ein_tracker    Modell 160 mit nur einem Tracker (zweiter nicht implementiert: 0xFFFF)
 * Schreibt jede Anfrage als "unit addr count" nach <verzeichnis>/mb_<port>.log. Die Registerbelegung ist nach der SunSpec-Spezifikation
 * nachgebaut (Modell 160 laut Fronius-Doku "Datamanager Modbus TCP & RTU"), nicht von einem echten Geraet mitgeschnitten.
 */
$port = (int)($argv[1] ?? 18960);
$dir = $argv[2] ?? sys_get_temp_dir();
$srv = stream_socket_server("tcp://127.0.0.1:$port", $e, $es);
if (!$srv) {
    fwrite(STDERR, "listen: $es\n");
    exit(1);
}
echo "listening $port\n";

/** Registerbelegung als Liste ab Adresse $base. */
function sunspec(string $mode): array
{
    $str = function (string $s): array {
        $s = str_pad($s, 16, "\0");
        return array_values(unpack('n8', $s));
    };
    $r = [0x5375, 0x6E53];                                           // "SunS"
    $r = array_merge($r, [1, 65], array_fill(0, 65, 0));            // Modell 1 (Common)
    $r = array_merge($r, [103, 50], array_fill(0, 50, 0));          // Modell 103 (Wechselrichter, int + SF)
    if ($mode !== 'kein160') {
        $zwei = $mode !== 'ein_tracker';
        $m160 = [160, 48, 0xFFFE, 0xFFFF, 0, 0, 0, 0, 2, 0];        // DCA_SF -2, DCV_SF -1, DCW_SF 0, DCWH_SF 0, Evt, N = 2, TmsPer
        $m160 = array_merge($m160, [1], $str('String 1'), [450, 5415, 2437, 0, 0, 0, 0, 0, 0, 0, 0]);
        $m160 = array_merge($m160, [2], $str('String 2'), $zwei ? [320, 4035, 1291, 0, 0, 0, 0, 0, 0, 0, 0] : [0xFFFF, 0xFFFF, 0xFFFF, 0, 0, 0, 0, 0, 0, 0, 0]);
        $r = array_merge($r, $m160);
    }
    return array_merge($r, [0xFFFF, 0]);                             // Ende der Liste
}

function handle($c, string $dir, int $port): void
{
    while (true) {
        $h = '';
        while (strlen($h) < 7) {
            $d = fread($c, 7 - strlen($h));
            if ($d === false || $d === '') {
                return;
            }
            $h .= $d;
        }
        $f = unpack('ntid/nproto/nlen/Cunit', $h);
        $pdu = '';
        while (strlen($pdu) < $f['len'] - 1) {
            $d = fread($c, $f['len'] - 1 - strlen($pdu));
            if ($d === false || $d === '') {
                return;
            }
            $pdu .= $d;
        }
        $mode = trim((string)@file_get_contents("$dir/mb_mode_$port.txt")) ?: 'ok';
        if ($mode === 'aus') {
            return;
        }
        $fn = ord($pdu[0]);
        $q = unpack('naddr/ncount', substr($pdu, 1, 4));
        @file_put_contents("$dir/mb_$port.log", $f['unit'] . ' ' . $q['addr'] . ' ' . $q['count'] . "\n", FILE_APPEND);
        $ex = function (int $code) use ($c, $f, $fn): void {
            fwrite($c, pack('nnnCCC', $f['tid'], 0, 3, $f['unit'], $fn | 0x80, $code));
        };
        if ($fn !== 3) {
            $ex(1);
            continue;
        }
        if ($f['unit'] !== 1 || $mode === 'ausnahme') {
            $ex(11);
            continue;
        }
        $map = sunspec($mode);
        $base = 40000;
        $regs = [];
        for ($a = $q['addr']; $a < $q['addr'] + $q['count']; $a++) {
            $i = $a - $base;
            if ($mode === 'ohne_sunspec' || $i < 0 || $i >= count($map)) {
                $ex(2);
                continue 2;
            }
            $regs[] = $map[$i];
        }
        fwrite($c, pack('nnnCCC', $f['tid'], 0, 3 + $q['count'] * 2, $f['unit'], 3, $q['count'] * 2) . pack('n*', ...$regs));
    }
}

while (true) {
    $c = @stream_socket_accept($srv, 1);
    if (!$c) {
        continue;
    }
    stream_set_timeout($c, 3);
    handle($c, $dir, $port);
    @fclose($c);
}
