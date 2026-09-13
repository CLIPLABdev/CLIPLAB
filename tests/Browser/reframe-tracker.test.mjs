import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const modulePath = process.argv[2];
if (!modulePath) throw new Error('Usage: node reframe-tracker.test.mjs <tracker-module>');
const source = await readFile(resolve(modulePath), 'utf8');
const { buildReframeKeyframes } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);

const tests = [];
const test = (name, fn) => tests.push([name, fn]);
const box = (x, y, width, height, confidence = 0.9) => ({ x, y, width, height, confidence });
const sample = (at_ms, boxes, width = 1000, height = 1000) => ({ at_ms, width, height, boxes });

test('tracks one face, normalizes geometry, applies EMA 0.35 and extends endpoints', () => {
  const points = buildReframeKeyframes([
    sample(0, [box(100, 200, 200, 200)]),
    sample(500, [box(300, 200, 200, 200)]),
    sample(1000, [box(500, 200, 200, 200)]),
  ], 2000, { tolerance: 0.001 });

  assert.deepEqual(points, [
    { at_ms: 0, center_x: 0.2, center_y: 0.3 },
    { at_ms: 500, center_x: 0.27, center_y: 0.3 },
    { at_ms: 1000, center_x: 0.3855, center_y: 0.3 },
    { at_ms: 2000, center_x: 0.3855, center_y: 0.3 },
  ]);
});

test('associates crossing faces one-to-one by greatest IoU then shortest center distance', () => {
  const points = buildReframeKeyframes([
    sample(0, [box(100, 200, 180, 180, 0.95), box(700, 200, 180, 180, 0.7)]),
    sample(500, [box(270, 200, 180, 180, 0.95), box(530, 200, 180, 180, 0.7)]),
    sample(1000, [box(450, 200, 180, 180, 0.7), box(350, 200, 180, 180, 0.95)]),
    sample(1500, [box(180, 200, 180, 180, 0.7), box(620, 200, 180, 180, 0.95)]),
  ], 1500, { tolerance: 0 });

  assert.equal(points[0].center_x, 0.19);
  assert.equal(points.at(-1).center_x, 0.300014);
  assert.ok(points.every((point, index) => index === 0 || point.at_ms > points[index - 1].at_ms));
});

test('keeps a track through exactly two missing samples without inventing gap points', () => {
  const points = buildReframeKeyframes([
    sample(0, [box(100, 100, 200, 200)]),
    sample(500, []),
    sample(1000, []),
    sample(1500, [box(700, 100, 200, 200)]),
  ], 1500, { tolerance: 0 });

  assert.deepEqual(points.map(point => point.at_ms), [0, 1500]);
  assert.deepEqual(points.map(point => point.center_x), [0.2, 0.41]);
});

test('expires a track on the third missing sample and assigns a new stable ID', () => {
  const points = buildReframeKeyframes([
    sample(0, [box(100, 100, 200, 200)]),
    sample(250, []),
    sample(500, []),
    sample(750, []),
    sample(1000, [box(700, 100, 200, 200)]),
    sample(1250, [box(700, 100, 200, 200)]),
  ], 1250, { tolerance: 0 });

  assert.deepEqual(points, [
    { at_ms: 0, center_x: 0.8, center_y: 0.2 },
    { at_ms: 1250, center_x: 0.8, center_y: 0.2 },
  ]);
});

test('selects lexicographically by persistence, mean confidence, mean area, then lower ID', () => {
  const persistentLowConfidence = buildReframeKeyframes([
    sample(0, [box(50, 100, 100, 100, 0.1), box(700, 100, 200, 200, 0.99)]),
    sample(500, [box(60, 100, 100, 100, 0.1)]),
    sample(1000, [box(70, 100, 100, 100, 0.1)]),
  ], 1000);
  assert.equal(persistentLowConfidence[0].center_x, 0.1);

  const confidenceWins = buildReframeKeyframes([
    sample(0, [box(50, 100, 100, 100, 0.6), box(700, 100, 100, 100, 0.9)]),
    sample(1000, [box(50, 100, 100, 100, 0.6), box(700, 100, 100, 100, 0.9)]),
  ], 1000);
  assert.equal(confidenceWins[0].center_x, 0.75);

  const areaWins = buildReframeKeyframes([
    sample(0, [box(50, 100, 100, 100, 0.8), box(600, 100, 300, 300, 0.8)]),
    sample(1000, [box(50, 100, 100, 100, 0.8), box(600, 100, 300, 300, 0.8)]),
  ], 1000);
  assert.equal(areaWins[0].center_x, 0.75);

  const idWins = buildReframeKeyframes([
    sample(0, [box(50, 100, 100, 100, 0.8), box(700, 100, 100, 100, 0.8)]),
    sample(1000, [box(50, 100, 100, 100, 0.8), box(700, 100, 100, 100, 0.8)]),
  ], 1000);
  assert.equal(idWins[0].center_x, 0.1);
});

test('discards invalid and low-confidence boxes and returns an empty list without a track', () => {
  const points = buildReframeKeyframes([
    sample(0, [
      box(100, 100, 200, 200, 0.49),
      box(Number.NaN, 0, 20, 20, 1),
      box(0, 0, 0, 20, 1),
      box(2000, 0, 10, 10, 1),
    ]),
    sample(1000, []),
  ], 1000, { minConfidence: 0.5 });
  assert.deepEqual(points, []);
});

test('simplifies normalized linear motion and uniformly caps a complex path at 32 points', () => {
  const linear = Array.from({ length: 21 }, (_, index) =>
    sample(index * 100, [box(100 + index * 20, 200, 100, 100)]));
  assert.deepEqual(buildReframeKeyframes(linear, 2000), [
    { at_ms: 0, center_x: 0.15, center_y: 0.25 },
    { at_ms: 2000, center_x: 0.512864, center_y: 0.25 },
  ]);

  const complex = Array.from({ length: 180 }, (_, index) => {
    const x = index % 2 === 0 ? 50 : 850;
    const y = index % 3 === 0 ? 50 : 850;
    return sample(index * 500, [box(x, y, 100, 100)]);
  });
  const points = buildReframeKeyframes(complex, 89500, { tolerance: 0, maxPoints: 32 });
  assert.equal(points.length, 32);
  assert.equal(points[0].at_ms, 0);
  assert.equal(points.at(-1).at_ms, 89500);
});

test('returns only canonical finite clamped fields with no negative zero or private metadata', () => {
  const points = buildReframeKeyframes([
    sample(250, [box(-20, -20, 100, 100, 0.9)]),
    sample(750, [box(950, 950, 100, 100, 0.9)]),
  ], 1000, { tolerance: 0 });

  for (const point of points) {
    assert.deepEqual(Object.keys(point), ['at_ms', 'center_x', 'center_y']);
    assert.ok(Number.isInteger(point.at_ms));
    for (const key of ['center_x', 'center_y']) {
      assert.ok(Number.isFinite(point[key]));
      assert.ok(point[key] >= 0 && point[key] <= 1);
      assert.equal(Object.is(point[key], -0), false);
      const decimals = String(point[key]).split('.')[1]?.length ?? 0;
      assert.ok(decimals <= 6);
    }
    for (const forbidden of ['box', 'confidence', 'source', 'detector', 'id']) {
      assert.equal(Object.hasOwn(point, forbidden), false);
    }
  }
});

let failed = 0;
for (const [name, fn] of tests) {
  try {
    await fn();
    console.log(`ok - ${name}`);
  } catch (error) {
    failed += 1;
    console.error(`not ok - ${name}`);
    console.error(error);
  }
}
if (failed > 0) process.exitCode = 1;
else console.log(`PASS ${tests.length} tracker tests`);
