import { defineConfig } from '@playwright/test';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

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
        baseURL: 'https://chormanager.ddev.site',
        ignoreHTTPSErrors: true,
        storageState: AUTH_FILE,
        trace: 'retain-on-failure',
        // Ohne diese defaulten Aktionen (fill/click/...) auf 0 = KEIN Timeout -> ein fehlender
        // Selektor haengt bis zum Test-Timeout statt schnell und mit klarer Meldung zu failen.
        // Aktionen, die bewusst laenger/kuerzer brauchen (z. B. die aggressiven Crawler-Klicks),
        // uebergeben ihren eigenen expliziten timeout und ueberschreiben diesen Default.
        actionTimeout: 15_000,
        navigationTimeout: 30_000,
        // Grosszuegiger Desktop-Viewport: im headless-Lauf deterministisch, im headed-Lauf
        // (--headed) gross genug, dass beim Zuschauen kaum Elemente ausserhalb des sichtbaren
        // Bereichs liegen. --start-maximized maximiert zusaetzlich das echte Fensterchrome im
        // headed-Modus (im headless-Modus wirkungslos).
        viewport: { width: 1600, height: 900 },
        launchOptions: { args: ['--start-maximized'] },
    },
    projects: [
        // Reine Unit-Checks der Crawler-Helfer (_*.spec.mjs) — kein Browser noetig.
        { name: 'checks', testMatch: /_[^/]*\.spec\.mjs$/ },
        { name: 'scenarios', testMatch: /scenarios\/.*\.e2e\.test\.mjs$/ },
        // Crawler klickt aggressiv auf denselben DB-Zustand, den 'scenarios' anlegt/prueft (Mitglieder,
        // Projekt) - ohne dependencies wuerde fullyParallel beide Projekte gleichzeitig gegen dieselbe DB
        // laufen lassen und zu Race Conditions fuehren. Deshalb laeuft crawler erst NACH scenarios.
        { name: 'crawler', testMatch: /crawler\/crawl\.e2e\.test\.mjs$/, dependencies: ['scenarios'] },
    ],
});
