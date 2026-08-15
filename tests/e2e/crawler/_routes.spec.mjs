import { test, expect } from '@playwright/test';
import { getRoutes } from './routes.mjs';

test('Routen-Tabelle enthält bekannte Routen', async () => {
    const routes = getRoutes();
    const patterns = routes.map((r) => `${r.method} ${r.pattern}`);
    expect(patterns).toContain('GET /voice-groups');
    expect(patterns).toContain('POST /projects');
    const projUpdate = routes.find((r) => r.pattern.includes('/projects/') && r.pattern.includes('update'));
    expect(projUpdate.params).toContain('id');
});

test('Routen-Tabelle löst Gruppen-Präfixe auf', async () => {
    const routes = getRoutes();
    const patterns = routes.map((r) => `${r.method} ${r.pattern}`);

    // $projGroup = $group->group('/projects', ...) in Routes.php definiert
    // ->get('/members', [ProjectController::class, 'listForMembers']) - ohne Präfix-Auflösung
    // käme hier fälschlich "GET /members" heraus (404, da die echte Route
    // "/projects/members" lautet).
    expect(patterns).toContain('GET /projects/members');

    // $songsGroup = $group->group('/song-library', ...) definiert ->get('', ...) als
    // Gruppen-Wurzelseite und ->get('/{id:[0-9]+}', ...) für die Detailseite - beide müssen
    // mit dem "/song-library"-Präfix aufgelöst werden.
    expect(patterns).toContain('GET /song-library');
    const songDetail = routes.find((r) => r.pattern === '/song-library/{id:[0-9]+}' && r.method === 'GET');
    expect(songDetail).toBeTruthy();
    expect(songDetail.params).toContain('id');

    // $roleGroup = $group->group('/roles', ...) definiert ->get('', ...) als Wurzelseite.
    expect(patterns).toContain('GET /roles');
});
