# Fronius2Lox – LoxBerry-Plugin

**Version: siehe `fronius2lox/bin/lib/AppVersion.php` (muss mit `fronius2lox/plugin.cfg` und [CHANGELOG.md](CHANGELOG.md) übereinstimmen; `build.sh` prüft das). Ziel-PHP: 8.4 (CLI + Web).**

Ersetzt den ioBroker-Adapter `fronius`. Fragt die Fronius Solar API v1 (JSON über HTTP) ab und veröffentlicht per MQTT.
Übergeordnete Regeln (Topics, Status, Versionierung): `../CLAUDE.md` und `../README.md`. Bei Widerspruch gelten die Regeln dieser Datei.

## Layout
- `fronius2lox/` = Inhalt des Plugin-ZIP (plugin.cfg, bin/, config/, cron/, daemon/, uninstall/, webfrontend/, *.sh)
- `../common/php/` = gemeinsamer Code (Util, Logger, MqttClient). `build.sh` kopiert ihn nach `bin/lib` des ZIP; nie aus einem anderen Plugin importieren
- `tests/` = `php tests/run.php` (Unit + Ende-zu-Ende mit Fake-Wechselrichter und Fake-Broker) – **nicht** im ZIP

## Harte Regeln
- Keine hartkodierten `/opt/loxberry`-Pfade. PHP: `loxberry_system.php` (`LBPCONFIGDIR` …); Shell/daemon/cron: Platzhalter `REPLACELBPBINDIR` usw.
- Dateien mit LF-Zeilenenden. `.gitattributes`/`.editorconfig` beachten.
- Plugin-Identität nie ändern (`AUTHOR.NAME` + `AUTHOR.EMAIL` + `PLUGIN.NAME` + `PLUGIN.FOLDER`), sonst entsteht beim Installieren ein zweites Plugin.
- Plugin-Ordner nie fest eintragen (kann `fronius2lox_000` heißen): Skripte nehmen ihn aus `$3` bzw. aus ihrem Ort. MQTT-Client-ID enthält den Ordner (`fronius_<Ordner>`).
- Weboberfläche und Dienst: `LBWeb::lbheader()` und `mqtt_connectiondetails()` überschreiben die globale Variable `$cfg`. Eigene Konfiguration heißt deshalb `$fro`.
- Laufzeitstatus (`status.json`, PID, Sperre) nur nach `/dev/shm/<Ordner>/`. Konfiguration atomar schreiben (Temp + rename), Rechte 0600.
- Kein MQTT-Paket als Abhängigkeit: eigener Client (`MqttClient`). Broker-Daten aus `mqtt_connectiondetails()` oder eigenem Broker.
- Die Solar API braucht keine Anmeldung; das Plugin hält **keine** Zugangsdaten außer dem optionalen Passwort eines eigenen MQTT-Brokers (nie loggen, nie per MQTT senden, nie anzeigen). Das Plugin ist rein **lesend**: keine Befehle, nichts in den Wechselrichter schreiben.
- Vorzeichen einheitlich mit den anderen Plugins: `netz_w` + Bezug / − Einspeisung (wie Fronius), `batterie_w` + Laden / − Entladen (Fronius `P_Akku` ist umgekehrt, daher negiert), `haus_w` positiv (Fronius `P_Load` ist negativ). Änderungen daran nur mit MAJOR-Version.
- Ohne gültigen Wert wird nichts gesendet (Fronius meldet nachts `null`); einzige Ausnahme `pv_w` = 0, solange der Energiefluss antwortet. Fällt ein Gerät aus: `verbunden` = 0 und `status/ok` = 0, `status/zaehler` läuft weiter; andere Geräte laufen weiter.
- **Nachtbetrieb:** Fronius-Wechselrichter schalten nachts ab (Symo/Primo: der Datamanager hängt am Wechselrichter). Nichterreichbarkeit nach geringer PV-Leistung (oder laut Sonnenstand, wenn die Leistung unbekannt ist) ist **kein Fehler**: `schlaeft` = 1, `verbunden` = 0, `pv_w` = 0, `status/ok` bleibt 1, nur Info-Log. Ausfall bei hoher Leistung bleibt ein Fehler. Nur der Fehlertext „nicht erreichbar“ kann Nacht sein, HTTP-/Antwortfehler (404, kaputtes JSON, Head-Fehler) nie. Bei Nichterreichbarkeit nur einmal pro Minute versuchen; Verbindungs-Timeout 5 s (`default_socket_timeout`!).
- Die Geräte (Zähler, Speicher, OhmPilot, Sensorkarte, String Control) erkennt das Plugin am Gerät selbst (`GetActiveDeviceInfo`), nicht per Konfiguration und nicht aus dem Internet. Das Plugin spricht nie mit Solarweb oder anderen Cloud-Diensten.
- Die Wertelisten stammen aus der Fronius-Doku (Solar API V1, 42,0410,2012, Dokumentversion 21). Nichts erfinden: Was die Doku nicht erklärt (z. B. Fehlercodes, LED-Codes, Sensorkanal-Namen), wird als Zahl bzw. Text des Geräts weitergereicht. Die Typliste `FroniusDevices.php` ist aus dem PDF erzeugt.
- Fronius verlangt mindestens 4 s zwischen Abfragen (Archiv: 120 s): Mindestintervall 5 s, Abfragen nacheinander mit Pause, langsame Daten nur alle 60 s, Archiv alle 300 s. Wiederholt scheiternde Abfragen zehn Minuten aussetzen.
- Zusatzabfragen (Wechselrichter-Details, Zähler, Speicher, Stammdaten usw.) dürfen scheitern, ohne den Durchgang zu stören (nur Debug-Log).
- Topic-Namen unter `fronius/` nie umbenennen (Loxone-Eingänge hängen daran), sonst MAJOR-Version erhöhen. Keine Leerzeichen in Topics.

## Entwicklung
- PHP lokal nötig für die Tests; fehlt es, das offen sagen und nichts als „getestet“ melden.
- Nicht lokal testbar (nur auf dem LoxBerry bzw. mit echtem Wechselrichter): LoxBerry-Bibliotheken (`LBWeb`, `LBLog`, `mqtt_connectiondetails`), Weboberfläche, echte Antworten verschiedener Modelle. Die Testdaten in `tests/fake_fronius.php` sind nach der Fronius-Dokumentation nachgebaut, nicht von einem echten Gerät mitgeschnitten.
- Dienst lokal: `FRO_DEV_DIR=<Ordner mit fronius.json> FRO_DEV_MQTT=127.0.0.1:18832 php fronius2lox/bin/froniusd.php` (Fake-Server: `php tests/fake_fronius.php 18951 <Ordner>`, `php tests/fake_mqtt.php 18832 <Ordner>`).
- Build: `bash build.sh` → `fronius2lox-<version>.zip`.

## Vorgaben aus dem LoxBerry-Entwicklerwiki (Stand 2026-11-10)
- `plugin.cfg`: `CUSTOM_LOGLEVELS=true` schaltet die Loglevel-Auswahl frei (der Installer liest nur diesen Schlüssel; `LOGLEVELS_ENABLED` gibt es nicht). Version dreiteilig ohne Buchstaben, `ARCHITECTURE` mit Werten immer in Anführungszeichen, `TITLE` höchstens 25 Zeichen.
- **Gateway-Abo:** `config/mqtt_subscriptions.cfg` ist **zeilenweise** (ein Topic je Zeile, z. B. `fronius/#`), kein INI-Format. Nur das Gateway V1 (Perl) liest sie; das **Gateway V2 (Python) liest sie nicht** (LoxBerry-Quellcode `sbin/mqtt_gateway.py`). Die Oberfläche prüft deshalb `config/system/subscriptions.json` (`GatewayInfo::subscription`) und sagt dem Benutzer, dass er das Abo im Gateway eintragen muss. Nie behaupten, das Plugin trage das Abo selbst ein.
- **UDP-Port** des Gateways nie fest eintragen: `mqtt_connectiondetails()['udpinport']` bzw. `general.json` (`Mqtt.Udpinport`), Standard 11884. Einfache Befehlsform `publish <topic> <wert>` (Leerzeichen im Topic nur in der JSON-Form). Das Gateway hat außerdem eine HTTP-Schnittstelle (`/admin/system/tools/mqtt.php`, mit LoxBerry-Anmeldung).
- Ungenutzte Installationsskripte (`preinstall.sh` usw.) und eine leere `dpkg/apt` nicht mitliefern (verzögert die Installation).
- Healthcheck: `bin/healthcheck` (Argumente `title` und `check`, Ausgabe als JSON mit `desc`, `status`, `result`, optional `logfile`; Status 3 Fehler = Benachrichtigung und E-Mail, 4 Warnung, 5 OK, 6 Info; schnell, nur lesend, nur das JSON ausgeben, Zeitzone setzen). Ein erwarteter Zustand (z. B. Fronius nachts abgeschaltet) ist nie Fehler oder Warnung.
- Weboberfläche: Menüleiste über `$navbar` (vor `LBWeb::lbheader()` definieren), nicht über eigene Links; Tests rendern jeden Reiter mit Attrappen der LoxBerry-Bibliotheken (`tests/stubs`, `tests/ui_page.php`).
- Keine festen `/opt/loxberry`-Pfade (auch nicht in Kommentaren: der Installer warnt dann), Platzhalter `REPLACELBP…` oder Konstanten verwenden.
