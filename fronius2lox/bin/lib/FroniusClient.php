<?php
/**
 * Client fuer die Fronius Solar API v1 (JSON ueber HTTP, ohne Anmeldung).
 * Bei Gen24 muss die Solar API im Wechselrichter freigeschaltet sein (Kommunikation > Solar API).
 */
final class FroniusClient
{
    private const TIMEOUT = 5;
    public string $error = '';

    public function __construct(private readonly string $host, private readonly int $port = 80)
    {
        // Der Verbindungsaufbau folgt default_socket_timeout (Standard 60 s): nachts ist der Datamanager offline,
        // ohne Begrenzung wuerde jede Abfrage den Dienst bis zu einer Minute blockieren.
        ini_set('default_socket_timeout', (string)self::TIMEOUT);
    }

    public function baseUrl(): string
    {
        return 'http://' . $this->host . ($this->port !== 80 ? ':' . $this->port : '') . '/solar_api/';
    }

    /**
     * Eine Abfrage. Gibt Body.Data zurueck, bei Fehler null (Text in $this->error).
     * @param string $path z. B. "v1/GetPowerFlowRealtimeData.fcgi" oder "GetAPIVersion.cgi"
     * @return array|null
     */
    public function get(string $path, array|string $params = []): ?array
    {
        $b = $this->body($path, $params);
        if ($b === null) {
            return null;
        }
        $data = $b['Data'] ?? [];
        return is_array($data) ? $data : [];
    }

    /**
     * Wie get(), liefert aber den ganzen Body (z. B. GetLoggerInfo antwortet mit Body.LoggerInfo statt Body.Data).
     * Ein Fehlercode im Kopf (Head.Status.Code != 0) gilt als Fehler; Texte stehen im Fehlerfeld.
     */
    public function body(string $path, array|string $params = []): ?array
    {
        $r = $this->raw($path, $params);
        if ($r === null) {
            return null;
        }
        if (!isset($r['Body'])) {
            return $r;           // GetAPIVersion.cgi hat keine Head/Body-Huelle
        }
        $code = (int)($r['Head']['Status']['Code'] ?? 0);
        if ($code !== 0) {
            $why = trim((string)($r['Head']['Status']['Reason'] ?? ''));
            $this->error = "Fronius meldet Fehler $code" . ($why !== '' ? ": $why" : '');
            return null;
        }
        return is_array($r['Body']) ? $r['Body'] : [];
    }

    /** Antwort als Array (inkl. Head/Body) oder null. */
    public function raw(string $path, array|string $params = []): ?array
    {
        $this->error = '';
        // $params als Text = fertige Query (z. B. wiederholte Parameter Channel=A&Channel=B bei GetArchiveData)
        $query = is_string($params) ? $params : ($params ? http_build_query($params) : '');
        $url = $this->baseUrl() . $path . ($query !== '' ? '?' . $query : '');
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => self::TIMEOUT, 'ignore_errors' => true,
            'header' => "Accept: application/json\r\n"]]);
        $http_response_header = null;
        $body = @file_get_contents($url, false, $ctx);
        if (function_exists('http_get_last_response_headers')) {      // PHP >= 8.4 (Ersatz fuer die Variable)
            $http_response_header = http_get_last_response_headers();
        }
        $code = 0;
        foreach ((array)$http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$h, $m)) {
                $code = (int)$m[1];
            }
        }
        if ($body === false || $code === 0) {
            $e = error_get_last();
            $this->error = 'nicht erreichbar' . (isset($e['message']) && stripos($e['message'], 'timed out') !== false ? ' (Zeitueberschreitung)' : '');
            return null;
        }
        if ($code === 404) {
            $this->error = 'Solar API nicht gefunden (HTTP 404) - im Wechselrichter freigeschaltet?';
            return null;
        }
        if ($code !== 200) {
            $this->error = "HTTP $code";
            return null;
        }
        $j = json_decode($body, true);
        if (!is_array($j)) {
            $this->error = 'keine gueltige JSON-Antwort';
            return null;
        }
        return $j;
    }

    /** Erreichbarkeit pruefen. @return array{0:string,1:string} [ok|fehler, Text] */
    public static function check(string $host, int $port): array
    {
        $c = new self($host, $port);
        $j = $c->raw('GetAPIVersion.cgi');
        if ($j === null) {
            return ['fehler', $c->error];
        }
        return isset($j['APIVersion']) ? ['ok', 'erreichbar, Solar API Version ' . $j['APIVersion']] : ['fehler', 'antwortet, aber keine Fronius Solar API'];
    }
}
