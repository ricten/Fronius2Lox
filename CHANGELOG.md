# Changelog

Alle nennenswerten Änderungen an Fronius2Lox. Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [Semantic Versioning](https://semver.org/lang/de/) (`MAJOR.MINOR.PATCH`).

Release-Ablauf: Version in `fronius2lox/plugin.cfg` und `fronius2lox/bin/lib/AppVersion.php` erhöhen, hier einen Eintrag ergänzen,
`bash build.sh` ausführen (prüft die Übereinstimmung), danach Git-Tag `vX.Y.Z` setzen.

## [Unveröffentlicht]

## [0.5.0] – 2026-11-10

### Neu
- **ZIP ohne Version im Namen** (`<name>.zip`) an jedem Release: `…/releases/latest/download/<name>.zip` zeigt immer auf das neueste Release (`bash build.sh` erzeugt beide ZIPs).
- **Automatische Updates:** `plugin.cfg` verweist auf `release.cfg` im Repo (`AUTOMATIC_UPDATES=true`, `RELEASECFG`). Neue Installationen bekommen damit Release-Updates; bereits installierte Versionen erhalten den Verweis erst durch die Neuinstallation von 0.5.0. `bash build.sh release-cfg` schreibt `release.cfg` für die gebaute Version (erst nach dem GitHub-Release).

### Geändert
- Weboberfläche im **LoxBerry Design System** (`lb-*`-Klassen, `lbheader(…, true)`) statt jQuery Mobile: Schalter, Karten, Tabellen und Knöpfe im Stil der LoxBerry-Seiten, Farben folgen dem gewählten Theme (auch dunkel). Formularfelder, Feldnamen und Speichern sind unverändert.
- Tests: Oberfläche prüft nojqm, `lb-content` und das Fehlen von jQuery-Mobile-Attributen.
- Doku: Seitenlinks in `docs/wiki/` funktionieren im Repository (mit `.md`), Abschnitt „Automatische Updates“ in `Installation.md` und `README.md`.

## [0.4.0] – 2026-11-10

### Neu
- **String 1 und 2 aus dem Archiv der Solar API** (`GetArchiveData`, Kanäle `Voltage_DC_String_<k>` und `Current_DC_String_<k>`; Symo/Primo/Hybrid, nicht GEN24): `wr/<Nr>/string_<k>_spannung_v`, `string_<k>_strom_a`, `string_<k>_leistung_w` (berechnet) und `string_<k>_zeit`. Das sind Datenlogger-Werte im Aufzeichnungsintervall (5–30 min), keine Echtzeitwerte; Werte über 45 Minuten Alter werden nicht gesendet. Die Solar API liefert Tracker 2 sonst nur beim GEN24, dort aber als Echtzeitwert (`spannung_dc_2_v`).
- **Optional Modbus TCP** für die MPP-Tracker in Echtzeit (SunSpec-Modell 160, nur lesend, standardmäßig aus): `spannung_dc_v`, `strom_dc_a`, `leistung_dc_w` und `…_dc_2_…` mit den gemessenen Werten (ersetzen die DC-Werte der Solar API), dazu `mppt_anzahl`, `mppt_<k>_name`, `modbus_ok`. Im Datamanager muss Modbus TCP eingeschaltet sein (Einstellungen → Modbus: tcp, int + SF, Port 502). Fehler stören die Solar-API-Abfrage nicht; nach drei Fehlern wird fünf Minuten pausiert.
- Fake-Modbus-Server und Tests für Modbus, SunSpec-Auswertung und die Archiv-Strings.

### Geändert
- `plugin.cfg`: `WEBSITE` verweist auf das GitHub-Repository (Link in der Plugin-Verwaltung).
- Die Option „Fehler- und Ereignisprotokoll“ heißt jetzt „Archiv“ und umfasst auch die String-Werte (gleiche Abfrage alle 5 Minuten, Fronius verlangt mindestens 120 s Abstand).

## [0.3.0] – 2026-11-10

### Neu
- **DC-Leistung je MPP-Tracker:** `wr/<Nr>/leistung_dc_w` (Tracker 1), `leistung_dc_2_w` … `leistung_dc_4_w` und `leistung_dc_summe_w` (ab zwei Trackern). Fronius liefert nur Spannung und Strom; die Leistung wird daraus berechnet (U × I, eine Nachkommastelle). Tracker 2 bis 4 erscheinen nur, wenn der Wechselrichter sie meldet (laut Fronius-Doku nur GEN24/Tauro/Verto, beim Symo evtl. nicht).

## [0.2.3] – 2026-11-10

Abgleich mit dem LoxBerry-Entwicklerwiki und dem LoxBerry-Quellcode.

### Behoben
- **Loglevel in der Plugin-Verwaltung:** Die Auswahl steuert in `plugin.cfg` der Schlüssel `CUSTOM_LOGLEVELS` (der Installer liest `LOGLEVELS_ENABLED` nicht). Er steht jetzt auf `true`, der überflüssige Schlüssel ist entfernt. Bei einer Aktualisierung erscheint die Auswahl, Standard ist Stufe 3.
- **Abo-Datei `mqtt_subscriptions.cfg`:** Das Gateway liest sie zeilenweise (ein Topic je Zeile), nicht als INI-Datei. Sie enthält jetzt nur `fronius/#`. Vorher meldete das Gateway V1 die Zeilen `[fronius]`, `TOPIC=…` und `NAME=…` als Abos an und `fronius/#` selbst nicht.
- **Gateway-Abo wird jetzt wirklich geprüft** (Reiter MQTT und Selbstprüfung). Das Gateway V2 liest die Abo-Datei von Plugins nicht (laut LoxBerry-Quellcode): Dort muss `fronius/#` im MQTT Gateway unter „Abonnements“ von Hand eingetragen werden. Die Prüfung liest die Abo-Liste des Gateways und erklärt, was fehlt. Vorher zeigte die Prüfung „hinterlegt“, sobald die Datei existierte.
- **UDP-Port des Gateways** wird aus den MQTT-Verbindungsdaten gelesen (`udpinport`) statt fest 11884 anzunehmen; Oberfläche, Anleitung für Loxone Config und Test der Befehlsbrücke zeigen den echten Port.

### Neu
- **Healthcheck** (`bin/healthcheck`): Die LoxBerry-Healthcheck-Seite zeigt den Zustand des Plugins (Dienst, MQTT-Broker, Quelle). Ein toter Dienst ist ein Fehler und löst die tägliche Benachrichtigung samt E-Mail aus; Ausfälle der Quelle sind eine Warnung; nicht eingerichtet ist eine Info.
- **Menüleiste** der LoxBerry-Oberfläche (`$navbar`) statt eigener Reiter.
- Neuer gemeinsamer Code `GatewayInfo` (Gateway-Version, UDP-Port, Abo-Prüfung) in `common/php`.

### Geändert
- **Gateway-Abo im Reiter MQTT als farbiges Feld:** grün, sobald mindestens ein passendes Abo im Gateway eingetragen ist (auch wenn nur ein Teil der Topics abonniert ist; dann mit Hinweis), rot nur, wenn keines eingetragen ist. Die eingetragenen Abos werden darunter aufgelistet.
- Leeres `preinstall.sh` entfernt (die Doku rät, ungenutzte Installationsskripte nicht mitzuliefern).

## [0.2.2] – 2026-11-09

### Geändert
- Logo: Der Fronius Symo links ist jetzt dem echten Gerät nachempfunden: Hochformat, hellgrauer Deckel mit Schrauben, roter Oval-Platzhalter, Display mit vier Tasten links unten, rote Textbalken, dunkler Anschlussbereich mit Luftöffnungen und Kabelverschraubung (weiterhin ohne Herstellerlogo und ohne Schrift).

## [0.2.1] – 2026-11-09

### Geändert
- Logo: links jetzt der **Fronius Symo** (schematisch, ohne Herstellerlogo: weißes Querformat-Gehäuse mit Display, Tasten, Anschlussbereich und DC-Trenner) statt eines allgemeinen Wechselrichters.

## [0.2.0] – 2026-11-09

Vollständiger Umfang der offiziellen Solar-API-Dokumentation (Fronius Solar API V1, 42,0410,2012, Dokumentversion 21) und Nachtbetrieb für Wechselrichter, die nachts abschalten (z. B. Fronius Symo 15.0-3-M mit Datamanager).

### Neu
- **Nachtbetrieb:** Der Datamanager hängt am Wechselrichter und ist nachts ohne Strom. Nichterreichbarkeit nach geringer PV-Leistung (Standard ≤ 100 W, einstellbar) gilt als „Nacht“, nicht als Fehler: `schlaeft` = 1, `verbunden` = 0, `pv_w` = 0, `wr/<Nr>/leistung_ac_w` = 0, `wr/<Nr>/status_text` = Offline, `status/ok` bleibt 1. Fällt das Gerät bei hoher Leistung aus (tagsüber), bleibt es ein Fehler. Optional Breiten-/Längengrad: erkennt die Nacht auch nach einem Neustart des Plugins (Sonnenstand, 90 Minuten Toleranz). Im Ausfall wird nur jede Minute neu versucht; beim Aufwachen werden Geräteliste, Stammdaten und Archiv sofort neu geholt.
- **Geräteerkennung am Gerät selbst** (`GetActiveDeviceInfo`): Welche Zähler, Speicher, OhmPilots, Sensorkarten und String Controls es gibt, fragt das Plugin beim Wechselrichter ab (alle 60 s) und fragt nur vorhandene Geräte ab. Anzahl und Seriennummern unter `info/anzahl_*` und `…/info/seriennummer`.
- **Wechselrichter** (`GetInverterRealtimeData`): zusätzlich Scheinleistung, MPP-Tracker 2–4 (GEN24), **3PInverterData** (Strom/Spannung je Phase, Umgebungstemperatur, Lüfterdrehzahlen) und **MinMaxInverterData** (Tages-, Jahres-, Gesamtextremwerte). Status mit allen offiziellen Codes (0–6 Startet, 7 Betrieb, 8 Standby, 9 Bootloader, 10 Fehler, 11 Leerlauf, 12 Bereit, 13 Schläft, 255 Unbekannt), `zustand` (GEN24-Text), Fehlercode, LED-Codes, Management-Timer, „Fehler quittierbar“.
- **Gerätetyp** (`GetInverterInfo` + Typliste der Doku, 199 Modelle): `info/typ` und `info/typ_name` (z. B. „Fronius Symo 15.03M“), Name (HTML-Kodierung des Datamanagers wird aufgelöst), Seriennummer, PV-Nennleistung, Sichtbarkeit.
- **Energiefluss** (`GetPowerFlowRealtimeData`): zusätzlich Energie je Wechselrichter, Komponenten-ID, OhmPilot und OhmPilot Eco (Smartloads), **Zusatzzähler** (Unterverbraucher, Fremderzeuger mit Name und Kategorie), Format-Version.
- **Smart Meter** (`GetMeterRealtimeData`): jetzt alle dokumentierten Kanäle: Phasenwerte für Wirk-/Schein-/Blindleistung, Leistungsfaktor, Spannung (auch Außenleiter), Strom, absolute Zähler (Plus/Minus), Energie je Phase, Blindenergie, Frequenz, Einbauort mit Klartext, `aktiv`/`sichtbar`/Zeitstempel. Bei `Visible` = 0 (Doku: unvollständig/veraltet) werden keine Messwerte gesendet.
- **Speicher** (`GetStorageRealtimeData`): Controller und **Module** (Zellspannungen, Zyklen, Temperaturextreme), Statustexte für BYD und LG, Zellstatus (aktuell/vorher) der Fronius Solar Battery.
- **OhmPilot** (`GetOhmPilotRealtimeData`), **Sensorkarte** (Jetzt- und Extremwerte je Kanal, Kanalnamen), **String Control** (Ströme, Tages-/Jahres-/Gesamtsummen, letzter Fehler).
- **Datenlogger** (`GetLoggerInfo`, `GetLoggerLEDInfo`, `GetAPIVersion`): Produkt, Versionen, Zeitzone, Faktoren, LED-Zustände.
- **Fehler- und Ereignisprotokoll** (`GetArchiveData`, nur Nicht-GEN24): letzter Fehlercode samt Zeit und Flags, Anzahl der Fehler und Ereignisse heute, letztes Ereignis mit Text (z. B. Leistungsbegrenzung). Abfrage alle 5 Minuten (Fronius verlangt mindestens 120 s Abstand).
- Neue Optionen: Weitere Geräte, Datenlogger, Archiv, Nachtbetrieb.

### Geändert
- Mindestabfrageintervall 5 s (Fronius: mindestens 4 s zwischen zwei Abfragen); selten ändernde Daten nur alle 60 s. Zwischen den Zusatzabfragen 150 ms Pause.
- Unsinnswerte (Betrag > 1e13, die Doku zeigt 8,4e252 bei einem GEN24 Primo) werden verworfen. GEN24 ohne Batterie meldet bis Firmware 1.17 fälschlich SOC 0: wird nicht gesendet.
- Abfragen, die wiederholt scheitern (z. B. Speicher beim Non-Hybrid), werden zehn Minuten ausgesetzt.
- Verbindungs-Timeout 5 s (vorher bis 60 s, wenn der Wechselrichter keine Antwort schickt).
- Fehlermeldungen im Log werden je Fehlertext gedrosselt (ein neuer Fehlertext wird sofort gemeldet).
- Meter-Einbauort: nach der ausführlichen Beschreibung der Doku (4.8) gilt 0 = Netzübergabe, 1 = Verbrauchspfad. Tabelle 6.5 der Doku nennt beides umgekehrt.

### Behoben
- Ein leeres Port-Feld wurde zu Port 1 statt 80.
- Komponenten-IDs mit mehr als fünf Stellen (OhmPilot 720897) wurden verworfen.

## [0.1.0] – 2026-11-09

Erste Version. Ersetzt den ioBroker-Adapter `fronius`.

### Neu
- Fragt die **Fronius Solar API v1** (JSON über HTTP, ohne Anmeldung) von bis zu **4 Wechselrichtern bzw. Datamanagern** ab und veröffentlicht die Werte per MQTT.
- **Energiefluss** (`GetPowerFlowRealtimeData`): `pv_w`, `haus_w`, `netz_w` (+ Bezug / − Einspeisung) samt `netz_bezug_w`/`netz_einspeisung_w`, `batterie_w` (+ Laden / − Entladen, wie Senec2Lox) samt Lade-/Entladeleistung, `batterie_soc`, Energie heute/Jahr/gesamt, Autarkie, Eigenverbrauch, Betriebsart, Standby, Notstrom.
- **Wechselrichter-Details** (`CommonInverterData`): AC-/DC-Spannung und -Strom, Leistung, Frequenz, Energiewerte, Status mit Klartext, Fehlercode.
- **Smart Meter** (Gesamtleistung, je Phase Leistung/Spannung/Strom, Frequenz, Zählerstände Bezug/Einspeisung) und **Batteriespeicher** (Ladestand, Spannung, Strom, Temperatur, Kapazität), jeweils nur wenn der Wechselrichter sie meldet.
- Stammdaten (Name, Typ, Seriennummer, Nennleistung, API-Version) alle 60 s.
- Option **Rohwerte**: alle Felder der Antworten zusätzlich unter `fronius/<name>/raw/…` (nur bei Änderung).
- Nachts meldet der Wechselrichter „null“ für die PV-Leistung: `pv_w` wird dann 0, andere fehlende Werte werden nicht gesendet.
- Fehlerbehandlung: Wechselrichter nicht erreichbar, Solar API nicht freigeschaltet (HTTP 404), Fehler in der Antwort → `verbunden` = 0, `status/ok` = 0, Lebenszeichen läuft weiter; ein ausgefallenes Gerät stört die anderen nicht.
- MQTT-Broker des LoxBerry oder **eigener Broker**.
- Weboberfläche: Einstellungen mit Erreichbarkeitsanzeige, MQTT, Einbindung in Loxone (Topic-Tabelle), Test (Sofort abrufen, Selbstprüfung), Logdateien.
- Topics `fronius/status/ok|zaehler|ts`.
