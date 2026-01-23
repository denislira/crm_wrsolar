const fs = require('fs');
const path = require('path');
const pino = require('pino');
const baileys = require('@adiwajshing/baileys');
const makeWASocket = (baileys && baileys.default) ? baileys.default : baileys;
const DisconnectReason = baileys.DisconnectReason || null;

let useSingleFileAuthState = baileys.useSingleFileAuthState;
// fallback: create a minimal compatible wrapper if the installed baileys doesn't provide it
if (!useSingleFileAuthState) {
  useSingleFileAuthState = function(file) {
      let state = { creds: {}, keys: {} };
      try {
        if (fs.existsSync(file)) {
          const s = JSON.parse(fs.readFileSync(file, 'utf8')) || {};
          state.creds = s.creds || {};
          state.keys = s.keys || {};
        }
      } catch (e) { /* ignore */ }
      const saveCreds = async (creds) => {
        try {
          const out = { creds: creds || {}, keys: state.keys || {} };
          fs.writeFileSync(file, JSON.stringify(out, null, 2), 'utf8');
        } catch (err) {
          // ignore write errors
        }
      };
      return { state, saveCreds };
  };
}

const logger = pino({ level: 'info' });

// Config: path to the WRCRM storage file where the PHP UI reads state
const defaultStoragePath = path.join(__dirname, '..', 'storage', 'wa_state.json');
const STORAGE_PATH = process.env.STORAGE_PATH || defaultStoragePath;
const AUTH_FILE = path.join(__dirname, 'auth_info.json');

function writeState(obj) {
  try {
    const dir = path.dirname(STORAGE_PATH);
    if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
    fs.writeFileSync(STORAGE_PATH, JSON.stringify(obj, null, 2), 'utf8');
    logger.info({ path: STORAGE_PATH }, 'Wrote state');
  } catch (e) {
    logger.error({ err: e }, 'Error writing state file');
  }
}

async function start() {
  logger.info({ authFile: AUTH_FILE }, 'Starting WA service');

  try {
    const { state, saveCreds } = useSingleFileAuthState(AUTH_FILE);

    const sock = makeWASocket({ auth: state, logger });

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', update => {
    const { connection, lastDisconnect, qr } = update;
    if (qr) {
      logger.info('QR received');
      // Save QR text into WRCRM storage so PHP UI can show QR
      writeState({ connected: false, qr_data: qr, qr_generated_at: new Date().toISOString() });
    }

    if (connection === 'open') {
      logger.info('Connection opened');
      // Clear QR and mark connected
      writeState({ connected: true, info: 'connected via Baileys - ' + (sock.user && sock.user.id) });
    }

    if (connection === 'close') {
      const reason = (lastDisconnect && lastDisconnect.error && lastDisconnect.error.output) ? lastDisconnect.error.output.statusCode : null;
      logger.info({ reason }, 'Connection closed');
      writeState({ connected: false, info: 'disconnected' });
    }
  });

    // Optional: simple CLI to send a message manually
  process.stdin.setEncoding('utf8');
  logger.info('Type: phoneNumber|message and press Enter to send (ex: 5511999999999|Oi)');
  process.stdin.on('data', async (chunk) => {
    const line = chunk.toString().trim();
    if (!line) return;
    const [to, ...rest] = line.split('|');
    const text = rest.join('|');
    if (!to || !text) { logger.info('Formato inválido. Use phone|message'); return; }
    try {
      const jid = (to.includes('@')) ? to : (to + '@s.whatsapp.net');
      await sock.sendMessage(jid, { text });
      logger.info({ to }, 'Mensagem enviada');
    } catch (e) {
      logger.error({ err: e }, 'Erro ao enviar mensagem');
    }
  });
  } catch (err) {
    logger.error({ err }, 'Failed to start WA service, will retry in 5s');
    // write failure to state so UI shows message
    try {
      writeState({ connected: false, info: 'service error: ' + (err && err.message ? err.message : String(err)) });
    } catch (e) { /* ignore */ }
    setTimeout(() => start(), 5000);
  }
}

// keep process alive on uncaught errors and attempt restart
process.on('uncaughtException', (err) => {
  logger.error({ err }, 'uncaughtException');
  try { writeState({ connected: false, info: 'uncaughtException: ' + (err && err.message) }); } catch (e) {}
  // attempt restart
  setTimeout(() => start(), 5000);
});

process.on('unhandledRejection', (reason) => {
  logger.error({ reason }, 'unhandledRejection');
  try { writeState({ connected: false, info: 'unhandledRejection' }); } catch (e) {}
  setTimeout(() => start(), 5000);
});

start();
