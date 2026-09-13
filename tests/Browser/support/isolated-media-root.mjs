import { lstat, mkdir, realpath, rmdir } from 'node:fs/promises';
import { dirname, isAbsolute, join, parse, relative, resolve, sep } from 'node:path';

const lexists = async path => lstat(path).then(() => true, () => false);
const comparablePath = path => process.platform === 'win32' ? path.toLowerCase() : path;
const pathWithin = (candidate, root) => {
  const comparableCandidate = comparablePath(candidate);
  const comparableRoot = comparablePath(root);
  return comparableCandidate === comparableRoot || comparableCandidate.startsWith(`${comparableRoot}${sep}`);
};

async function validateExistingSegments(path) {
  const root = parse(path).root;
  const tail = relative(root, path);
  if (tail === '' || tail.startsWith('..') || isAbsolute(tail)) {
    throw new Error('Test media root is unsafe.');
  }
  let current = root;
  for (const segment of tail.split(sep)) {
    if (segment === '' || segment === '.' || segment === '..') {
      throw new Error('Test media root is unsafe.');
    }
    current = join(current, segment);
    const stat = await lstat(current);
    const canonical = await realpath(current);
    if (stat.isSymbolicLink() || !stat.isDirectory()
        || comparablePath(canonical) !== comparablePath(current)) {
      throw new Error('Test media root is unsafe.');
    }
  }
}

export async function prepareIsolatedBaseRoot(candidate, publicRoot) {
  const base = resolve(candidate);
  const publicCanonical = await realpath(publicRoot);
  if (!isAbsolute(base) || parse(base).root === base || pathWithin(base, publicCanonical)) {
    throw new Error('Test media root is unsafe.');
  }

  let cursor = base;
  const missing = [];
  while (!await lexists(cursor)) {
    const parent = dirname(cursor);
    if (parent === cursor) throw new Error('Test media root is unsafe.');
    missing.unshift(relative(parent, cursor));
    cursor = parent;
  }
  await validateExistingSegments(cursor);
  const nearestCanonical = await realpath(cursor);
  if (comparablePath(nearestCanonical) !== comparablePath(cursor)) {
    throw new Error('Test media root is unsafe.');
  }
  const projected = missing.reduce((path, segment) => join(path, segment), nearestCanonical);
  if (comparablePath(projected) !== comparablePath(base) || pathWithin(projected, publicCanonical)) {
    throw new Error('Test media root is unsafe.');
  }

  const created = [];
  let current = cursor;
  try {
    for (const segment of missing) {
      current = join(current, segment);
      await mkdir(current);
      created.push(current);
      await validateExistingSegments(current);
    }
  } catch (error) {
    for (const path of [...created].reverse()) {
      try { await rmdir(path); } catch {}
    }
    throw error;
  }

  return {
    cleanup: async () => {
      for (const path of [...created].reverse()) {
        if (!await lexists(path)) continue;
        await validateExistingSegments(path);
        await rmdir(path);
      }
    },
  };
}
