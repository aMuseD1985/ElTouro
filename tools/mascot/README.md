# ElTouro-Maskottchen

Quelle ist der von Gemini erzeugte Bogen mit sechs Ansichten (Front, Seite, hinten rechts, hinten links, Rückansicht, isometrisch),
weißer Hintergrund mit Rahmen, Bildunterschrift und weichem Schlagschatten.

    pip install numpy scipy pillow
    python3 tools/mascot/build.py <Bogen.jpg>

`cutout.py` schneidet jede Ansicht frei: heller, farbloser Hintergrund und Schlagschatten werden vom Rand her entfernt, große
weiße Taschen im Inneren (zwischen Beinen und Lenkstange) ebenfalls, Zähne und Augenweiß bleiben; die Kante wird um 2 px
zurückgenommen und aus dem Inneren eingefärbt – kein weißer Saum. `build.py` malt die Sonnenbrille in die Seitenansicht
(die Vorlage hat dort keine – **der Stier trägt immer seine Sonnenbrille**), schreibt die Quellen in voller Größe hierher und die
Web-Versionen nach `assets/img/mascot/` (180 px hoch, die großen 400 px). Linke Ansichten (`rear-left`, `side-left`) sind
gespiegelte rechte. Die isometrische Ansicht hat keine Brille und wird nicht verwendet. Wer die Sprites ändert, hängt in den
URLs (`?v=`) die Version hoch und zählt in `sw.js` `CACHE` weiter.

Fehlt noch: echte Linksansichten (von hinten links, linke Seite) mit anderer Körperseite – dafür einen Bogen im gleichen Layout
erzeugen und `PANELS` in `cutout.py` anpassen.
