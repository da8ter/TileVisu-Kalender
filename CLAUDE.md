# TileVisu Kalender (Minikalender)

Symcon-Kachel (HTML-SDK), die die Termine einer Kalenderinstanz tageweise und als Monatsansicht zeigt. Öffentliches Repo `da8ter/TileVisu-Kalender`. Bedienung und unterstützte Kalendermodule: `TileVisu Minikalender/README.md`.

Betriebsdaten dieses Rechners (Zweige, Testsystem) stehen in `CLAUDE.local.md` (nicht eingecheckt).

## Aufbau

- **`TileVisu Minikalender/`**: einziges Modul, Klasse `TileVisuMinikalender`, Präfix `TVKAL`, `IPSModuleStrict`. Timer `Update` ruft `TVKAL_Update`.
- **`libs/`**: Traits `TileStateBuffer` (Zustand beim Öffnen) und `EventFormatting` (Tagesliste, Monatsansicht, Textbereinigung). Sie stehen im Namensraum `TVKAL`, damit sie nicht mit gleichnamigen Traits anderer Module im selben PHP-Prozess kollidieren; Konstanten sind Namensraum-Konstanten, weil Trait-Konstanten erst ab PHP 8.2 gehen. `module.php` bleibt so unter 500 Zeilen.
- **Öffnen ohne Neuabruf:** `GetVisualizationTile` nimmt den Zustand aus dem Puffer `LastData` (in Stücken, Beschreibung in `LastMeta`), solange er gilt; ein Neuabruf beim Kalendermodul läuft nur im Update. Fehlt ein brauchbarer Stand, stößt das Öffnen einmal ein Update im Hintergrund an (`RegisterOnceTimer`, Sperre 5 min), nie `SetTimerInterval`. Die Regeln stehen als Kommentare in `libs/TileStateBuffer.php`. Anders als bei anderen TileVisu-Kacheln lohnt der Puffer hier, weil das Öffnen sonst die Termine eines Jahres zurück bis eines Jahres voraus vom Kalendermodul holte.
- **Scrollen:** Liste, Tagesansicht und Termin-Overlay grenzen das Scrollen ein (`overscroll-behavior: contain`, `touch-action: pan-y`), sonst scrollte auf iOS und im Symcon-WebView die ganze Visu mit.

## Prüfen

```bash
tests/run.sh                  # php -l aller PHP-Dateien, JSON-Prüfung, dann tests/tile_test.php
```

`tests/bootstrap.php` ist eine SDK-Attrappe; `RegisterOnceTimer` wird vorgemerkt und im Test abgespielt.

## Regeln

- **Commits:** deutsche Botschaft, ein Thema je Commit, **ohne** Co-Authored-By-Zeile; Prüfungen vorher.
- **Nie** `git checkout`/`git restore` auf Dateien: die Arbeitskopie kann nicht committete Arbeit enthalten.
- **Push und Release nur auf Zuruf.** Release: `version`, `build` und `date` in `library.json` hochsetzen (`date` ist ein Unix-Zeitstempel).
- **Öffentliches Repo:** keine IP-Adressen, Ports, Instanz-IDs, Token, Pfade unter `/Users/`, keine Personendaten und keine echten Termine – auch nicht in Tests und Kommentaren. Fixtures mit Platzhaltern.
- **Symcon-Standards:** `strict_types`, `IPSModuleStrict` mit vollen Typen, Darstellungen statt Variablenprofilen, Texte über `locale.json`, Nutzertexte sagen „Symcon“.
- **Termindaten sind fremde Eingabe** (externe Kalender): in der Kachel nur escaped ins HTML, im Startzustand des Kacheldokuments mit `JSON_HEX_TAG` (siehe Plattformwissen `kachel-nachrichten.md`).

## Wissen

Gemeinsames Symcon-Plattformwissen (Puffergrenzen, Timer, Kachel-Nachrichten): https://github.com/da8ter/SymDo-Family-Organizer/tree/SymDo-Beta/docs/plattform – lokal `../List/docs/plattform/`. Symcon-Fragen am offiziellen Handbuch prüfen.
