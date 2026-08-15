// Startet die e2e-Suite mit mobilem Viewport (siehe steps/browser.mjs).
//
// Warum ein Runner statt "E2E_VIEWPORT=mobile npx playwright test" im npm-Script:
// diese Schreibweise ist POSIX-Shell-Syntax und schlaegt in PowerShell fehl - das Projekt
// wird aber auf Windows entwickelt. Zusaetzliche Argumente werden durchgereicht,
// z. B. `npm run e2e:mobile -- --project=scenarios --headed`.

import { spawnSync } from 'node:child_process';

const args = ['playwright', 'test', '--config', 'tests/e2e/playwright.config.mjs', ...process.argv.slice(2)];
const npx = process.platform === 'win32' ? 'npx.cmd' : 'npx';

const result = spawnSync(npx, args, {
    stdio: 'inherit',
    env: { ...process.env, E2E_VIEWPORT: 'mobile' },
    shell: process.platform === 'win32',
});

process.exit(result.status ?? 1);
