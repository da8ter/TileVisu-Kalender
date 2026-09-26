<?php

declare(strict_types=1);

/*
 * Isoliertes SDK-Double für TileVisu Minikalender; verbindet sich nie mit einem laufenden Symcon.
 * Kalendermodule sind globale Funktionen <Prefix>_GetCachedCalendar/_UpdateCalendar/_GetEvents, die jeden Aufruf in
 * $GLOBALS['calendarCalls'] festhalten:
 *   CACHED    _GetCachedCalendar (liest den Cache) und _UpdateCalendar (Neuabruf), wie ICCR
 *   DOWNLOAD  nur _UpdateCalendar (jeder Aufruf ist ein Neuabruf)
 *   EVENTS    nur _GetEvents($id, $from, $to) (~Calendar-Interface)
 * RegisterOnceTimer merkt den Timer nur vor (Symcon führt ihn danach in einem eigenen Thread aus); fireOnce() spielt
 * die vorgemerkten ab.
 * Aufruf aus dem Repository-Ordner: tests/run.sh
 */

const KR_READY = 10103, IPS_KERNELSTARTED = 10001, KL_ERROR = 10205, KL_WARNING = 10204;

class IPSModuleStrict
{
    public int $InstanceID;
    public array $properties = [], $buffers = [], $timers = [], $onceTimers = [], $updates = [], $logs = [], $debug = [], $references = [], $messages = [];
    public int $status = 0;

    public function __construct(int $InstanceID = 12345) { $this->InstanceID = $InstanceID; }
    public function Create(): void {}
    public function ApplyChanges(): void {}
    public function Destroy(): void {}
    protected function RegisterPropertyInteger(string $k, int $v): void { $this->properties[$k] = $v; }
    protected function RegisterPropertyBoolean(string $k, bool $v): void { $this->properties[$k] = $v; }
    protected function ReadPropertyInteger(string $k): int { return $this->properties[$k]; }
    protected function ReadPropertyBoolean(string $k): bool { return $this->properties[$k]; }
    protected function RegisterTimer(string $name, int $ms, string $script): void { $this->timers[$name] = $ms; }
    protected function SetTimerInterval(string $name, int $ms): void { $this->timers[$name] = $ms; }
    protected function RegisterOnceTimer(string $Ident, string $ScriptText): bool { $this->onceTimers[] = [$Ident, $ScriptText]; return true; }
    protected function SetVisualizationType(int $type): void {}
    protected function UpdateVisualizationValue(string $value): void { $this->updates[] = $value; }
    protected function GetBuffer(string $name): string { return $this->buffers[$name] ?? ''; }
    protected function SetBuffer(string $name, string $data): void { $this->buffers[$name] = $data; }
    protected function SendDebug(string $message, string $data, int $format): void { $this->debug[] = [$message, $data]; }
    protected function LogMessage(string $message, int $type): void { $this->logs[] = [$message, $type]; }
    protected function Translate(string $text): string { return $text; }
    protected function SetStatus(int $status): void { $this->status = $status; }
    protected function GetReferenceList(): array { return array_keys($this->references); }
    protected function RegisterReference(int $id): void { $this->references[$id] = true; }
    protected function UnregisterReference(int $id): void { unset($this->references[$id]); }
    protected function RegisterMessage(int $id, int $message): void { $this->messages[$id][] = $message; }
}

$GLOBALS['runlevel'] = KR_READY;
$GLOBALS['instances'] = [];      // Instanz-ID => Modul-GUID
$GLOBALS['modules'] = [];        // Modul-GUID => Präfix
$GLOBALS['calendarEvents'] = []; // was jedes Kalendermodul liefert
$GLOBALS['calendarCalls'] = [];  // [Funktion, Argumente]

function IPS_GetKernelRunlevel(): int { return $GLOBALS['runlevel']; }
function IPS_InstanceExists(int $id): bool { return isset($GLOBALS['instances'][$id]); }
function IPS_GetInstance(int $id): array { return ['InstanceID' => $id, 'ModuleInfo' => ['ModuleID' => $GLOBALS['instances'][$id]]]; }
function IPS_GetModule(string $guid): array { return ['ModuleID' => $guid, 'Prefix' => $GLOBALS['modules'][$guid]]; }

function CACHED_GetCachedCalendar(int $id): string { $GLOBALS['calendarCalls'][] = [__FUNCTION__, [$id]]; return (string) json_encode($GLOBALS['calendarEvents']); }
function CACHED_UpdateCalendar(int $id): string { $GLOBALS['calendarCalls'][] = [__FUNCTION__, [$id]]; return (string) json_encode($GLOBALS['calendarEvents']); }
function DOWNLOAD_UpdateCalendar(int $id): string { $GLOBALS['calendarCalls'][] = [__FUNCTION__, [$id]]; return (string) json_encode($GLOBALS['calendarEvents']); }
function EVENTS_GetEvents(int $id, int $from, int $to): array { $GLOBALS['calendarCalls'][] = [__FUNCTION__, [$id, $from, $to]]; return $GLOBALS['calendarEvents']; }

/** Kalenderinstanz eines der Kalendermodule oben; liefert ihre ID. */
function calendar(string $prefix): int
{
    $id = 20000 + count($GLOBALS['instances']);
    $guid = '{' . $prefix . '}';
    $GLOBALS['instances'][$id] = $guid;
    $GLOBALS['modules'][$guid] = $prefix;
    return $id;
}

/** Ein Termin, wie Kalendermodule ihn liefern. */
function event(string $uid, int $from, int $to, string $name, array $extra = []): array
{
    return $extra + ['UID' => $uid, 'Name' => $name, 'From' => $from, 'To' => $to, 'allDay' => false, 'Location' => '', 'Description' => '', 'Categories' => '', 'Status' => 'CONFIRMED'];
}

/**
 * Führt die vorgemerkten Einmal-Timer aus, wie Symcon es nach dem laufenden Aufruf täte; nur Skripte der Form
 * TVKAL_<Methode>($_IPS['TARGET']); sind bekannt. Liefert, wie viele liefen.
 */
function fireOnce(IPSModuleStrict $m): int
{
    $pending = $m->onceTimers;
    $m->onceTimers = [];
    foreach ($pending as [$ident, $script]) {
        if (!preg_match('/^TVKAL_(\w+)\(\$_IPS\[\'TARGET\'\]\);$/', $script, $match) || !is_callable([$m, $match[1]])) {
            throw new RuntimeException('Unbekanntes Skript im Einmal-Timer ' . $ident . ': ' . $script);
        }
        $m->{$match[1]}();
    }
    return count($pending);
}

/** Aufrufe eines Kalendermoduls seit dem letzten Zurücksetzen. */
function calls(): array
{
    $calls = array_column($GLOBALS['calendarCalls'], 0);
    $GLOBALS['calendarCalls'] = [];
    return $calls;
}

$GLOBALS['checks'] = 0;
function check(bool $condition, string $label): void
{
    $GLOBALS['checks']++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "ok   $label\n";
}

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return true; // mit @ unterdrückt, wie PHP selbst
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__ . '/../TileVisu Minikalender/module.php';
