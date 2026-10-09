# ElTouro-Maskottchen

Freigestellte Ansichten aus Marcos Vorlage (6 Perspektiven, Oktober 2026), in voller Größe. Die Web-Versionen liegen in
`assets/img/mascot/` (180 bzw. 400 px hoch, WebP mit Transparenz). Regel: **Der Stier trägt immer seine Sonnenbrille** –
in der Seitenansicht ist sie nachgemalt (`side-with-glasses.webp`); die isometrische Ansicht der Vorlage hatte keine und
wird deshalb nicht verwendet.

Neu exportieren (ImageMagick):

    magick tools/mascot/rear.webp -resize x180 -quality 88 -define webp:alpha-quality=100 assets/img/mascot/rear.webp

Linksversionen entstehen durch Spiegeln (`-flop`) der Rechtsversionen.
