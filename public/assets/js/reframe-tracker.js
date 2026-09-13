const DEFAULTS = Object.freeze({
  maxMissing: 2,
  alpha: 0.35,
  tolerance: 0.025,
  maxPoints: 32,
  minConfidence: 0,
});

const finite = value => typeof value === 'number' && Number.isFinite(value);
const clamp = value => Math.max(0, Math.min(1, value));
const EPSILON = 1e-12;
const descending = (a, b) => Math.abs(a - b) <= EPSILON ? 0 : b - a;
const ascending = (a, b) => Math.abs(a - b) <= EPSILON ? 0 : a - b;

function normalizeOptions(options) {
  const source = options && typeof options === 'object' ? options : {};
  return {
    maxMissing: Number.isInteger(source.maxMissing) && source.maxMissing >= 0
      ? Math.min(source.maxMissing, 2) : DEFAULTS.maxMissing,
    alpha: finite(source.alpha) && source.alpha > 0 && source.alpha <= 1
      ? source.alpha : DEFAULTS.alpha,
    tolerance: finite(source.tolerance) && source.tolerance >= 0
      ? source.tolerance : DEFAULTS.tolerance,
    maxPoints: Number.isInteger(source.maxPoints) && source.maxPoints >= 2
      ? Math.min(source.maxPoints, 32) : DEFAULTS.maxPoints,
    minConfidence: finite(source.minConfidence) && source.minConfidence >= 0 && source.minConfidence <= 1
      ? source.minConfidence : DEFAULTS.minConfidence,
  };
}

function normalizeBox(raw, frameWidth, frameHeight, minConfidence) {
  if (!raw || typeof raw !== 'object' || !finite(frameWidth) || !finite(frameHeight)
      || frameWidth <= 0 || frameHeight <= 0) return null;
  const x = raw.x ?? raw.originX;
  const y = raw.y ?? raw.originY;
  const width = raw.width;
  const height = raw.height;
  const confidence = raw.confidence ?? raw.score ?? 0;
  if (![x, y, width, height, confidence].every(finite) || width <= 0 || height <= 0
      || confidence < minConfidence || confidence > 1) return null;

  const left = clamp(x / frameWidth);
  const top = clamp(y / frameHeight);
  const right = clamp((x + width) / frameWidth);
  const bottom = clamp((y + height) / frameHeight);
  if (right <= left || bottom <= top) return null;
  const normalizedWidth = right - left;
  const normalizedHeight = bottom - top;
  return {
    x: left,
    y: top,
    width: normalizedWidth,
    height: normalizedHeight,
    centerX: left + normalizedWidth / 2,
    centerY: top + normalizedHeight / 2,
    area: normalizedWidth * normalizedHeight,
    confidence,
  };
}

function overlap(a, b) {
  const left = Math.max(a.x, b.x);
  const top = Math.max(a.y, b.y);
  const right = Math.min(a.x + a.width, b.x + b.width);
  const bottom = Math.min(a.y + a.height, b.y + b.height);
  const intersection = Math.max(0, right - left) * Math.max(0, bottom - top);
  const union = a.area + b.area - intersection;
  return union > 0 ? intersection / union : 0;
}

function centerDistance(a, b) {
  return Math.hypot(a.centerX - b.centerX, a.centerY - b.centerY);
}

function associate(tracks, boxes) {
  const candidates = [];
  for (const track of tracks) {
    for (let boxIndex = 0; boxIndex < boxes.length; boxIndex += 1) {
      candidates.push({
        track,
        boxIndex,
        iou: overlap(track.lastBox, boxes[boxIndex]),
        distance: centerDistance(track.lastBox, boxes[boxIndex]),
      });
    }
  }
  candidates.sort((a, b) => (
    descending(a.iou, b.iou)
    || ascending(a.distance, b.distance)
    || a.track.id - b.track.id
    || a.boxIndex - b.boxIndex
  ));

  const trackIds = new Set();
  const boxIds = new Set();
  const matches = [];
  for (const candidate of candidates) {
    if (trackIds.has(candidate.track.id) || boxIds.has(candidate.boxIndex)) continue;
    trackIds.add(candidate.track.id);
    boxIds.add(candidate.boxIndex);
    matches.push(candidate);
  }
  return { matches, trackIds, boxIds };
}

function pointVector(point, durationMs) {
  return [point.at_ms / durationMs, point.center_x, point.center_y];
}

function perpendicularDistance(point, start, end, durationMs) {
  const value = pointVector(point, durationMs);
  const first = pointVector(start, durationMs);
  const last = pointVector(end, durationMs);
  const segment = last.map((coordinate, index) => coordinate - first[index]);
  const relative = value.map((coordinate, index) => coordinate - first[index]);
  const lengthSquared = segment.reduce((sum, coordinate) => sum + coordinate * coordinate, 0);
  if (lengthSquared === 0) {
    return Math.sqrt(relative.reduce((sum, coordinate) => sum + coordinate * coordinate, 0));
  }
  const projection = Math.max(0, Math.min(1,
    relative.reduce((sum, coordinate, index) => sum + coordinate * segment[index], 0) / lengthSquared));
  return Math.sqrt(relative.reduce((sum, coordinate, index) => {
    const delta = coordinate - projection * segment[index];
    return sum + delta * delta;
  }, 0));
}

function simplify(points, durationMs, tolerance) {
  if (points.length <= 2) return points.slice();
  let index = -1;
  let greatest = tolerance;
  for (let current = 1; current < points.length - 1; current += 1) {
    const distance = perpendicularDistance(points[current], points[0], points.at(-1), durationMs);
    if (distance > greatest) {
      greatest = distance;
      index = current;
    }
  }
  if (index === -1) return [points[0], points.at(-1)];
  const left = simplify(points.slice(0, index + 1), durationMs, tolerance);
  const right = simplify(points.slice(index), durationMs, tolerance);
  return left.slice(0, -1).concat(right);
}

function downsample(points, maximum) {
  if (points.length <= maximum) return points;
  const selected = [];
  for (let index = 0; index < maximum; index += 1) {
    selected.push(points[Math.round(index * (points.length - 1) / (maximum - 1))]);
  }
  return selected;
}

function rounded(value) {
  const result = Math.round(clamp(value) * 1000000) / 1000000;
  return Object.is(result, -0) ? 0 : result;
}

function canonicalPoint(point) {
  return {
    at_ms: point.at_ms,
    center_x: rounded(point.center_x),
    center_y: rounded(point.center_y),
  };
}

export function buildReframeKeyframes(samples, durationMs, options = undefined) {
  if (!Array.isArray(samples) || !Number.isInteger(durationMs) || durationMs <= 0) return [];
  const settings = normalizeOptions(options);
  const ordered = samples
    .map((sample, index) => ({ sample, index }))
    .filter(({ sample }) => sample && Number.isInteger(sample.at_ms)
      && sample.at_ms >= 0 && sample.at_ms <= durationMs)
    .sort((a, b) => a.sample.at_ms - b.sample.at_ms || a.index - b.index);

  const active = [];
  const completed = [];
  let nextId = 1;
  let previousTimestamp = -1;

  for (const { sample } of ordered) {
    if (sample.at_ms === previousTimestamp) continue;
    previousTimestamp = sample.at_ms;
    const boxes = (Array.isArray(sample.boxes) ? sample.boxes : [])
      .map(raw => normalizeBox(raw, sample.width, sample.height, settings.minConfidence))
      .filter(box => box !== null);
    const { matches, trackIds, boxIds } = associate(active, boxes);

    for (const { track, boxIndex } of matches) {
      const detected = boxes[boxIndex];
      track.smoothX = settings.alpha * detected.centerX + (1 - settings.alpha) * track.smoothX;
      track.smoothY = settings.alpha * detected.centerY + (1 - settings.alpha) * track.smoothY;
      track.lastBox = detected;
      track.missing = 0;
      track.confidenceTotal += detected.confidence;
      track.areaTotal += detected.area;
      track.detections += 1;
      track.points.push({ at_ms: sample.at_ms, center_x: track.smoothX, center_y: track.smoothY });
    }

    for (let index = active.length - 1; index >= 0; index -= 1) {
      const track = active[index];
      if (trackIds.has(track.id)) continue;
      track.missing += 1;
      if (track.missing > settings.maxMissing) {
        completed.push(track);
        active.splice(index, 1);
      }
    }

    for (let boxIndex = 0; boxIndex < boxes.length; boxIndex += 1) {
      if (boxIds.has(boxIndex)) continue;
      const detected = boxes[boxIndex];
      active.push({
        id: nextId,
        lastBox: detected,
        missing: 0,
        smoothX: detected.centerX,
        smoothY: detected.centerY,
        confidenceTotal: detected.confidence,
        areaTotal: detected.area,
        detections: 1,
        points: [{ at_ms: sample.at_ms, center_x: detected.centerX, center_y: detected.centerY }],
      });
      nextId += 1;
    }
  }

  const tracks = completed.concat(active);
  if (tracks.length === 0) return [];
  tracks.sort((a, b) => (
    b.detections - a.detections
    || descending(a.confidenceTotal / a.detections, b.confidenceTotal / b.detections)
    || descending(a.areaTotal / a.detections, b.areaTotal / b.detections)
    || a.id - b.id
  ));
  const selected = tracks[0].points.slice();
  if (selected.length === 0) return [];
  if (selected[0].at_ms !== 0) selected.unshift({ ...selected[0], at_ms: 0 });
  if (selected.at(-1).at_ms !== durationMs) selected.push({ ...selected.at(-1), at_ms: durationMs });
  return downsample(simplify(selected, durationMs, settings.tolerance), settings.maxPoints)
    .map(canonicalPoint);
}
