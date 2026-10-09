"""Builds all mascot sprites from the generated sheet: cut out, paint the sunglasses into the side view, export.

    python3 tools/mascot/build.py <sheet.jpg>

Writes the full-size sources to tools/mascot/ and the web sprites to assets/img/mascot/.
Rule: the bull always wears his sunglasses (the side view of the sheet has none – painted here).
"""
import os, sys
import numpy as np
from PIL import Image, ImageDraw, ImageFilter, ImageOps
sys.path.insert(0, os.path.dirname(__file__))
from cutout import PANELS, cut

ROOT = os.path.normpath(os.path.join(os.path.dirname(__file__), '..', '..'))
SRC = os.path.join(ROOT, 'tools', 'mascot')
OUT = os.path.join(ROOT, 'assets', 'img', 'mascot')


def glasses(img, eye):
    """Dark sunglasses over the eye of the side view (facing right). eye = (x, y) of the eye centre in image pixels."""
    k = 4
    w, h = img.size
    layer = Image.new('RGBA', (w * k, h * k), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    ex, ey = eye
    import math
    P = lambda dx, dy: ((ex + dx) * k, (ey + dy) * k)
    # slightly slanted, rounded lens (flatter on top), centred a bit in front of the eye
    lens = []
    for i in range(48):
        t = 2 * math.pi * i / 48
        x, y = 36 * math.cos(t), 23 * math.sin(t)
        if y < 0:
            y *= 0.8
        lens.append((x * math.cos(-0.12) - y * math.sin(-0.12) + 2, x * math.sin(-0.12) + y * math.cos(-0.12) + 4))
    d.polygon([P(*p) for p in lens], fill=(14, 18, 26, 255), outline=(8, 10, 16, 255))
    d.line([P(-33, -2), P(-56, -4)], fill=(14, 18, 26, 255), width=3 * k)           # temple arm towards the ear
    d.polygon([P(-18, -9), P(14, -13), P(12, -8), P(-19, -4)], fill=(150, 165, 185, 190))   # shine
    layer = layer.resize((w, h), Image.LANCZOS)
    out = img.copy()
    # only where the figure is opaque, so the temple arm doesn't stick out of the helmet
    a = np.array(layer)[..., 3].astype(np.float32) * (np.array(img)[..., 3].astype(np.float32) / 255)
    layer.putalpha(Image.fromarray(a.astype(np.uint8)))
    out.alpha_composite(layer)
    return out


def export(img, name, height):
    w = round(img.width * height / img.height)
    small = img.resize((w, height), Image.LANCZOS)
    small.save(os.path.join(OUT, name + '.webp'), quality=88, method=6)


if __name__ == '__main__':
    sheet = np.array(Image.open(sys.argv[1]).convert('RGB'))
    views = {}
    for name, (x0, y0, x1, y1) in PANELS.items():
        views[name] = Image.fromarray(cut(sheet[y0:y1, x0:x1]))
        views[name].save(os.path.join(SRC, name + '.webp'), quality=92, method=6)
    side = views['side']
    # the eye sits about 59 % across and 22 % down the side view
    side = glasses(side, (side.width * 0.554, side.height * 0.215))
    side.save(os.path.join(SRC, 'side-with-glasses.webp'), quality=92, method=6)
    os.makedirs(OUT, exist_ok=True)
    export(views['front'], 'front', 180)
    export(views['rear'], 'rear', 180)
    export(views['rear-right'], 'rear-right', 180)
    export(ImageOps.mirror(views['rear-right']), 'rear-left', 180)
    export(side, 'side', 180)
    export(ImageOps.mirror(side), 'side-left', 180)
    export(side, 'side-large', 400)
    export(views['look-back'], 'look-back-large', 400)
    print('done')
