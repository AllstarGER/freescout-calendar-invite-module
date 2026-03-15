<?php

namespace Modules\CalendarInviteModule\Providers;

use Illuminate\Support\ServiceProvider;
use Eventy;

class CalendarInviteModuleServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'calendarinvitemodule');

        // Hook into thread body output (4 params: body, thread, conversation, mailbox)
        Eventy::addFilter('thread.body_output', function ($body, $thread, $conversation, $mailbox) {
            return $this->processCalendarInvite($body, $thread);
        }, 20, 4);
    }

    public function register()
    {
        //
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
            $html = view('calendarinvitemodule::invite', ['event' => $event])->render();
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
                return html_entity_decode(strip_tags($m[0]));
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
            'summary'     => $this->getIcsField($vevent, 'SUMMARY'),
            'dtstart'     => $this->parseIcsDate($vevent, 'DTSTART'),
            'dtend'       => $this->parseIcsDate($vevent, 'DTEND'),
            'location'    => $this->getIcsField($vevent, 'LOCATION'),
            'description' => $this->getIcsField($vevent, 'DESCRIPTION'),
            'organizer'   => $this->getIcsOrganizer($vevent),
            'attendees'   => $this->getIcsAttendees($vevent),
            'teams_link'  => null,
            'method'      => 'REQUEST',
        ];

        // Extract method from VCALENDAR level
        if (preg_match('/METHOD:(\S+)/i', $ics, $m)) {
            $event['method'] = strtoupper(trim($m[1]));
        }

        // Find Teams/meeting link in description and location
        $allText = ($event['description'] ?? '') . ' ' . ($event['location'] ?? '');
        if (preg_match('/(https:\/\/teams\.microsoft\.com\/[^\s<>"\\\\]+)/i', $allText, $m)) {
            $event['teams_link'] = $this->unescapeIcs($m[1]);
        } elseif (preg_match('/(https:\/\/[^\s<>"\\\\]*(?:zoom|webex|meet\.google)[^\s<>"\\\\]*)/i', $allText, $m)) {
            $event['teams_link'] = $this->unescapeIcs($m[1]);
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

        $formats = ['Ymd\THis\Z', 'Ymd\THis', 'Ymd'];
        foreach ($formats as $format) {
            $dt = \DateTime::createFromFormat($format, $dateStr);
            if ($dt !== false) {
                if ($format === 'Ymd\THis\Z') {
                    // UTC time, convert to Berlin
                    $dt->setTimezone(new \DateTimeZone('Europe/Berlin'));
                } elseif ($tzid) {
                    try {
                        $dt = \DateTime::createFromFormat($format, $dateStr, new \DateTimeZone($tzid));
                        $dt->setTimezone(new \DateTimeZone('Europe/Berlin'));
                    } catch (\Exception $e) {
                        // ignore timezone error
                    }
                }
                return $dt;
            }
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
}
