import { defineConfig } from '@playwright/test';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { BASE_URL, DEVICE_OPTIONS } from './steps/browser.mjs';

const dir = path.dirname(fileURLToPath(import.meta.url));
export const AUTH_FILE = path.join(dir, '.auth', 'admin.json');

export default defineConfig({
    testDir: dir,
    // globalSetup bootstrappt DB + Admin + Login und speichert die Session.
    globalSetup: path.join(dir, 'global-setup.mjs'),
    timeout: 120000,
    fullyParallel: true,
    workers: process.env.CI ? 2 : undefined,
    reporter: [['list']],
    use: {
        baseURL: BASE_URL,
        ignoreHTTPSErrors: true,
        storageState: AUTH_FILE,
        trace: 'retain-on-failure',
        // Ohne diese defaulten Aktionen (fill/click/...) auf 0 = KEIN Timeout -> ein fehlender
        // Selektor hängt bis zum Test-Timeout statt schnell und mit klarer Meldung zu failen.
        // Aktionen, die bewusst länger/kürzer brauchen (z. B. die aggressiven Crawler-Klicks),
        // übergeben ihren eigenen expliziten timeout und überschreiben diesen Default.
        actionTimeout: 15_000,
        navigationTimeout: 30_000,
        // Geräteoptionen kommen aus steps/browser.mjs: headless fix 1600x900 (deterministisch),
        // beim Zuschauen (--headed/--ui) viewport null, damit die Seite die echte Fenstergröße
        // nutzt und --start-maximized greift (ein fixer Viewport würde das maximierte Fenster
        // wieder zurückzwingen), mit E2E_VIEWPORT=mobile stattdessen das Pixel-5-Profil.
        ...DEVICE_OPTIONS,
        launchOptions: { args: ['--start-maximized'] },
    },
    projects: [
        // Reine Unit-Checks der Crawler-Helfer (_*.spec.mjs) — kein Browser nötig.
        { name: 'checks', testMatch: /_[^/]*\.spec\.mjs$/ },
        { name: 'scenarios', testMatch: /scenarios\/.*\.e2e\.test\.mjs$/ },
        // Crawler klickt aggressiv auf denselben DB-Zustand, den 'scenarios' anlegt/prüft (Mitglieder,
        // Projekt) - ohne dependencies würde fullyParallel beide Projekte gleichzeitig gegen dieselbe DB
        // laufen lassen und zu Race Conditions führen. Deshalb läuft crawler erst NACH scenarios.
        { name: 'crawler', testMatch: /crawler\/crawl\.e2e\.test\.mjs$/, dependencies: ['scenarios'] },
    ],
});
