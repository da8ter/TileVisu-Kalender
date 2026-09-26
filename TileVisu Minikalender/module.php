<?php

declare(strict_types=1);

class TileVisuMinikalender extends IPSModuleStrict
{
    private const STATUS_ACTIVE = 102;
    private const STATUS_NO_CALENDAR = 201;
    private const STATUS_UNSUPPORTED_CALENDAR = 202;

    // Puffer LastData: JSON des zuletzt gebauten Zustands, ab LAST_DATA_CHUNK Bytes in Stücken LastData1, LastData2, …
    // (Symcon warnt ab 256 kB je Puffer und kürzt über 512 kB); LastMeta beschreibt ihn.
    private const LAST_DATA_CHUNK = 200000;
    private const LAST_DATA_MAX_CHUNKS = 10;
    // Spielraum des Update-Timers über das Intervall hinaus, in Sekunden
    private const LAST_DATA_GRACE = 60;
    // Termine im Debug-Auszug der Rohdaten
    private const DEBUG_SAMPLE_EVENTS = 3;

    public function Create(): void
    {
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

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Kein Heavy Work vor KR_READY: function_exists() auf Fremdmodul-Funktionen ist
        // erst nach dem Kernel-Start verlässlich — sonst landet die Instanz nach einem
        // Symcon-Neustart fälschlich auf Status 202 und erholt sich nie.
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        $calendarID = $this->ReadPropertyInteger('CalendarID');

        foreach ($this->GetReferenceList() as $ref) {
            $this->UnregisterReference($ref);
        }
        if ($calendarID > 0) {
            $this->RegisterReference($calendarID);
        }

        if ($calendarID <= 0 || !IPS_InstanceExists($calendarID)) {
            $this->SetStatus(self::STATUS_NO_CALENDAR);
            $this->SetTimerInterval('Update', 0);
            $this->ClearLastData();
            $this->SendPayload();
            return;
        }

        if ($this->GetCalendarCall($calendarID) === null) {
            $this->SetStatus(self::STATUS_UNSUPPORTED_CALENDAR);
            $this->SetTimerInterval('Update', 0);
            $this->ClearLastData();
            $this->SendPayload();
            return;
        }

        $this->SetStatus(self::STATUS_ACTIVE);
        $this->ScheduleNextTimer();
        $this->Update();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    /**
     * Setzt den Update-Timer so, dass er spätestens kurz nach Mitternacht feuert,
     * damit "Heute"/"Morgen"-Labels beim Tageswechsel zeitnah erneuert werden.
     */
    private function ScheduleNextTimer(): void
    {
        $minutes       = max(1, $this->ReadPropertyInteger('UpdateInterval'));
        $defaultNextS  = $minutes * 60;
        $untilMidnight = strtotime('tomorrow 00:00:30') - time();
        $nextS         = max(30, min($defaultNextS, $untilMidnight));
        $this->SetTimerInterval('Update', $nextS * 1000);
        $this->SendDebug('ScheduleNextTimer', sprintf('Nächster Tick in %ds (Default %ds, bis Mitternacht %ds)', $nextS, $defaultNextS, $untilMidnight), 0);
    }

    public function GetVisualizationTile(): string
    {
        $module = file_get_contents(__DIR__ . '/module.html');
        if ($module === false) {
            $this->LogMessage('module.html could not be loaded', KL_ERROR);
            return '';
        }
        $bootstrap = '<script>(()=>{const data=' . $this->TilePayloadJson() . ';if(typeof handleMessage==="function"){handleMessage(data);}else{window.__tvkalInitialData=data;}})();</script>';
        return $module . $bootstrap;
    }

    /**
     * Zustand für eine frisch geöffnete Kachel: der Puffer LastData, solange er aktuell genug ist (PayloadValidUntil),
     * sonst neu aus dem Cache des Kalendermoduls. Einen Neuabruf (<Prefix>_UpdateCalendar) löst das Öffnen nie aus;
     * bietet das Modul nur diesen, bleibt es beim letzten Stand, den der Timer geholt hat.
     */
    private function TilePayloadJson(): string
    {
        $last = $this->ReadLastData();
        $now = time();
        if ($last !== null && $last['meta']['ok'] && $now >= $last['meta']['at'] && $now < $last['meta']['until']) {
            return $last['json'];
        }
        $payload = $this->BuildPayload(false);
        if ($payload === null) {
            return $last['json'] ?? $this->EncodePayload($this->BuildEmptyPayload());
        }
        $json = $this->EncodePayload($payload);
        if (!empty($payload['allEvents'])) {
            $this->WriteLastData($json, $payload); // die nächsten Öffnungen brauchen den Cache nicht mehr
        }
        return $json;
    }

    public function Update(): void
    {
        $start = microtime(true);
        $this->SendDebug('Update', 'Manuelle/Timer-Aktualisierung gestartet', 0);

        $payload = $this->BuildPayload() ?? $this->BuildEmptyPayload(); // mit Neuabruf: nie null

        $json = $this->EncodePayload($payload);
        $this->WriteLastData($json, $payload);
        $this->SendPayload($payload);

        $totalEvents = array_sum(array_map(static fn($d) => count($d['events'] ?? []), $payload['days'] ?? []));
        $duration = round((microtime(true) - $start) * 1000, 1);
        $this->SendDebug('Update', sprintf('Fertig: %d Tage, %d Termine gesamt, Payload %d Bytes, Dauer %.1f ms', count($payload['days'] ?? []), $totalEvents, strlen($json), $duration), 0);

        $this->ScheduleNextTimer();
    }

    private function SendPayload(?array $payload = null): void
    {
        if ($payload === null) {
            $buffer = $this->ReadLastData()['json'] ?? '';
            $payload = $buffer !== '' ? json_decode($buffer, true) : $this->BuildEmptyPayload();
            if (!is_array($payload)) {
                $payload = $this->BuildEmptyPayload();
            }
        }
        $this->UpdateVisualizationValue($this->EncodePayload($payload));
    }

    private function EncodePayload(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** Der Puffer LastData samt Beschreibung (LastMeta), wenn er vollständig ist, sonst null. */
    private function ReadLastData(): ?array
    {
        $meta = json_decode($this->GetBuffer('LastMeta'), true);
        foreach (['at', 'until', 'len', 'parts'] as $field) {
            if (!is_array($meta) || !is_int($meta[$field] ?? null)) {
                return null;
            }
        }
        if (!is_bool($meta['ok'] ?? null) || $meta['parts'] < 1 || $meta['parts'] > self::LAST_DATA_MAX_CHUNKS) {
            return null;
        }
        $json = $this->GetBuffer('LastData');
        for ($i = 1; $i < $meta['parts']; $i++) {
            $json .= $this->GetBuffer('LastData' . $i);
        }
        return strlen($json) === $meta['len'] ? ['json' => $json, 'meta' => $meta] : null;
    }

    /**
     * Legt den Zustand in LastData ab (große in Stücken, getrennt nur an UTF-8-Zeichengrenzen: der Kernel hält Puffer
     * als Text) und beschreibt ihn in LastMeta. 'ok' nur mit Terminen: einen leeren Stand (etwa ein Kalendermodul,
     * das nach dem Start noch keinen Cache hat) baut das nächste Öffnen neu.
     */
    private function WriteLastData(string $json, array $payload): void
    {
        $chunks = [];
        $length = strlen($json);
        $offset = 0;
        while ($offset < $length) {
            $size = min(self::LAST_DATA_CHUNK, $length - $offset);
            // höchstens drei Folgebytes zurück: das Stück endet vor dem Zeichen, in das es sonst schnitte
            for ($back = 0; $back < 3 && $offset + $size < $length && (ord($json[$offset + $size]) & 0xC0) === 0x80; $back++) {
                $size--;
            }
            $chunks[] = substr($json, $offset, $size);
            $offset += $size;
        }
        if ($chunks === [] || count($chunks) > self::LAST_DATA_MAX_CHUNKS) {
            $this->SendDebug('LastData', sprintf('Zustand nicht gepuffert (%d Bytes)', $length), 0);
            $this->ClearLastData();
            return;
        }
        $old = $this->ReadLastDataParts();
        foreach ($chunks as $i => $chunk) {
            $this->SetBuffer($i === 0 ? 'LastData' : 'LastData' . $i, $chunk);
        }
        for ($i = count($chunks); $i < $old; $i++) {
            $this->SetBuffer('LastData' . $i, ''); // Stücke eines früheren, größeren Zustands
        }
        $this->SetBuffer('LastMeta', (string) json_encode([
            'at'    => (int) ($payload['generatedAt'] ?? 0),
            'until' => $this->PayloadValidUntil($payload),
            'len'   => $length,
            'parts' => count($chunks),
            'ok'    => !empty($payload['allEvents'])
        ]));
    }

    private function ClearLastData(): void
    {
        $parts = $this->ReadLastDataParts();
        $this->SetBuffer('LastData', '');
        for ($i = 1; $i < $parts; $i++) {
            $this->SetBuffer('LastData' . $i, '');
        }
        $this->SetBuffer('LastMeta', '');
    }

    /** Anzahl der Stücke laut LastMeta (1, wenn er fehlt). */
    private function ReadLastDataParts(): int
    {
        $meta = json_decode($this->GetBuffer('LastMeta'), true);
        return is_array($meta) && is_int($meta['parts'] ?? null) ? max(1, min($meta['parts'], self::LAST_DATA_MAX_CHUNKS)) : 1;
    }

    /**
     * Bis wann der Zustand stimmt: bis ein Termin der Tagesliste beginnt oder endet (Hervorhebung „läuft“), bis zum
     * Tageswechsel (Heute/Morgen, Tagesliste) und höchstens ein Aktualisierungsintervall lang – so alt ist der Stand
     * einer schon offenen Kachel auch. Termine, die das Kalendermodul seitdem neu hat, zeigt das nächste Update.
     */
    private function PayloadValidUntil(array $payload): int
    {
        $at = (int) ($payload['generatedAt'] ?? 0);
        $until = min($at + max(1, $this->ReadPropertyInteger('UpdateInterval')) * 60 + self::LAST_DATA_GRACE, (int) strtotime('tomorrow', $at));
        foreach ($payload['days'] ?? [] as $day) {
            foreach ($day['events'] ?? [] as $e) {
                $from = (int) ($e['from'] ?? 0);
                $to = (int) ($e['to'] ?? 0);
                if (!empty($e['running'])) {
                    $until = min($until, $to);
                } elseif ($from > $at) {
                    $until = min($until, $from);
                } elseif ($to > $at) {
                    $until = min($until, $at); // läuft schon, war beim Bauen aber noch nicht markiert
                }
            }
        }
        return $until;
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

    /**
     * Zustand der Kachel aus dem Kalendermodul. Ohne $allowDownload (Öffnen der Kachel) null, wenn die Termine nur
     * über einen Neuabruf (<Prefix>_UpdateCalendar) zu bekommen wären.
     */
    private function BuildPayload(bool $allowDownload = true): ?array
    {
        $calendarID = $this->ReadPropertyInteger('CalendarID');
        if ($calendarID <= 0 || !IPS_InstanceExists($calendarID)) {
            $this->SendDebug('BuildPayload', 'Abbruch: keine oder ungültige Kalenderinstanz (ID=' . $calendarID . ')', 0);
            return $this->BuildEmptyPayload();
        }
        if (!$allowDownload && ($this->GetCalendarCall($calendarID)['download'] ?? false)) {
            $this->SendDebug('BuildPayload', 'Kein Neuabruf beim Öffnen der Kachel: das Kalendermodul bietet nur _UpdateCalendar', 0);
            return null;
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
            // Auszug statt aller Termine: der volle Ausdruck kostete bei jedem Aufbau ein JSON aller Termine eines Jahres
            $sample = array_slice($events, 0, self::DEBUG_SAMPLE_EVENTS, true);
            $this->SendDebug('FetchEvents', sprintf('Rohdaten (%d von %d): ', count($sample), count($events)) . json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 0);
        }

        $labels = $this->GetFrontendLabels();

        $days = $this->GroupEventsByDay($events, $from, $daysAhead, $labels);
        $grouped = array_sum(array_map(static fn($d) => count($d['events']), $days));
        $this->SendDebug('GroupEventsByDay', sprintf('%d Tage, %d Termine im Fenster', count($days), $grouped), 0);

        $maxEvents = $this->ReadPropertyInteger('MaxEvents');
        if ($maxEvents > 0) {
            $days = $this->LimitEvents($days, $maxEvents);
            $limited = array_sum(array_map(static fn($d) => count($d['events']), $days));
            $this->SendDebug('LimitEvents', sprintf('MaxEvents=%d → %d Termine nach Limit', $maxEvents, $limited), 0);
        }

        $allEvents = $this->BuildAllEvents($events, $labels);
        $this->SendDebug('BuildAllEvents', sprintf('%d Events für Monatsnavigation normalisiert', count($allEvents)), 0);

        return [
            'generatedAt' => time(),
            'days'        => $days,
            'allEvents'   => $allEvents,
            'monthInfo'   => $this->GetMonthInfo(),
            'config'      => $this->GetFrontendConfig(),
            'labels'      => $labels
        ];
    }

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

    /**
     * Ermittelt einen aufrufbaren Kalender-Endpoint.
     * Rückgabe: ['fn' => string, 'argc' => int, 'download' => bool] oder null wenn nichts Passendes gefunden.
     */
    private function GetCalendarCall(int $instanceID): ?array
    {
        // IPS_GetInstance/IPS_GetModule können bei verwaisten Instanzen (Modul-Bibliothek
        // deinstalliert) werfen — @ unterdrückt nur Warnungen, keine Exceptions.
        try {
            $instance = IPS_GetInstance($instanceID);
            $moduleID = $instance['ModuleInfo']['ModuleID'] ?? '';
            if ($moduleID === '') {
                return null;
            }
            $prefix = IPS_GetModule($moduleID)['Prefix'] ?? '';
        } catch (Throwable $e) {
            $this->SendDebug('GetCalendarCall', 'Instanz/Modul nicht lesbar: ' . $e->getMessage(), 0);
            return null;
        }
        if ($prefix === '') {
            return null;
        }

        // Kandidaten in Prioritäts-Reihenfolge:
        // 1. <Prefix>_GetCachedCalendar($id)         – liefert den Cache ohne externen Abruf (bevorzugt, z. B. ICCR)
        // 2. <Prefix>_UpdateCalendar($id)            – erzwingt Neuabruf (Fallback)
        // 3. <Prefix>_GetEvents($id, $from, $to)     – Symcon ~Calendar-Interface
        $candidates = [
            ['fn' => $prefix . '_GetCachedCalendar', 'argc' => 1, 'download' => false],
            ['fn' => $prefix . '_UpdateCalendar',    'argc' => 1, 'download' => true],
            ['fn' => $prefix . '_GetEvents',         'argc' => 3, 'download' => false]
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
