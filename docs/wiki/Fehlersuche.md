# Fehlersuche

Zuerst im Plugin den Reiter **Test** öffnen: **Selbstprüfung** und **Sofort abrufen**. Details stehen im Reiter **Logdateien** (`fronius.log`); den Loglevel stellt man in der LoxBerry-Plugin-Verwaltung ein.

| Symptom | Ursache / Lösung |
|---|---|
| Kästchen grau, „abgeschaltet (Nacht) – kein Fehler“ | Der Wechselrichter ist abgeschaltet, nachts normal (Nachtbetrieb). Morgens dauert es, bis er und der Datamanager wieder antworten |
| Nachts `status/ok` = 0 | Nachtbetrieb aus, oder die PV-Leistung war beim letzten Abruf höher als die eingestellte Schwelle (z. B. Ausfall mitten am Tag). Schwelle erhöhen oder Koordinaten eintragen; steht der Wert nach einem Neustart des Plugins nachts auf 0, hilft ebenfalls die Eingabe der Koordinaten |
| Tagsüber Fehler „nicht erreichbar“ | Echter Ausfall: IP/Host falsch, Netzwerk, Datamanager abgestürzt (Datamanager-Neustart in der Weboberfläche). Im Browser `http://<IP>/solar_api/GetAPIVersion.cgi` aufrufen |
| Kästchen rot, „nicht erreichbar“ | IP/Host falsch, Wechselrichter aus, Firewall. Im Browser `http://<IP>/solar_api/GetAPIVersion.cgi` aufrufen |
| „Solar API nicht gefunden (HTTP 404)“ | Gen24: Solar API im Wechselrichter freischalten (Kommunikation → Solar API) |
| „keine gueltige JSON-Antwort“ | Die Adresse gehört nicht zu einem Fronius-Wechselrichter (anderer Webserver) |
| „Fronius meldet Fehler …“ | Der Wechselrichter lehnt die Abfrage ab (z. B. nachts oder beim Start). Meist harmlos; bleibt es dauerhaft, Firmware prüfen |
| Keine Werte in MQTT | Wechselrichter auf **Aktiv**? Reiter MQTT und Selbstprüfung ansehen |
| Keine Werte in Loxone, aber in MQTT | **Gateway V2:** Das Abo `fronius/#` muss von Hand im MQTT Gateway unter „Abonnements“ stehen (V2 liest die Abo-Datei des Plugins nicht); der Reiter MQTT zeigt, ob es eingetragen ist |
| `haus_w`, `netz_w` fehlen | Ohne Smart Meter kennt der Wechselrichter weder Haus- noch Netzleistung (`Meter_Location` = `unknown`). Zähler im Wechselrichter einrichten |
| Kein `batterie_*` | Kein Speicher am Wechselrichter (Symo Non-Hybrid hat keinen) oder er meldet nichts (z. B. Standby) |
| `energie_tag_wh` fehlt (GEN24) | Der GEN24 meldet Tages- und Jahresenergie nicht (immer leer), den Gesamtwert alle 5 Minuten. Tagesenergie dann in Loxone oder mit dem PVOutput-Plugin aus der Leistung bilden |
| `typ_name` leer | Der Gerätetyp (`DT`) steht nicht in der Typliste der Doku (neues Modell). `info/typ` zeigt die Nummer |
| Fehlerprotokoll bleibt leer | GEN24 liefert kein Archiv (GetArchiveData). Beim Symo: Option „Fehler- und Ereignisprotokoll“ prüfen; Fronius verlangt 120 s Mindestabstand, das Plugin fragt alle 5 Minuten |
| `zaehler/…` ohne Messwerte, nur `sichtbar` = 0 | Der Zähler meldet laut Doku unvollständige oder veraltete Werte; Zählerverbindung am Wechselrichter prüfen |
| Keine `string_1_…`/`string_2_…` | Der Datenlogger liefert sie nur bei Symo/Primo/Hybrid (nicht GEN24) und nur, wenn die Option „Archiv“ an ist. Der Wert ist höchstens 45 Minuten alt; nach dem Aufwachen dauert es bis zum nächsten Aufzeichnungszeitpunkt (Raster 5–30 min). Echtzeitwerte: Modbus TCP, siehe [Konfiguration](Konfiguration.md) |
| `modbus_ok` = 0 | Modbus TCP im Datamanager einschalten (Einstellungen → Modbus: tcp, int + SF, Port 502), Port im Plugin prüfen; der Grund steht im Log (z. B. „Modbus-Ausnahme 11“ = Wechselrichter antwortet nicht, Unit-ID) |
| `wr/<Nr>/temperatur_umgebung_c`, Lüfterwerte fehlen | Die meisten Wechselrichter liefern diese Werte nicht (laut Doku nur wenige Modelle) |
| Kein `zaehler/…` oder `speicher/…` | Option im Reiter Einstellungen aus, oder der Wechselrichter meldet das Gerät nicht |
| `pv_w` ist nachts 0, Rest fehlt | Normal: Der Wechselrichter schläft, einzelne Felder sind dann leer und werden nicht gesendet |
| Batterie-Vorzeichen „falsch herum“ | Gewollt: + = Laden wie bei Senec2Lox. Fronius selbst meldet `P_Akku` umgekehrt |
| Werte kommen träge | Abfrageintervall senken (z. B. auf 5 s) |
| `status/ok` = 0 | Mindestens ein Gerät antwortet nicht. Name des Geräts und Grund stehen im Reiter MQTT und im Log |
| Dienst läuft nicht | Der Wächter startet ihn spätestens nach einer Minute; sonst `log/plugins/fronius2lox/start-error.log` ansehen oder „Dienst neu starten“ |
