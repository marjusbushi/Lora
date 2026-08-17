# Ura WhatsApp QR-lite — instalimi në server (Forge)

Deploy-i standard i Laravel-it **nuk** e instalon dhe **nuk** e nis këtë
shërbim — është daemon më vete. Pa këta hapa, skeda Cilësimet → WhatsApp
tregon "ura s'është aktive" dhe asgjë tjetër nuk ndryshon (fail-closed).

## 1. Gjenero token-in e përbashkët (një herë)

```bash
openssl rand -hex 32
```

## 2. .env i Laravel-it (staging/prod)

```
WHATSAPP_BRIDGE_URL=http://127.0.0.1:3100
WHATSAPP_BRIDGE_TOKEN=<token-i i mësipërm>
```

Pastaj `php artisan config:cache`.

## 3. Instalimi i varësive (pas çdo deploy që prek whatsapp-bridge/)

Shto në deploy script-in e Forge, pas `npm ci` të rrënjës:

```bash
cd whatsapp-bridge && npm ci --omit=dev
```

## 4. Daemon-i në Forge (Server → Daemons → New Daemon)

- **Command:** `node src/index.mjs`
- **Directory:** `/home/forge/<app>/whatsapp-bridge`
- **User:** `forge`

Kaq — **pa asnjë sekret në komandë**: ura e lexon token-in vetë nga
`WHATSAPP_BRIDGE_TOKEN` i `.env`-it të Laravel-it ngjitur (../.env), dhe
sesionet + outbox-i shkojnë vetiu nën `storage/whatsapp-sessions` (mbijetojnë
deploy-et). Override-e në env të daemon-it po deshe: `BRIDGE_TOKEN`,
`BRIDGE_PORT` (3100), `SESSIONS_DIR`, `LARAVEL_ENV_PATH`. Ura dëgjon vetëm
në 127.0.0.1 — asnjë port publik.

## 5. Verifikimi

```bash
curl -s -H "Authorization: Bearer <token>" http://127.0.0.1:3100/sessions/1
```

→ `{"status":"disconnected",...}` = ura punon. Pastaj nga PMS:
Cilësimet → WhatsApp → "Lidh me QR".

## Shënime operative

- Sesioni bie herë pas here (natyra e QR-lite) — ura rilidhet vetë; kur
  s'rilidhet dot (logout nga telefoni), skeda kërkon ri-skanim QR.
- Ngjarjet drejt Laravel-it ruhen në `outbox.jsonl` per-tenant dhe
  riprovohen me backoff — një deploy i Laravel-it nuk humb mesazhe.
- Rreziku i bllokimit nga Meta është i pranuar (opt-in me paralajmërim në
  UI) — përdorni gjithmonë numër të dedikuar hoteli.
