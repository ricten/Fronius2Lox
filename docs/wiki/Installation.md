# Installation

## Solar API im Wechselrichter freischalten

- **Gen24 (Symo GEN24, Primo GEN24, Tauro, Verto …):** Weboberfläche des Wechselrichters → **Kommunikation → Solar API** → „Solar API aktivieren“ (je nach Firmware heißt der Punkt „Solar API v1 aktivieren“). Ohne diese Freigabe antwortet der Wechselrichter mit HTTP 404.
- **Ältere Geräte (Symo, Primo mit Datamanager):** Die Solar API ist ab Werk aktiv.

Test im Browser: `http://<IP-des-Wechselrichters>/solar_api/GetAPIVersion.cgi` muss JSON mit `APIVersion` liefern.

**Symo und Primo (mit Datamanager):** Die Adresse ist die des Datamanagers (Steckkarte im Wechselrichter). Er bekommt seinen Strom vom Wechselrichter und ist nachts offline; das Plugin behandelt das als Normalfall (siehe [Konfiguration](Konfiguration), Nachtbetrieb). Am besten die IP des Datamanagers im Router fest vergeben.

## ZIP bauen

```bash
bash build.sh
```

Ergebnis: `fronius2lox-<version>.zip` (enthält nur den Inhalt von `fronius2lox/` plus die gemeinsamen Klassen, keine Tests und keine Doku). Fertige ZIPs gibt es unter den GitHub-Releases des Repos.

## Auf dem LoxBerry installieren

1. LoxBerry-Oberfläche → **Plugin-Verwaltung** → **Plugin installieren**.
2. ZIP hochladen und installieren. Die Installation startet den Dienst.
3. Plugin **Fronius2Lox** öffnen.
4. IP des Wechselrichters eintragen, **Aktiv** anhaken, **Speichern** (siehe [Konfiguration](Konfiguration)). Das Kästchen vor der Zeile zeigt, ob der Wechselrichter erreichbar ist.
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

## Deinstallation

Plugin in der Plugin-Verwaltung entfernen. Der Dienst wird beendet und `/dev/shm/fronius2lox` gelöscht. Am Wechselrichter wird nichts verändert.
