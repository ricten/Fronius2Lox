# Vorlage für das LoxBerry-Wiki

`fronius2lox_start.txt` ist die Seite für das [LoxBerry-Wiki](https://wiki.loxberry.de/plugins/start) im **DokuWiki-Format**
(Aufbau wie die Seite des Plugins [Sonos4Loxone](https://wiki.loxberry.de/plugins/sonos4loxone/start): Plugin-Daten, Überblick,
Konfiguration, Referenz, Miniserver-Anbindung, FAQ, Changelog).

## Vor dem Einstellen anpassen

- Der Link zum Forenbeitrag steht schon in der Zeile „Diskussion“ der Plugin-Daten (bei einem neuen Beitrag dort anpassen).
- Bild `plugins:fronius2lox:fronius2lox.png`: Datei `docs/logo/fronius2lox.png` im Medien-Manager des Wikis hochladen
  (Namensraum `plugins:fronius2lox`).
- Das Feld **Status** („BETA“) und **Min. LB Version** („4.0.0.0“) prüfen. Die Version steht in `fronius2lox/plugin.cfg`.
- **Release Download** muss genau **eine nackte ZIP-URL** sein (`…/releases/latest/download/fronius2lox.zip`, kein Link-Text, kein zweiter Link). Der App-Store des LoxBerry liest das Feld aus dem Wiki: steht dort etwas anderes (z. B. die Seite `…/releases/latest`), ist das Plugin im Shop nicht installierbar, nur das Repository lässt sich öffnen. **Min. LB Version** nur als Zahl (`4.0.0.0`); die PHP-Voraussetzung steht im Seitentext. Bei jedem Release nur **Version** und die Changelog-Tabelle nachziehen (die ZIP-URL bleibt gleich).
- Screenshots (z. B. Einstellungen, Test) lassen sich als `{{:plugins:fronius2lox:name.png?600|Text}}` einfügen.

## Einstellen

Die Seite gehört in den Namensraum `plugins:fronius2lox:start`. Das Anlegen und Bearbeiten im LoxBerry-Wiki setzt ein Wiki-Konto
bzw. die Freigabe durch das LoxBerry-Team voraus (Details im LoxBerry-Forum). Den Inhalt der Datei im Wiki-Editor einfügen.
