<?php

namespace Modules\CalendarInviteModule\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CalendarInviteController extends Controller
{
    public function createDraft(Request $request)
    {
        $url = config('calendarinvitemodule.terminplaner_url');
        $apiKey = config('calendarinvitemodule.terminplaner_api_key');

        if (!$url || !$apiKey) {
            return response()->json(['error' => 'Terminplaner nicht konfiguriert'], 503);
        }

        $data = $request->only(['patient_name', 'treatment_type', 'notes', 'start_datetime', 'end_datetime']);

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
            return response()->json(['error' => 'Verbindung zum Terminplaner fehlgeschlagen: ' . $error], 502);
        }

        $result = json_decode($response, true);

        if ($httpCode !== 201) {
            return response()->json(['error' => $result['error'] ?? 'Fehler beim Erstellen des Terminentwurfs'], $httpCode ?: 500);
        }

        return response()->json($result);
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

        // Get the mailbox email from the thread's conversation
        $fromEmail = null;
        $fromName = null;
        if ($threadId) {
            try {
                $thread = \App\Thread::find($threadId);
                if ($thread && $thread->conversation && $thread->conversation->mailbox) {
                    $mailbox = $thread->conversation->mailbox;
                    $fromEmail = $mailbox->email;
                    $fromName = $mailbox->name;
                }
            } catch (\Exception $e) {
                // fallback below
            }
        }

        if (!$fromEmail) {
            return response()->json(['error' => 'Mailbox nicht gefunden'], 400);
        }

        // Build ICS REPLY
        $now = gmdate('Ymd\THis\Z');
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
             . "ORGANIZER;CN={$organizerEmail}:MAILTO:{$organizerEmail}\r\n"
             . "ATTENDEE;CN={$fromName};PARTSTAT={$status}:MAILTO:{$fromEmail}\r\n"
             . "END:VEVENT\r\n"
             . "END:VCALENDAR\r\n";

        // Send via Laravel Mail with ICS attachment
        $statusLabels = [
            'ACCEPTED' => 'Zugesagt',
            'DECLINED' => 'Abgesagt',
            'TENTATIVE' => 'Vielleicht',
        ];
        $statusLabel = $statusLabels[$status] ?? $status;
        $subject = "{$statusLabel}: {$summary}";

        try {
            \Mail::raw("Antwort auf Einladung: {$summary}\nStatus: {$statusLabel}\nVon: {$fromName} <{$fromEmail}>", function ($message) use ($organizerEmail, $fromEmail, $fromName, $subject, $ics) {
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
}
