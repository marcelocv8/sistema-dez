import { spawn } from 'node:child_process';

process.env.VITE_USE_MSW = 'false';
process.env.VITE_API_URL = 'http://127.0.0.1:18001';

const child = spawn('node', ['node_modules/vite/bin/vite.js', 'apps/equipe', '--host', 'localhost', '--port', '4175'], {
  stdio: 'inherit',
  env: process.env,
});
child.on('exit', (code) => process.exit(code ?? 0));
