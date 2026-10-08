<?php

declare(strict_types=1);

/*
 * Öffnen der Kachel (GetVisualizationTile): der Zustand kommt aus dem Puffer LastData, solange er aktuell genug ist,
 * sonst aus dem Cache des Kalendermoduls; ein Neuabruf (<Prefix>_UpdateCalendar) läuft beim Öffnen nie synchron. Fehlt
 * dann ein brauchbarer Stand, stößt das Öffnen einmal ein Update im Hintergrund an (RegisterOnceTimer, mit Sperre).
 * Die Kachel ist mit und ohne Puffer byte-gleich; große Zustände liegen in Stücken unter der Puffergrenze; der
 * Debug-Auszug der Rohdaten bleibt klein.
 */

require __DIR__ . '/bootstrap.php';

const MODULE_HTML = __DIR__ . '/../TileVisu Minikalender/module.html';
const BOOT_PREFIX = '<script>(()=>{const data=';
const BOOT_SUFFIX = ';if(typeof handleMessage==="function"){handleMessage(data);}else{window.__tvkalInitialData=data;}})();</script>';

/** Kachel an $calendarID, gespeichert wie aus dem Formular (ApplyChanges → Update). */
function tile(int $calendarID, array $properties = []): TileVisuMinikalender
{
    $m = new TileVisuMinikalender();
    $m->Create();
    $m->properties = array_replace($m->properties, ['CalendarID' => $calendarID], $properties);
    $m->ApplyChanges();
    return $m;
}

/** Das JSON des Erstaufbaus; null, wenn die Seite nicht module.html plus Startskript ist. */
function tileJson(string $html): ?string
{
    $head = (string) file_get_contents(MODULE_HTML) . BOOT_PREFIX;
    if (!str_starts_with($html, $head) || !str_ends_with($html, BOOT_SUFFIX)) {
        return null;
    }
    return substr($html, strlen($head), -strlen(BOOT_SUFFIX));
}

function priv(TileVisuMinikalender $m, string $method, mixed ...$args): mixed
{
    return \Closure::bind(fn (): mixed => $this->$method(...$args), $m, TileVisuMinikalender::class)();
}

function meta(TileVisuMinikalender $m): array
{
    return (array) json_decode($m->buffers['LastMeta'] ?? '', true);
}

/** LastMeta ändern, als wäre der Puffer anders entstanden. */
function patchMeta(TileVisuMinikalender $m, array $changes): void
{
    $m->buffers['LastMeta'] = (string) json_encode(array_replace(meta($m), $changes));
}

// Kurz vor Mitternacht warten: der Tageswechsel macht den Puffer ungültig, mitten im Lauf wäre das Ergebnis Zufall
while (strtotime('tomorrow') - time() < 90) {
    sleep(5);
}
$now = time();
$GLOBALS['calendarEvents'] = [
    event('r1', $now - 3600, $now + 3600, 'Läuft gerade'),
    event('f1', (int) strtotime('+2 days 10:00'), (int) strtotime('+2 days 11:00'), 'Zahnarzt', ['Location' => 'Praxis']),
    event('a1', (int) strtotime('+3 days 00:00'), (int) strtotime('+4 days 00:00'), 'Grüße ✓ Feiertag', ['allDay' => true]),
    event('p1', (int) strtotime('-10 days 09:00'), (int) strtotime('-10 days 10:00'), 'Vergangen'),
    event('y1', (int) strtotime('+200 days 12:00'), (int) strtotime('+200 days 13:00'), 'Urlaub'),
];

echo "== Öffnen: Zustand aus dem Puffer, byte-gleich\n";
$cal = calendar('CACHED');
calls();
$m = tile($cal);
check(calls() === ['CACHED_GetCachedCalendar'] && meta($m)['ok'] === true, 'ApplyChanges → Update liest den Cache des Kalendermoduls und füllt den Puffer');
$tries = 0;
do {
    $t0 = time();
    $m->Update();
    $pushed = (string) end($m->updates);
    calls();
    $with = $m->GetVisualizationTile();
    $withCalls = calls();
    $m->buffers = [];
    $without = $m->GetVisualizationTile();
    $withoutCalls = calls();
} while (time() !== $t0 && ++$tries < 5); // generatedAt zählt Sekunden: beide Aufbauten in derselben
check($withCalls === [], 'Öffnen mit aktuellem Puffer ruft das Kalendermodul nicht auf');
check(tileJson($with) === $pushed, 'die geöffnete Kachel zeigt genau den Zustand, den das letzte Update an offene Kacheln geschickt hat');
check($withoutCalls === ['CACHED_GetCachedCalendar'], 'ohne Puffer: aus dem Cache des Kalendermoduls gebaut (wie bisher), kein Neuabruf');
check($with === $without, 'die ausgelieferte Kachel ist mit und ohne Puffer byte-gleich (' . strlen($with) . ' Bytes)');
$payload = json_decode((string) tileJson($with), true);
check(count($payload['allEvents']) === 5 && $payload['days'][0]['events'][0]['running'] === true, 'Inhalt wie bisher: alle Termine für die Monatsansicht, der laufende Termin hervorgehoben');

$m->buffers['LastData'] = str_replace('Zahnarzt', 'GIFTGIFT', $m->buffers['LastData']);
check(str_contains($m->GetVisualizationTile(), 'GIFTGIFT') && calls() === [], 'Gegenprobe: fremder Inhalt im Puffer wird ausgeliefert, der Vergleich lief also über den Puffer');
patchMeta($m, ['until' => time() - 1]);
$html = $m->GetVisualizationTile();
check(!str_contains($html, 'GIFTGIFT') && calls() === ['CACHED_GetCachedCalendar'] && meta($m)['until'] > time(), 'abgelaufener Puffer: aus dem Cache neu gebaut und wieder abgelegt');
patchMeta($m, ['at' => time() + 60]);
$m->GetVisualizationTile();
check(calls() === ['CACHED_GetCachedCalendar'], 'Puffer aus der Zukunft (Uhr zurückgestellt): neu gebaut');
patchMeta($m, ['len' => meta($m)['len'] + 1]);
$m->GetVisualizationTile();
check(calls() === ['CACHED_GetCachedCalendar'], 'Puffer unvollständig (Länge stimmt nicht): neu gebaut');
$m->buffers = ['LastData' => '{"generatedAt":1}'];
$html = $m->GetVisualizationTile();
check(calls() === ['CACHED_GetCachedCalendar'] && tileJson($html) !== '{"generatedAt":1}', 'Puffer eines älteren Modulstands (ohne LastMeta): neu gebaut');

echo "== Gültigkeit des Puffers\n";
$at = (int) strtotime('today 12:00');
$until = static fn (array $events, int $at, int $interval = 60): int => priv(tile($cal, ['UpdateInterval' => $interval]), 'PayloadValidUntil', ['generatedAt' => $at, 'days' => [['events' => $events]]]);
check($until([], $at) === $at + 3600 + 60, 'ohne Termine: ein Aktualisierungsintervall (60 min) plus eine Minute Spielraum');
check($until([], $at, 5) === $at + 300 + 60, 'Intervall 5 min: fünf Minuten plus Spielraum');
check($until([['from' => $at - 60, 'to' => $at + 600, 'running' => true]], $at) === $at + 600, 'laufender Termin: bis zu seinem Ende');
check($until([['from' => $at + 900, 'to' => $at + 1800, 'running' => false]], $at) === $at + 900, 'kommender Termin: bis zu seinem Beginn');
check($until([['from' => $at - 1, 'to' => $at + 600, 'running' => false]], $at) === $at, 'begonnen, aber nicht markiert: sofort ungültig');
check($until([['from' => $at - 7200, 'to' => $at - 3600, 'running' => false]], $at) === $at + 3660, 'vergangener Termin: ändert nichts');
$late = (int) strtotime('today 23:40');
check($until([], $late) === (int) strtotime('tomorrow'), 'Tageswechsel vor Ablauf des Intervalls: bis Mitternacht');

echo "== Kein Neuabruf beim Öffnen\n";
$download = calendar('DOWNLOAD');
calls();
$d = tile($download);
check(calls() === ['DOWNLOAD_UpdateCalendar'], 'Update (Timer, ApplyChanges, Knopf) ruft weiter neu ab');
$html = $d->GetVisualizationTile();
check(calls() === [] && tileJson($html) === end($d->updates), 'Öffnen: kein Neuabruf, der Stand des letzten Updates');
patchMeta($d, ['until' => time() - 1]);
$stale = priv($d, 'ReadLastData')['json'];
$html = $d->GetVisualizationTile();
check(calls() === [] && tileJson($html) === $stale && $d->onceTimers === [], 'Puffer abgelaufen, aber brauchbar: kein Neuabruf, kein Update im Hintergrund, der letzte Stand bleibt');

echo "== Update im Hintergrund, wenn beim Öffnen kein Stand da ist\n";
$d->buffers = []; // nach Reload oder Kernelstart
$d->debug = [];
$pushes = count($d->updates);
$html = $d->GetVisualizationTile();
$empty = json_decode((string) tileJson($html), true);
check(calls() === [] && $empty['allEvents'] === [] && $empty['days'] === [], 'ohne Stand: das Öffnen ruft _UpdateCalendar nicht synchron auf und liefert sofort den leeren Zustand');
check($d->onceTimers === [['OpenUpdate', "TVKAL_Update(\$_IPS['TARGET']);"]] && count($d->updates) === $pushes, 'genau ein Update im Hintergrund angestoßen (RegisterOnceTimer), beim Öffnen noch nicht gelaufen');
check(in_array('Kein Neuabruf beim Öffnen der Kachel: das Kalendermodul bietet nur _UpdateCalendar', array_column($d->debug, 1), true)
    && in_array('Kein brauchbarer Stand: Update im Hintergrund angestoßen', array_column($d->debug, 1), true), 'beides steht im Debug');
$d->GetVisualizationTile();
$d->GetVisualizationTile();
check(count($d->onceTimers) === 1 && calls() === [], 'weitere Öffnungen innerhalb der Sperre: kein weiteres Update');
check(fireOnce($d) === 1 && calls() === ['DOWNLOAD_UpdateCalendar'], 'das Update im Hintergrund ruft genau einmal neu ab');
$pushed = json_decode((string) end($d->updates), true);
check(count($d->updates) === $pushes + 1 && count($pushed['allEvents']) === 5 && meta($d)['ok'] === true, 'und schickt den Stand an die offenen Kacheln');
$html = $d->GetVisualizationTile();
check(calls() === [] && $d->onceTimers === [] && tileJson($html) === end($d->updates), 'danach öffnet die Kachel aus dem Puffer, ohne weiteres Update');
$lock = $d->buffers['OpenUpdateAt'];
$d->buffers = ['OpenUpdateAt' => $lock];
$d->GetVisualizationTile();
check($d->onceTimers === [], 'Stand wieder weg, die Sperre (5 min) läuft noch: kein neues Update');
$d->buffers['OpenUpdateAt'] = (string) (time() - 301);
$d->GetVisualizationTile();
check(count($d->onceTimers) === 1 && calls() === [], 'nach Ablauf der Sperre: wieder genau ein Update im Hintergrund');
fireOnce($d);
calls();
$d->buffers = ['OpenUpdateAt' => (string) (time() + 3600)];
$d->GetVisualizationTile();
check(count($d->onceTimers) === 1, 'Sperre aus der Zukunft (Uhr zurückgestellt) gilt nicht: Update angestoßen');
fireOnce($d);
calls();
$saved = $GLOBALS['calendarEvents'];
$GLOBALS['calendarEvents'] = [];
$f = tile($download); // der Abruf in ApplyChanges liefert nichts, etwa beim Kernelstart ohne Netz
calls();
$f->GetVisualizationTile();
check(meta($f)['ok'] === false && count($f->onceTimers) === 1 && calls() === [], 'Stand ohne Termine (Abruf beim Start gescheitert): ebenso ein Update im Hintergrund');
$GLOBALS['calendarEvents'] = $saved;
fireOnce($f);
check(calls() === ['DOWNLOAD_UpdateCalendar'] && meta($f)['ok'] === true, 'das Update holt die Termine nach');

$events = calendar('EVENTS');
$e = tile($events);
calls();
patchMeta($e, ['until' => time() - 1]);
$e->GetVisualizationTile();
$call = end($GLOBALS['calendarCalls']);
$GLOBALS['calendarCalls'] = [];
check($call[0] === 'EVENTS_GetEvents' && $call[1][1] === (int) strtotime('-1 year today 00:00:00') && $call[1][2] === (int) strtotime('+1 year today 23:59:59'),
    '~Calendar-Interface: beim Neubau wie bisher _GetEvents über ein Jahr zurück und voraus');

echo "== Leere und ungültige Kalender\n";
$saved = $GLOBALS['calendarEvents'];
$GLOBALS['calendarEvents'] = [];
$n = tile($cal);
check(meta($n)['ok'] === false, 'Kalendermodul ohne Termine (etwa noch ohne Cache nach dem Start): Puffer nicht als aktuell markiert');
calls();
$n->GetVisualizationTile();
check(calls() === ['CACHED_GetCachedCalendar'] && $n->onceTimers === [], 'Öffnen baut dann neu aus dem Cache, statt bis zum nächsten Update leer zu bleiben; kein Update im Hintergrund');
$GLOBALS['calendarEvents'] = $saved;
$x = tile($cal);
$x->properties['CalendarID'] = 0;
$x->ApplyChanges();
check(($x->buffers['LastData'] ?? '') === '' && ($x->buffers['LastMeta'] ?? '') === '', 'Kalenderinstanz entfernt: Puffer geleert');
calls();
$empty = json_decode((string) tileJson($x->GetVisualizationTile()), true);
check(calls() === [] && $empty['allEvents'] === [] && $x->status === 201 && $x->onceTimers === [], 'die Kachel zeigt wie bisher den leeren Zustand, ohne Update im Hintergrund');
$none = calendar('NONE');
$u = tile($none);
$u->GetVisualizationTile();
check($u->status === 202 && ($u->buffers['LastData'] ?? '') === '' && $u->onceTimers === [], 'Kalendermodul ohne passende Funktion: Status 202, Puffer leer, kein Update im Hintergrund');
check($m->onceTimers === [] && $e->onceTimers === [], 'Kalendermodule mit Cache oder _GetEvents: nie ein Update im Hintergrund');

echo "== Große Zustände\n";
$many = [];
$base = (int) strtotime('+5 days 08:00');
for ($i = 0; $i < 600; $i++) {
    $many[] = event('m' . $i, $base + $i * 3600, $base + $i * 3600 + 1800, 'Termin ' . $i . ' – Grüße ✓', ['Description' => str_repeat('Beschreibung mit Umlauten äöü und 😀. ', 6)]);
}
$GLOBALS['calendarEvents'] = array_merge($saved, $many);
$b = tile($cal);
$parts = meta($b)['parts'];
$sizes = array_map('strlen', array_filter($b->buffers, static fn (string $k): bool => str_starts_with($k, 'LastData'), ARRAY_FILTER_USE_KEY));
$valid = array_filter($b->buffers, static fn (string $v, string $k): bool => str_starts_with($k, 'LastData') && preg_match('//u', $v) === 1, ARRAY_FILTER_USE_BOTH);
check($parts >= 2 && max($sizes) < 250000 && count($valid) === count($sizes), sprintf('Zustand von %d Bytes in %d Stücken, jedes unter 250000 Bytes und gültiges UTF-8', meta($b)['len'], $parts));
$raw = array_values(array_filter($b->debug, static fn (array $d): bool => str_starts_with($d[1], 'Rohdaten')));
check(count($raw) === 1 && str_starts_with($raw[0][1], 'Rohdaten (3 von 605): ') && strlen($raw[0][1]) < 5000,
    'Debug der Rohdaten: Auszug aus 3 von 605 Terminen (' . strlen($raw[0][1] ?? '') . ' Bytes) statt aller');
$tries = 0;
do {
    $t0 = time();
    $b->Update();
    calls();
    $with = $b->GetVisualizationTile();
    $withCalls = calls();
    $b->buffers = [];
    $without = $b->GetVisualizationTile();
} while (time() !== $t0 && ++$tries < 5);
check($withCalls === [] && $with === $without && tileJson($with) === end($b->updates), 'aus den Stücken byte-gleich wie ohne Puffer, ohne Aufruf des Kalendermoduls');
$GLOBALS['calendarEvents'] = $saved;
$b->Update();
check(meta($b)['parts'] === 1 && ($b->buffers['LastData1'] ?? '') === '' && ($b->buffers['LastData' . ($parts - 1)] ?? '') === '', 'kleinerer Zustand danach: die übrigen Stücke sind geleert');

foreach (['ä' => 1, '✓' => 2, '😀' => 3] as $char => $tail) {
    $json = str_repeat('a', 200000 - $tail) . $char . str_repeat('b', 10);
    priv($b, 'WriteLastData', $json, ['generatedAt' => time(), 'days' => [], 'allEvents' => [1]]);
    check(preg_match('//u', $b->buffers['LastData']) === 1 && priv($b, 'ReadLastData')['json'] === $json && strlen($b->buffers['LastData']) === 200000 - $tail,
        'Stückgrenze in einem ' . strlen($char) . '-Byte-Zeichen: das Stück endet davor, zusammengesetzt unverändert');
}
$b->debug = [];
priv($b, 'WriteLastData', str_repeat('x', 200000 * 10 + 1), ['generatedAt' => time(), 'days' => [], 'allEvents' => [1]]);
check(($b->buffers['LastData'] ?? '') === '' && ($b->buffers['LastMeta'] ?? '') === '' && str_contains((string) ($b->debug[0][1] ?? ''), 'nicht gepuffert'),
    'Zustand über zehn Stücke: nicht gepuffert, Hinweis im Debug');

// Sicherheit: Termintexte aus fremden Kalendern dürfen den Inline-Block nicht beenden
$x = tile(4711);
$x->ApplyChanges();
$boese = '</script><img src=x onerror=alert(1)>';
priv($x, 'WriteLastData', priv($x, 'EncodePayload', ['generatedAt' => time(), 'days' => [], 'allEvents' => [['title' => $boese]]]),
    ['generatedAt' => time(), 'days' => [], 'allEvents' => [1]]);
$html = $x->GetVisualizationTile();
$block = substr($html, strrpos($html, '<script>(()=>{const data='));
check(!str_contains($html, '</script><img') && substr_count($block, '</script>') === 1, 'Termintext mit </script>: bleibt im Inline-Block (JSON_HEX_TAG)');
check(json_decode(tileJson($html), true)['allEvents'][0]['title'] === $boese, 'der Text kommt in der Kachel unverändert an');
$alt = '{"generatedAt":' . time() . ',"days":[],"allEvents":[{"title":"' . $boese . '"}]}';
priv($x, 'WriteLastData', $alt, ['generatedAt' => time(), 'days' => [], 'allEvents' => [1]]);
check(!str_contains($x->GetVisualizationTile(), '</script><img'), 'auch ein Puffer aus einem älteren Modulstand (ohne Maskierung) beendet den Block nicht');

echo "\nAlle {$GLOBALS['checks']} Prüfungen bestanden.\n";
