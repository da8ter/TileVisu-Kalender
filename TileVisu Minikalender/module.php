<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/TileStateBuffer.php';
require_once __DIR__ . '/libs/EventFormatting.php';

class TileVisuMinikalender extends IPSModuleStrict
{
    use \TVKAL\TileStateBuffer;
    use \TVKAL\EventFormatting;

    private const STATUS_ACTIVE = 102;
    private const STATUS_NO_CALENDAR = 201;
    private const STATUS_UNSUPPORTED_CALENDAR = 202;

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
        $this->DebugRawEvents($events);

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
}
