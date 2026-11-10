# MQTT-Topics

Schema `fronius/<name>/<wert>`, `<name>` = Name des Wechselrichters aus den Einstellungen (Standard `wr1`). Zahlen mit Punkt und ohne Einheit, Zustände 0/1, nicht retained. Werte ohne Inhalt werden nicht gesendet. Grundlage ist die offizielle **Fronius Solar API V1** (Dokument 42,0410,2012, Version 21); welche Felder ein Gerät wirklich liefert, hängt von Modell und Firmware ab.

`<Nr>` ist die Geräte- bzw. Kanalnummer aus der Solar API (bei einem Wechselrichter meist `1`, beim Zähler `0`).

## Energiefluss (`GetPowerFlowRealtimeData`)

| Topic | Einheit | Fronius-Feld | Bemerkung |
|---|---|---|---|
| `pv_w` | W | `P_PV` | nachts 0 |
| `haus_w` | W | `P_Load` | positiv (Fronius: negativ); nur mit Smart Meter |
| `netz_w` | W | `P_Grid` | + Bezug / − Einspeisung |
| `netz_bezug_w`, `netz_einspeisung_w` | W | aus `P_Grid` | je ≥ 0 |
| `batterie_w` | W | `P_Akku` | + Laden / − Entladen (negiert) |
| `batterie_laden_w`, `batterie_entladen_w` | W | aus `P_Akku` | je ≥ 0 |
| `batterie_soc` | % | `Inverters.<Nr>.SOC` | vom ersten Wechselrichter mit Speicher |
| `batterie_standby`, `notstrom` | 0/1 | `BatteryStandby`, `BackupMode` | |
| `string_1_spannung_v`, `string_1_strom_a`, `string_1_leistung_w`, `string_1_zeit` (ebenso `string_2_…`) | V, A, W, Unix-Zeit | Archivkanäle `Voltage_DC_String_<k>`, `Current_DC_String_<k>` (`GetArchiveData`; Symo/Primo/Hybrid, **nicht GEN24**). Das sind die **Werte des Datenloggers im Aufzeichnungsintervall** (5 bis 30 Minuten), keine Echtzeitwerte; `…_zeit` ist der Zeitpunkt des Werts. Die Leistung ist Spannung × Strom (berechnet). Werte, die älter als 45 Minuten sind, werden nicht gesendet. Das ist dieselbe Quelle, aus der der ioBroker-Adapter String 2 bekommt |
| `mppt_anzahl`, `mppt_<k>_name`, `modbus_ok` | – | nur mit **Modbus** (siehe unten): Zahl der MPP-Tracker, Name (z. B. `String 1`), 1/0 = Modbus-Abfrage klappt |
| `leistung_dc_w`, `leistung_dc_2_w` … `_4_w`, `leistung_dc_summe_w` | W | **berechnet** aus `UDC` × `IDC` je MPP-Tracker (Tracker 1 = `UDC`/`IDC`); mit Modbus der gemessene Wert (`DCW`). Fronius liefert über die Solar API keine DC-Leistung. Tracker 2 bis 4 gibt es nur, wenn der Wechselrichter `UDC_2`/`IDC_2` … meldet (laut Doku nur GEN24/Tauro/Verto); die Summe nur ab zwei gemeldeten Trackern |
| `energie_tag_wh`, `energie_jahr_wh`, `energie_gesamt_wh` | Wh | `E_Day`, `E_Year`, `E_Total` | GEN24: Tages-/Jahreswert immer leer, Gesamtwert alle 5 min |
| `autarkie`, `eigenverbrauch` | % | `rel_Autonomy`, `rel_SelfConsumption` | nur mit Smart Meter |
| `modus` | Text | `Mode` | `produce-only`, `meter`, `vague-meter`, `bidirectional`, `ac-coupled` |
| `zaehler_standort` | Text | `Meter_Location` | `grid`, `load`, `unknown` |
| `info/powerflow_version` | – | `Version` | Format-Version der Antwort |
| `wr/<Nr>/leistung_ac_w` | W | `Inverters.<Nr>.P` | AC-Leistung |
| `wr/<Nr>/energie_tag_wh`, `energie_jahr_wh`, `energie_gesamt_wh` | Wh | `Inverters.<Nr>.E_*` | |
| `wr/<Nr>/komponenten_id` | – | `CID` | |
| `wr/<Nr>/info/typ`, `typ_name` | – / Text | `DT` | `typ_name` aus der Typliste der Doku (6.2) |
| `wr/<Nr>/batterie_soc`, `batterie_modus` | %, Text | `SOC`, `Battery_Mode` | nur Hybrid-Wechselrichter |
| `ohmpilot/<ID>/leistung_w`, `temperatur_c`, `status_text` | W, °C, Text | `Smartloads.Ohmpilots` | ID = Komponenten-ID (z. B. 720897) |
| `ohmpilot_eco/<ID>/leistung_w`, `temperatur_1_c`, `temperatur_2_c`, `status_heizstab1`, `status_heizstab2` | | `Smartloads.OhmpilotEcos` | nur GEN24 |
| `zusatzzaehler/<ID>/leistung_w` | W | `SecondaryMeters.<ID>.P` | Vorzeichen nach Einbauort |
| `zusatzzaehler/<ID>/standort_code`, `standort_text`, `name`, `kategorie` | | `MLoc`, `Label`, `Category` | z. B. `METER_CAT_HEATPUMP` |

## Status des Plugins

| Topic | Bedeutung |
|---|---|
| `verbunden` | 0/1: Wechselrichter liefert Werte |
| `schlaeft` | 1 = Wechselrichter abgeschaltet (Nacht), kein Fehler; sonst 0 |
| `fronius/status/ok` | 1 = alle Geräte liefern Werte **oder** sind nachts abgeschaltet; der Broker setzt 0, wenn der Dienst abbricht |
| `fronius/status/zaehler` | Lebenszeichen 0–999, zählt je Durchgang |
| `fronius/status/ts` | Zeitstempel des letzten Durchgangs |

Im Nachtbetrieb (siehe [Konfiguration](Konfiguration)) setzt das Plugin außerdem `pv_w` = 0, `wr/<Nr>/leistung_ac_w` = 0 und `wr/<Nr>/status_text` = `Offline`.

## Wechselrichter `wr/<Nr>/…` (Option „Details je Wechselrichter“)

`GetInverterRealtimeData` mit den Sammlungen `CommonInverterData` (enthält die Werte von `CumulationInverterData`), `3PInverterData` und `MinMaxInverterData`. Energiewerte in kWh werden zu Wh umgerechnet.

| Topic | Einheit | Fronius-Feld |
|---|---|---|
| `leistung_ac_w`, `scheinleistung_va` | W, VA | `PAC`, `SAC` |
| `spannung_ac_v`, `strom_ac_a`, `frequenz_hz` | V, A, Hz | `UAC`, `IAC`, `FAC` |
| `spannung_dc_v`, `strom_dc_a` | V, A | `UDC`, `IDC` |
| `spannung_dc_2_v` … `_4_v`, `strom_dc_2_a` … `_4_a` | V, A | `UDC_2` … `UDC_4`, `IDC_2` … `IDC_4` (MPP-Tracker, nur GEN24/Tauro/Verto) |
| `energie_tag_wh`, `energie_jahr_wh`, `energie_gesamt_wh` | Wh | `DAY_ENERGY`, `YEAR_ENERGY`, `TOTAL_ENERGY` (Non-Hybrid: laut Fronius ungenau; GEN24: Tag/Jahr leer) |
| `status_code`, `status_text` | – | `DeviceStatus.StatusCode`: 0–6 Startet, 7 Betrieb, 8 Standby, 9 Bootloader, 10 Fehler, 11 Leerlauf, 12 Bereit, 13 Schläft, 255 Unbekannt |
| `zustand` | Text | `DeviceStatus.InverterState` (GEN24, z. B. `Running`) |
| `fehler_code` | – | `DeviceStatus.ErrorCode` (0 = kein Fehler) |
| `led_farbe_code`, `led_zustand_code` | – | `DeviceStatus.LEDColor`, `LEDState` (Codes laut Gerät, in der Doku nicht erklärt) |
| `timer_restzeit_s`, `fehler_quittierbar` | s, 0/1 | `MgmtTimerRemainingTime`, `StateToReset` |
| `strom_l1_a` … `strom_l3_a`, `spannung_l1_v` … `spannung_l3_v` | A, V | `IAC_L1` … `IAC_L3`, `UAC_L1` … `UAC_L3` (nur dreiphasig) |
| `temperatur_umgebung_c` | °C | `T_AMBIENT` (die meisten Geräte nicht) |
| `luefter_vorne_links`, `luefter_vorne_rechts`, `luefter_hinten_links`, `luefter_hinten_rechts` | – | `ROTATION_SPEED_FAN_FL/FR/BL/BR` (nur CL, XL, IG 500/400) |
| `tag_leistung_max_w`, `jahr_…`, `gesamt_…` | W | `DAY_PMAX`, `YEAR_PMAX`, `TOTAL_PMAX` (alle 60 s) |
| `tag_spannung_ac_max_v`, `…_ac_min_v`, `…_dc_max_v`, `jahr_…`, `gesamt_…` | V | `*_UACMAX`, `*_UACMIN`, `*_UDCMAX` |
| `info/name`, `typ`, `typ_name`, `seriennummer` | Text | `GetInverterInfo`: `CustomName`, `DT`, `UniqueID` (alle 60 s) |
| `info/pv_nennleistung_wp`, `sichtbar`, `status_code`, `status_text`, `fehler_code`, `zustand` | | `PVPower`, `Show`, `StatusCode`, `ErrorCode`, `InverterState` |

## Fehler und Ereignisse (Option „Fehler- und Ereignisprotokoll“, nicht GEN24)

Aus `GetArchiveData` (heutiger Tag, alle 5 Minuten). Die Bedeutung der Fehler- und Statuscodes steht in den Fronius-Betriebsanleitungen, nicht in der Solar-API-Doku.

| Topic | Bedeutung |
|---|---|
| `wr/<Nr>/fehler_letzter_code` | Code des letzten Fehlers heute |
| `wr/<Nr>/fehler_letzter_zeit` | Zeitpunkt (Unix-Zeit) |
| `wr/<Nr>/fehler_letzter_flags` | Flags, z. B. `fatal,official` |
| `wr/<Nr>/fehler_anzahl_heute` | Anzahl Fehler heute |
| `wr/<Nr>/ereignis_letzter_code`, `ereignis_letzter_text`, `ereignis_letzte_zeit` | letztes Ereignis des Wechselrichters |
| `fehler_anzahl_heute`, `ereignis_anzahl_heute` | Summe über alle Geräte; immer vorhanden, auch 0 |
| `ereignis_letzter_code`, `ereignis_letzter_text`, `ereignis_letzte_zeit` | letztes allgemeines Ereignis (z. B. „Power limitation 20%“) |

## Smart Meter `zaehler/<Nr>/…` (`GetMeterRealtimeData`)

Vorzeichen und Richtung hängen vom Einbauort ab: Bei **Netzübergabe** (`standort_code` 0) ist `leistung_w` positiv beim Bezug und negativ bei Einspeisung; im **Verbrauchspfad** (1) ist es umgekehrt (positiv = erzeugt). Bei Fremderzeugern (3) ist positiv = Erzeugung, bei Unterverbrauchern (256–511) negativ = Verbrauch.

| Topic | Einheit | Fronius-Feld |
|---|---|---|
| `leistung_w`, `scheinleistung_va`, `blindleistung_var`, `leistungsfaktor` | W, VA, var | `PowerReal_P_Sum`, `PowerApparent_S_Sum`, `PowerReactive_Q_Sum`, `PowerFactor_Sum` |
| `leistung_l1_w` … `l3`, `scheinleistung_l1_va` …, `blindleistung_l1_var` …, `leistungsfaktor_l1` … | | `PowerReal_P_Phase_n`, `PowerApparent_S_Phase_n`, `PowerReactive_Q_Phase_n`, `PowerFactor_Phase_n` |
| `spannung_l1_v` … `l3`, `spannung_l1_l2_v`, `spannung_l2_l3_v`, `spannung_l3_l1_v`, `spannung_mittel_v` | V | `Voltage_AC_Phase_n`, `Voltage_AC_PhaseToPhase_12/23/31`, `Voltage_AC_Phase_Average` |
| `strom_l1_a` … `l3`, `strom_summe_a` | A | `Current_AC_Phase_n`, `Current_AC_Sum` |
| `frequenz_hz` | Hz | `Frequency_Phase_Average` |
| `energie_bezug_wh`, `energie_einspeisung_wh` | Wh | `EnergyReal_WAC_Sum_Consumed`, `…_Produced` (Sicht des Zählers) |
| `energie_plus_absolut_wh`, `energie_minus_absolut_wh` | Wh | `EnergyReal_WAC_Plus_Absolute`, `…_Minus_Absolute` (Sicht von Solar.web) |
| `energie_l1_verbraucht_wh`, `energie_l1_erzeugt_wh` … | Wh | `EnergyReal_WAC_Phase_n_Consumed/Produced` |
| `blindenergie_verbraucht_varh`, `blindenergie_erzeugt_varh`, `blindenergie_l1_…` | varh | `EnergyReactive_VArAC_*` |
| `aktiv`, `sichtbar`, `zeitstempel` | 0/1, 0/1, s | `Enable`, `Visible`, `TimeStamp` |
| `standort_code`, `standort_text` | – | `Meter_Location_Current`: 0 Netzübergabe, 1 Verbrauchspfad, 3 Fremderzeuger, 4 Fremderzeuger mit Batterie, 256–511 Unterverbraucher |
| `info/hersteller`, `modell`, `seriennummer` | Text | `Details` |

Meldet der Zähler `Visible` = 0 (laut Doku unvollständige oder veraltete Werte), sendet das Plugin nur `aktiv` und `sichtbar`.

## Batteriespeicher `speicher/<Nr>/…` (`GetStorageRealtimeData`, nicht Non-Hybrid)

| Topic | Einheit | Fronius-Feld |
|---|---|---|
| `soc` | % | `StateOfCharge_Relative` |
| `spannung_v`, `strom_a`, `temperatur_c` | V, A, °C | `Voltage_DC`, `Current_DC` (+ Laden), `Temperature_Cell` |
| `kapazitaet_wh`, `nennkapazitaet_wh` | Wh | `Capacity_Maximum`, `DesignedCapacity` |
| `spannung_zelle_max_v`, `spannung_zelle_min_v`, `zyklen`, `temperatur_max_c`, `temperatur_min_c` | | `Voltage_DC_*_Cell`, `CycleCount_BatteryCell`, `Temperature_Cell_Maximum/Minimum` |
| `aktiv`, `zeitstempel` | | `Enable`, `TimeStamp` |
| `status_code`, `status_text` | – | `Status_BatteryCell`; Text nur für BYD (0 Standby, 1 Inaktiv, 2 Darkstart, 3 Aktiv, 4 Fehler, 5 Update) und LG (1 Standby, 3 Aktiviert, 5 Fehler, 10 Schlaf) |
| `module_anzahl` | | Anzahl Module (nur Fronius Solar Battery) |
| `modul/<n>/…` | | je Modul dieselben Werte; `status_aktuell_text`, `status_vorher_text` aus `Status_BatteryCell` (Nibble: hoch = aktuell, niedrig = vorher) |
| `info/hersteller`, `modell`, `seriennummer` | Text | `Details` |

## Weitere Geräte (Option „Weitere Geräte“)

| Topic | Fronius-Feld |
|---|---|
| `ohmpilot/<Nr>/leistung_w`, `energie_verbraucht_wh`, `temperatur_c` | `PowerReal_PAC_Sum`, `EnergyReal_WAC_Sum_Consumed`, `Temperature_Channel_1` |
| `ohmpilot/<Nr>/status_code`, `status_text`, `fehler_code` | `CodeOfState`: 0 Betrieb, 1 Mindesttemperatur halten, 2 Legionellenschutz, 3 kritischer Fehler, 4 Fehler, 5 Boost; `CodeOfError` |
| `ohmpilot/<Nr>/info/hersteller`, `modell`, `seriennummer`, `software`, `hardware` | `Details` |
| `sensor/<Nr>/kanal_<n>`, `kanal_<n>_einheit` | `GetSensorRealtimeData` NowSensorData |
| `sensor/<Nr>/kanal_<n>_tag_max`, `_tag_min`, `_monat_…`, `_jahr_…`, `_gesamt_…`, `kanal_<n>_aktiv` | MinMaxSensorData (alle 60 s) |
| `sensor/<Nr>/info/kanalnamen` | Kanalnamen der Karte, kommagetrennt |
| `string/<Nr>/strom_<k>` | `GetStringRealtimeData` NowStringControlData |
| `string/<Nr>/summe_tag_<k>_ah`, `summe_jahr_<k>_ah`, `summe_gesamt_<k>_ah` | CurrentSumStringControlData |
| `string/<Nr>/fehler_zeit`, `fehler_mittelwert`, `fehler_kanal_<k>_abweichung_prozent`, `fehler_kanal_<k>_summe_ah` | LastErrorStringControlData |

Die Kanalnummern der Sensorkarte (`<n>`) sind die Nummern der Solar API; die Doku ordnet den Nummern keine festen Namen zu (Beispiel: 0 und 1 Temperatur, 2 Einstrahlung). `info/kanalnamen` zeigt die Namen, die das Gerät meldet.

## Datenlogger (Option „Datenlogger“, nicht GEN24)

| Topic | Fronius-Feld |
|---|---|
| `logger/seriennummer`, `produkt`, `plattform`, `hw_version`, `sw_version` | `UniqueID`, `ProductID`, `PlatformID`, `HWVersion`, `SWVersion` |
| `logger/zeitzone_ort`, `zeitzone_name`, `utc_offset_s`, `sprache` | `TimezoneLocation`, `TimezoneName`, `UTCOffset`, `DefaultLanguage` |
| `logger/einspeisefaktor`, `kostenfaktor`, `waehrung`, `co2_faktor`, `co2_einheit` | `DeliveryFactor`, `CashFactor`, `CashCurrency`, `CO2Factor`, `CO2Unit` |
| `logger/led_power_farbe`, `led_power_zustand`, `led_solarnet_…`, `led_solarweb_…`, `led_wlan_…` | `GetLoggerLEDInfo`: Farbe red/green/none, Zustand on/off/blinking/alternating |
| `info/api_version`, `info/api_kompatibel` | `GetAPIVersion`: `APIVersion`, `CompatibilityRange` |
| `info/anzahl_wechselrichter`, `anzahl_zaehler`, `anzahl_speicher`, `anzahl_ohmpilot`, `anzahl_sensorkarten`, `anzahl_stringcontrol` | `GetActiveDeviceInfo` (gerade online gemeldete Geräte) |
| `<Klasse>/<Nr>/info/seriennummer` | `Serial` aus `GetActiveDeviceInfo` |

## Rohwerte (Option „Rohwerte“)

`raw/<abfrage>/<pfad>` mit Pfad in Kleinbuchstaben, z. B. `raw/powerflow/site/p_pv`. Abfragen: `powerflow`, `wechselrichter/<Nr>`, `wechselrichter_3p/<Nr>`, `wechselrichter_minmax/<Nr>`, `zaehler`, `speicher`, `ohmpilot`, `sensor/<Nr>`, `sensor_minmax/<Nr>`, `string/<Nr>`, `string_summe_<zeitraum>/<Nr>`, `string_fehler/<Nr>`, `geraete`, `info`, `logger`, `logger_led`, `archiv`. Nur bei Änderung gesendet, alle 5 Minuten aufgefrischt.

## Nicht übernommen

Die Archivabfrage `GetArchiveData` bietet außerdem Tages- und Detailreihen für Energie, Strom, Spannung und Temperatur (nicht GEN24, 120 s Mindestabstand, nur gespeicherte Vergangenheit). Das Plugin nutzt sie nur für Fehler und Ereignisse; die aktuellen Werte liefern die Realtime-Abfragen. Auch `GetInverterRealtimeData` mit `Scope=System` (Werte je Gerät in einer Antwort) ist nicht nötig, da dieselben Werte je Gerät abgefragt werden.
