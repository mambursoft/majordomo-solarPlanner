#!/usr/bin/env python3
"""
Generate the Solar Planner module icons as PNG.

Produces:
  img/modules/solarplanner.png        (128x128, used by MajorDoMo module list/UI)
  img/modules/solarplanner_32.png     (32x32, inline header)

Draws a sun, a battery and a small plan curve on a dark rounded tile, matching
the SVG in img/solarplanner.svg. Requires Pillow.

Usage: python scripts/make_icons.py
"""

import math
import os
from PIL import Image, ImageDraw

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT_DIR = os.path.join(ROOT, "img", "modules")

BG = (13, 27, 42, 255)       # #0d1b2a dark navy
SUN = (255, 209, 102, 255)   # #ffd166
BATT = (6, 214, 160, 255)    # #06d6a0
BATT2 = (42, 157, 143, 255)  # #2a9d8f
GRID = (17, 138, 178, 255)   # #118ab2


def rounded_rect(draw, box, radius, fill):
    draw.rounded_rectangle(box, radius=radius, fill=fill)


def draw_icon(size):
    # Render at 4x for anti-aliasing, then downscale.
    scale = 4
    s = size * scale
    img = Image.new("RGBA", (s, s), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)

    def u(v):
        return int(round(v / 96.0 * s))  # coordinates authored on a 96-grid

    # Background tile.
    rounded_rect(d, [0, 0, s - 1, s - 1], radius=u(16), fill=BG)

    # Sun.
    cx, cy, r = u(34), u(32), u(13)
    d.ellipse([cx - r, cy - r, cx + r, cy + r], fill=SUN)
    ray = u(3)
    for ang in range(0, 360, 45):
        a = math.radians(ang)
        x1 = cx + math.cos(a) * u(18)
        y1 = cy + math.sin(a) * u(18)
        x2 = cx + math.cos(a) * u(23)
        y2 = cy + math.sin(a) * u(23)
        d.line([x1, y1, x2, y2], fill=SUN, width=ray)

    # Battery (outline + bars).
    d.rounded_rectangle([u(54), u(52), u(84), u(82)], radius=u(4), outline=BATT, width=u(4))
    d.rounded_rectangle([u(60), u(48), u(78), u(54)], radius=u(2), fill=BATT)
    d.rounded_rectangle([u(59), u(60), u(79), u(66)], radius=u(2), fill=BATT)
    d.rounded_rectangle([u(59), u(70), u(79), u(76)], radius=u(2), fill=BATT2)

    # Plan curve.
    pts = [(u(14), u(78)), (u(20), u(68)), (u(28), u(74)), (u(38), u(60))]
    d.line(pts, fill=GRID, width=u(4), joint="curve")

    return img.resize((size, size), Image.LANCZOS)


def main():
    os.makedirs(OUT_DIR, exist_ok=True)
    draw_icon(128).save(os.path.join(OUT_DIR, "solarplanner.png"))
    draw_icon(32).save(os.path.join(OUT_DIR, "solarplanner_32.png"))
    print("Icons written to", OUT_DIR)


if __name__ == "__main__":
    main()
