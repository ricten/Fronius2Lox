<?php
/**
 * Nachtbetrieb: Der Fronius-Wechselrichter (und mit ihm der Datamanager, der an ihm haengt) schaltet nachts ab.
 * Dann ist die Solar API nicht erreichbar - das ist normal und kein Fehler.
 *
 * Regel: Nichterreichbarkeit gilt als "Nacht" (erwartet), wenn
 *  - die PV-Leistung vor dem Ausfall hoechstens `schwelle_w` betrug (die Leistung faellt abends langsam auf 0, bevor das Geraet abschaltet), oder
 *  - keine letzte Leistung bekannt ist (Dienst frisch gestartet), Koordinaten eingetragen sind und die Sonne laut Sonnenstand nicht oben steht.
 * Faellt das Geraet dagegen bei hoher Leistung aus (tagsueber), bleibt es ein Fehler.
 */
final class FroniusNight
{
    /** Toleranz um Sonnenauf- und -untergang in Minuten (Wechselrichter startet/stoppt etwas versetzt). */
    private const int TOLERANZ_MIN = 90;

    public static function expected(array $nacht, ?float $lastPv, int $now): bool
    {
        if (empty($nacht['aktiv'])) {
            return false;
        }
        if ($lastPv !== null) {
            return $lastPv <= (float)$nacht['schwelle_w'];
        }
        return self::sunDown($nacht, $now);
    }

    /** Steht die Sonne laut Koordinaten (mit Toleranz) nicht oben? Ohne Koordinaten false. */
    public static function sunDown(array $nacht, int $now): bool
    {
        if ($nacht['breite'] === '' || $nacht['laenge'] === '') {
            return false;
        }
        $i = date_sun_info($now, (float)$nacht['breite'], (float)$nacht['laenge']);
        $up = $i['sunrise'] ?? null;
        $down = $i['sunset'] ?? null;
        if ($up === true) {          // Polartag
            return false;
        }
        if ($up === false || $down === false) {      // Polarnacht
            return true;
        }
        if (!is_int($up) || !is_int($down)) {
            return false;
        }
        $tol = self::TOLERANZ_MIN * 60;
        return $now < $up + $tol || $now > $down - $tol;
    }
}
