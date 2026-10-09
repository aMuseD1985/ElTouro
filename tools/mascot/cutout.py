"""Cuts the six mascot views out of the generated sheet (white background, soft shadow, frame and caption per panel).

    python3 tools/mascot/cutout.py <sheet.jpg> <out_dir>

Needs numpy, scipy, pillow. Writes <view>.png (RGBA, full size, tight crop) for front, side, rear-right, look-back,
rear, iso. Background, drop shadow and enclosed white pockets are removed, edges are decontaminated (no white fringe).
"""
import sys, os
import numpy as np
from PIL import Image
from scipy import ndimage as ndi

# panel interior (x0, y0, x1, y1) in a 2048 x 2058 sheet: inside the frame, above the caption
PANELS = {
    'front':      (30, 60, 650, 975),
    'side':       (720, 60, 1335, 975),
    'rear-right': (1410, 60, 2030, 975),
    'look-back':  (30, 1100, 650, 1990),
    'rear':       (720, 1100, 1335, 1990),
    'iso':        (1410, 1100, 2030, 1990),
}


def cut(rgb):
    a = rgb.astype(np.float32)
    mx, mn = a.max(2), a.min(2)
    sat = mx - mn
    # "paper": light and colourless – white background and the soft grey of the drop shadow
    paper = (mn >= 150) & (sat <= 34)
    pure = mn >= 243
    h, w = paper.shape
    lab, n = ndi.label(paper)
    border = np.unique(np.concatenate([lab[0], lab[-1], lab[:, 0], lab[:, -1]]))
    bg = np.isin(lab, border[border > 0])
    # enclosed pockets: pure white areas inside the figure (between legs, scooter stem …); small ones are teeth and eyes
    lab2, n2 = ndi.label(pure & ~bg)
    sizes = ndi.sum(np.ones_like(lab2), lab2, index=np.arange(1, n2 + 1))
    for i, s in enumerate(sizes, 1):
        if s > 0.0012 * h * w:
            bg |= lab2 == i
    fg = ~bg
    # keep the figure only: drop specks and shadow remnants
    lab3, n3 = ndi.label(fg)
    sizes3 = ndi.sum(np.ones_like(lab3), lab3, index=np.arange(1, n3 + 1))
    keep = [i for i, s in enumerate(sizes3, 1) if s > 0.02 * sizes3.max()]
    fg = np.isin(lab3, keep)
    fg = ndi.binary_fill_holes(fg) if False else fg
    fg = ndi.binary_opening(fg, iterations=1)
    # soft edge: shrink by 2 px (kills the white fringe), then feather
    core = ndi.binary_erosion(fg, iterations=2)
    alpha = ndi.gaussian_filter(core.astype(np.float32), 1.0)
    alpha = np.clip((alpha - 0.15) / 0.7, 0, 1)
    # edge colours from the nearest solid pixel instead of the blend with white
    solid = ndi.binary_erosion(fg, iterations=4)
    idx = ndi.distance_transform_edt(~solid, return_distances=False, return_indices=True)
    out = rgb[idx[0], idx[1]]
    out = np.where(solid[..., None], rgb, out)
    rgba = np.dstack([out, (alpha * 255).astype(np.uint8)])
    ys, xs = np.where(alpha > 0.02)
    return rgba[ys.min():ys.max() + 1, xs.min():xs.max() + 1]


if __name__ == '__main__':
    sheet = np.array(Image.open(sys.argv[1]).convert('RGB'))
    os.makedirs(sys.argv[2], exist_ok=True)
    for name, (x0, y0, x1, y1) in PANELS.items():
        r = cut(sheet[y0:y1, x0:x1])
        Image.fromarray(r, 'RGBA').save(os.path.join(sys.argv[2], name + '.png'))
        print(name, r.shape[1], 'x', r.shape[0])
