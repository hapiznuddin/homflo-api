<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Undangan rumah tangga</title>
</head>
<body style="margin:0;padding:24px;background:#f5f5f4;font-family:Arial,Helvetica,sans-serif;color:#1c1917;">
    <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:16px;padding:24px;">
        <h1 style="margin:0 0 16px;font-size:20px;">Undangan bergabung dengan {{ $householdName }}</h1>

        <p style="margin:0 0 12px;font-size:14px;line-height:1.6;">
            {{ $inviterName }} mengundang Anda untuk bergabung dengan rumah tangga
            <strong>{{ $householdName }}</strong> di Homflo.
        </p>

        <p style="margin:0 0 20px;font-size:14px;line-height:1.6;">
            Buka tautan berikut untuk menerima undangan. Anda harus masuk dengan email
            yang menerima undangan ini.
        </p>

        <p style="margin:0 0 20px;">
            <a href="{{ $acceptUrl }}"
               style="display:inline-block;background:#0f766e;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:10px;font-size:14px;">
                Terima undangan
            </a>
        </p>

        <p style="margin:0 0 8px;font-size:12px;line-height:1.6;color:#57534e;">
            Tautan ini bersifat rahasia dan hanya dapat dipakai sekali.
            @if ($expiresAt)
                Berlaku sampai {{ $expiresAt->timezone(config('app.timezone'))->translatedFormat('j F Y H:i') }}.
            @endif
        </p>

        <p style="margin:0;font-size:12px;line-height:1.6;color:#57534e;">
            Tidak merasa mengenal pengirim undangan ini? Abaikan email ini.
        </p>
    </div>
</body>
</html>
