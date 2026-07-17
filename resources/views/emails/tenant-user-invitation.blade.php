<!DOCTYPE html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Ftesë për {{ $invitation->tenant->name }}</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.6;">
    <h1 style="font-size: 22px;">Ftesë për {{ $invitation->tenant->name }}</h1>
    <p>Je ftuar t’i bashkohesh hotelit me rolin <strong>{{ $invitation->role->name }}</strong>.</p>
    <p>
        <a href="{{ $invitationUrl }}" style="display: inline-block; padding: 12px 18px; border-radius: 8px; background: #047857; color: #fff; text-decoration: none;">
            Shiko dhe prano ftesën
        </a>
    </p>
    <p>Linku skadon më {{ $invitation->expires_at->timezone('Europe/Tirane')->format('d.m.Y H:i') }}.</p>
    <p>Nëse nuk e prisje këtë ftesë, mos ndërmerr asnjë veprim.</p>
</body>
</html>
