import { test, expect } from '@playwright/test';
import { checkHtmlForPhpErrors } from './detectors.mjs';
import { isDenied } from './denylist.mjs';

test('erkennt PHP-Fehler im HTML', async () => {
    expect(checkHtmlForPhpErrors('<b>Fatal error</b>: boom')).toContain('Fatal error');
    expect(checkHtmlForPhpErrors('<h1>Alles gut</h1>')).toBeNull();
});

test('Denylist blockt Logout und Restore', async () => {
    expect(isDenied('Abmelden', '/logout')).toBe(true);
    expect(isDenied('Backup wiederherstellen', '/backups/restore/3')).toBe(true);
    expect(isDenied('Speichern', '/projects')).toBe(false);
});
