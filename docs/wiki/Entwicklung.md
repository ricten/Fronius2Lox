# Entwicklung

## Aufbau

```
fronius2lox/                Inhalt des Plugin-ZIP
├── plugin.cfg
├── postinstall.sh preupgrade.sh postupgrade.sh
├── uninstall/uninstall  daemon/daemon  cron/cron.01min
├── bin/
│   ├── froniusd.php        Dienst (Abfrage, MQTT)
│   ├── healthcheck         LoxBerry-Healthcheck (Dienst, Broker, Quelle)
│   ├── start.sh stop.sh
│   └── lib/                 FroniusClient, FroniusMapper, FroniusConfig, bootstrap, AppVersion
│                            + Util, Logger, MqttClient (aus ../common/php, kopiert build.sh)
├── config/mqtt_subscriptions.cfg
├── webfrontend/htmlauth/index.php
└── icons/
tests/                       Unit-/Ende-zu-Ende-Tests und Fake-Server (nicht im ZIP)
tools/make_logo.py           Logo und Plugin-Icons (Stil wie Senec2Lox)
docs/                        Wiki, Logo (nicht im ZIP)
```

Der gemeinsame Code liegt außerhalb des Repos in `loxberry-plugins/common/php/`. `build.sh` kopiert ihn ins ZIP; kein Plugin importiert aus einem anderen.

## Klassen

| Klasse | Aufgabe |
|---|---|
| `FroniusClient` | HTTP-Abfragen der Solar API (JSON, Fehlertexte, Erreichbarkeitsprüfung) |
| `FroniusMapper` | Wandelt Antworten in Topics um (Vorzeichen, Einheiten, `null`-Behandlung, Rohwerte) |
| `FroniusConfig` | Standardwerte, Laden, Prüfen der Eingaben, Geräteliste |

## Abfragen je Durchgang

| Abfrage | Wann |
|---|---|
| `GetPowerFlowRealtimeData.fcgi` | immer; schlägt sie fehl, gilt das Gerät als nicht erreichbar (Nacht oder Fehler, siehe unten) |
| `GetInverterRealtimeData.cgi` (Scope Device, `CommonInverterData`, `3PInverterData`) | je Wechselrichter, jeder Durchgang, Option |
| `GetInverterRealtimeData.cgi` (`MinMaxInverterData`) | je Wechselrichter, alle 60 s, Option |
| `GetMeterRealtimeData.cgi` (Scope System) | wenn `GetActiveDeviceInfo` einen Zähler meldet, Option |
| `GetStorageRealtimeData.cgi` (Scope System) | wenn ein Speicher gemeldet wird, Option |
| `GetOhmPilotRealtimeData.cgi`, `GetSensorRealtimeData.cgi` (NowSensorData), `GetStringRealtimeData.cgi` (NowStringControlData) | wenn das Gerät gemeldet wird, Option „Weitere Geräte“ |
| `GetActiveDeviceInfo.cgi?DeviceClass=System`, `GetInverterInfo.cgi` | alle 60 s und nach dem Aufwachen |
| `GetAPIVersion.cgi`, `GetLoggerInfo.cgi`, `GetLoggerLEDInfo.cgi` | alle 60 s, Option „Datenlogger“ |
| Sensorkarte MinMax, String Control Summen (Tag/Jahr/Gesamt) und letzter Fehler | alle 60 s |
| `GetArchiveData.cgi` (Channel InverterErrors und InverterEvents, heute) | alle 300 s, Option „Fehler- und Ereignisprotokoll“ (Fronius: mindestens 120 s Abstand) |

Fehler bei den Zusatzabfragen werden nur im Debug-Log vermerkt; nach drei Fehlschlägen in Folge setzt das Plugin die Abfrage zehn Minuten aus. Nach der Fronius-Doku (2.6) sind höchstens zwei Realtime-Abfragen gleichzeitig erlaubt und mindestens 4 s Abstand zwischen zwei Aufrufen vorgesehen; das Plugin arbeitet strikt nacheinander (mit 150 ms Pause) und erlaubt kein Intervall unter 5 s.

## Nachtbetrieb

`FroniusNight::expected()` entscheidet, ob eine Nichterreichbarkeit normal ist: PV-Leistung beim letzten Abruf ≤ Schwelle (steht in `/dev/shm/<Ordner>/lastpv.json`, übersteht Neustarts des Dienstes) oder, wenn sie unbekannt ist, laut Koordinaten Nacht (`date_sun_info`, 90 Minuten Toleranz). Nur Fehler der Art „nicht erreichbar“ (kein HTTP-Fehler, keine Fehlerantwort) können als Nacht gelten. Während der Nichterreichbarkeit versucht der Dienst es einmal pro Minute (`FRO_DEV_RETRY` in den Tests).

## Tests

```bash
php tests/run.php        # Unit + Ende-zu-Ende (Dienst gegen Fake-Wechselrichter und Fake-Broker)
```

`tests/run.php` prüft zusätzlich den **Healthcheck** (`bin/healthcheck`, alle Zustände) und rendert **jeden Reiter der Weboberfläche** mit Attrappen der LoxBerry-Bibliotheken (`tests/stubs/`, `tests/ui_page.php`): Das findet Laufzeitfehler, prüft Menüleiste, Gateway-Abo und UDP-Port. Das echte Aussehen in LoxBerry (Design, jQuery Mobile) lässt sich so nicht prüfen. Der gemeinsame Code `GatewayInfo` hat eigene Tests: `php ../common/tests/run.php`.

`tests/run.php` startet `tests/fake_fronius.php` (zwei Instanzen) und `tests/fake_mqtt.php` selbst. Geprüft werden Zahlenformat, Mapper (Vorzeichen, Einheiten, `null`), Konfiguration, Client gegen den Fake-Wechselrichter (auch Head-Fehler, 404, kein JSON) und der komplette Dienst: Werte, Details, Zähler, Speicher, Gerät ohne Zähler/Speicher, Nacht, Ausfall und Erholung, Rohwerte nur bei Änderung, Konfigurationsänderung im Betrieb.

**Nicht lokal testbar** (nur auf dem LoxBerry bzw. mit echtem Wechselrichter): LoxBerry-Bibliotheken (`LBWeb`, `LBLog`, `mqtt_connectiondetails`), die Weboberfläche, echte Antworten verschiedener Modelle. Die Testantworten sind nach der Fronius-Dokumentation nachgebaut.

## Dienst lokal starten

```bash
php tests/fake_fronius.php 18951 /tmp/frotest &
php tests/fake_mqtt.php 18832 /tmp/frotest &
mkdir -p /tmp/frotest   # fronius.json mit Gerät 127.0.0.1:18951 ablegen
FRO_DEV_DIR=/tmp/frotest FRO_DEV_MQTT=127.0.0.1:18832 php fronius2lox/bin/froniusd.php
```

Umgebungsvariablen im Entwicklungsmodus: `FRO_DEV_DIR` (Basisverzeichnis für Konfiguration, Log, Status; erlaubt außerdem Intervalle ab 1 s), `FRO_DEV_FOLDER`, `FRO_DEV_RUN`, `FRO_DEV_MQTT=host:port`, `FRO_DEV_LOGLEVEL`, `FRO_DEV_RETRY` (Sekunden zwischen Versuchen bei Nichterreichbarkeit, Standard 60), `FRO_DEV_ARCHIV` (Sekunden zwischen Archivabfragen, Standard 300). Den Fake-Wechselrichter steuert die Datei `fro_mode_<port>.txt`: `ok` (GEN24 mit Speicher), `symo` (Symo Non-Hybrid mit Zähler, OhmPilot, Sensorkarte, String Control, Archiv), `symo_abend` (nur noch 20 W), `aus` (abgeschaltet: Verbindung wird ohne Antwort geschlossen), `nacht`, `ohne`, `404`, `kaputt`.

## Build und Release

1. Version in `fronius2lox/plugin.cfg` **und** `fronius2lox/bin/lib/AppVersion.php` erhöhen (Bugfix → PATCH, Funktion → MINOR, inkompatible Topics → MAJOR).
2. Eintrag in `CHANGELOG.md` (`## [x.y.z] – Datum`).
3. `bash build.sh` prüft Versionen und Changelog, kopiert den gemeinsamen Code, prüft PHP-/Shell-Syntax und erzeugt `fronius2lox-<version>.zip` (alte ZIPs werden gelöscht).
4. Tag `vX.Y.Z` setzen, pushen, GitHub-Release mit dem ZIP anlegen.

## Wichtige Regeln

Siehe [CLAUDE.md](../../CLAUDE.md): keine festen Pfade (`REPLACELBPBINDIR` …), Plugin-Identität nie ändern, eigene Konfiguration nie `$cfg` nennen (LoxBerry überschreibt sie), Laufzeitstatus nur in `/dev/shm`, nur lesend auf den Wechselrichter zugreifen.
