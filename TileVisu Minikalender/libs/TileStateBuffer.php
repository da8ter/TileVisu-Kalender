<?php

declare(strict_types=1);

namespace TVKAL;

// Puffer LastData: JSON des zuletzt gebauten Zustands, ab LAST_DATA_CHUNK Bytes in Stücken LastData1, LastData2, …
// (Symcon warnt ab 256 kB je Puffer und kürzt über 512 kB); LastMeta beschreibt ihn.
const LAST_DATA_CHUNK = 200000;
const LAST_DATA_MAX_CHUNKS = 10;
// Spielraum des Update-Timers über das Intervall hinaus, in Sekunden
const LAST_DATA_GRACE = 60;
// Termine im Debug-Auszug der Rohdaten
const DEBUG_SAMPLE_EVENTS = 3;
// Sperre für das Update im Hintergrund beim Öffnen, in Sekunden: mehrere Öffnungen hintereinander lösen einen Abruf aus
const OPEN_UPDATE_LOCK = 300;

/**
 * Zustand der Kachel beim Öffnen (GetVisualizationTile): der Puffer LastData samt Beschreibung LastMeta, wie lange ein
 * Stand gilt, das Update im Hintergrund, wenn keiner da ist, und der Debug-Auszug der Rohdaten. Erwartet von der Klasse
 * BuildPayload(bool): ?array, BuildEmptyPayload(): array, Update() (TVKAL_Update) und die Eigenschaft UpdateInterval.
 */
trait TileStateBuffer
{
    /**
     * Zustand für eine frisch geöffnete Kachel: der Puffer LastData, solange er aktuell genug ist (PayloadValidUntil),
     * sonst neu aus dem Cache des Kalendermoduls. Einen Neuabruf (<Prefix>_UpdateCalendar) macht das Öffnen nie selbst;
     * bietet das Modul nur diesen, bleibt es beim letzten Stand, den der Timer geholt hat. Fehlt ein brauchbarer Stand
     * (nach Reload oder Kernelstart, oder der Abruf lieferte keine Termine), stößt es ein Update im Hintergrund an.
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
            if ($last === null || !$last['meta']['ok']) {
                $this->RequestBackgroundUpdate(); // sonst bliebe die Kachel bis zum nächsten Timer leer
            }
            return $last['json'] ?? $this->EncodePayload($this->BuildEmptyPayload());
        }
        $json = $this->EncodePayload($payload);
        if (!empty($payload['allEvents'])) {
            $this->WriteLastData($json, $payload); // die nächsten Öffnungen brauchen den Cache nicht mehr
        }
        return $json;
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
        if (!is_bool($meta['ok'] ?? null) || $meta['parts'] < 1 || $meta['parts'] > LAST_DATA_MAX_CHUNKS) {
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
            $size = min(LAST_DATA_CHUNK, $length - $offset);
            // höchstens drei Folgebytes zurück: das Stück endet vor dem Zeichen, in das es sonst schnitte
            for ($back = 0; $back < 3 && $offset + $size < $length && (ord($json[$offset + $size]) & 0xC0) === 0x80; $back++) {
                $size--;
            }
            $chunks[] = substr($json, $offset, $size);
            $offset += $size;
        }
        if ($chunks === [] || count($chunks) > LAST_DATA_MAX_CHUNKS) {
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
        return is_array($meta) && is_int($meta['parts'] ?? null) ? max(1, min($meta['parts'], LAST_DATA_MAX_CHUNKS)) : 1;
    }

    /**
     * Bis wann der Zustand stimmt: bis ein Termin der Tagesliste beginnt oder endet (Hervorhebung „läuft“), bis zum
     * Tageswechsel (Heute/Morgen, Tagesliste) und höchstens ein Aktualisierungsintervall lang – so alt ist der Stand
     * einer schon offenen Kachel auch. Termine, die das Kalendermodul seitdem neu hat, zeigt das nächste Update.
     */
    private function PayloadValidUntil(array $payload): int
    {
        $at = (int) ($payload['generatedAt'] ?? 0);
        $until = min($at + max(1, $this->ReadPropertyInteger('UpdateInterval')) * 60 + LAST_DATA_GRACE, (int) strtotime('tomorrow', $at));
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

    /**
     * Stößt einmalig ein Update im Hintergrund an (RegisterOnceTimer): das Öffnen wartet nicht auf den Neuabruf, das
     * Update schickt den Stand danach an die offenen Kacheln. Der Zeitstempel im Puffer OpenUpdateAt sperrt weitere
     * Auslösungen für OPEN_UPDATE_LOCK Sekunden. Bewusst kein SetTimerInterval: das zählte bei jedem Öffnen von vorn
     * und ließe den Update-Timer verhungern.
     */
    private function RequestBackgroundUpdate(): void
    {
        $now = time();
        $requested = (int) $this->GetBuffer('OpenUpdateAt');
        if ($requested <= $now && $now - $requested < OPEN_UPDATE_LOCK) {
            $this->SendDebug('TilePayload', sprintf('Update im Hintergrund schon angestoßen (vor %d s)', $now - $requested), 0);
            return;
        }
        $this->SetBuffer('OpenUpdateAt', (string) $now);
        $this->RegisterOnceTimer('OpenUpdate', 'TVKAL_Update($_IPS[\'TARGET\']);');
        $this->SendDebug('TilePayload', 'Kein brauchbarer Stand: Update im Hintergrund angestoßen', 0);
    }

    /** Debug der Rohdaten: ein Auszug statt aller Termine (der volle Ausdruck kostete bei jedem Aufbau ein JSON aller Termine eines Jahres). */
    private function DebugRawEvents(array $events): void
    {
        if (count($events) > 0) {
            $sample = array_slice($events, 0, DEBUG_SAMPLE_EVENTS, true);
            $this->SendDebug('FetchEvents', sprintf('Rohdaten (%d von %d): ', count($sample), count($events)) . json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 0);
        }
    }
}
