<?php

declare(strict_types=1);

class TileVisuMinikalender extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('CalendarID', 0);
        $this->RegisterPropertyInteger('DaysAhead', 7);
        $this->RegisterPropertyInteger('UpdateInterval', 60);
        $this->RegisterPropertyInteger('MaxEvents', 0);
        $this->RegisterPropertyBoolean('ShowLocation', true);
        $this->RegisterPropertyBoolean('ShowDescriptionPopup', true);
        $this->RegisterPropertyBoolean('HighlightRunning', true);

        $this->RegisterTimer('Update', 0, 'TVKAL_Update($_IPS[\'TARGET\']);');

        $this->SetVisualizationType(1);
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $calendarID = $this->ReadPropertyInteger('CalendarID');
        if ($calendarID <= 0 || !IPS_InstanceExists($calendarID)) {
            $this->SetStatus(201);
            $this->SetTimerInterval('Update', 0);
            $this->SetBuffer('LastData', '');
            $this->SendPayload();
            return;
        }

        if ($this->GetCalendarCall($calendarID) === null) {
            $this->SetStatus(202);
            $this->SetTimerInterval('Update', 0);
            $this->SetBuffer('LastData', '');
            $this->SendPayload();
            return;
        }

        $this->SetStatus(102);
        $minutes = max(1, $this->ReadPropertyInteger('UpdateInterval'));
        $this->SetTimerInterval('Update', $minutes * 60 * 1000);

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->Update();
        }
    }

    public function GetVisualizationTile()
    {
        $module = file_get_contents(__DIR__ . '/module.html');
        $payload = $this->BuildPayload();
        $bootstrap = '<script>(()=>{const data=' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';if(typeof handleMessage==="function"){handleMessage(data);}else{window.__tvkalInitialData=data;}})();</script>';
        return $module . $bootstrap;
    }

    public function Update(): void
    {
        $start = microtime(true);
        $this->SendDebug('Update', 'Manuelle/Timer-Aktualisierung gestartet', 0);

        $payload = $this->BuildPayload();

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->SetBuffer('LastData', $json);
        $this->SendPayload($payload);

        $totalEvents = array_sum(array_map(static fn($d) => count($d['events'] ?? []), $payload['days'] ?? []));
        $duration = round((microtime(true) - $start) * 1000, 1);
        $this->SendDebug('Update', sprintf('Fertig: %d Tage, %d Termine gesamt, Payload %d Bytes, Dauer %.1f ms', count($payload['days'] ?? []), $totalEvents, strlen($json), $duration), 0);
    }

    private function SendPayload(?array $payload = null): void
    {
        if ($payload === null) {
            $buffer = $this->GetBuffer('LastData');
            $payload = $buffer !== '' ? json_decode($buffer, true) : $this->BuildEmptyPayload();
        }
        $this->UpdateVisualizationValue(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function BuildEmptyPayload(): array
    {
        return [
            'generatedAt' => time(),
            'days'        => [],
            'allEvents'   => [],
            'monthInfo'   => $this->GetMonthInfo(),
            'config'      => $this->GetFrontendConfig(),
            'labels'      => $this->GetFrontendLabels()
        ];
    }

    private function GetMonthInfo(): array
    {
        $ref = strtotime('today');
        return [
            'year'  => (int) date('Y', $ref),
            'month' => (int) date('n', $ref),
            'today' => date('Y-m-d', $ref)
        ];
    }

    private function GetFrontendConfig(): array
    {
        return [
            'showLocation'         => $this->ReadPropertyBoolean('ShowLocation'),
            'showDescriptionPopup' => $this->ReadPropertyBoolean('ShowDescriptionPopup'),
            'highlightRunning'     => $this->ReadPropertyBoolean('HighlightRunning')
        ];
    }

    private function GetFrontendLabels(): array
    {
        return [
            'allDay'      => $this->Translate('all day'),
            'noEvents'    => $this->Translate('No events'),
            'location'    => $this->Translate('Location'),
            'description' => $this->Translate('Description'),
            'categories'  => $this->Translate('Categories'),
            'close'       => $this->Translate('Close'),
            'today'       => $this->Translate('Today'),
            'tomorrow'    => $this->Translate('Tomorrow'),
            'viewList'    => $this->Translate('List view'),
            'viewMonth'   => $this->Translate('Month view'),
            'weekdays'    => [
                $this->Translate('Sun'),
                $this->Translate('Mon'),
                $this->Translate('Tue'),
                $this->Translate('Wed'),
                $this->Translate('Thu'),
                $this->Translate('Fri'),
                $this->Translate('Sat')
            ],
            'weekdaysLong' => [
                $this->Translate('Sunday'),
                $this->Translate('Monday'),
                $this->Translate('Tuesday'),
                $this->Translate('Wednesday'),
                $this->Translate('Thursday'),
                $this->Translate('Friday'),
                $this->Translate('Saturday')
            ],
            'months'      => [
                $this->Translate('January'),
                $this->Translate('February'),
                $this->Translate('March'),
                $this->Translate('April'),
                $this->Translate('May'),
                $this->Translate('June'),
                $this->Translate('July'),
                $this->Translate('August'),
                $this->Translate('September'),
                $this->Translate('October'),
                $this->Translate('November'),
                $this->Translate('December')
            ]
        ];
    }

    private function BuildPayload(): array
    {
        $calendarID = $this->ReadPropertyInteger('CalendarID');
        if ($calendarID <= 0 || !IPS_InstanceExists($calendarID)) {
            $this->SendDebug('BuildPayload', 'Abbruch: keine oder ungültige Kalenderinstanz (ID=' . $calendarID . ')', 0);
            return $this->BuildEmptyPayload();
        }

        $daysAhead = max(1, $this->ReadPropertyInteger('DaysAhead'));
        $from      = strtotime('today');
        $fetchFrom = strtotime('-1 year today 00:00:00');
        $fetchTo   = strtotime('+1 year today 23:59:59');
        $this->SendDebug('BuildPayload', sprintf('Kalender #%d, Abruf %s … %s', $calendarID, date('Y-m-d H:i', $fetchFrom), date('Y-m-d H:i', $fetchTo)), 0);

        $events = $this->FetchEvents($calendarID, $fetchFrom, $fetchTo);
        if ($events === null) {
            $this->SendDebug('BuildPayload', 'Abbruch: FetchEvents lieferte NULL', 0);
            return $this->BuildEmptyPayload();
        }
        $this->SendDebug('FetchEvents', sprintf('%d Termine vom Kalendermodul erhalten', count($events)), 0);
        if (count($events) > 0) {
            $this->SendDebug('FetchEvents', 'Rohdaten: ' . json_encode($events, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 0);
        }

        $days = $this->GroupEventsByDay($events, $from, $daysAhead);
        $grouped = array_sum(array_map(static fn($d) => count($d['events']), $days));
        $this->SendDebug('GroupEventsByDay', sprintf('%d Tage, %d Termine im Fenster', count($days), $grouped), 0);

        $maxEvents = $this->ReadPropertyInteger('MaxEvents');
        if ($maxEvents > 0) {
            $days = $this->LimitEvents($days, $maxEvents);
            $limited = array_sum(array_map(static fn($d) => count($d['events']), $days));
            $this->SendDebug('LimitEvents', sprintf('MaxEvents=%d → %d Termine nach Limit', $maxEvents, $limited), 0);
        }

        $allEvents = $this->BuildAllEvents($events);
        $this->SendDebug('BuildAllEvents', sprintf('%d Events für Monatsnavigation normalisiert', count($allEvents)), 0);

        return [
            'generatedAt' => time(),
            'days'        => $days,
            'allEvents'   => $allEvents,
            'monthInfo'   => $this->GetMonthInfo(),
            'config'      => $this->GetFrontendConfig(),
            'labels'      => $this->GetFrontendLabels()
        ];
    }

    private function BuildAllEvents(array $events): array
    {
        $labels = $this->GetFrontendLabels();
        $out    = [];
        foreach ($events as $e) {
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
        $s = preg_replace('/[\r\n\t]+/', ', ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        $s = preg_replace('/(\s*,\s*)+/', ', ', $s);
        return trim($s, " ,");
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
        $s = preg_replace('/[ \t]+/', ' ', $s);
        return trim($s);
    }

    /**
     * Ermittelt einen aufrufbaren Kalender-Endpoint.
     * Rückgabe: ['fn' => string, 'args' => array] oder null wenn nichts Passendes gefunden.
     */
    private function GetCalendarCall(int $instanceID): ?array
    {
        $instance = @IPS_GetInstance($instanceID);
        $moduleID = $instance['ModuleInfo']['ModuleID'] ?? '';
        if ($moduleID === '') {
            return null;
        }
        $prefix = @IPS_GetModule($moduleID)['Prefix'] ?? '';
        if ($prefix === '') {
            return null;
        }

        // Kandidaten in Prioritäts-Reihenfolge:
        // 1. <Prefix>_GetCachedCalendar($id)         – liefert den Cache ohne externen Abruf (bevorzugt, z. B. ICCR)
        // 2. <Prefix>_UpdateCalendar($id)            – erzwingt Neuabruf (Fallback)
        // 3. <Prefix>_GetEvents($id, $from, $to)     – Symcon ~Calendar-Interface
        $candidates = [
            ['fn' => $prefix . '_GetCachedCalendar', 'argc' => 1],
            ['fn' => $prefix . '_UpdateCalendar',    'argc' => 1],
            ['fn' => $prefix . '_GetEvents',         'argc' => 3]
        ];

        foreach ($candidates as $c) {
            if (function_exists($c['fn'])) {
                return $c;
            }
        }
        return null;
    }

    private function FetchEvents(int $instanceID, int $from, int $to): ?array
    {
        $call = $this->GetCalendarCall($instanceID);
        if ($call === null) {
            $this->SendDebug('FetchEvents', 'Keine passende Kalenderfunktion gefunden', 0);
            return null;
        }

        $args = $call['argc'] === 1 ? [$instanceID] : [$instanceID, $from, $to];
        $this->SendDebug('FetchEvents', sprintf('Aufruf %s(%s)', $call['fn'], implode(', ', $args)), 0);

        try {
            $events = @call_user_func_array($call['fn'], $args);
        } catch (Throwable $e) {
            $this->SendDebug('FetchEvents', 'Exception: ' . $e->getMessage(), 0);
            $this->LogMessage($call['fn'] . ' failed: ' . $e->getMessage(), KL_WARNING);
            return null;
        }

        // Manche Kalendermodule liefern den Event-Datensatz als JSON-String
        if (is_string($events)) {
            $decoded = json_decode($events, true);
            if (is_array($decoded)) {
                $this->SendDebug('FetchEvents', sprintf('JSON-String dekodiert (%d Bytes)', strlen($events)), 0);
                $events = $decoded;
            } else {
                $this->SendDebug('FetchEvents', 'String-Rückgabe ist kein gültiges JSON: ' . json_last_error_msg(), 0);
                return null;
            }
        }

        if (!is_array($events)) {
            $this->SendDebug('FetchEvents', 'Rückgabe ist kein Array (Typ=' . gettype($events) . ')', 0);
            return null;
        }
        return $events;
    }

    private function GroupEventsByDay(array $events, int $from, int $daysAhead): array
    {
        $now         = time();
        $labels      = $this->GetFrontendLabels();
        $weekdayLong = $labels['weekdaysLong'];
        $days        = [];

        for ($i = 0; $i < $daysAhead; $i++) {
            $dayStart = $from + $i * 86400;
            $dayEnd   = $dayStart + 86400;
            $dateKey  = date('Y-m-d', $dayStart);

            $label = match ($i) {
                0       => $labels['today'],
                1       => $labels['tomorrow'],
                default => $weekdayLong[(int) date('w', $dayStart)]
            };

            $dayEvents = [];
            foreach ($events as $e) {
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

            usort($dayEvents, static function ($a, $b) {
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