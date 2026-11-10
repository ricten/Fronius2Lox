"""Erzeugt das Logo und die LoxBerry-Plugin-Icons im Stil von Senec2Lox / Nut2Lox.

Aufruf (aus dem Repo-Ordner):  python tools/make_logo.py
Benoetigt Pillow. Schrift: Arial Bold (Windows) bzw. DejaVu Sans Bold.

Ausgabe:
  docs/logo/fronius2lox.png                      gruener, runder LoxBerry-Stil
  fronius2lox/icons/icon_{64,128,256,512}.png     dasselbe als Plugin-Icons

Links der Fronius Symo (eigene, schematische Darstellung ohne Herstellerlogo und Schrift: hellgrauer Deckel, Display, dunkler Anschlussbereich),
rechts der Miniserver (wie bei Senec2Lox), dazwischen ein Pfeil, darunter der Name "Fronius2Lox".
"""
import math
import os
from PIL import Image, ImageDraw, ImageFont, ImageOps

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SS = 4  # Supersampling

G_LIGHT, G_DARK = (141, 198, 63), (63, 125, 16)        # Gruenverlauf
LABEL = "Fronius2Lox"
LABEL_WIDTH = 310      # Breite der Beschriftung im 512er-Raster
LABEL_BASE = 352       # Grundlinie
OX, OY = -17, 11        # Verschiebung der ganzen Gruppe (Geraete + Name), damit sie im Kreis mittig sitzt

INVERTER = (120, 140, 204, 252)            # x1, y1, x2, y2 (Fronius Symo, Hochformat wie auf dem Foto)
MINISERVER = (270, 152, 426, 240)          # x1, y1, x2, y2 (gruenes Gehaeuse, Draufsicht)
LINK_Y = 196                               # Verbindungslinie (Mitte beider Geraete)
LINK_X = (214, 256)


def miniserver_shapes():
    """Miniserver als gruenes Gehaeuse (Draufsicht), identisch zu Senec2Lox.

    Formen: ("rrect", x1, y1, x2, y2, radius, rgba) | ("circle", cx, cy, r, rgba) | ("ellipse", cx, cy, rx, ry, rgba) | ("line", x1, y1, x2, y2, breite, rgba)
    """
    x1, y1, x2, y2 = MINISERVER
    white = (255, 255, 255, 255)
    green = (104, 190, 64, 255)
    dark = (38, 48, 38, 255)
    teal, orange, blue = (38, 172, 172, 255), (242, 140, 28, 255), (40, 120, 200, 255)
    red = (226, 42, 72, 255)
    sh = []
    sh.append(("rrect", x1 + 70, y1 - 15, x1 + 98, y1 + 6, 0, white))
    sh.append(("rrect", x1 + 72, y1 - 13, x1 + 96, y1 + 6, 0, teal))
    sh.append(("rrect", x1 + 116, y1 - 15, x1 + 134, y1 + 6, 0, white))
    sh.append(("rrect", x1 + 118, y1 - 13, x1 + 132, y1 + 6, 0, orange))
    for i, c in enumerate((orange, blue, white)):
        tx = x1 + 12 + i * 15
        sh.append(("rrect", tx - 2, y2 - 4, tx + 10, y2 + 16, 0, white))
        sh.append(("rrect", tx, y2 - 4, tx + 8, y2 + 14, 0, c))
    sh.append(("rrect", x1 + 78, y2 - 6, x2 - 6, y2 + 12, 0, white))
    sh.append(("rrect", x1 + 80, y2 - 4, x2 - 8, y2 + 10, 0, red))
    for i in range(12):
        sh.append(("circle", x1 + 88 + i * 5.6, y2 + 3, 1.6, white))
    sh.append(("rrect", x1 - 3, y1 - 3, x2 + 3, y2 + 3, 2, white))
    sh.append(("rrect", x1, y1, x2, y2, 0, green))
    sh.append(("rrect", x1 + 52, y1 + 36, x2 - 14, y1 + 46, 0, dark))
    sh.append(("rrect", x1 + 60, y1 + 39, x1 + 92, y1 + 43, 0, (200, 210, 200, 255)))
    sh.append(("rrect", x2 - 50, y1 + 39, x2 - 22, y1 + 43, 0, (120, 135, 120, 255)))
    return sh


def inverter_shapes():
    """Fronius Symo schematisch nach dem Foto (ohne Herstellerlogo und ohne Schrift): Hochformat, hellgrauer Deckel mit Schrauben,
    roter Oval-Platzhalter in der Mitte, Display mit vier Tasten links unten, rote Textbalken rechts unten, dunkler Anschlussbereich
    mit Luftoeffnungen, Schrauben und Kabelverschraubung unten."""
    x1, y1, x2, y2 = INVERTER
    w, h = x2 - x1, y2 - y1
    cx = (x1 + x2) / 2
    white = (255, 255, 255, 255)
    grey = (194, 195, 190, 255)
    grey_hi = (212, 213, 208, 255)
    dark = (72, 78, 84, 255)
    dark_hi = (104, 110, 116, 255)
    red = (219, 45, 45, 255)
    screw = (132, 134, 130, 255)
    sh = []
    sh.append(("rrect", x1 - 3, y1 - 3, x2 + 3, y2 + 3, 12, white))                 # weisser Rahmen wie beim Miniserver
    sh.append(("rrect", x1, y1, x2, y2, 10, grey))                                    # Deckel
    sh.append(("rrect", x1 + 5, y1 + 3, x2 - 5, y1 + 7, 2, grey_hi))                  # Lichtkante oben
    for yy in (22, 62):                                                               # Schrauben an den Seiten
        sh.append(("circle", x1 + 4, y1 + yy, 1.9, screw))
        sh.append(("circle", x2 - 4, y1 + yy, 1.9, screw))
    # Fronius-Oval (nur roter Platzhalter, ohne Schriftzug) mit weissem Strich
    sh.append(("ellipse", cx, y1 + 40, 17, 6.5, red))
    sh.append(("rrect", cx - 9, y1 + 38.5, cx + 9, y1 + 41.5, 1.5, white))
    # Display links unten: dunkles Gehaeuse, helles LCD, vier Tasten
    sh.append(("rrect", x1 + 9, y1 + 67, x1 + 32, y1 + 87, 2.5, dark))
    sh.append(("rrect", x1 + 12, y1 + 69.5, x1 + 29, y1 + 78, 1.5, (214, 226, 232, 255)))
    for i in range(4):
        sh.append(("rrect", x1 + 12 + i * 4.4, y1 + 81, x1 + 15 + i * 4.4, y1 + 84.5, 1, (46, 50, 54, 255)))
    # rote Textbalken rechts unten (Platzhalter fuer den Schriftzug)
    sh.append(("rrect", x2 - 36, y1 + 72, x2 - 9, y1 + 75.5, 1, red))
    sh.append(("rrect", x2 - 44, y1 + 78, x2 - 9, y1 + 81.5, 1, red))
    # dunkler Anschlussbereich unten, unten abgerundet
    sh.append(("rrect", x1, y2 - 27, x2, y2, 10, dark))
    sh.append(("rrect", x1, y2 - 27, x2, y2 - 14, 0, dark))
    sh.append(("rrect", x1 + 4, y2 - 26, x2 - 4, y2 - 24.5, 1, dark_hi))
    for i in range(7):                                                                # Luftoeffnungen
        sh.append(("circle", x1 + 16 + i * ((w - 32) / 6), y2 - 17, 1.1, dark_hi))
    for sx in (6, w - 6):                                                             # Schrauben im Anschlussbereich
        sh.append(("circle", x1 + sx, y2 - 11, 1.9, screw))
    for sx in (w * 0.3, w * 0.7):
        sh.append(("circle", x1 + sx, y2 - 4, 1.7, (34, 36, 38, 255)))
    # Kabelverschraubung unten in der Mitte (ragt aus dem Gehaeuse)
    sh.append(("rrect", cx - 4.5, y2 - 3, cx + 4.5, y2 + 7, 2.5, (38, 41, 44, 255)))
    sh.append(("rrect", cx - 3, y2 + 4, cx + 3, y2 + 8, 1.5, (24, 26, 28, 255)))
    return sh


def _font(px: int) -> ImageFont.FreeTypeFont:
    for path in (r"C:\Windows\Fonts\arialbd.ttf", "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
                 "/Library/Fonts/Arial Bold.ttf"):
        if os.path.exists(path):
            return ImageFont.truetype(path, px)
    return ImageFont.load_default()


def label_px() -> float:
    """Schriftgroesse, bei der LABEL genau LABEL_WIDTH breit ist."""
    f = _font(200)
    try:
        w = ImageDraw.Draw(Image.new("L", (10, 10))).textlength(LABEL, font=f)
    except Exception:
        return 60.0
    return 200 * LABEL_WIDTH / w if w else 60.0


def render(size: int) -> Image.Image:
    k = size * SS / 512.0
    S = size * SS
    D = int(480 * k)
    off = int(16 * k)

    # Kreis mit Radialverlauf (Lichtpunkt oben links)
    g = Image.radial_gradient("L").resize((int(1.8 * D), int(1.8 * D)), Image.BICUBIC)
    left, top = int(0.9 * D - 0.40 * D), int(0.9 * D - 0.34 * D)
    g = g.crop((left, top, left + D, top + D))
    disc = ImageOps.colorize(g, G_LIGHT, G_DARK).convert("RGBA")
    mask = Image.new("L", (D, D), 0)
    ImageDraw.Draw(mask).ellipse((0, 0, D - 1, D - 1), fill=255)
    img = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    img.paste(disc, (off, off), mask)

    ov = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    d = ImageDraw.Draw(ov)

    def r(x1, y1, x2, y2):
        return [x1 * k, y1 * k, x2 * k, y2 * k]

    # dezenter Glanz oben und feiner Innenring
    d.ellipse(r(60, 24, 452, 250), fill=(255, 255, 255, 34))
    d.ellipse(r(34, 34, 478, 478), outline=(255, 255, 255, 70), width=max(1, int(3 * k)))

    def draw(shapes):
        for sh in shapes:
            if sh[0] == "rrect":
                _, x1, y1, x2, y2, rad, c = sh
                d.rounded_rectangle(r(x1 + OX, y1 + OY, x2 + OX, y2 + OY), radius=rad * k, fill=c)
            elif sh[0] == "circle":
                _, cx, cy, rr, c = sh
                d.ellipse(r(cx - rr + OX, cy - rr + OY, cx + rr + OX, cy + rr + OY), fill=c)
            elif sh[0] == "ellipse":
                _, cx, cy, rx, ry, c = sh
                d.ellipse(r(cx - rx + OX, cy - ry + OY, cx + rx + OX, cy + ry + OY), fill=c)
            else:
                _, x1, y1, x2, y2, w, c = sh
                d.line([((x1 + OX) * k, (y1 + OY) * k), ((x2 + OX) * k, (y2 + OY) * k)], fill=c, width=max(1, int(w * k)))

    draw(inverter_shapes())
    draw(miniserver_shapes())
    # Verbindung mit Pfeilspitze (Wechselrichter -> Miniserver)
    ly = (LINK_Y + OY) * k
    d.line([((LINK_X[0] + OX) * k, ly), ((LINK_X[1] - 4 + OX) * k, ly)], fill="white", width=int(7 * k))
    d.polygon([((LINK_X[1] + 8 + OX) * k, ly), ((LINK_X[1] - 6 + OX) * k, ly - 11 * k),
               ((LINK_X[1] - 6 + OX) * k, ly + 11 * k)], fill="white")
    # Beschriftung: Name in einer Zeile
    font = _font(int(label_px() * k))
    d.text((256 * k, (LABEL_BASE + OY) * k), LABEL, font=font, fill="white", anchor="ms")
    img = Image.alpha_composite(img, ov)
    return img.resize((size, size), Image.LANCZOS)


if __name__ == "__main__":
    logo_dir = os.path.join(ROOT, "docs", "logo")
    icon_dir = os.path.join(ROOT, "fronius2lox", "icons")
    os.makedirs(logo_dir, exist_ok=True)
    os.makedirs(icon_dir, exist_ok=True)
    render(512).save(os.path.join(logo_dir, "fronius2lox.png"))
    for s in (64, 128, 256, 512):
        render(s).save(os.path.join(icon_dir, "icon_%d.png" % s))
    print("Logo und Icons geschrieben")
