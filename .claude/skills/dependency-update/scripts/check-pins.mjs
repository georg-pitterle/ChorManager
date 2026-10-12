#!/usr/bin/env node
// Findet alle festgelegten Versionen außerhalb von composer/npm (Docker-Images,
// Compose-Images, GitHub Actions, ddev-Laufzeiten) und schlägt nach, welche
// neueren Versionen es gibt.
//
// Aufruf aus dem Repo-Wurzelverzeichnis:  node .claude/skills/dependency-update/scripts/check-pins.mjs
// Optional GITHUB_TOKEN setzen, falls das GitHub-Limit (60 Abfragen/h) greift.
//
// Die Fundstellen kommen aus `git ls-files`, nicht aus einer festen Liste:
// Kommt ein neues Dockerfile oder Compose-File dazu, taucht es hier von selbst auf.
//
// Spalten:
//   gleicher Major  neueste Version innerhalb des aktuellen Majors (direkt einspielbar)
//   Ziel            neueste empfohlene Version überhaupt. Für Images mit Release-Kanal
//                   (node lts, nginx stable, mysql/mariadb lts) ist das der Tag mit demselben
//                   Digest wie der Kanal-Alias - nicht einfach die höchste Nummer, denn die
//                   wäre bei nginx Mainline und bei mysql ein Innovation-Release.

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const files = execFileSync('git', ['ls-files'], { encoding: 'utf8' }).split('\n').filter(Boolean);

const isDockerfile = (f) => /(^|\/)Dockerfile[^/]*$/.test(f) && !f.endsWith('.example');
const isCompose = (f) => /(^|\/)docker-compose[^/]*\.ya?ml$/.test(f) && !f.endsWith('.example');
const isWorkflow = (f) => /^\.github\/workflows\/[^/]+\.ya?ml$/.test(f);

// Release-Kanäle: Alias-Tag (ohne Suffix) je Docker-Hub-Repository.
const CHANNELS = {
    'library/node': 'lts',
    'library/nginx': 'stable',
    'library/mysql': 'lts',
    'library/mariadb': 'lts',
};

const pins = [];

for (const file of files) {
    let lines;
    try {
        lines = readFileSync(file, 'utf8').split('\n');
    } catch {
        continue;
    }
    lines.forEach((line, i) => {
        const at = `${file}:${i + 1}`;
        let m;
        if (isDockerfile(file)) {
            if ((m = line.match(/^\s*FROM\s+(?:--platform=\S+\s+)?(\S+)/i))) {
                pins.push({ at, kind: 'image', ref: m[1] });
            }
            if ((m = line.match(/--from=(\S+:\S+)/))) {
                pins.push({ at, kind: 'image', ref: m[1] });
            }
        }
        if (isCompose(file) && (m = line.match(/^\s*image:\s*["']?([^"'\s#]+)/))) {
            pins.push({ at, kind: 'image', ref: m[1] });
        }
        if (isWorkflow(file) && (m = line.match(/uses:\s*([\w.-]+\/[\w.-]+)(?:\/[^@\s]*)?@(\S+)/))) {
            pins.push({ at, kind: 'action', repo: m[1], tag: m[2] });
        }
    });
}

// ddev-Laufzeiten: php_version und database.version in .ddev/config.yaml
if (files.includes('.ddev/config.yaml')) {
    const lines = readFileSync('.ddev/config.yaml', 'utf8').split('\n');
    let dbType = null;
    lines.forEach((line, i) => {
        const at = `.ddev/config.yaml:${i + 1}`;
        let m;
        if ((m = line.match(/^php_version:\s*"?([\d.]+)"?/))) {
            pins.push({ at, kind: 'image', ref: `php:${m[1]}`, note: 'ddev php_version' });
        }
        if ((m = line.match(/^\s+type:\s*(\w+)/))) {
            dbType = m[1];
        }
        if (dbType && (m = line.match(/^\s+version:\s*"?([\d.]+)"?/))) {
            pins.push({ at, kind: 'ddev-db', dbType, ref: `${dbType}:${m[1]}`, note: 'ddev database' });
            dbType = null;
        }
    });
}

const ghHeaders = { 'User-Agent': 'chormanager-check-pins', Accept: 'application/vnd.github+json' };
if (process.env.GITHUB_TOKEN) {
    ghHeaders.Authorization = `Bearer ${process.env.GITHUB_TOKEN}`;
}

const cache = new Map();
function cached(key, fn) {
    if (!cache.has(key)) {
        cache.set(key, fn());
    }
    return cache.get(key);
}

async function getJson(url, headers = {}) {
    const res = await fetch(url, { headers });
    if (!res.ok) {
        throw new Error(`${new URL(url).host} ${res.status}`);
    }
    return res.json();
}

const parseVersion = (s) => s.split('.').map(Number);
const compare = (a, b) => {
    for (let i = 0; i < Math.max(a.length, b.length); i++) {
        const d = (a[i] ?? 0) - (b[i] ?? 0);
        if (d !== 0) {
            return d;
        }
    }
    return 0;
};

// Zerlegt "v8.5-fpm-alpine" in Präfix "v", Zahlenteil "8.5" und Suffix "-fpm-alpine".
function splitTag(tag) {
    const m = tag.match(/^(v?)(\d+(?:\.\d+)*)(.*)$/);
    return m ? { prefix: m[1], version: m[2], parts: parseVersion(m[2]), suffix: m[3] } : null;
}

// Docker-Hub-Tags mit Digest. 500 Tags nach Aktualität reichen für alle bisherigen Pins.
function dockerHubTags(repo, suffix) {
    return cached(`hub:${repo}:${suffix}`, async () => {
        const tags = [];
        let url = `https://hub.docker.com/v2/repositories/${repo}/tags?page_size=100&ordering=last_updated`
            + (suffix ? `&name=${encodeURIComponent(suffix)}` : '');
        for (let page = 0; url && page < 5; page++) {
            const json = await getJson(url);
            tags.push(...json.results.map((r) => ({ name: r.name, digest: r.digest })));
            url = json.next;
        }
        return tags;
    });
}

function githubReleaseTags(repo) {
    return cached(`gh:${repo.toLowerCase()}`, async () => {
        const json = await getJson(`https://api.github.com/repos/${repo}/releases?per_page=50`, ghHeaders);
        return json.filter((r) => !r.draft && !r.prerelease).map((r) => ({ name: r.tag_name }));
    });
}

// Versionen aus Tags, die zum Pin passen: gleiches Suffix, Präfix egal. Bei `truncate`
// (Actions, @v7) werden längere Versionen auf die Granularität des Pins gekürzt, sonst
// muss die Granularität exakt stimmen ("24-alpine" nur gegen "26-alpine").
function candidates(tags, split, { truncate = false } = {}) {
    const n = split.parts.length;
    const out = [];
    for (const t of tags) {
        const s = splitTag(t.name);
        if (!s || s.suffix !== split.suffix) {
            continue;
        }
        if (truncate ? s.parts.length < n : s.parts.length !== n) {
            continue;
        }
        // raw behält die Schreibweise des Tags (Collabora: 26.04, nicht 26.4).
        const raw = truncate ? s.version.split('.').slice(0, n).join('.') : s.version;
        out.push({ parts: s.parts.slice(0, n), raw, digest: t.digest });
    }
    return out;
}

const max = (list) => list.reduce((best, c) => (!best || compare(c.parts, best.parts) > 0 ? c : best), null);
const fmt = (split, c) => (c ? `${split.prefix}${c.raw}${split.suffix}` : null);

function evaluate(split, list, channelTarget) {
    // Mit Kanal nie über dessen Ziel hinaus: nginx 1.31 ist Mainline, auch wenn der Major gleich bleibt.
    const inChannel = channelTarget ? list.filter((c) => compare(c.parts, channelTarget.parts) <= 0) : list;
    const sameMajor = max(inChannel.filter((c) => c.parts[0] === split.parts[0]));
    const target = channelTarget ?? max(list);
    return { sameMajor: fmt(split, sameMajor), target: fmt(split, target), sameMajorC: sameMajor, targetC: target };
}

function classify(split, r) {
    if (!r.targetC && !r.sameMajorC) {
        return 'nicht gefunden';
    }
    const out = [];
    if (r.sameMajorC && compare(r.sameMajorC.parts, split.parts) > 0) {
        out.push('minor/patch');
    }
    if (r.targetC && r.targetC.parts[0] > split.parts[0]) {
        out.push('MAJOR');
    }
    return out.length ? out.join(' + ') : 'aktuell';
}

async function lookupAction(pin) {
    if (/^[0-9a-f]{40}$/.test(pin.tag)) {
        return { name: pin.repo, current: pin.tag.slice(0, 12), status: 'SHA-gepinnt' };
    }
    const split = splitTag(pin.tag);
    if (!split || split.suffix) {
        return { name: pin.repo, current: pin.tag, status: 'kein Versions-Tag' };
    }
    const r = evaluate(split, candidates(await githubReleaseTags(pin.repo), split, { truncate: true }));
    return { name: pin.repo, current: pin.tag, ...r, status: classify(split, r) };
}

async function lookupImage(pin) {
    let ref = pin.ref;
    if (ref.includes('$')) {
        return { name: ref, current: '-', status: 'variabel (Build-Arg/Env)' };
    }
    const digestPinned = ref.includes('@sha256:');
    ref = ref.replace(/@sha256:[0-9a-f]+$/, '');
    const [image, tag = 'latest'] = ref.split(/:(?=[^/]*$)/);
    if (/chormanager-/.test(image)) {
        return { name: image, current: tag, status: 'eigenes Image' };
    }
    const split = splitTag(tag);
    if (!split) {
        return { name: image, current: tag, status: 'gleitender Tag' };
    }
    const segments = image.split('/');
    let list;
    let channelTarget = null;
    if (segments[0].includes('.')) {
        // Registry wie ghcr.io: Releases des gleichnamigen GitHub-Repos als Quelle.
        if (segments[0] !== 'ghcr.io') {
            return { name: image, current: tag, status: 'Registry unbekannt' };
        }
        list = candidates(await githubReleaseTags(segments.slice(1, 3).join('/')), split);
    } else {
        const repo = segments.length === 1 ? `library/${image}` : image;
        const tags = await dockerHubTags(repo, split.suffix);
        list = candidates(tags, split);
        if (CHANNELS[repo]) {
            const alias = `${CHANNELS[repo]}${split.suffix}`;
            const aliasTag = await cached(`hub:${repo}:tag:${alias}`, () =>
                getJson(`https://hub.docker.com/v2/repositories/${repo}/tags/${alias}`));
            channelTarget = list.find((c) => c.digest && c.digest === aliasTag.digest) ?? null;
            if (!channelTarget) {
                return { name: image, current: tag, ...evaluate(split, list), status: `Kanal ${alias} nicht zuordenbar` };
            }
        }
    }
    const r = evaluate(split, list, channelTarget);
    const status = classify(split, r) + (digestPinned ? ' (Digest-gepinnt)' : '');
    return { name: image, current: tag, ...r, status };
}

// ddev nutzt eigene Images (ddev/ddev-dbserver-mariadb-<version>). Ziel ist die LTS-Version
// von MariaDB, aber nur, wenn ddev sie schon anbietet - sonst die höchste angebotene darunter.
async function lookupDdevDb(pin) {
    const split = splitTag(pin.ref.split(':')[1]);
    const prefix = `ddev-dbserver-${pin.dbType}-`;
    const json = await getJson(`https://hub.docker.com/v2/repositories/ddev/?page_size=100&name=${prefix}`);
    const supported = json.results
        .map((r) => r.name)
        .filter((n) => n.startsWith(prefix))
        .map((n) => ({ name: n.slice(prefix.length) }));
    const list = candidates(supported, split);
    let ltsC = null;
    if (CHANNELS[`library/${pin.dbType}`]) {
        const official = candidates(await dockerHubTags(`library/${pin.dbType}`, ''), split);
        const alias = await getJson(`https://hub.docker.com/v2/repositories/library/${pin.dbType}/tags/lts`);
        ltsC = official.find((c) => c.digest === alias.digest) ?? null;
    }
    const capped = ltsC ? list.filter((c) => compare(c.parts, ltsC.parts) <= 0) : list;
    const r = evaluate(split, capped, max(capped));
    const lts = ltsC ? `; lts=${ltsC.raw}` : '';
    return { name: `${pin.dbType} (ddev database)`, current: split.version, ...r,
        status: `${classify(split, r)} [ddev bis ${max(list)?.raw ?? '?'}${lts}]` };
}

const rows = [];
for (const pin of pins) {
    try {
        const r = pin.kind === 'action' ? await lookupAction(pin)
            : pin.kind === 'ddev-db' ? await lookupDdevDb(pin)
                : await lookupImage(pin);
        const name = r.name + (pin.note && pin.kind !== 'ddev-db' ? ` (${pin.note})` : '');
        rows.push({ at: pin.at, name, current: r.current, sameMajor: r.sameMajor ?? '-', target: r.target ?? '-', status: r.status });
    } catch (e) {
        process.exitCode = 1;
        rows.push({ at: pin.at, name: pin.ref ?? pin.repo, current: pin.tag ?? '-', sameMajor: '-', target: '-',
            status: `Fehler: ${e.message}` });
    }
}

const header = { at: 'Fundstelle', name: 'Image/Action', current: 'aktuell', sameMajor: 'gleicher Major',
    target: 'Ziel', status: 'Einstufung' };
const cols = Object.keys(header);
const width = Object.fromEntries(cols.map((c) => [c, Math.max(...[header, ...rows].map((r) => String(r[c]).length))]));
for (const r of [header, ...rows]) {
    console.log(cols.map((c) => String(r[c]).padEnd(width[c])).join('  ').trimEnd());
}
