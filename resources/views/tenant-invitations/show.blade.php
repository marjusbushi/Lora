<!DOCTYPE html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Ftesë për {{ $invitation->tenant->name }}</title>
    <style>
        body { margin: 0; background: #f5f5f4; color: #1c1917; font-family: Arial, sans-serif; }
        main { max-width: 520px; margin: 8vh auto; padding: 32px; border: 1px solid #e7e5e4; border-radius: 16px; background: #fff; box-shadow: 0 12px 30px rgba(0,0,0,.06); }
        h1 { margin-top: 0; font-size: 24px; }
        p { line-height: 1.6; }
        button { border: 0; border-radius: 10px; background: #047857; color: #fff; cursor: pointer; font-size: 15px; font-weight: 700; padding: 12px 18px; }
        .success { border-radius: 10px; background: #ecfdf5; color: #065f46; padding: 12px 14px; }
    </style>
</head>
<body>
<main>
    <h1>Ftesë për {{ $invitation->tenant->name }}</h1>

    @if (session('success'))
        <p class="success">{{ session('success') }}</p>
    @endif

    @if ($invitation->accepted_at)
        <p class="success">Kjo ftesë është pranuar. Anëtarësia jote është aktive.</p>
    @else
        <p>Do t’i bashkohesh hotelit me rolin <strong>{{ $invitation->role->name }}</strong>.</p>
        <p>Pranoje vetëm nëse e njeh dhe e pret këtë ftesë.</p>
        <form method="POST" action="{{ $acceptUrl }}">
            @csrf
            <button type="submit">Prano ftesën</button>
        </form>
    @endif
</main>
</body>
</html>
