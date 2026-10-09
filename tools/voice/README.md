# Stimme des Stiers

Der Fahrmodus (`assets/navigate.js`) spricht über `assets/voice.js`:

1. **Aufgenommene Clips** (`assets/voice/<de|en>/<id>.mp3` + `manifest.json`) – wenn zu einem Satz ein Clip da ist, wird er abgespielt.
2. **Sonst die Gerätestimme** (Web Speech API, `speechSynthesis`) – das ist die Stimme des Handys und nicht einstellbar.
   Sie bleibt Rückfall für Pausennamen („Pause bei Café Müller“), Touren über 60 km und alles ohne Clip.

Ohne `manifest.json` ändert sich nichts. Clips lassen sich satzweise nachliefern.

## Ablauf

```bash
php tools/voice/phrases.php list de > phrases-de.tsv     # id <TAB> Satz  (426 Zeilen)
php tools/voice/phrases.php list en > phrases-en.tsv
php tools/voice/phrases.php cues                         # optionale Klänge (Muh, Klingel …)
# pro Zeile eine Datei assets/voice/de/<id>.mp3 erzeugen (siehe unten), dann:
php tools/voice/phrases.php manifest                     # schreibt assets/voice/<lang>/manifest.json
```

Die Sätze sind genau die, die die App sagt (Abstände 60–500 m, Kreisverkehr 1.–8. Ausfahrt, Kilometerzahlen 1–60 ganzzahlig).
Ändert jemand einen Text in `lang.php`, `list` neu laufen lassen – alte Clips passen dann nicht mehr und werden übersprungen.

**Klänge (optional)** liegen als `assets/voice/sfx/<name>.mp3` (`start`, `turn`, `stop`, `halfway`, `arrive`, `offroute`, `back`, `speed`) und
laufen vor dem jeweiligen Satz. Kurz halten (unter 1,5 s), sonst nervt „turn“ bei jeder Kurve.

## Welche Engine?

Whisper ist **Spracherkennung** (Sprache → Text), keine Sprachausgabe. Du brauchst eine TTS-Engine; Whisper taugt danach zur Kontrolle:
Clip transkribieren und mit dem Satz vergleichen, damit kein verschluckter Satz durchrutscht.

| Engine | Läuft | Stärken | Achtung |
|---|---|---|---|
| **Piper** (`rhasspy/piper`, Stimme `de_DE-thorsten-high` bzw. `thorsten_emotional`) | lokal, CPU, in Minuten für alle Sätze | kostenlos, schnell, deutsche Stimmen frei lizenziert (Thorsten: CC0), eigene Stimme trainierbar | klingt sachlich – der Stier-Charakter kommt aus der Nachbearbeitung |
| **Chatterbox Multilingual** (Resemble AI, MIT) | lokal, besser mit GPU | Stimme aus ein paar Sekunden Probe nachbilden, Emotion regelbar | Modell groß, Probe nur mit eigener/freigegebener Stimme |
| **ElevenLabs** (Voice Design: „tiefer, rauer Stier, selbstbewusst, leicht ironisch“) | Cloud | klingt am besten, Charakterstimmen per Beschreibung | kostenpflichtiger Tarif für kommerzielle Nutzung; es gehen nur feste Sätze raus, keine Nutzerdaten – datenschutzrechtlich unkritisch |
| Coqui **XTTS-v2** | lokal | Stimmklonen | Modelllizenz **nur nicht-kommerziell** – für ElTouro nicht verwenden, sobald es kommerziell wird |

Empfehlung: Piper zum schnellen Ausprobieren, ElevenLabs oder Chatterbox für die endgültige Stierstimme. Lizenz der gewählten Stimme vor dem Livegang prüfen.

### Beispiel mit Piper und Stier-Effekt

```bash
mkdir -p assets/voice/de
while IFS=$'\t' read -r id text; do
  echo "$text" | piper --model de_DE-thorsten-high.onnx --output_file /tmp/clip.wav
  # tiefer (−14 %), Tempo gleich, etwas kompressiert; Abtastrate des Modells anpassen (thorsten-high: 22050)
  ffmpeg -loglevel error -y -i /tmp/clip.wav \
    -af "asetrate=22050*0.86,aresample=22050,atempo=1.163,acompressor=threshold=-18dB:ratio=3,loudnorm=I=-16" \
    -ac 1 -b:a 64k "assets/voice/de/$id.mp3"
done < phrases-de.tsv
php tools/voice/phrases.php manifest
```

Mono, 64 kbit/s reicht für Sprache: etwa 8 KB pro Satz, alle 426 rund 4 MB pro Sprache. Die Clips liegen im Repo (`assets/voice/`),
der Service Worker hält sie nach dem ersten Abspielen auf dem Gerät – im Funkloch klingt der Stier trotzdem.
