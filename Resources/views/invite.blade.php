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

    @if($event['teams_link'])
    <div style="margin-top: 12px;">
        <a href="{{ $event['teams_link'] }}" target="_blank" rel="noopener"
           style="display: inline-block; background: #4a6cf7; color: #fff; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-weight: 500;">
            🔗 An Besprechung teilnehmen
        </a>
    </div>
    @endif

    @if($event['description'])
    <details style="margin-top: 10px;">
        <summary style="cursor: pointer; color: #718096; font-size: 13px;">Details anzeigen</summary>
        <div style="margin-top: 8px; padding: 8px; background: #fff; border-radius: 4px; white-space: pre-wrap; font-size: 13px; color: #4a5568;">{!! nl2br(e(Str::limit($event['description'], 2000))) !!}</div>
    </details>
    @endif
</div>
