// Ura WhatsApp QR-lite e Lora PMS (task MHQ #335).
//
// Një proces i vetëm mban sesionet Baileys për shumë hotele (tenant-ë):
//   - API lokale HTTP (vetëm me Bearer BRIDGE_TOKEN): start/status/logout/send
//   - Ngjarjet (statusi i lidhjes, mesazhet hyrëse) POST-ohen te domaini i
//     VETË hotelit (event_url i dhënë në start) me të njëjtin token — Laravel
//     e zgjidh tenant-in nga hosti dhe e kryqëzon me tenant_id e payload-it.
//
// Auth state per-tenant në SESSIONS_DIR/tenant-<id>/ (multi-file, siç e do
// Baileys). Rikthehet vetiu në boot për sesionet ekzistuese.
//
// KUJDES (vendim i pranuar nga pronari): kjo është rruga JO-zyrtare e
// WhatsApp Web — Meta mund ta bllokojë numrin. Opt-in për hotel, me
// paralajmërim në UI.

import { createServer } from 'node:http';
import { mkdirSync, readdirSync, rmSync, existsSync, readFileSync, writeFileSync, appendFileSync, realpathSync } from 'node:fs';
import { join } from 'node:path';
import makeWASocket, { useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion } from '@whiskeysockets/baileys';
import QRCode from 'qrcode';
import pino from 'pino';

const PORT = Number(process.env.BRIDGE_PORT || 3100);

// Token-i: nga env, ose nga .env i Laravel-it ngjitur (../.env nga cwd — ura
// jeton në <app>/whatsapp-bridge). NJË burim i vetëm sekreti: pronari e fut
// vetëm te Environment i Forge, komanda e daemon-it mbetet pa sekrete
// ('node src/index.mjs') dhe sekreti s'duket në UI të Forge a në ps.
// Në layout-in zero-downtime të Forge, cwd zgjidhet përmes symlink-ut 'current'
// te release-i FIZIK — që fshihet nga retention pas disa deploy-esh. Çdo rrugë
// e qëndrueshme (env, sessions) duhet realpath-uar NJË herë në nisje drejt
// vendndodhjes së përbashkët, ndryshe daemon-i mbetet me rrugë të vdekura dhe
// outbox-i humb ngjarje (gjetje Codex PR #436).
function stablePath(path) {
    try {
        return realpathSync(path);
    } catch {
        return path;
    }
}

function resolveToken() {
    if (process.env.BRIDGE_TOKEN) return process.env.BRIDGE_TOKEN;

    const envPath = stablePath(process.env.LARAVEL_ENV_PATH || join(process.cwd(), '..', '.env'));
    try {
        const line = readFileSync(envPath, 'utf8')
            .split('\n')
            .find((l) => l.startsWith('WHATSAPP_BRIDGE_TOKEN='));

        return line
            ? line.slice('WHATSAPP_BRIDGE_TOKEN='.length).trim().replace(/^["']|["']$/g, '')
            : '';
    } catch {
        return '';
    }
}

const TOKEN = resolveToken();

// Sesionet + outbox-i duhet të mbijetojnë deploy-et: kur ura jeton brenda
// aplikacionit Laravel, parazgjedhja shkon nën storage/ të tij.
const SESSIONS_DIR = process.env.SESSIONS_DIR
    || (existsSync(join(process.cwd(), '..', 'storage'))
        ? join(stablePath(join(process.cwd(), '..', 'storage')), 'whatsapp-sessions')
        : join(process.cwd(), 'sessions'));

const logger = pino({ level: process.env.LOG_LEVEL || 'info' });

if (!TOKEN) {
    logger.error('Token mungon (as BRIDGE_TOKEN në env, as WHATSAPP_BRIDGE_TOKEN në ../.env) — ura nuk niset (fail-closed).');
    process.exit(1);
}

mkdirSync(SESSIONS_DIR, { recursive: true });

// tenantId → { sock, status, qrDataUrl, phone, eventUrl, stopping }
const sessions = new Map();

const metaPath = (tenantId) => join(SESSIONS_DIR, `tenant-${tenantId}`, 'bridge-meta.json');

function saveMeta(tenantId, eventUrl) {
    writeFileSync(metaPath(tenantId), JSON.stringify({ eventUrl }), 'utf8');
}

function loadMeta(tenantId) {
    try {
        return JSON.parse(readFileSync(metaPath(tenantId), 'utf8'));
    } catch {
        return null;
    }
}

// ---- Outbox i qëndrueshëm ------------------------------------------------
// Mesazhet e mysafirëve s'kanë rrugë tjetër drejt PMS-it — një dështim kalimtar
// i Laravel-it (deploy, 5xx, rrjeti) NUK guxon t'i humbasë (gjetje Codex PR
// #435). Çdo ngjarje shkruhet së pari në disk (outbox.jsonl per-tenant, e
// mbijeton restart-in e daemon-it) dhe fshihet vetëm pas një 2xx; dështimet
// riprovohen me backoff duke ruajtur radhën.

const outboxPath = (tenantId) => join(SESSIONS_DIR, `tenant-${tenantId}`, 'outbox.jsonl');
const flushing = new Map(); // tenantId → true | timer

function postEvent(tenantId, type, payload) {
    mkdirSync(join(SESSIONS_DIR, `tenant-${tenantId}`), { recursive: true });
    appendFileSync(outboxPath(tenantId), JSON.stringify({ tenant_id: tenantId, type, payload }) + '\n', 'utf8');
    flushOutbox(tenantId);
}

async function flushOutbox(tenantId, attempt = 0) {
    if (flushing.get(tenantId) === true) return;
    flushing.set(tenantId, true);

    try {
        const eventUrl = sessions.get(tenantId)?.eventUrl || loadMeta(tenantId)?.eventUrl;
        const path = outboxPath(tenantId);
        if (!eventUrl || !existsSync(path)) return;

        while (true) {
            const lines = readFileSync(path, 'utf8').split('\n').filter(Boolean);
            if (!lines.length) return;

            let res;
            try {
                res = await fetch(eventUrl, {
                    method: 'POST',
                    headers: { 'content-type': 'application/json', authorization: `Bearer ${TOKEN}` },
                    body: lines[0],
                });
            } catch (err) {
                logger.warn({ tenantId, err: String(err) }, 'Laravel i paarritshëm — riprovoj me backoff');
                return scheduleRetry(tenantId, attempt);
            }

            // 4xx = ngjarje e refuzuar përgjithmonë (token/tenant i gabuar) —
            // hidhet që radha të mos bllokohet; 5xx/429 = kalimtare, riprovohet.
            if (!res.ok && (res.status >= 500 || res.status === 429)) {
                logger.warn({ tenantId, status: res.status }, 'Laravel ktheu gabim kalimtar — riprovoj');
                return scheduleRetry(tenantId, attempt);
            }
            if (!res.ok) logger.error({ tenantId, status: res.status }, 'Ngjarje e refuzuar përgjithmonë — u hodh');

            writeFileSync(path, lines.slice(1).join('\n') + (lines.length > 1 ? '\n' : ''), 'utf8');
            attempt = 0;
        }
    } finally {
        if (flushing.get(tenantId) === true) flushing.delete(tenantId);
    }
}

function scheduleRetry(tenantId, attempt) {
    const delay = Math.min(60_000, 5_000 * 2 ** attempt); // 5s→10s→20s→40s→60s tavan
    const timer = setTimeout(() => {
        flushing.delete(tenantId);
        flushOutbox(tenantId, attempt + 1);
    }, delay);
    flushing.set(tenantId, timer);
}

// Zbraz outbox-et e mbetura periodikisht (p.sh. pas restart-i të daemon-it).
setInterval(() => {
    for (const entry of readdirSync(SESSIONS_DIR, { withFileTypes: true })) {
        const match = entry.isDirectory() && entry.name.match(/^tenant-(\d+)$/);
        if (match && existsSync(join(SESSIONS_DIR, entry.name, 'outbox.jsonl'))) {
            flushOutbox(Number(match[1]));
        }
    }
}, 60_000).unref();

async function startSession(tenantId, eventUrl) {
    const existing = sessions.get(tenantId);
    if (existing?.sock && existing.status !== 'disconnected') {
        if (eventUrl) { existing.eventUrl = eventUrl; saveMeta(tenantId, eventUrl); }
        return existing;
    }

    const dir = join(SESSIONS_DIR, `tenant-${tenantId}`);
    mkdirSync(dir, { recursive: true });
    if (eventUrl) saveMeta(tenantId, eventUrl);

    const { state, saveCreds } = await useMultiFileAuthState(dir);
    const { version } = await fetchLatestBaileysVersion().catch(() => ({ version: undefined }));

    const sock = makeWASocket({
        version,
        auth: state,
        logger: logger.child({ tenantId, level: 'warn' }),
        printQRInTerminal: false,
        markOnlineOnConnect: false,
        syncFullHistory: false,
    });

    const session = {
        sock,
        status: 'pairing',
        qrDataUrl: null,
        phone: null,
        eventUrl: eventUrl || loadMeta(tenantId)?.eventUrl || null,
        stopping: false,
    };
    sessions.set(tenantId, session);

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', async (update) => {
        const { connection, lastDisconnect, qr } = update;

        if (qr) {
            session.status = 'pairing';
            session.qrDataUrl = await QRCode.toDataURL(qr).catch(() => null);
            postEvent(tenantId, 'status', { status: 'pairing' });
        }

        if (connection === 'open') {
            session.status = 'connected';
            session.qrDataUrl = null;
            session.phone = (sock.user?.id || '').split(':')[0] || null;
            postEvent(tenantId, 'status', { status: 'connected', phone: session.phone });
            logger.info({ tenantId, phone: session.phone }, 'WhatsApp u lidh');
        }

        if (connection === 'close') {
            const code = lastDisconnect?.error?.output?.statusCode;
            const loggedOut = code === DisconnectReason.loggedOut;
            session.qrDataUrl = null;

            if (loggedOut || session.stopping) {
                session.status = 'disconnected';
                sessions.delete(tenantId);
                if (loggedOut) rmSync(dir, { recursive: true, force: true });
                postEvent(tenantId, 'status', { status: 'disconnected' });
                logger.info({ tenantId, loggedOut }, 'Sesioni u mbyll');
            } else {
                // Rilidhje automatike — sesionet bien shpesh (natyra e QR-lite).
                // Socket-i i vjetër është i vdekur: HIQE nga mapa që startSession
                // të ndërtojë një të ri (ndryshe early-return-i e kthen të vdekurin
                // dhe rilidhja s'ndodh kurrë — gjetje Codex PR #435). event_url
                // mbijeton në bridge-meta.json.
                sessions.delete(tenantId);
                logger.warn({ tenantId, code }, 'Lidhja ra — rilidhem me socket të ri');
                setTimeout(() => startSession(tenantId).catch((err) =>
                    logger.error({ tenantId, err: String(err) }, 'Rilidhja dështoi')), 3000);
            }
        }
    });

    sock.ev.on('messages.upsert', ({ messages, type }) => {
        if (type !== 'notify') return;

        for (const msg of messages) {
            const jid = msg.key?.remoteJid || '';
            // Vetëm biseda private 1-me-1 — kurrë grupe, statuse a newsletter.
            // KUJDES: WhatsApp-i i ri adreson bisedat private edhe me '@lid'
            // (fshehja e numrit — LID); pa të, mesazhet e mysafirëve injorohen
            // në heshtje (gjetur live, task #341). Numri real vjen te senderPn.
            const isPrivate = jid.endsWith('@s.whatsapp.net') || jid.endsWith('@lid');
            if (msg.key?.fromMe || !isPrivate) continue;

            const body = msg.message?.conversation
                || msg.message?.extendedTextMessage?.text
                || '';
            if (!body.trim()) continue; // media: v2 — teksti mjafton për v1

            // Numri i vërtetë (kur adresa është @lid): senderPn/participantPn.
            const pn = msg.key?.senderPn || msg.key?.participantPn
                || (jid.endsWith('@s.whatsapp.net') ? jid : '');

            postEvent(tenantId, 'message', {
                jid,
                phone: String(pn).split('@')[0].replace(/\D/g, ''),
                message_id: msg.key.id,
                name: msg.pushName || '',
                body,
                timestamp: Number(msg.messageTimestamp) || Math.floor(Date.now() / 1000),
            });
        }
    });

    return session;
}

// Rikthe sesionet ekzistuese në boot (pas restart-i të daemon-it).
for (const entry of readdirSync(SESSIONS_DIR, { withFileTypes: true })) {
    const match = entry.isDirectory() && entry.name.match(/^tenant-(\d+)$/);
    if (match && existsSync(join(SESSIONS_DIR, entry.name, 'creds.json'))) {
        const tenantId = Number(match[1]);
        startSession(tenantId).catch((err) =>
            logger.error({ tenantId, err: String(err) }, 'Rikthimi i sesionit dështoi'));
    }
}

// ---- API lokale ----------------------------------------------------------

function json(res, code, data) {
    res.writeHead(code, { 'content-type': 'application/json' });
    res.end(JSON.stringify(data));
}

const server = createServer(async (req, res) => {
    if ((req.headers.authorization || '') !== `Bearer ${TOKEN}`) {
        return json(res, 403, { error: 'forbidden' });
    }

    const url = new URL(req.url, `http://127.0.0.1:${PORT}`);
    const match = url.pathname.match(/^\/sessions\/(\d+)(?:\/(start|logout|send))?$/);
    if (!match) return json(res, 404, { error: 'not found' });

    const tenantId = Number(match[1]);
    const action = match[2] || (req.method === 'GET' ? 'status' : null);

    let body = {};
    if (req.method === 'POST') {
        const chunks = [];
        for await (const chunk of req) chunks.push(chunk);
        try {
            body = chunks.length ? JSON.parse(Buffer.concat(chunks).toString('utf8')) : {};
        } catch {
            return json(res, 400, { error: 'bad json' });
        }
    }

    try {
        switch (action) {
            case 'start': {
                const session = await startSession(tenantId, String(body.event_url || ''));
                return json(res, 200, { status: session.status });
            }
            case 'status': {
                const session = sessions.get(tenantId);
                return json(res, 200, {
                    status: session?.status || 'disconnected',
                    qr: session?.qrDataUrl || null,
                    phone: session?.phone || null,
                });
            }
            case 'logout': {
                const session = sessions.get(tenantId);
                if (session?.sock) {
                    session.stopping = true;
                    await session.sock.logout().catch(() => {});
                    session.sock.end?.();
                }
                sessions.delete(tenantId);
                rmSync(join(SESSIONS_DIR, `tenant-${tenantId}`), { recursive: true, force: true });
                return json(res, 200, { status: 'disconnected' });
            }
            case 'send': {
                const session = sessions.get(tenantId);
                if (session?.status !== 'connected') {
                    return json(res, 409, { error: 'not connected' });
                }
                const jid = String(body.jid || '');
                const text = String(body.text || '');
                // Përgjigja shkon te i njëjti jid ku erdhi biseda — edhe @lid.
                if (!(jid.endsWith('@s.whatsapp.net') || jid.endsWith('@lid')) || !text.trim()) {
                    return json(res, 422, { error: 'invalid jid or text' });
                }
                const sent = await session.sock.sendMessage(jid, { text });
                return json(res, 200, { id: sent?.key?.id || null });
            }
            default:
                return json(res, 405, { error: 'method' });
        }
    } catch (err) {
        logger.error({ tenantId, action, err: String(err) }, 'Veprimi dështoi');
        return json(res, 500, { error: 'internal' });
    }
});

server.listen(PORT, '127.0.0.1', () => {
    logger.info({ port: PORT, sessionsDir: SESSIONS_DIR }, 'Ura WhatsApp gati (vetëm localhost)');
});
