// Checks that the committed assets/dist/ was built from the current sources.
//
//   node scripts/check-dist-fresh.mjs          exit 1 when assets/dist/BUILD does not match the sources
//   node scripts/check-dist-fresh.mjs --write  record the hash (run by "npm run build")
//
// The hash covers src/, the build config, the lockfile and the map-engine files that end up in the bundle.
// Line endings are normalized so the result is the same on every platform.

import { createHash } from 'node:crypto';
import { readFileSync, readdirSync, statSync, writeFileSync, existsSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('..', import.meta.url));
const engine = join(root, 'node_modules/@sla/map-engine');

function files(dir) {
  return readdirSync(dir).flatMap((name) => {
    const p = join(dir, name);
    return statSync(p).isDirectory() ? files(p) : [p];
  });
}

const inputs = [
  ...files(join(root, 'src')),
  join(root, 'vite.config.ts'),
  join(root, 'tsconfig.json'),
  join(root, 'package-lock.json'),
  ...['package.json', 'src/core/map-base.ts', 'src/data/map-sources.ts', 'src/data/types.ts'].map((f) => join(engine, f)),
].sort();

const hash = createHash('sha256');
for (const f of inputs) {
  if (!existsSync(f)) {
    console.error(`missing input ${relative(root, f)} (run npm install)`);
    process.exit(2);
  }
  hash.update(relative(root, f).replace(/\\/g, '/'));
  hash.update(readFileSync(f, 'utf8').replace(/\r\n?/g, '\n'));
}
const digest = hash.digest('hex');
const buildFile = join(root, 'assets/dist/BUILD');

if (process.argv.includes('--write')) {
  writeFileSync(buildFile, `${digest}\n`);
  console.log(`assets/dist/BUILD ${digest.slice(0, 12)}`);
} else {
  const recorded = existsSync(buildFile) ? readFileSync(buildFile, 'utf8').trim() : '';
  if (recorded !== digest) {
    console.error('assets/dist/ is stale: run "npm run build" and commit assets/dist/');
    process.exit(1);
  }
  console.log('assets/dist/ is up to date');
}
