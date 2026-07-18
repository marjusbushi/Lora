<!doctype html>
<html lang="sq">
<body style="margin:0;background:#f5f7f6;font-family:Arial,sans-serif;color:#17211d">
<div style="max-width:620px;margin:32px auto;background:#fff;border:1px solid #dde5e1;border-radius:12px;padding:28px">
    <p style="margin:0 0 8px;color:#16805f;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase">Lora PMS · Raport periodik</p>
    <h1 style="margin:0 0 12px;font-size:24px">{{ $savedReport->name }}</h1>
    <p style="margin:0 0 22px;color:#66736d;line-height:1.5">Raporti është përditësuar me të dhënat më të fundit të hotelit.</p>
    <a href="{{ $reportUrl }}" style="display:inline-block;background:#16805f;color:#fff;text-decoration:none;border-radius:8px;padding:12px 18px;font-weight:700">Hap raportin</a>
    <p style="margin:22px 0 0;color:#87928d;font-size:12px">Frekuenca: {{ $savedReport->frequency }}</p>
</div>
</body>
</html>
