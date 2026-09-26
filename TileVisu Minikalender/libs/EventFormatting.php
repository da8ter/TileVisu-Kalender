<?php

declare(strict_types=1);

namespace TVKAL;

/**
 * Termine für die Anzeige aufbereiten: die Tagesliste (GroupEventsByDay, FormatTimeRange, LimitEvents), alle Termine
 * für die Monatsansicht (BuildAllEvents) und bereinigte Texte (CleanInline, CleanMulti).
 */
trait EventFormatting
{
    private function BuildAllEvents(array $events, array $labels): array
    {
        $out = [];
        foreach ($events as $e) {
            if (!is_array($e)) {
                continue;
            }
            $evFrom = (int) ($e['From'] ?? 0);
            $evTo   = (int) ($e['To'] ?? 0);
            if ($evFrom === 0 || $evTo === 0) {
                continue;
            }
            $allDay = !empty($e['allDay']);
            $out[] = [
                'uid'         => (string) ($e['UID'] ?? ''),
                'title'       => $this->CleanInline((string) ($e['Name'] ?? '')),
                'from'        => $evFrom,
                'to'          => $evTo,
                'allDay'      => $allDay,
                'timeLabel'   => $allDay ? $labels['allDay'] : date('H:i', $evFrom) . ' – ' . date('H:i', $evTo),
                'location'    => $this->CleanInline((string) ($e['Location'] ?? '')),
                'description' => $this->CleanMulti((string) ($e['Description'] ?? '')),
                'categories'  => $this->CleanInline((string) ($e['Categories'] ?? '')),
                'status'      => (string) ($e['Status'] ?? '')
            ];
        }
        return $out;
    }

    /**
     * Bereinigt einzeilige Texte (Titel, Location, Kategorien):
     * - löst iCal-Escape-Sequenzen auf (\n, \r, \,, \;, \\)
     * - ersetzt Zeilenumbrüche/Tabs durch ", "
     * - normalisiert Whitespace und Mehrfach-Kommas
     */
    private function CleanInline(string $s): string
    {
        if ($s === '') {
            return '';
        }
        $s = str_replace(
            ['\\n', '\\N', '\\r', '\\R', '\\,', '\\;', '\\\\'],
            ["\n", "\n", "\r", "\r", ',', ';', '\\'],
            $s
        );
        // preg_replace liefert bei kaputtem UTF-8 aus externen Kalendern null — dann Rohwert behalten
        $s = preg_replace('/[\r\n\t]+/', ', ', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        $s = preg_replace('/(\s*,\s*)+/', ', ', $s) ?? $s;
        return trim($s, ' ,');
    }

    /**
     * Bereinigt mehrzeilige Texte (Beschreibung): iCal-Escapes auflösen,
     * echte Zeilenumbrüche behalten, aber Whitespace innerhalb der Zeile normalisieren.
     */
    private function CleanMulti(string $s): string
    {
        if ($s === '') {
            return '';
        }
        $s = str_replace(
            ['\\n', '\\N', '\\r', '\\R', '\\,', '\\;', '\\\\'],
            ["\n", "\n", "\n", "\n", ',', ';', '\\'],
            $s
        );
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace('/[ \t]+/', ' ', $s) ?? $s;
        return trim($s);
    }

    private function GroupEventsByDay(array $events, int $from, int $daysAhead, array $labels): array
    {
        $now         = time();
        $weekdayLong = $labels['weekdaysLong'];
        $days        = [];

        for ($i = 0; $i < $daysAhead; $i++) {
            // Kalendertage statt +86400: an DST-Tagen (23/25h) würden sonst alle
            // Tagesgrenzen um eine Stunde driften (doppelte/übersprungene Tage).
            $dayStart = (int) strtotime(sprintf('+%d day', $i), $from);
            $dayEnd   = (int) strtotime('+1 day', $dayStart);
            $dateKey  = date('Y-m-d', $dayStart);

            $label = match ($i) {
                0       => $labels['today'],
                1       => $labels['tomorrow'],
                default => $weekdayLong[(int) date('w', $dayStart)]
            };

            $dayEvents = [];
            foreach ($events as $e) {
                if (!is_array($e)) {
                    continue;
                }
                $evFrom = (int) ($e['From'] ?? 0);
                $evTo   = (int) ($e['To'] ?? 0);
                if ($evFrom === 0 || $evTo === 0) {
                    continue;
                }
                if ($evTo <= $dayStart || $evFrom >= $dayEnd) {
                    continue;
                }

                $allDay = !empty($e['allDay']);
                $dayEvents[] = [
                    'uid'         => (string) ($e['UID'] ?? ''),
                    'title'       => $this->CleanInline((string) ($e['Name'] ?? '')),
                    'timeLabel'   => $allDay ? $labels['allDay'] : $this->FormatTimeRange($evFrom, $evTo, $dayStart, $dayEnd),
                    'allDay'      => $allDay,
                    'from'        => $evFrom,
                    'to'          => $evTo,
                    'location'    => $this->CleanInline((string) ($e['Location'] ?? '')),
                    'description' => $this->CleanMulti((string) ($e['Description'] ?? '')),
                    'categories'  => $this->CleanInline((string) ($e['Categories'] ?? '')),
                    'status'      => (string) ($e['Status'] ?? ''),
                    'running'     => ($now >= $evFrom && $now < $evTo)
                ];
            }

            usort($dayEvents, static function (array $a, array $b): int {
                if ($a['allDay'] !== $b['allDay']) {
                    return $a['allDay'] ? -1 : 1;
                }
                return $a['from'] <=> $b['from'];
            });

            $days[] = [
                'date'      => $dateKey,
                'label'     => $label,
                'dateShort' => date('d.m.', $dayStart),
                'events'    => $dayEvents
            ];
        }

        return $days;
    }

    private function FormatTimeRange(int $evFrom, int $evTo, int $dayStart, int $dayEnd): string
    {
        $startsBefore = $evFrom < $dayStart;
        $endsAfter    = $evTo > $dayEnd;

        $start = $startsBefore ? '00:00' : date('H:i', $evFrom);
        $end   = $endsAfter ? '24:00' : date('H:i', $evTo);

        return $start . ' – ' . $end;
    }

    private function LimitEvents(array $days, int $max): array
    {
        $count = 0;
        $out   = [];
        foreach ($days as $day) {
            if ($count >= $max) {
                break;
            }
            $remaining = $max - $count;
            if (count($day['events']) > $remaining) {
                $day['events'] = array_slice($day['events'], 0, $remaining);
            }
            $count += count($day['events']);
            $out[] = $day;
        }
        return $out;
    }
}
