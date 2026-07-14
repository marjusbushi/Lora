@php
    $g = $reservation->guest;
    $room = $reservation->room;
    $type = $room?->roomType?->name;
@endphp
<!DOCTYPE html>
<html lang="sq">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;background:#FAF7F1;font-family:Arial,Helvetica,sans-serif;color:#1F1D1A;">
    <div style="max-width:560px;margin:0 auto;padding:24px;">
        <div style="background:#1F1D1A;color:#FAF7F1;padding:20px 24px;">
            <div style="font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#B08F5E;">Rezervim i ri</div>
            <div style="font-size:22px;margin-top:4px;">{{ $hotelName }}</div>
        </div>
        <div style="background:#ffffff;padding:24px;border:1px solid #EFE9DE;">
            <p style="margin:0 0 16px;font-size:15px;">Erdhi një rezervim i ri nga faqja:</p>
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <tr><td style="padding:8px 0;color:#8A8276;">Nr. rezervimit</td><td style="padding:8px 0;text-align:right;font-weight:bold;">#{{ $reservation->id }}</td></tr>
                <tr><td style="padding:8px 0;color:#8A8276;">Mysafiri</td><td style="padding:8px 0;text-align:right;">{{ trim(($g->first_name ?? '') . ' ' . ($g->last_name ?? '')) ?: 'Mysafir' }}</td></tr>
                @if($g?->email)<tr><td style="padding:8px 0;color:#8A8276;">Email</td><td style="padding:8px 0;text-align:right;">{{ $g->email }}</td></tr>@endif
                @if($g?->phone)<tr><td style="padding:8px 0;color:#8A8276;">Telefon</td><td style="padding:8px 0;text-align:right;">{{ $g->phone }}</td></tr>@endif
                <tr><td style="padding:8px 0;color:#8A8276;">Dhoma</td><td style="padding:8px 0;text-align:right;">{{ $room?->room_number }}{{ $type ? ' — ' . $type : '' }}</td></tr>
                <tr><td style="padding:8px 0;color:#8A8276;">Check-in</td><td style="padding:8px 0;text-align:right;">{{ optional($reservation->check_in_date)->format('d/m/Y') }}</td></tr>
                <tr><td style="padding:8px 0;color:#8A8276;">Check-out</td><td style="padding:8px 0;text-align:right;">{{ optional($reservation->check_out_date)->format('d/m/Y') }}</td></tr>
                <tr><td style="padding:8px 0;color:#8A8276;">Persona</td><td style="padding:8px 0;text-align:right;">{{ $reservation->adults }}</td></tr>
                <tr><td style="padding:12px 0 0;border-top:1px solid #EFE9DE;color:#8A8276;">Total</td><td style="padding:12px 0 0;border-top:1px solid #EFE9DE;text-align:right;font-weight:bold;color:#9A7B4F;">€{{ $reservation->total_amount }}</td></tr>
            </table>
            @if($reservation->notes)
                <p style="margin:16px 0 0;font-size:13px;color:#8A8276;">Shënime: {{ $reservation->notes }}</p>
            @endif
            <p style="margin:20px 0 0;font-size:13px;color:#8A8276;">Statusi: <b>në pritje (pending)</b> — konfirmoje te paneli.</p>
        </div>
    </div>
</body>
</html>
