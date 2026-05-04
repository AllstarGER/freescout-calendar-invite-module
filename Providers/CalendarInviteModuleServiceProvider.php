<?php

namespace Modules\CalendarInviteModule\Providers;

use Illuminate\Support\ServiceProvider;
use Eventy;
use Modules\CalendarInviteModule\Http\Controllers\CalendarInviteController;

class CalendarInviteModuleServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'calendarinvitemodule');
        $this->loadRoutesFrom(__DIR__ . '/../Http/routes.php');

        // Hook into thread body output (4 params: body, thread, conversation, mailbox)
        Eventy::addFilter('thread.body_output', function ($body, $thread, $conversation, $mailbox) {
            return $this->processCalendarInvite($body, $thread);
        }, 20, 4);

        Eventy::addFilter('users.ajax.response_default', function ($response, $request) {
            if ($request->action !== 'calendar_invite_create_draft') {
                return $response;
            }

            $draftResponse = app(CalendarInviteController::class)->createDraft($request);
            $result = json_decode($draftResponse->getContent(), true);
            $result = is_array($result) ? $result : [];

            if ($draftResponse->getStatusCode() >= 200 && $draftResponse->getStatusCode() < 300 && empty($result['error'])) {
                return array_merge([
                    'status' => 'success',
                    'msg' => '',
                ], $result);
            }

            $message = $result['error'] ?? 'Fehler beim Erstellen des Terminentwurfs';

            return array_merge($result, [
                'status' => 'error',
                'msg' => $message,
                'error' => $message,
            ]);
        }, 20, 2);

        Eventy::addAction('javascript', function ($javascripts = null) {
            echo <<<'JS'
(function () {
    if (window.CalendarInviteModuleInitialized) {
        return;
    }
    window.CalendarInviteModuleInitialized = true;

    function csrfToken() {
        var token = document.querySelector('meta[name="csrf-token"]');
        return token ? token.getAttribute('content') : '';
    }

    function parseJson(text) {
        try {
            return JSON.parse(text || '{}');
        } catch (e) {
            return {};
        }
    }

    function postJson(url, data, onload, onerror) {
        var token = csrfToken();
        if (token && data && !data._token) {
            data._token = token;
        }

        if (window.axios && typeof window.axios.post === 'function') {
            window.axios.post(url, data, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function (response) {
                onload({
                    status: response.status,
                    responseText: JSON.stringify(response.data || {})
                });
            }).catch(function (error) {
                if (error && error.response) {
                    onload({
                        status: error.response.status,
                        responseText: JSON.stringify(error.response.data || {})
                    });
                    return;
                }
                onerror();
            });
            return;
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        if (token) {
            xhr.setRequestHeader('X-CSRF-TOKEN', token);
        }
        xhr.onload = function () {
            onload(xhr);
        };
        xhr.onerror = onerror;
        xhr.send(JSON.stringify(data));
    }

    function closestButton(target, selector) {
        if (!target || !target.closest) {
            return null;
        }
        return target.closest(selector);
    }

    function restoreButtons(buttons, activeButton, originalText) {
        activeButton.innerHTML = originalText;
        buttons.forEach(function (button) {
            button.disabled = false;
            button.style.opacity = '1';
        });
    }

    function errorText(xhr, result) {
        if (result && result.error) {
            return result.error;
        }
        return 'HTTP ' + xhr.status + ': Unerwartete Antwort';
    }

    function handleRsvp(btn) {
        if (btn.disabled) {
            return;
        }

        var status = btn.dataset.status;
        var group = document.getElementById(btn.dataset.rsvpGroup);
        var buttons = group ? group.querySelectorAll('button') : [];
        buttons.forEach(function (button) {
            button.disabled = true;
            button.style.opacity = '0.5';
        });
        btn.style.opacity = '1';

        var labels = {ACCEPTED: '✓ Zugesagt', DECLINED: '✗ Abgesagt', TENTATIVE: '? Vielleicht'};
        var originalText = btn.innerHTML;
        btn.innerHTML = '⏳ Sende...';

        postJson(btn.dataset.rsvpUrl || '/calendar-invite/rsvp', {
            status: status,
            uid: btn.dataset.uid,
            summary: btn.dataset.summary,
            dtstart: btn.dataset.dtstart,
            dtend: btn.dataset.dtend,
            sequence: btn.dataset.sequence,
            organizer_email: btn.dataset.organizerEmail,
            thread_id: btn.dataset.threadId
        }, function (xhr) {
            if (xhr.status >= 200 && xhr.status < 300) {
                btn.innerHTML = labels[status] || status;
                btn.style.opacity = '1';
                buttons.forEach(function (button) {
                    if (button !== btn) {
                        button.style.display = 'none';
                    }
                });
                return;
            }

            var result = parseJson(xhr.responseText);
            btn.innerHTML = '❌ ' + (result.error || 'Fehler');
            setTimeout(function () {
                restoreButtons(buttons, btn, originalText);
            }, 3000);
        }, function () {
            btn.innerHTML = '❌ Verbindungsfehler';
            setTimeout(function () {
                restoreButtons(buttons, btn, originalText);
            }, 3000);
        });
    }

    function openCreatedDraft(btn) {
        if (!btn.dataset.createdUrl) {
            return false;
        }
        window.open(btn.dataset.createdUrl, '_blank', 'noopener');
        return true;
    }

    function uniquePush(items, value) {
        value = (value || '').trim();
        if (value && items.indexOf(value) === -1) {
            items.push(value);
        }
    }

    function cleanUrl(url) {
        return (url || '').replace(/[.,;:!?]+$/, '').trim();
    }

    function collectLinks(text, links) {
        var matches = (text || '').match(/(?:https?:\/\/|tel:|sip:)[^\s<>"']+/g) || [];
        matches.forEach(function (url) {
            uniquePush(links, cleanUrl(url));
        });
    }

    function collectJoinLines(text, lines) {
        (text || '').split(/\r?\n/).forEach(function (line) {
            line = line.trim();
            if (!line) {
                return;
            }
            if (/(?:https?:\/\/|tel:|sip:)|teams|zoom|webex|meet\.google|besprechung|teilnehmen|beitreten|meeting|kenncode|passcode|passwort|zugangscode|pin|einwahl|dial|telefon|phone|telefonkonferenz|konferenz|konferenz-id|meeting-id/i.test(line)) {
                uniquePush(lines, line);
            }
        });
    }

    function handleCreateDraft(btn) {
        if (openCreatedDraft(btn) || btn.disabled) {
            return;
        }

        var originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '⏳ Wird erstellt...';
        btn.style.opacity = '0.7';

        var notes = [];
        var description = btn.dataset.description || '';
        var links = [];
        var joinLines = [];

        uniquePush(links, btn.dataset.teamsLink);
        collectLinks(btn.dataset.location, links);
        collectLinks(description, links);
        collectJoinLines(btn.dataset.location, joinLines);
        collectJoinLines(description, joinLines);

        if (links.length) {
            notes.push('Links zur Teilnahme:\n' + links.join('\n'));
        }
        if (joinLines.length) {
            notes.push('Teilnahmeinformationen:\n' + joinLines.join('\n'));
        }
        if (btn.dataset.organizer) {
            notes.push('Organisator: ' + btn.dataset.organizer);
        }
        if (btn.dataset.location) {
            notes.push('Ort: ' + btn.dataset.location);
        }
        if (description.trim()) {
            notes.push('Einladungstext:\n' + description.trim());
        }

        postJson(btn.dataset.createDraftUrl || '/calendar-invite/create-draft', {
            action: 'calendar_invite_create_draft',
            thread_id: btn.dataset.threadId,
            patient_name: btn.dataset.summary || 'Termin',
            treatment_type: 'Besprechung',
            start_datetime: btn.dataset.start,
            end_datetime: btn.dataset.end,
            notes: notes.join('\n')
        }, function (xhr) {
            var result = parseJson(xhr.responseText);
            if (xhr.status >= 200 && xhr.status < 300 && !result.error && (result.url || result.draft_id)) {
                btn.style.background = '#2f855a';
                btn.style.opacity = '1';
                if (result.url) {
                    btn.dataset.createdUrl = result.url;
                    btn.disabled = false;
                    btn.innerHTML = '📋 Im Terminplaner öffnen';
                } else {
                    btn.innerHTML = '✅ Erstellt';
                }
                return;
            }

            btn.innerHTML = '❌ ' + errorText(xhr, result);
            btn.style.background = '#e53e3e';
            setTimeout(function () {
                btn.innerHTML = originalText;
                btn.style.background = '#38a169';
                btn.style.opacity = '1';
                btn.disabled = false;
            }, 3000);
        }, function () {
            btn.innerHTML = '❌ Verbindungsfehler';
            btn.style.background = '#e53e3e';
            setTimeout(function () {
                btn.innerHTML = originalText;
                btn.style.background = '#38a169';
                btn.style.opacity = '1';
                btn.disabled = false;
            }, 3000);
        });
    }

    document.addEventListener('click', function (event) {
        var rsvpButton = closestButton(event.target, '.cal-invite-rsvp');
        if (rsvpButton) {
            event.preventDefault();
            handleRsvp(rsvpButton);
            return;
        }

        var draftButton = closestButton(event.target, '.cal-invite-create-draft');
        if (draftButton) {
            event.preventDefault();
            handleCreateDraft(draftButton);
        }
    });
})();

JS;
        }, 20, 1);
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../Config/config.php', 'calendarinvitemodule');
    }

    protected function processCalendarInvite($body, $thread)
    {
        $icsData = $this->extractIcsFromThread($thread);

        if (!$icsData) {
            return $body;
        }

        $event = $this->parseIcs($icsData);

        if (!$event) {
            return $body;
        }

        try {
            $html = view('calendarinvitemodule::invite', [
                'event' => $event,
                'thread_id' => $thread->id ?? null,
            ])->render();
            return $html . $body;
        } catch (\Exception $e) {
            return $body;
        }
    }

    protected function extractIcsFromThread($thread)
    {
        if (!$thread) {
            return null;
        }

        // 1. Check attachments (invitation.ics, calendar.ics, etc.)
        try {
            if ($thread->attachments && count($thread->attachments) > 0) {
                foreach ($thread->attachments as $attachment) {
                    $name = strtolower($attachment->file_name ?? '');
                    $mime = strtolower($attachment->mime_type ?? '');

                    if ($mime === 'text/calendar' || substr($name, -4) === '.ics') {
                        try {
                            $content = $attachment->getFileContents();
                            if ($content && strpos($content, 'BEGIN:VCALENDAR') !== false) {
                                return $content;
                            }
                        } catch (\Exception $e) {
                            // Try next attachment
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // ignore
        }

        // 2. Check if ICS data is in the body itself
        $bodyText = $thread->body ?? '';
        if (is_string($bodyText) && strpos($bodyText, 'BEGIN:VCALENDAR') !== false) {
            if (preg_match('/BEGIN:VCALENDAR.*?END:VCALENDAR/s', $bodyText, $m)) {
                return html_entity_decode(strip_tags($m[0]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return null;
    }

    protected function parseIcs($ics)
    {
        if (!preg_match('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $match)) {
            return null;
        }

        $vevent = $match[1];

        $event = [
            'summary'        => $this->getIcsField($vevent, 'SUMMARY'),
            'dtstart'        => $this->parseIcsDate($vevent, 'DTSTART'),
            'dtend'          => $this->parseIcsDate($vevent, 'DTEND'),
            'dtstart_raw'    => $this->getIcsField($vevent, 'DTSTART'),
            'dtend_raw'      => $this->getIcsField($vevent, 'DTEND'),
            'location'       => $this->getIcsField($vevent, 'LOCATION'),
            'description'    => $this->getIcsField($vevent, 'DESCRIPTION'),
            'organizer'      => $this->getIcsOrganizer($vevent),
            'organizer_email'=> $this->getIcsOrganizerEmail($vevent),
            'attendees'      => $this->getIcsAttendees($vevent),
            'uid'            => $this->getIcsField($vevent, 'UID'),
            'sequence'       => $this->getIcsField($vevent, 'SEQUENCE') ?: '0',
            'teams_link'     => null,
            'method'         => 'REQUEST',
        ];

        // Extract method from VCALENDAR level
        if (preg_match('/METHOD:(\S+)/i', $ics, $m)) {
            $event['method'] = strtoupper(trim($m[1]));
        }

        // Find Teams/meeting link in description and location
        $allText = ($event['description'] ?? '') . ' ' . ($event['location'] ?? '');
        if (preg_match('/(https:\/\/teams\.microsoft\.com\/[^\s<>"\\\\]+)/i', $allText, $m)) {
            $event['teams_link'] = $this->cleanMeetingLink($m[1]);
        } elseif (preg_match('/(https:\/\/[^\s<>"\\\\]*(?:zoom|webex|meet\.google)[^\s<>"\\\\]*)/i', $allText, $m)) {
            $event['teams_link'] = $this->cleanMeetingLink($m[1]);
        }

        // Unescape fields
        foreach (['summary', 'location', 'description'] as $field) {
            if (!empty($event[$field])) {
                $event[$field] = $this->unescapeIcs($event[$field]);
            }
        }

        return !empty($event['summary']) ? $event : null;
    }

    protected function getIcsField($vevent, $field)
    {
        // Handle fields with parameters like DTSTART;TZID=...:value
        // Also handle folded lines (lines starting with space/tab are continuations)
        $lines = preg_split('/\r?\n/', $vevent);
        $value = null;
        $collecting = false;

        foreach ($lines as $line) {
            if ($collecting) {
                // Continuation line starts with space or tab
                if (strlen($line) > 0 && ($line[0] === ' ' || $line[0] === "\t")) {
                    $value .= substr($line, 1);
                    continue;
                } else {
                    break;
                }
            }

            if (preg_match('/^' . preg_quote($field, '/') . '(?:;[^:]*)?:(.*)$/i', $line, $m)) {
                $value = $m[1];
                $collecting = true;
            }
        }

        return $value !== null ? trim($value) : null;
    }

    protected function parseIcsDate($vevent, $field)
    {
        $dateStr = $this->getIcsField($vevent, $field);
        if (!$dateStr) {
            return null;
        }

        // Check for TZID in the field line
        $lines = preg_split('/\r?\n/', $vevent);
        $tzid = null;
        foreach ($lines as $line) {
            if (preg_match('/^' . preg_quote($field, '/') . ';.*TZID=([^;:]+)/i', $line, $m)) {
                $tzid = trim($m[1]);
                break;
            }
        }

        $dateStr = trim($dateStr);
        $berlinTz = new \DateTimeZone('Europe/Berlin');
        try {
            $sourceTz = $tzid ? new \DateTimeZone($tzid) : $berlinTz;
        } catch (\Exception $e) {
            $sourceTz = $berlinTz;
        }

        $formats = ['Ymd\THis\Z', 'Ymd\THis', 'Ymd'];
        foreach ($formats as $format) {
            $dt = \DateTime::createFromFormat(
                $format,
                $dateStr,
                $format === 'Ymd\THis\Z' ? new \DateTimeZone('UTC') : $sourceTz
            );
            $errors = \DateTime::getLastErrors();
            if ($dt !== false && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                $dt->setTimezone($berlinTz);
                return $dt;
            }
        }
        return null;
    }

    protected function getIcsOrganizerEmail($vevent)
    {
        if (preg_match('/ORGANIZER[^:]*:MAILTO:([^\r\n]+)/i', $vevent, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    protected function getIcsOrganizer($vevent)
    {
        if (preg_match('/ORGANIZER[^:]*;CN=([^;:\r\n]+)/i', $vevent, $m)) {
            return trim($m[1], '" ');
        }
        if (preg_match('/ORGANIZER[^:]*:MAILTO:([^\r\n]+)/i', $vevent, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    protected function getIcsAttendees($vevent)
    {
        $attendees = [];
        if (preg_match_all('/ATTENDEE[^:]*(?:;CN=([^;:\r\n]+))?[^:]*:MAILTO:([^\r\n]+)/i', $vevent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $name = isset($m[1]) ? trim($m[1], '" ') : '';
                $email = trim($m[2]);
                $attendees[] = $name ?: $email;
            }
        }
        return $attendees;
    }

    protected function unescapeIcs($str)
    {
        return str_replace(
            ['\\n', '\\N', '\\,', '\\;', '\\\\'],
            ["\n", "\n", ',', ';', '\\'],
            $str
        );
    }

    protected function cleanMeetingLink($url)
    {
        $url = rtrim($this->unescapeIcs($url), " \t\r\n.,;:!?");

        if (stripos($url, 'https://') !== 0 || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $url;
    }
}
