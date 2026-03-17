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
}
