<?php

namespace Modules\CalendarInviteModule\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CalendarInviteController extends Controller
{
    public function createDraft(Request $request)
    {
        $thread = $this->authorizedThread($request->input('thread_id'));
        if (!$thread) {
            return response()->json(['error' => 'Thread nicht gefunden oder kein Zugriff'], 403);
        }

        $url = config('calendarinvitemodule.terminplaner_url');
        $apiKey = config('calendarinvitemodule.terminplaner_api_key');

        if (!$url || !$apiKey) {
            return response()->json(['error' => 'Terminplaner nicht konfiguriert'], 503);
        }

        $validationError = $this->validateDraftRequest($request);
        if ($validationError) {
            return response()->json(['error' => $validationError], 422);
        }

        $data = [
            'patient_name' => $this->cleanText($request->input('patient_name'), 255),
            'treatment_type' => $this->cleanText($request->input('treatment_type'), 255),
            'notes' => $this->cleanText($request->input('notes', ''), 10000),
            'start_datetime' => $request->input('start_datetime'),
            'end_datetime' => $request->input('end_datetime'),
        ];

        $ch = curl_init(rtrim($url, '/') . '/api/external/appointments/draft');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Api-Key: ' . $apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            \Log::error('CalendarInvite createDraft Terminplaner connection failed', [
                'error' => $error,
            ]);

            return response()->json(['error' => 'Verbindung zum Terminplaner fehlgeschlagen'], 502);
        }

        $result = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = is_array($result) && !empty($result['error'])
                ? $result['error']
                : trim(strip_tags((string)$response));
            $message = $message ? mb_substr($message, 0, 300) : 'Fehler beim Erstellen des Terminentwurfs';

            \Log::error('CalendarInvite createDraft unexpected Terminplaner response', [
                'http_code' => $httpCode,
                'message' => $message,
            ]);

            return response()->json(['error' => 'Terminplaner konnte den Entwurf nicht erstellen'], 502);
        }

        if (!is_array($result) || empty($result['draft_id'])) {
            \Log::error('CalendarInvite createDraft invalid Terminplaner JSON', [
                'http_code' => $httpCode,
                'response' => mb_substr((string)$response, 0, 300),
            ]);

            return response()->json(['error' => 'Terminplaner lieferte keine Entwurfs-ID'], 502);
        }

        return response()->json($result, 201);
    }

    public function rsvp(Request $request)
    {
        $status = $request->input('status'); // ACCEPTED, DECLINED, TENTATIVE
        $organizerEmail = $request->input('organizer_email');
        $uid = $request->input('uid');
        $summary = $request->input('summary');
        $dtstart = $request->input('dtstart');
        $dtend = $request->input('dtend');
        $sequence = $request->input('sequence', '0');
        $threadId = $request->input('thread_id');

        if (!in_array($status, ['ACCEPTED', 'DECLINED', 'TENTATIVE'])) {
            return response()->json(['error' => 'Ungültiger Status'], 400);
        }

        if (!$organizerEmail || !$uid) {
            return response()->json(['error' => 'Organisator-Email oder UID fehlt'], 400);
        }

        if (!filter_var($organizerEmail, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['error' => 'Ungültige Organisator-Email'], 400);
        }

        $thread = $this->authorizedThread($threadId);
        if (!$thread) {
            return response()->json(['error' => 'Thread nicht gefunden oder kein Zugriff'], 403);
        }

        $mailbox = $thread->conversation->mailbox;
        $fromEmail = $mailbox->email;
        $fromName = $mailbox->name;

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['error' => 'Mailbox-Email ungültig'], 400);
        }

        $dtstart = $this->cleanIcsDateTime($dtstart);
        $dtend = $this->cleanIcsDateTime($dtend);
        if (!$dtstart || !$dtend) {
            return response()->json(['error' => 'Ungültige Terminzeit'], 400);
        }

        // Build ICS REPLY
        $now = gmdate('Ymd\THis\Z');
        $uid = $this->escapeIcsText($uid);
        $summary = $this->escapeIcsText($summary);
        $sequence = preg_match('/^\d+$/', (string)$sequence) ? (string)$sequence : '0';
        $organizerCn = $this->escapeIcsParam($organizerEmail);
        $fromNameCn = $this->escapeIcsParam($fromName ?: $fromEmail);
        $ics = "BEGIN:VCALENDAR\r\n"
             . "VERSION:2.0\r\n"
             . "PRODID:-//FreeScout CalendarInviteModule//EN\r\n"
             . "METHOD:REPLY\r\n"
             . "BEGIN:VEVENT\r\n"
             . "UID:{$uid}\r\n"
             . "SEQUENCE:{$sequence}\r\n"
             . "DTSTAMP:{$now}\r\n"
             . "DTSTART:{$dtstart}\r\n"
             . "DTEND:{$dtend}\r\n"
             . "SUMMARY:{$summary}\r\n"
             . "ORGANIZER;CN=\"{$organizerCn}\":MAILTO:{$organizerEmail}\r\n"
             . "ATTENDEE;CN=\"{$fromNameCn}\";PARTSTAT={$status}:MAILTO:{$fromEmail}\r\n"
             . "END:VEVENT\r\n"
             . "END:VCALENDAR\r\n";

        // Send via Laravel Mail with ICS attachment
        $statusLabels = [
            'ACCEPTED' => 'Zugesagt',
            'DECLINED' => 'Abgesagt',
            'TENTATIVE' => 'Vielleicht',
        ];
        $statusLabel = $statusLabels[$status] ?? $status;
        $subject = $this->cleanHeaderText($statusLabel . ': ' . $request->input('summary', ''));

        try {
            $mailBody = "Antwort auf Einladung: " . $this->cleanText($request->input('summary', ''), 255) . "\n"
                . "Status: {$statusLabel}\n"
                . "Von: " . $this->cleanText($fromName, 255) . " <{$fromEmail}>";

            \Mail::raw($mailBody, function ($message) use ($organizerEmail, $fromEmail, $fromName, $subject, $ics) {
                $message->from($fromEmail, $fromName)
                        ->to($organizerEmail)
                        ->subject($subject);

                // Attach ICS as text/calendar with METHOD=REPLY
                $message->attachData($ics, 'response.ics', [
                    'mime' => 'text/calendar; method=REPLY',
                ]);

                // Also set content-type alternative for better Outlook compatibility
                $message->getSwiftMessage()->getHeaders()->addTextHeader(
                    'Content-Class', 'urn:content-classes:calendarmessage'
                );
            });
        } catch (\Exception $e) {
            \Log::error('CalendarInvite RSVP failed: ' . $e->getMessage());
            return response()->json(['error' => 'Mail-Versand fehlgeschlagen: ' . $e->getMessage()], 500);
        }

        return response()->json(['success' => true, 'status' => $statusLabel], 200);
    }

    protected function authorizedThread($threadId)
    {
        if (!$threadId) {
            return null;
        }

        $thread = \App\Thread::find($threadId);
        if (!$thread || !$thread->conversation || !$thread->conversation->mailbox) {
            return null;
        }

        $user = auth()->user();
        if (!$user || !$user->can('viewCached', $thread->conversation)) {
            return null;
        }

        return $thread;
    }

    protected function validateDraftRequest(Request $request)
    {
        if (!trim((string)$request->input('patient_name'))) {
            return 'Terminname fehlt';
        }
        if (!trim((string)$request->input('treatment_type'))) {
            return 'Terminart fehlt';
        }
        if (!$this->isLocalDateTime($request->input('start_datetime'))) {
            return 'Startzeit fehlt oder ist ungültig';
        }
        if (!$this->isLocalDateTime($request->input('end_datetime'))) {
            return 'Endzeit fehlt oder ist ungültig';
        }
        if (strcmp($request->input('end_datetime'), $request->input('start_datetime')) < 0) {
            return 'Endzeit liegt vor Startzeit';
        }

        return null;
    }

    protected function isLocalDateTime($value)
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $value)) {
            return false;
        }

        $dt = \DateTime::createFromFormat('!Y-m-d\TH:i:s', $value);
        $errors = \DateTime::getLastErrors();

        return $dt !== false
            && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $dt->format('Y-m-d\TH:i:s') === $value;
    }

    protected function cleanText($value, $maxLength)
    {
        if (is_array($value) || is_object($value)) {
            $value = '';
        }
        $value = str_replace("\0", '', (string)$value);
        $value = trim($value);

        return mb_substr($value, 0, $maxLength);
    }

    protected function cleanHeaderText($value)
    {
        if (is_array($value) || is_object($value)) {
            $value = '';
        }

        return trim(str_replace(["\r", "\n"], ' ', (string)$value));
    }

    protected function cleanIcsDateTime($value)
    {
        if (is_array($value) || is_object($value)) {
            return null;
        }
        $value = trim((string)$value);

        return preg_match('/^\d{8}(?:T\d{6}Z?)?$/', $value) ? $value : null;
    }

    protected function escapeIcsText($value)
    {
        if (is_array($value) || is_object($value)) {
            $value = '';
        }
        $value = str_replace(["\r", "\n"], ' ', (string)$value);

        return str_replace(
            ['\\', ';', ',', "\n"],
            ['\\\\', '\\;', '\\,', '\\n'],
            $value
        );
    }

    protected function escapeIcsParam($value)
    {
        if (is_array($value) || is_object($value)) {
            $value = '';
        }
        $value = str_replace(["\r", "\n"], ' ', (string)$value);
        $value = preg_replace('/[\x00-\x1F\x7F";:]/', ' ', $value);

        return str_replace(
            ['\\'],
            ['\\\\'],
            $value
        );
    }
}
