<div style="background: #f0f4ff; border: 1px solid #4a6cf7; border-radius: 8px; padding: 16px; margin-bottom: 16px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 14px; color: #1a1a1a;">
    <div style="display: flex; align-items: center; margin-bottom: 12px;">
        <span style="font-size: 20px; margin-right: 8px;">📅</span>
        <strong style="font-size: 16px; color: #2d3748;">
            @if($event['method'] === 'CANCEL')
                <span style="color: #e53e3e;">ABGESAGT:</span>
            @elseif($event['method'] === 'REPLY')
                Antwort:
            @else
                Einladung:
            @endif
            {{ $event['summary'] }}
        </strong>
    </div>

    <table style="border-collapse: collapse; width: 100%;">
        @if($event['dtstart'])
        <tr>
            <td style="padding: 4px 12px 4px 0; vertical-align: top; color: #718096; white-space: nowrap;">📆 Datum:</td>
            <td style="padding: 4px 0;">
                {{ $event['dtstart']->format('d.m.Y') }}
                @if($event['dtend'] && $event['dtstart']->format('d.m.Y') !== $event['dtend']->format('d.m.Y'))
                    – {{ $event['dtend']->format('d.m.Y') }}
                @endif
            </td>
        </tr>
        <tr>
            <td style="padding: 4px 12px 4px 0; vertical-align: top; color: #718096; white-space: nowrap;">🕐 Uhrzeit:</td>
            <td style="padding: 4px 0;">
                {{ $event['dtstart']->format('H:i') }} – {{ $event['dtend'] ? $event['dtend']->format('H:i') : '' }} Uhr
            </td>
        </tr>
        @endif

        @if($event['organizer'])
        <tr>
            <td style="padding: 4px 12px 4px 0; vertical-align: top; color: #718096; white-space: nowrap;">👤 Organisator:</td>
            <td style="padding: 4px 0;">{{ $event['organizer'] }}</td>
        </tr>
        @endif

        @if($event['location'])
        <tr>
            <td style="padding: 4px 12px 4px 0; vertical-align: top; color: #718096; white-space: nowrap;">📍 Ort:</td>
            <td style="padding: 4px 0;">{{ $event['location'] }}</td>
        </tr>
        @endif

        @if(!empty($event['attendees']))
        <tr>
            <td style="padding: 4px 12px 4px 0; vertical-align: top; color: #718096; white-space: nowrap;">👥 Teilnehmer:</td>
            <td style="padding: 4px 0;">{{ implode(', ', $event['attendees']) }}</td>
        </tr>
        @endif
    </table>

    {{-- RSVP Buttons (nur bei REQUEST, nicht bei CANCEL/REPLY) --}}
    @if($event['method'] === 'REQUEST' && $event['uid'] && $event['organizer_email'])
    @php
        $rsvpId = 'cal-rsvp-' . md5($event['uid']);
    @endphp
    <div id="{{ $rsvpId }}" style="margin-top: 12px; display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
        <span style="color: #718096; font-size: 13px; margin-right: 4px;">Antworten:</span>
        <button class="cal-invite-rsvp" type="button"
                data-status="ACCEPTED"
                data-uid="{{ e($event['uid']) }}"
                data-summary="{{ e($event['summary'] ?? '') }}"
                data-dtstart="{{ e($event['dtstart_raw'] ?? '') }}"
                data-dtend="{{ e($event['dtend_raw'] ?? '') }}"
                data-sequence="{{ e($event['sequence']) }}"
                data-organizer-email="{{ e($event['organizer_email']) }}"
                data-thread-id="{{ e($thread_id ?? '') }}"
                data-rsvp-url="{{ e(url('/calendar-invite/rsvp')) }}"
                data-rsvp-group="{{ $rsvpId }}"
                style="background: #38a169; color: #fff; padding: 6px 14px; border-radius: 5px; border: none; cursor: pointer; font-weight: 500; font-size: 13px; font-family: inherit;">
            ✓ Ja
        </button>
        <button class="cal-invite-rsvp" type="button"
                data-status="TENTATIVE"
                data-uid="{{ e($event['uid']) }}"
                data-summary="{{ e($event['summary'] ?? '') }}"
                data-dtstart="{{ e($event['dtstart_raw'] ?? '') }}"
                data-dtend="{{ e($event['dtend_raw'] ?? '') }}"
                data-sequence="{{ e($event['sequence']) }}"
                data-organizer-email="{{ e($event['organizer_email']) }}"
                data-thread-id="{{ e($thread_id ?? '') }}"
                data-rsvp-url="{{ e(url('/calendar-invite/rsvp')) }}"
                data-rsvp-group="{{ $rsvpId }}"
                style="background: #d69e2e; color: #fff; padding: 6px 14px; border-radius: 5px; border: none; cursor: pointer; font-weight: 500; font-size: 13px; font-family: inherit;">
            ? Vielleicht
        </button>
        <button class="cal-invite-rsvp" type="button"
                data-status="DECLINED"
                data-uid="{{ e($event['uid']) }}"
                data-summary="{{ e($event['summary'] ?? '') }}"
                data-dtstart="{{ e($event['dtstart_raw'] ?? '') }}"
                data-dtend="{{ e($event['dtend_raw'] ?? '') }}"
                data-sequence="{{ e($event['sequence']) }}"
                data-organizer-email="{{ e($event['organizer_email']) }}"
                data-thread-id="{{ e($thread_id ?? '') }}"
                data-rsvp-url="{{ e(url('/calendar-invite/rsvp')) }}"
                data-rsvp-group="{{ $rsvpId }}"
                style="background: #e53e3e; color: #fff; padding: 6px 14px; border-radius: 5px; border: none; cursor: pointer; font-weight: 500; font-size: 13px; font-family: inherit;">
            ✗ Nein
        </button>
    </div>
    @endif

    {{-- Action Buttons (Teams-Link, Terminplaner) --}}
    <div style="margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap;">
        @if($event['teams_link'])
        <a href="{{ $event['teams_link'] }}" target="_blank" rel="noopener"
           style="display: inline-block; background: #4a6cf7; color: #fff; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-weight: 500;">
            🔗 An Besprechung teilnehmen
        </a>
        @endif

        @if($event['dtstart'] && $event['method'] !== 'CANCEL')
        @php
            $btnId = 'cal-draft-' . md5(($event['summary'] ?? '') . ($event['dtstart'] ? $event['dtstart']->format('c') : ''));
        @endphp
        <button id="{{ $btnId }}" class="cal-invite-create-draft" type="button"
                data-summary="{{ e($event['summary'] ?? '') }}"
                data-start="{{ $event['dtstart'] ? $event['dtstart']->format('Y-m-d\TH:i:s') : '' }}"
                data-end="{{ $event['dtend'] ? $event['dtend']->format('Y-m-d\TH:i:s') : '' }}"
                data-organizer="{{ e($event['organizer'] ?? '') }}"
                data-location="{{ e($event['location'] ?? '') }}"
                data-teams-link="{{ e($event['teams_link'] ?? '') }}"
                data-description="{{ e($event['description'] ?? '') }}"
                data-thread-id="{{ e($thread_id ?? '') }}"
                data-create-draft-url="{{ e(route('users.ajax')) }}"
                style="display: inline-block; background: #38a169; color: #fff; padding: 8px 16px; border-radius: 6px; border: none; cursor: pointer; font-weight: 500; font-size: 14px; font-family: inherit;">
            📋 In Terminplaner übernehmen
        </button>
        @endif
    </div>

    @if($event['description'])
    <details style="margin-top: 10px;">
        <summary style="cursor: pointer; color: #718096; font-size: 13px;">Details anzeigen</summary>
        <div style="margin-top: 8px; padding: 8px; background: #fff; border-radius: 4px; white-space: pre-wrap; font-size: 13px; color: #4a5568;">{!! nl2br(e(Str::limit($event['description'], 2000))) !!}</div>
    </details>
    @endif
</div>
