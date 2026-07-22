const fs = require('fs');
const path = require('path');
const pino = require('pino');
const baileys = require('@adiwajshing/baileys');

const makeWASocket = baileys && baileys.default ? baileys.default : baileys;
const initAuthCreds = baileys.initAuthCreds;
const BufferJSON = baileys.BufferJSON || {
  replacer: (_key, value) => value,
  reviver: (_key, value) => value
};
let useSingleFileAuthState = baileys.useSingleFileAuthState;
if (!useSingleFileAuthState) {
  useSingleFileAuthState = function authStateFallback(file) {
    let saved = {};
    try {
      if (fs.existsSync(file)) {
        saved = JSON.parse(fs.readFileSync(file, 'utf8'), BufferJSON.reviver) || {};
      }
    } catch (err) {
      logger.warn({ err, authFile: file }, 'Auth invalido, criando uma nova sessao');
      saved = {};
    }

    const hasValidCreds = saved.creds
      && saved.creds.noiseKey
      && saved.creds.noiseKey.public
      && saved.creds.noiseKey.private
      && saved.creds.signedIdentityKey
      && saved.creds.signedIdentityKey.public
      && saved.creds.signedIdentityKey.private;

    const state = {
      creds: hasValidCreds ? saved.creds : initAuthCreds(),
      keys: saved.keys || {}
    };

    const persist = () => {
      ensureDir(file);
      fs.writeFileSync(file, JSON.stringify({ creds: state.creds, keys: state.keys }, BufferJSON.replacer, 2), 'utf8');
    };

    const saveCreds = async (creds) => {
      if (creds) state.creds = { ...state.creds, ...creds };
      persist();
    };

    return {
      state: {
        creds: state.creds,
        keys: {
          get: async (type, ids) => {
            const data = {};
            for (const id of ids) {
              data[id] = state.keys[type] ? state.keys[type][id] : undefined;
            }
            return data;
          },
          set: async (data) => {
            for (const category of Object.keys(data || {})) {
              state.keys[category] = state.keys[category] || {};
              for (const id of Object.keys(data[category] || {})) {
                const value = data[category][id];
                if (value) state.keys[category][id] = value;
                else delete state.keys[category][id];
              }
            }
            persist();
          }
        }
      },
      saveCreds
    };
  };
}
const logger = pino({ level: process.env.WA_DEBUG === '1' ? (process.env.LOG_LEVEL || 'info') : 'silent' });

const defaultStoragePath = path.join(__dirname, '..', 'storage', 'wa_state.json');
const STORAGE_PATH = process.env.STORAGE_PATH || defaultStoragePath;
const COMMAND_PATH = process.env.COMMAND_PATH || path.join(path.dirname(STORAGE_PATH), 'wa_command.json');
const AUTH_FILE = process.env.AUTH_FILE || path.join(__dirname, 'auth_info.json');

let sock = null;
let saveCredsHandler = null;
let lastCommandId = null;
let restarting = false;
let currentState = { connected: false, info: 'iniciando servico Baileys' };

function ensureDir(filePath) {
  const dir = path.dirname(filePath);
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
}

function readJson(filePath, fallback = {}) {
  try {
    if (!fs.existsSync(filePath)) return fallback;
    return JSON.parse(fs.readFileSync(filePath, 'utf8')) || fallback;
  } catch (err) {
    logger.warn({ err, filePath }, 'Falha ao ler JSON');
    return fallback;
  }
}

function writeJson(filePath, data) {
  ensureDir(filePath);
  fs.writeFileSync(filePath, JSON.stringify(data, null, 2), 'utf8');
}

function writeState(partial) {
  currentState = {
    ...currentState,
    ...partial,
    service_running: true,
    service_pid: process.pid,
    service_heartbeat_at: new Date().toISOString()
  };
  writeJson(STORAGE_PATH, currentState);
}

function removeAuth() {
  try {
    if (fs.existsSync(AUTH_FILE)) fs.unlinkSync(AUTH_FILE);
  } catch (err) {
    logger.warn({ err, authFile: AUTH_FILE }, 'Nao foi possivel remover auth');
  }
}

function closeSocket() {
  if (!sock) return;
  try {
    if (sock.ev && saveCredsHandler) sock.ev.off('creds.update', saveCredsHandler);
  } catch (err) {
    logger.debug({ err }, 'Falha ao remover listener creds.update');
  }
  try {
    if (typeof sock.end === 'function') sock.end();
  } catch (err) {
    logger.debug({ err }, 'Falha ao encerrar socket');
  }
  sock = null;
  saveCredsHandler = null;
}

async function startSocket({ fresh = false, reason = 'start' } = {}) {
  if (restarting) return;
  restarting = true;

  try {
    closeSocket();
    if (fresh) removeAuth();

    writeState({
      connected: false,
      qr_data: fresh ? null : currentState.qr_data,
      info: fresh ? 'gerando novo QR pelo Baileys' : 'conectando ao Baileys',
      last_action: reason
    });

    const { state, saveCreds } = useSingleFileAuthState(AUTH_FILE);
    saveCredsHandler = saveCreds;
    sock = makeWASocket({ auth: state, logger, printQRInTerminal: false });
    sock.ev.on('creds.update', saveCredsHandler);

    sock.ev.on('connection.update', (update) => {
      const { connection, lastDisconnect, qr } = update;

      if (qr) {
        logger.info('QR real recebido do Baileys');
        writeState({
          connected: false,
          qr_data: qr,
          qr_generated_at: new Date().toISOString(),
          info: 'QR real gerado pelo Baileys. Escaneie no WhatsApp.'
        });
      }

      if (connection === 'open') {
        logger.info('WhatsApp conectado');
        writeState({
          connected: true,
          qr_data: null,
          info: 'connected via Baileys - ' + (sock.user && sock.user.id ? sock.user.id : 'online'),
          connected_at: new Date().toISOString()
        });
      }

      if (connection === 'close') {
        const reasonCode = lastDisconnect && lastDisconnect.error && lastDisconnect.error.output
          ? lastDisconnect.error.output.statusCode
          : null;
        logger.info({ reasonCode }, 'Conexao fechada');
        writeState({
          connected: false,
          qr_data: null,
          info: 'desconectado do Baileys'
        });
      }
    });
  } catch (err) {
    logger.error({ err }, 'Falha ao iniciar Baileys');
    writeState({
      connected: false,
      qr_data: null,
      info: 'erro no servico Baileys: ' + (err && err.message ? err.message : String(err))
    });
  } finally {
    restarting = false;
  }
}

async function handleCommand(command) {
  if (!command || !command.id || command.id === lastCommandId) return;
  lastCommandId = command.id;

  if (command.action === 'renew_qr') {
    logger.info({ command }, 'Comando renew_qr recebido');
    await startSocket({ fresh: true, reason: 'renew_qr' });
  }

  if (command.action === 'disconnect') {
    logger.info({ command }, 'Comando disconnect recebido');
    try {
      if (sock && typeof sock.logout === 'function') await sock.logout();
    } catch (err) {
      logger.warn({ err }, 'Falha ao executar logout');
    }
    closeSocket();
    removeAuth();
    writeState({
      connected: false,
      qr_data: null,
      info: 'desconectado manualmente'
    });
  }
}

function pollCommand() {
  const command = readJson(COMMAND_PATH, null);
  handleCommand(command).catch((err) => logger.error({ err }, 'Erro ao processar comando'));
}

setInterval(pollCommand, 1500);

process.on('SIGINT', () => {
  closeSocket();
  writeState({ service_running: false, info: 'servico encerrado' });
  process.exit(0);
});

process.on('uncaughtException', (err) => {
  logger.error({ err }, 'uncaughtException');
  writeState({ connected: false, info: 'uncaughtException: ' + (err && err.message ? err.message : String(err)) });
});

process.on('unhandledRejection', (reason) => {
  logger.error({ reason }, 'unhandledRejection');
  writeState({ connected: false, info: 'unhandledRejection: ' + String(reason) });
});

logger.info({ storage: STORAGE_PATH, command: COMMAND_PATH, auth: AUTH_FILE }, 'Iniciando wa-service');
startSocket({ fresh: false, reason: 'service_start' });
