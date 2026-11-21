// Local Stage 10 only. Keeps data until the owner explicitly requests cleanup.
const { spawn, spawnSync } = require('node:child_process');
const { createHash } = require('node:crypto');
const { createWriteStream, mkdirSync } = require('node:fs');
const net = require('node:net');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const backend = path.join(root, 'backend');
const frontend = path.join(root, 'frontend');
const helper = path.join(backend, 'tests/Support/stage10-manual-setup.php');
const api = 'http://127.0.0.1:8120';
const site = 'http://127.0.0.1:3120';
const identity = createHash('sha256').update(root.toLowerCase()).digest('hex').slice(0, 20);
const pipe = `\\\\.\\pipe\\sorerunapp-stage10-manual-${identity}`;
const operation = process.argv[2] || 'status';

function command(message) {
  return new Promise((resolve, reject) => {
    const client = net.createConnection(pipe);
    let result = '';
    client.setTimeout(3000, () => client.destroy(new Error('Manual launcher did not respond.')));
    client.on('connect', () => client.write(message));
    client.on('data', chunk => { result += chunk; });
    client.on('end', () => { try { resolve(JSON.parse(result)); } catch { reject(new Error('Invalid launcher response.')); } });
    client.on('error', reject);
  });
}

function ensureFree(port) {
  return new Promise((resolve, reject) => {
    const probe = net.createServer();
    probe.once('error', () => reject(new Error(`Port ${port} is in use; no existing process was stopped.`)));
    probe.listen(port, '127.0.0.1', () => probe.close(resolve));
  });
}

async function start() {
  if (process.platform !== 'win32') throw new Error('This launcher is prepared for Windows/Laragon.');
  if (await command('status').catch(() => null)) throw new Error('Manual launcher already running. Use status, or open the existing URLs.');
  await Promise.all([ensureFree(3120), ensureFree(8120)]);
  const preflight = spawnSync('php', [helper, 'status'], { cwd: backend, encoding: 'utf8', windowsHide: true });
  if (preflight.status !== 0) throw new Error('Isolated PHP/MySQL preflight failed. Run prepare or inspect the helper output.');
  const status = JSON.parse(preflight.stdout);
  if (status.database !== 'sorerunapp_test' || status.environment !== 'testing' || !status.prepared) {
    throw new Error('Manual data is not prepared. Run node scripts/landing-manual.cjs prepare first.');
  }
  const children = [];
  const streams = [];
  let stopping = false;
  let ready = false;
  const control = net.createServer(socket => {
    socket.once('data', data => {
      const stop = data.toString() === 'stop';
      socket.end(JSON.stringify({ running: !stopping, ready, frontend: site, api, database: 'sorerunapp_test',
        pids: children.filter(child => child.exitCode === null).map(child => child.pid), stopping: stop }));
      if (stop) setImmediate(() => void shutdown(0));
    });
    socket.on('error', () => {});
  });
  async function shutdown(code) {
    if (stopping) return;
    stopping = true;
    await Promise.all(children.map(child => new Promise(resolve => {
      if (!child.pid || child.exitCode !== null || child.signalCode !== null) return resolve();
      // Only child trees created by this still-running launcher are terminated.
      const killer = spawn('taskkill.exe', ['/PID', String(child.pid), '/T', '/F'], { windowsHide: true, stdio: 'ignore' });
      killer.once('exit', resolve);
      killer.once('error', resolve);
    })));
    control.close();
    for (const stream of streams) stream.end();
    console.log('Manual servers stopped. Account, draft, publication and media were retained.');
    process.exit(code);
  }
  process.on('SIGINT', () => void shutdown(0));
  process.on('SIGTERM', () => void shutdown(0));
  await new Promise((resolve, reject) => { control.once('error', reject); control.listen(pipe, resolve); });
  mkdirSync(path.join(backend, 'storage/logs'), { recursive: true });
  function launch(name, executable, args, cwd, env = process.env) {
    const log = createWriteStream(path.join(backend, `storage/logs/stage10-manual-${name}.log`), { flags: 'a' });
    streams.push(log);
    const child = spawn(executable, args, { cwd, env, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
    children.push(child);
    for (const output of [child.stdout, child.stderr]) output.on('data', chunk => { log.write(chunk); process.stdout.write(chunk); });
    child.once('error', () => { console.error(`${name} could not start.`); void shutdown(1); });
    child.once('exit', code => {
      if (!stopping) { console.error(`${name} exited (${code}). Check the local logs; retry start if Next stopped for its security advisory.`); void shutdown(code || 1); }
    });
  }
  launch('api', 'php', ['-S', '127.0.0.1:8120', '-t', path.join(backend, 'public'), path.join(backend, 'tests/Support/stage10-manual-router.php')], backend);
  launch('frontend', process.execPath, [path.join(frontend, 'node_modules/next/dist/bin/next'), 'dev', '--hostname', '127.0.0.1', '--port', '3120'], frontend, {
    ...process.env, NODE_ENV: 'development', NEXT_TELEMETRY_DISABLED: '1', SORERUNAPP_TEST_OUTPUT: 'landing-manual',
    NEXT_PUBLIC_API_BASE_URL: api, SITE_URL: site, LANDING_INDEXABLE: 'false',
  });
  try {
    const deadline = Date.now() + 120000;
    while (Date.now() < deadline) {
      const checks = await Promise.all([
        fetch(`${api}/__stage10_manual/health`, { signal: AbortSignal.timeout(5000) }).then(r => r.json()).catch(() => null),
        fetch(`${site}/login`, { signal: AbortSignal.timeout(10000) }).then(r => r.status).catch(() => null),
      ]);
      if (checks[0]?.database === 'sorerunapp_test' && checks[0]?.manual === true && checks[1] === 200) {
        const landing = await fetch(site, { signal: AbortSignal.timeout(20000) });
        const html = await landing.text();
        if (!landing.ok || html.includes('Informasi komunitas belum dapat dimuat')) throw new Error('Public SSR is unavailable; inspect the sanitized frontend log.');
        await new Promise(resolve => setTimeout(resolve, 2000));
        if (stopping) return;
        ready = true;
        console.log(`\nManual Stage 10 ready: ${site}\nAPI: ${api}\nKeep this terminal open. Ctrl+C or node scripts/landing-manual.cjs stop stops only these servers.\nNo fixtures are removed on stop.`);
        return;
      }
      await new Promise(resolve => setTimeout(resolve, 1000));
    }
    throw new Error('Manual server readiness timed out.');
  } catch (error) {
    console.error(error.message);
    await shutdown(1);
  }
}

(async () => {
  if (operation === 'prepare') {
    const result = spawnSync('php', [helper, 'prepare'], { cwd: backend, stdio: 'inherit', windowsHide: true });
    process.exitCode = result.status ?? 1;
  } else if (operation === 'start') await start();
  else if (operation === 'status' || operation === 'stop') {
    console.log(JSON.stringify(await command(operation).catch(() => ({ running: false, message: 'No manual launcher is running; retained data was not changed.' }))));
  } else throw new Error('Use prepare, start, status or stop.');
})().catch(error => { console.error(error.message); process.exitCode = 1; });
