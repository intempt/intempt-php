#!/usr/bin/env node
/**
 * Bucket derivation is server-only. Governed by EXP-ASSIGN-001..005 in
 * `brain/product/specs/experiences/experiences-spec.md`.
 *
 * The failure this prevents is silent and unreportable: two derivations that disagree serve the
 * same person a different variant depending on which channel they arrive through. Nothing in the
 * product surfaces it, because each side is internally consistent.
 *
 * HOW THE PLATFORM ACTUALLY DECIDES — stated because an earlier version of this comment claimed the
 * platform "hashes (experienceId, identifier) and takes the result modulo the bucket count", and
 * that is false in every part. `VariantChooserService` holds `private final Random random = new
 * SecureRandom()` and draws with `random.nextInt(100)`; stability comes from PERSISTING that draw
 * under a `VariantChooseKey`, not from any hash; and `10000` appears nowhere in the service's
 * experience package. A guard whose stated reason is wrong invites someone to "fix" the guard.
 *
 * The rule survives the correction, and is in fact stronger for it: an SDK cannot re-derive a draw
 * it did not witness, so there is no client-side arithmetic that could ever agree with the server.
 *
 * The patterns below are therefore aimed at what an SDK author would INVENT, not at what the
 * platform does — a hash, a modulo, and a percentage compare are the three shapes local bucketing
 * always takes. `% 100` matters most: it is the platform's own range, so it is the arithmetic a
 * reimplementation would most plausibly reach for, and it is what this guard used to wave through.
 *
 * Zero dependencies, so it runs before install and on a machine that cannot build this SDK.
 *
 * An entry may be allowed — hashing has legitimate non-bucketing uses, idempotency keys and cache
 * keys among them — by listing it in the sidecar allowlist with a reason. An allowlist entry that
 * no longer matches anything is itself an error, so the file cannot silently rot.
 */

import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join, relative, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = process.env.GUARD_ROOT ?? join(here, '..');
const roots = (process.env.GUARD_SRC ?? 'src').split(',').map((d) => d.trim()).filter(Boolean);
const allowPath = join(here, 'no-local-bucketing-allow.json');

const SOURCE = /\.(ts|tsx|js|mjs|cjs|py|php|kt|java|swift)$/;
const SKIP_DIR = /^(node_modules|\.git|dist|build|vendor|target|__pycache__|\.venv|Pods|DerivedData)$/;

/** Hashing primitives, and the bucket arithmetic itself. */
const PATTERNS = [
  [/\b(sha-?256|sha-?1|md5|murmur|fnv|crc32|xxhash)\b/i, 'a hashing primitive'],
  // The platform's own range. `$bucket = $crc % 100` with a percentage compare is local bucketing
  // using the exact arithmetic the server uses, and it passed this guard silently until it was
  // planted deliberately. `\b` after 100 keeps `% 10000` on its own line below.
  [/%\s*100\b|\bmod\s+100\b/i, 'modulo 100 — the range the platform draws in'],
  [/%\s*10000\b|\bmod\s+10000\b/i, 'modulo a bucket count'],
  [/\bBUCKETS?_PER_|\bTOTAL_BUCKETS\b/i, 'bucket arithmetic'],
  // The compare that turns a number into an assignment. Catches the second half of the pair above,
  // so removing only the modulo does not make the derivation invisible again.
  [/\b(bucket|bucketed|bucketing|percentile|rollout_?(share|percent\w*))\b\s*[=<>!]/i,
    'a local bucket or rollout-share comparison'],
  [/createHash|MessageDigest|hashlib|CryptoKit|\bDigest\b/, 'a hash construction'],
];

function walk(dir, out = []) {
  if (!existsSync(dir)) return out;
  for (const name of readdirSync(dir)) {
    if (SKIP_DIR.test(name)) continue;
    const p = join(dir, name);
    if (statSync(p).isDirectory()) walk(p, out);
    else if (SOURCE.test(name)) out.push(p);
  }
  return out;
}

const allow = existsSync(allowPath) ? JSON.parse(readFileSync(allowPath, 'utf8')) : {};
const seen = new Set();
const hits = [];

for (const r of roots) {
  for (const file of walk(join(root, r))) {
    const rel = relative(root, file);
    readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
      if (/^\s*(\/\/|#|\*|--)/.test(line)) return; // a comment explaining the rule is not a breach
      for (const [re, what] of PATTERNS) {
        if (!re.test(line)) continue;
        const key = `${rel}:${i + 1}`;
        if (allow[key] || allow[rel]) { seen.add(allow[key] ? key : rel); return; }
        hits.push(`${key}  ${what}\n      ${line.trim().slice(0, 100)}`);
        return;
      }
    });
  }
}

const problems = [];
if (hits.length) {
  problems.push(
    `bucket derivation must be server-only (EXP-ASSIGN-001..005) — ${hits.length} occurrence(s):\n    ` +
      hits.join('\n    ')
  );
}

// The reverse check. Without it the allowlist becomes a place to park anything, and a stale entry
// hides the fact that its justification no longer applies.
const stale = Object.keys(allow).filter((k) => !seen.has(k));
if (stale.length) {
  problems.push(`allowlist entries that no longer match anything: ${stale.join(', ')}`);
}
const unexplained = Object.entries(allow).filter(([, v]) => !String(v ?? '').trim());
if (unexplained.length) {
  problems.push(`allowlist entries with no reason: ${unexplained.map(([k]) => k).join(', ')}`);
}

if (problems.length) {
  console.error('no-local-bucketing FAILED');
  for (const p of problems) console.error(`  - ${p}`);
  process.exit(1);
}
console.log(
  `no-local-bucketing OK — scanned ${roots.join(', ')}, ${Object.keys(allow).length} documented allowance(s)`
);
