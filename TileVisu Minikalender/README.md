# TileVisu Minikalender

Kompakte Kachel für die IP-Symcon-Kachelvisualisierung, die Termine einer Kalenderinstanz tagesweise gruppiert und platzsparend anzeigt.
Unterstützt jede Instanz, deren Modul eine der folgenden Funktionen bereitstellt (in dieser Prioritätsreihenfolge):

1. `<Prefix>_GetCachedCalendar($id)` – liest den bereits vom Kalendermodul gecachten Datensatz (bevorzugt, z. B. `ICCR` – iCal-Kalenderimport)
2. `<Prefix>_UpdateCalendar($id)` – erzwingt einen Neuabruf (Fallback)
3. `<Prefix>_GetEvents($id, $from, $to)` – Symcon `~Calendar`-Interface

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in Symcon](#4-einrichten-der-instanzen-in-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [Visualisierung](#6-visualisierung)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

* Anzeige der Termine einer beliebigen Kalenderinstanz (z. B. iCal-Kalenderimport, Module mit `~Calendar`-Interface)
* Zeitraum: Heute + konfigurierbare Anzahl Folgetage
* Gruppierung nach Tag mit Labels *Heute*, *Morgen*, Wochentag + Datum
* Ganztägige Termine als separate Zeilen oberhalb der zeitgebundenen
* Hervorhebung aktuell laufender Termine
* Klick auf einen Termin öffnet ein Detail-Popup mit Ort, Beschreibung und Kategorien
* Interner Scroll-Container – passt sich automatisch der Kachelgröße an
* Vollständig lokalisiert (DE/EN)

### 2. Voraussetzungen

- Symcon ab Version 7.1
- Mindestens eine Kalenderinstanz, deren Modul `<Prefix>_GetCachedCalendar($id)`, `<Prefix>_UpdateCalendar($id)` oder `<Prefix>_GetEvents($id, $from, $to)` bereitstellt

### 3. Software-Installation

* Über das Module Control folgende URL hinzufügen: *(Repository-URL des Projekts)*
* Anschließend das Modul **TileVisu Minikalender** auswählen

### 4. Einrichten der Instanzen in Symcon

Unter *Instanz hinzufügen* kann das Modul **TileVisu Minikalender** mithilfe des Schnellfilters gefunden werden.
Weitere Informationen: [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

| Name | Beschreibung |
|---|---|
| Kalenderinstanz | Die Kalenderinstanz, deren Termine angezeigt werden sollen. Das Modul prüft automatisch, ob `<Prefix>_GetCachedCalendar`, `<Prefix>_UpdateCalendar` oder `<Prefix>_GetEvents` verfügbar ist. |
| Tage im Voraus (inkl. heute) | Anzahl Tage, die ab heute in der Kachel gezeigt werden (1–60). |
| Aktualisierungsintervall | Zeitabstand in Minuten für den automatischen Abruf der Termine (1 – 1440 min, Standard 60 min). |
| Maximale Anzahl Termine | Obergrenze insgesamt über alle Tage (0 = unbegrenzt). |
| Ort anzeigen | Zeigt den Veranstaltungsort unter dem Titel. |
| Detail-Popup bei Klick anzeigen | Öffnet beim Klick auf einen Termin ein Overlay mit Details. |
| Laufende Termine hervorheben | Aktuell laufende Termine werden farblich markiert. |

Über den Button **Jetzt aktualisieren** kann ein manueller Refresh angestoßen werden.

### 5. Statusvariablen und Profile

Das Modul legt **keine** Statusvariablen oder Profile an – die Darstellung erfolgt ausschließlich über die Kachelvisualisierung.

#### Instanzstatus

| Code | Bedeutung |
|---|---|
| 102 | OK – Kalenderinstanz ausgewählt und kompatibel |
| 201 | Keine Kalenderinstanz ausgewählt |
| 202 | Ausgewählte Instanz stellt keine unterstützte Kalenderfunktion bereit (`_GetCachedCalendar`, `_UpdateCalendar` oder `_GetEvents`) |

### 6. Visualisierung

Die Instanz liefert automatisch eine Kachel (HTML-SDK, `SetVisualizationType(1)`) und kann direkt per Drag & Drop in der Kachelvisualisierung platziert werden. Das Layout passt sich der Kachelgröße an und scrollt intern.

### 7. PHP-Befehlsreferenz

`TVKAL_Update(integer $InstanzID);`
Löst einen sofortigen Neuabruf der Termine beim konfigurierten Kalender aus und aktualisiert die Kachel.

Beispiel:
`TVKAL_Update(12345);`