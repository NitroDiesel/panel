#!/usr/bin/env node
/**
 * Fails when production dependencies have a high or critical advisory that isn't in
 * .github/audit-allowlist.json, or whose allowlist entry has expired. Every allowlist
 * entry needs a reason and an expiry date so accepted advisories get re-reviewed.
 *
 * usage: node .github/scripts/yarn-audit.js
 */
const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const GATED = new Set(['high', 'critical']);
const allowlistPath = path.join(__dirname, '..', 'audit-allowlist.json');

const allowlist = new Map();
for (const entry of JSON.parse(fs.readFileSync(allowlistPath, 'utf8')).yarn) {
    for (const field of ['id', 'package', 'reason', 'expires']) {
        if (!entry[field]) {
            console.error(`Allowlist entry ${JSON.stringify(entry)} is missing "${field}".`);
            process.exit(2);
        }
    }
    allowlist.set(entry.id, entry);
}

const audit = spawnSync('yarn', ['audit', '--groups', 'dependencies', '--json'], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
if (audit.error) {
    console.error(`Could not run yarn audit: ${audit.error.message}`);
    process.exit(2);
}

const found = new Map();
for (const line of audit.stdout.split('\n')) {
    if (!line.trim()) continue;
    const event = JSON.parse(line);
    if (event.type !== 'auditAdvisory') continue;
    const advisory = event.data.advisory;
    if (!GATED.has(advisory.severity)) continue;
    const id = advisory.github_advisory_id;
    if (!found.has(id)) {
        found.set(id, { id, package: advisory.module_name, severity: advisory.severity, title: advisory.title, paths: new Set() });
    }
    found.get(id).paths.add(event.data.resolution.path);
}

const today = new Date().toISOString().slice(0, 10);
const failures = [];
for (const advisory of found.values()) {
    const entry = allowlist.get(advisory.id);
    const where = `${advisory.package} (${advisory.severity}) ${advisory.id}: ${advisory.title}\n    via ${[...advisory.paths].slice(0, 3).join(', ')}`;
    if (!entry) {
        failures.push(`not allowlisted: ${where}`);
    } else if (entry.expires < today) {
        failures.push(`allowlist entry expired on ${entry.expires}: ${where}`);
    }
}

for (const id of allowlist.keys()) {
    if (!found.has(id)) {
        console.log(`note: ${id} is allowlisted but no longer reported; remove it from audit-allowlist.json.`);
    }
}

console.log(`${found.size} high/critical advisories in production dependencies, ${found.size - failures.length} allowlisted.`);
if (failures.length > 0) {
    console.error(failures.join('\n'));
    process.exit(1);
}
