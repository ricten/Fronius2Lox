<?php
/**
 * Modbus TCP (nur lesen, Funktion 3) und SunSpec "Multiple MPPT Inverter Extension Model (160)" des Fronius Datamanagers.
 *
 * Warum: Die Fronius Solar API liefert Spannung und Strom des zweiten MPP-Trackers nur beim GEN24/Tauro/Verto. Beim Symo steht
 * die Aufteilung auf die Tracker nur im SunSpec-Modell 160 der Modbus-Schnittstelle (Fronius "Datamanager Modbus TCP & RTU").
 * Dafuer muss Modbus TCP im Datamanager eingeschaltet sein (Einstellungen > Modbus: Datenausgabe "tcp", SunSpec-Modelltyp "int + SF").
 *
 * Das Plugin schreibt nie per Modbus (keine Funktionen ausser 3).
 */
final class FroniusModbus
{
    public string $error = '';
    /** @var resource|null */
    private $sock = null;
    private int $tid = 0;

    public function __construct(private readonly string $host, private readonly int $port = 502, private readonly float $timeout = 3.0)
    {
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
        $this->sock = null;
    }

    private function connect(): bool
    {
        $this->close();
        $s = @stream_socket_client('tcp://' . $this->host . ':' . $this->port, $errno, $errstr, $this->timeout);
        if (!$s) {
            $this->error = 'nicht erreichbar (Modbus TCP ' . $this->host . ':' . $this->port . ($errstr !== '' ? ': ' . $errstr : '') . ')';
            return false;
        }
        stream_set_timeout($s, (int)$this->timeout, (int)(($this->timeout - (int)$this->timeout) * 1e6));
        $this->sock = $s;
        return true;
    }

    private function readExact(int $n): ?string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $d = @fread($this->sock, $n - strlen($buf));
            if ($d === false || $d === '') {
                return null;
            }
            $buf .= $d;
        }
        return $buf;
    }

    /**
     * Halteregister lesen (Funktion 3). Adresse 0-basiert wie im Protokoll.
     * @return list<int>|null  16-Bit-Werte ohne Vorzeichen; null bei Fehler ($error)
     */
    public function read(int $unit, int $addr, int $count): ?array
    {
        if ($count < 1 || $count > 125 || $addr < 0 || $addr > 65535) {
            $this->error = 'ungueltiger Registerbereich';
            return null;
        }
        if (!is_resource($this->sock) && !$this->connect()) {
            return null;
        }
        $this->tid = ($this->tid + 1) & 0xFFFF;
        $req = pack('nnnCCnn', $this->tid, 0, 6, $unit & 0xFF, 3, $addr, $count);
        if (@fwrite($this->sock, $req) !== strlen($req)) {
            $this->close();
            $this->error = 'Senden fehlgeschlagen (Modbus)';
            return null;
        }
        $h = $this->readExact(7);
        if ($h === null) {
            $this->close();
            $this->error = 'keine Antwort (Zeitueberschreitung, Modbus)';
            return null;
        }
        $f = unpack('ntid/nproto/nlen/Cunit', $h);
        if ($f['proto'] !== 0 || $f['len'] < 2 || $f['len'] > 260 || $f['tid'] !== $this->tid) {
            $this->close();
            $this->error = 'ungueltige Modbus-Antwort';
            return null;
        }
        $pdu = $this->readExact($f['len'] - 1);
        if ($pdu === null) {
            $this->close();
            $this->error = 'Modbus-Antwort abgebrochen';
            return null;
        }
        $fn = ord($pdu[0]);
        if ($fn === 0x83) {
            $code = ord($pdu[1] ?? "\0");
            $this->error = 'Modbus-Ausnahme ' . $code . match ($code) {
                2 => ' (Adresse nicht vorhanden)',
                11 => ' (Wechselrichter antwortet nicht, Unit-ID richtig?)',
                default => '',
            };
            return null;
        }
        if ($fn !== 3 || strlen($pdu) < 2 || ord($pdu[1]) !== $count * 2 || strlen($pdu) !== 2 + $count * 2) {
            $this->close();
            $this->error = 'unerwartete Modbus-Antwort';
            return null;
        }
        return array_values(unpack('n*', substr($pdu, 2)));
    }
}

/** SunSpec-Modelle suchen und das Mehrfach-MPPT-Modell (160) auswerten. */
final class FroniusSunSpec
{
    /** Moegliche Basisadressen (0-basiert): Fronius 40000, sonst laut SunSpec 0 oder 50000. */
    private const BASEN = [40000, 0, 50000];

    /**
     * Adresse und Laenge eines SunSpec-Modells suchen (Kette aus "SunS", dann je Modell Kennung und Laenge).
     * @return array{addr:int,len:int}|null  addr = Adresse der Modellkennung
     */
    public static function find(FroniusModbus $m, int $unit, int $model): ?array
    {
        foreach (self::BASEN as $base) {
            $h = $m->read($unit, $base, 2);
            if ($h === null) {
                if (str_contains($m->error, 'nicht erreichbar') || str_contains($m->error, 'Zeitueberschreitung')) {
                    return null;        // keine Verbindung: weitere Basen bringen nichts
                }
                continue;
            }
            if ($h !== [0x5375, 0x6E53]) {          // "SunS"
                continue;
            }
            $addr = $base + 2;
            for ($i = 0; $i < 30; $i++) {
                $hd = $m->read($unit, $addr, 2);
                if ($hd === null) {
                    return null;
                }
                [$id, $len] = $hd;
                if ($id === 0xFFFF || ($id === 0 && $len === 0)) {
                    $m->error = 'Modell ' . $model . ' (Mehrfach-MPPT) fehlt in der SunSpec-Liste des Wechselrichters';
                    return null;
                }
                if ($id === $model) {
                    return ['addr' => $addr, 'len' => $len];
                }
                $addr += $len + 2;
            }
            $m->error = 'SunSpec-Modellliste nicht gefunden';
            return null;
        }
        $m->error = 'keine SunSpec-Kennung („SunS“) gefunden: Modbus TCP im Datamanager eingeschaltet, Port und Unit-ID richtig?';
        return null;
    }

    private static function int16(int $v): int
    {
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    /** Wert mit Skalierungsfaktor; null bei "nicht implementiert" (0xFFFF bzw. Faktor 0x8000). */
    private static function scaled(int $raw, int $sfRaw): ?float
    {
        if ($raw === 0xFFFF || $sfRaw === 0x8000) {
            return null;
        }
        $sf = self::int16($sfRaw);
        if ($sf < -6 || $sf > 6) {
            return null;
        }
        return $raw * (10 ** $sf);
    }

    /**
     * Spannung, Strom und Leistung je MPP-Tracker (Modell 160).
     * $cache haelt die gefundene Adresse (spart die Suche bei jedem Durchgang).
     * @return list<array{name:string,spannung_v:?float,strom_a:?float,leistung_w:?float}>|null
     */
    public static function mppt(FroniusModbus $m, int $unit, ?array &$cache): ?array
    {
        for ($try = 0; $try < 2; $try++) {
            if ($cache === null) {
                $cache = self::find($m, $unit, 160);
                if ($cache === null) {
                    return null;
                }
            }
            $head = $m->read($unit, $cache['addr'], 10);
            if ($head === null) {
                $cache = null;
                return null;
            }
            if ($head[0] !== 160) {
                $cache = null;          // Adresse stimmt nicht mehr: neu suchen
                continue;
            }
            $n = min($head[8], 4);
            if ($n < 1 || $head[8] > 20) {
                $m->error = 'Mehrfach-MPPT-Modell ohne Tracker';
                return null;
            }
            $regs = $m->read($unit, $cache['addr'] + 10, 20 * $n);
            if ($regs === null) {
                $cache = null;
                return null;
            }
            $out = [];
            for ($k = 0; $k < $n; $k++) {
                $o = 20 * $k;
                $name = '';
                for ($j = 1; $j <= 8; $j++) {
                    $name .= pack('n', $regs[$o + $j]);
                }
                $name = (string)preg_replace('/[^\x20-\x7E]/', '', $name);
                $out[] = [
                    'name' => trim($name),
                    'strom_a' => self::scaled($regs[$o + 9], $head[2]),
                    'spannung_v' => self::scaled($regs[$o + 10], $head[3]),
                    'leistung_w' => self::scaled($regs[$o + 11], $head[4]),
                ];
            }
            return $out;
        }
        $m->error = 'Mehrfach-MPPT-Modell nicht lesbar';
        return null;
    }
}
