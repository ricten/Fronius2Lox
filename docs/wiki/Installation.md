# Installation

## Solar API im Wechselrichter freischalten

- **Gen24 (Symo GEN24, Primo GEN24, Tauro, Verto …):** Weboberfläche des Wechselrichters → **Kommunikation → Solar API** → „Solar API aktivieren“ (je nach Firmware heißt der Punkt „Solar API v1 aktivieren“). Ohne diese Freigabe antwortet der Wechselrichter mit HTTP 404.
- **Ältere Geräte (Symo, Primo mit Datamanager):** Die Solar API ist ab Werk aktiv.

Test im Browser: `http://<IP-des-Wechselrichters>/solar_api/GetAPIVersion.cgi` muss JSON mit `APIVersion` liefern.

**Symo und Primo (mit Datamanager):** Die Adresse ist die des Datamanagers (Steckkarte im Wechselrichter). Er bekommt seinen Strom vom Wechselrichter und ist nachts offline; das Plugin behandelt das als Normalfall (siehe [Konfiguration](Konfiguration.md), Nachtbetrieb). Am besten die IP des Datamanagers im Router fest vergeben.

## ZIP bauen

```bash
bash build.sh
```

Ergebnis: `fronius2lox-<version>.zip` (enthält nur den Inhalt von `fronius2lox/` plus die gemeinsamen Klassen, keine Tests und keine Doku). Fertige ZIPs gibt es unter den GitHub-Releases des Repos.

**Immer das neueste Release:** Der Link `https://github.com/ricten/Fronius2Lox/releases/latest/download/fronius2lox.zip` lädt das ZIP des jeweils letzten Releases (ohne Version im Dateinamen). Er lässt sich in der LoxBerry-Plugin-Verwaltung beim Installieren von einer Internetadresse eintragen und muss nach einem neuen Release nicht angepasst werden.

## Auf dem LoxBerry installieren

1. LoxBerry-Oberfläche → **Plugin-Verwaltung** → **Plugin installieren**.
2. ZIP hochladen und installieren. Die Installation startet den Dienst.
3. Plugin **Fronius2Lox** öffnen.
4. IP des Wechselrichters eintragen, **Aktiv** anhaken, **Speichern** (siehe [Konfiguration](Konfiguration.md)). Das Kästchen vor der Zeile zeigt, ob der Wechselrichter erreichbar ist.
5. Nach spätestens einem Abfrageintervall (Standard 10 s) erscheinen Werte unter `fronius/<name>/*` (im LoxBerry-MQTT-Finder prüfbar).

## Was die Installation einrichtet

| Teil | Ort (von LoxBerry gesetzt) |
|---|---|
| Dienst und Bibliotheken | `bin/plugins/fronius2lox/` |
| Konfiguration | `config/plugins/fronius2lox/fronius.json` (Rechte 0600) |
| Logdatei | `log/plugins/fronius2lox/fronius.log` |
| Live-Status (RAM) | `/dev/shm/fronius2lox/status.json` |
| Autostart | Daemon beim Boot (root → startet als `loxberry`) |
| Watchdog | Cronjob jede Minute startet den Dienst, falls er nicht läuft |

Das Plugin ändert nichts außerhalb seiner eigenen Ordner.

## Update

Beim Update sichern `preupgrade.sh`/`postupgrade.sh` **Konfiguration und Daten** und spielen sie zurück. Neue Konfigurationsschlüssel ergänzt der Dienst beim Laden automatisch mit Standardwerten.

### Automatische Updates

Ab Version 0.5.0 nutzt Fronius2Lox die Update-Funktion der LoxBerry-Plugin-Verwaltung. In der `plugin.cfg` steht der Verweis auf die Datei `release.cfg` im GitHub-Repository (`ricten/Fronius2Lox`, Zweig `main`). LoxBerry prüft sie täglich und vergleicht die dort eingetragene Version mit der installierten.

- **Neue Installationen** stehen in der Plugin-Verwaltung bei „Automatische Updates“ auf **Releases**: eine neuere Version wird selbst heruntergeladen und installiert.
- **Schon installierte Plugins** bekommen den Verweis erst, wenn einmal von Hand (ZIP oder Link) mindestens Version 0.5.0 installiert wurde. Danach lässt sich die Einstellung in der Plugin-Verwaltung ändern: *Aus*, *Benachrichtigen* oder *Releases*.
- Konfiguration und Daten bleiben wie bei jedem Update erhalten (siehe oben).
- Die `release.cfg` zeigt immer auf das ZIP der jeweils letzten Version; sie wird erst nach dem GitHub-Release angepasst, damit nie auf ein fehlendes ZIP verwiesen wird.

## Deinstallation

Plugin in der Plugin-Verwaltung entfernen. Der Dienst wird beendet und `/dev/shm/fronius2lox` gelöscht. Am Wechselrichter wird nichts verändert.
