# Loxone-Anbindung

Der Weg: **Wechselrichter → Fronius2Lox → LoxBerry-MQTT-Broker → LoxBerry-MQTT-Gateway → Miniserver (virtuelle Eingänge)**.

## 1. MQTT-Gateway einrichten

**Abonnement `fronius/#` im MQTT Gateway:** Beim **Gateway V1** (Perl) trägt das Plugin es über die Datei `mqtt_subscriptions.cfg` selbst ein (eine Zeile je Topic). Das **Gateway V2** (Python) liest diese Datei nicht (LoxBerry-Quellcode): Dort das Abo **von Hand** im MQTT Gateway unter „Abonnements“ eintragen: `fronius/#`. Das Plugin prüft im Reiter *MQTT* und in der Selbstprüfung, ob es eingetragen ist. Mit **eigenem Broker** (Reiter MQTT) bekommt der Miniserver die Werte nur, wenn das Gateway diesen Broker abonniert.

Für Lebenszeichen und Fehlererkennung die Topics `fronius/status/ok` und `fronius/status/zaehler` im Gateway auf **No Cache** stellen.

## 2. Virtuelle Eingänge in Loxone Config

Das Gateway ersetzt `/` durch `_`. Aus `fronius/wr1/pv_w` wird der Eingang `fronius_wr1_pv_w`. Die Tabelle aller Topics samt Eingangsnamen zeigt die Plugin-Oberfläche im Reiter **Einbindung in Loxone**. Typische Eingänge:

| Eingang | Bedeutung |
|---|---|
| `fronius_wr1_pv_w` | PV-Leistung (W), nachts 0 |
| `fronius_wr1_haus_w` | Hausverbrauch (W) |
| `fronius_wr1_netz_w` | Netz (W): + Bezug / − Einspeisung |
| `fronius_wr1_netz_einspeisung_w` | Einspeisung (W, ≥ 0) |
| `fronius_wr1_batterie_w` | Batterie (W): + Laden / − Entladen |
| `fronius_wr1_batterie_soc` | Ladestand (%) |
| `fronius_wr1_energie_tag_wh` | Erzeugung heute (Wh) |
| `fronius_wr1_wr_1_status_code` | Wechselrichter-Status (7 = Betrieb) |
| `fronius_wr1_verbunden` | 1 = Wechselrichter erreichbar |
| `fronius_status_ok` | 1 = alle Geräte liefern Werte |
| `fronius_status_zaehler` | Lebenszeichen 0…999 |

Hinweise:

- **Ausfallerkennung:** `fronius_status_zaehler` ändert sich bei jedem Durchgang (Standard alle 10 s). Bleibt er stehen, läuft der Dienst nicht mehr.
- **Vorzeichen:** `batterie_w` und `netz_w` sind „+ = zum Haus hin bzw. in die Batterie hinein“ wie bei Senec2Lox: Bezug und Laden sind positiv. Pläne und Bausteine lassen sich dadurch zwischen Senec und Fronius vertauschen.
- **Nachts** schaltet der Wechselrichter ab (Symo/Primo: der Datamanager hängt am Wechselrichter und ist dann nicht erreichbar). Das Plugin wertet das als Normalfall: `fronius_wr1_schlaeft` = 1, `fronius_wr1_verbunden` = 0, `fronius_wr1_pv_w` = 0, `fronius_status_ok` bleibt 1. Alarm in Loxone besser auf `fronius_status_ok` = 0 bauen (echter Fehler, auch tagsüber) statt auf `verbunden`. Fehlende Werte werden nicht gesendet; die Eingänge behalten ihren letzten Wert (z. B. `energie_tag_wh` bis zum nächsten Morgen).
- **Fehler und Status:** `fronius_wr1_wr_1_status_text` (Betrieb, Standby, Fehler, Schläft …), `fronius_wr1_wr_1_fehler_code` (0 = kein Fehler), `fronius_wr1_fehler_anzahl_heute`, `fronius_wr1_wr_1_fehler_letzter_code`. Ein Fehlercode ungleich 0 oder ein neuer letzter Fehler eignet sich gut für eine Meldung. Die Bedeutung der Codes steht in der Fronius-Betriebsanleitung.
- **Zählervorzeichen:** `zaehler/<Nr>/leistung_w` folgt dem Einbauort des Zählers (Netzübergabe: + Bezug / − Einspeisung). Für den Netzbezug im Haus ist `fronius_wr1_netz_w` bequemer; dessen Vorzeichen ist immer gleich.
- Keine Leerzeichen in Gerätenamen verwenden, sonst findet das Gateway die Eingänge nicht.

Dieses Plugin hat keine Befehle: Die Solar API ist nur lesend. Die Batteriesteuerung (Lade-/Entladegrenzen) ist damit nicht möglich.
