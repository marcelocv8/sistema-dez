import { spawn } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const backend = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', 'backend');
const php = join(process.env.LOCALAPPDATA ?? '', 'php', 'php.exe');

const env = {
  ...process.env,
  DB_CONNECTION: 'pgsql',
  DB_HOST: 'localhost',
  DB_PORT: '5433',
  DB_DATABASE: 'sistema_dez_test',
  DB_USERNAME: 'sistema_dez',
  DB_PASSWORD: 'local_change_me',
  APP_ENV: 'testing',
  SESSION_DRIVER: 'database',
  CACHE_STORE: 'database',
  SANCTUM_STATEFUL_DOMAINS: 'localhost:4175',
  FRONTEND_URLS: 'http://localhost:4175',
};

const child = spawn(php, ['-S', '127.0.0.1:18001', '-t', 'public'], { cwd: backend, env, stdio: 'inherit' });
child.on('exit', (code) => process.exit(code ?? 0));
const childV6 = spawn(php, ['-S', '[::1]:18001', '-t', 'public'], { cwd: backend, env, stdio: 'ignore' });
childV6.on('exit', (code) => { if (code && code !== 0 && !child.pid) process.exit(code); });
process.on('exit', () => { child.kill(); childV6.kill(); });
