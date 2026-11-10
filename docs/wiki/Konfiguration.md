# Konfiguration

Reiter: **Einstellungen · MQTT · Einbindung in Loxone · Test · Logdateien**. Änderungen wirken ohne Neustart des Dienstes. Jeder Reiter speichert nur seine eigenen Einstellungen.

## Allgemein

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Abfrageintervall | 10 s | 5–300 s. Fronius verlangt mindestens 4 s zwischen zwei Abfragen. Je Durchgang gehen mehrere kleine Anfragen an den Wechselrichter (Energiefluss, Wechselrichter, 3-Phasen, Zähler …), zwischen ihnen liegen 150 ms Pause |
| Details je Wechselrichter | an | Spannungen, Ströme, Frequenz, Status, Phasenwerte (`3PInverterData`), Extremwerte (`MinMaxInverterData`) |
| Smart Meter | an | Alle Kanäle des Zählers (`zaehler/<Nr>/…`), nur wenn der Wechselrichter einen Zähler meldet |
| Batteriespeicher | an | Ladestand, Temperatur, Kapazität, Module (`speicher/<Nr>/…`), nur wenn ein Speicher gemeldet wird |
| Weitere Geräte | an | OhmPilot, Sensorkarte, String Control, nur wenn gemeldet |
| Datenlogger | an | Version, Zeitzone, LEDs, API-Version (nicht GEN24) |
| Archiv (Fehler, Ereignisse, Strings) | an | Letzter Fehler- und Ereigniscode des Tages und Spannung, Strom, Leistung von String 1 und 2 (`GetArchiveData`, nicht GEN24), alle 5 min. Die String-Werte sind Datenlogger-Werte im Aufzeichnungsintervall (5–30 min), keine Echtzeitwerte |
| Rohwerte | aus | Alle Felder der Antworten zusätzlich unter `fronius/<name>/raw/…` (nur bei Änderung, alle 5 min zur Auffrischung) |

**Welche Geräte vorhanden sind, fragt das Plugin beim Wechselrichter selbst ab** (`GetActiveDeviceInfo`, alle 60 s): Zähler, Speicher, OhmPilot, Sensorkarte und String Control werden nur abgefragt, wenn der Wechselrichter sie meldet. Abfragen, die wiederholt scheitern, setzt das Plugin zehn Minuten aus.

## Nachtbetrieb

Fronius-Wechselrichter (vor allem Symo und Primo mit Datamanager) schalten nachts ab; der Datamanager hängt am Wechselrichter und ist dann nicht erreichbar. Das ist normal und soll keinen Alarm auslösen.

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Nachtbetrieb | an | Aus: Jede Nichterreichbarkeit ist ein Fehler |
| Geringe Leistung bis | 100 W | War die PV-Leistung beim letzten erfolgreichen Abruf höchstens so hoch (abends), gilt der Ausfall als Nacht |
| Breitengrad / Längengrad | leer | Optional, z. B. `51.0` / `9.0`. Nur nötig, damit der Ausfall auch nach einem Neustart des Plugins mitten in der Nacht (letzte Leistung unbekannt) als normal erkannt wird: Die Sonne steht nicht oben (90 Minuten Toleranz um Auf- und Untergang) |

Im Nachtbetrieb gilt:

| Topic | Wert |
|---|---|
| `fronius/<name>/schlaeft` | 1 (sonst 0) |
| `fronius/<name>/verbunden` | 0 |
| `fronius/<name>/pv_w` | 0 (damit Loxone nicht den letzten Tageswert behält) |
| `fronius/<name>/wr/<Nr>/leistung_ac_w` | 0, `status_text` = `Offline` |
| `fronius/status/ok` | bleibt 1 |

Fällt der Wechselrichter dagegen bei hoher Leistung aus (tagsüber), ist das ein Fehler: `verbunden` = 0, `status/ok` = 0. Das Plugin versucht es bei Nichterreichbarkeit nur einmal pro Minute (ein ausgeschalteter Wechselrichter braucht keine Dauerabfrage) und holt beim Aufwachen Geräteliste, Stammdaten und Fehlerprotokoll sofort neu. Morgens dauert es, bis der Wechselrichter startet und der Datamanager gebootet hat; bis dahin bleibt `schlaeft` = 1.

## Wechselrichter

Bis zu vier Einträge.

| Spalte | Beschreibung |
|---|---|
| Status | Kästchen: grün = Solar API erreichbar, rot = Fehler (Text daneben), grau = kein Host |
| Aktiv | Nur aktive Einträge werden abgefragt |
| Name im Topic | `a-z 0-9 _ -`; leer = `wr1`, `wr2` … (Nummer der Zeile). Doppelte Namen bekommen `_2` |
| Host/IP, Port | Adresse des Wechselrichters bzw. Datamanagers (Port 80). Bei GEN24 ab Firmware 1.35 gibt es zusätzlich HTTPS; aus privaten Adressen (10.x, 172.16–31.x, 192.168.x) genügt HTTP, das Plugin nutzt HTTP |

Hängen mehrere Wechselrichter an **einem** Datamanager (Solar Net), genügt dessen Adresse: Alle Geräte erscheinen unter `wr/<Nr>/…`. Bei Gen24 hat jeder Wechselrichter seine eigene Adresse.

## Vorzeichen

| Wert | Vorzeichen |
|---|---|
| `netz_w` | + Bezug, − Einspeisung (wie Fronius) |
| `batterie_w` | + Laden, − Entladen (Fronius meldet umgekehrt; das Plugin dreht es, damit es zu Senec2Lox passt) |
| `haus_w` | immer positiv (Fronius meldet den Verbrauch negativ) |
| `zaehler/<Nr>/leistung_w` | wie vom Smart Meter, abhängig vom Einbauort: bei Netzübergabe (`standort_code` 0) + Bezug / − Einspeisung, im Verbrauchspfad (1) umgekehrt |

## MPP-Tracker per Modbus TCP (optional, Echtzeit)

Die Solar API kennt Spannung und Strom von **Tracker 2 bis 4** nur beim GEN24/Tauro/Verto. Beim Symo kommen String 1 und 2 deshalb aus dem Archiv (`string_<k>_…`, Werte im 5-Minuten-Raster). Wer sie **in Echtzeit** will, kann zusätzlich Modbus TCP nutzen (nur lesend, SunSpec-Modell 160 „Multiple MPPT“):

1. In der Weboberfläche des Datamanagers **Einstellungen → Modbus**: Datenausgabe über Modbus **tcp**, SunSpec-Modelltyp **int + SF**, Port 502.
2. Im Plugin (Reiter Einstellungen) „MPP-Tracker per Modbus TCP lesen“ anhaken und speichern.

Dann erscheinen `wr/<Nr>/spannung_dc_v`, `strom_dc_a`, `leistung_dc_w` (Tracker 1) und `…_dc_2_…` (Tracker 2) mit den gemessenen Werten; sie ersetzen die DC-Werte der Solar API. Die Unit-ID ist die Wechselrichter-Nummer. Fehler (Modbus aus, falscher Port, Wechselrichter schläft) stören die Solar-API-Abfrage nicht; `wr/<Nr>/modbus_ok` zeigt den Zustand, der Grund steht im Log. Das Plugin schreibt nie per Modbus.

## MQTT-Broker (Reiter MQTT)

Das **Gateway-Abo** steht als farbiges Feld oben im Reiter: **grün**, sobald mindestens ein passendes Abo im MQTT Gateway eingetragen ist (ist nur ein Teil der Topics abonniert, steht ein Hinweis dabei), **rot** nur, wenn keines eingetragen ist. Die eingetragenen Abos werden darunter aufgelistet.

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Eigenen MQTT-Broker verwenden | aus | Aus: Broker des LoxBerry (Zugangsdaten verwaltet LoxBerry) |
| Host/IP, Port | – / 1883 | Eigener Broker (ohne TLS) |
| Benutzer, Passwort | leer | Optional; das Passwort wird nie angezeigt oder geloggt |

Mit eigenem Broker erreichen die Werte den Miniserver nur, wenn das MQTT-Gateway diesen Broker abonniert.

## Test

| Aktion | Wirkung |
|---|---|
| Sofort abrufen | Zeigt alle Topics und Werte, die das Plugin gerade veröffentlichen würde |
| Selbstprüfung | Konfiguration, Solar API, Energiefluss, Broker, Gateway-Abo, Dienst |
| Dienst neu starten | Beendet und startet den Dienst |

Der Loglevel wird in der LoxBerry-Plugin-Verwaltung eingestellt.
