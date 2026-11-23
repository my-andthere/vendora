import sharp from 'sharp';
import type { Metadata } from 'sharp';
import { q1 } from './db';
import { fmtQty } from './format';

/**
 * Documentation photo checks — port of legacy_php/includes/photo_check.php.
 * Blur detection uses the variance of the Laplacian; the PHP version used GD,
 * this port uses sharp.
 */

/** Blur threshold (variance of Laplacian). Lower = blurrier. */
export const BLUR_THRESHOLD = 90.0;

/** Side length of the image used for analysis. */
export const BLUR_ANALYZE_SIZE = 320;

/**
 * Variance of the Laplacian of an image, or null when the file cannot be
 * analysed (unsupported format / too small).
 */
export async function imageBlurScore(absPath: string): Promise<number | null> {
  let meta: Metadata;
  try {
    meta = await sharp(absPath).metadata();
  } catch {
    return null;
  }
  const w = meta.width ?? 0;
  const h = meta.height ?? 0;
  if (w < 8 || h < 8) return null;

  const tw = BLUR_ANALYZE_SIZE;
  const th = Math.max(1, Math.round((h * tw) / Math.max(1, w)));

  let raw: Buffer;
  try {
    raw = await sharp(absPath)
      .resize(tw, th, { fit: 'fill' })
      .removeAlpha()
      .raw()
      .toBuffer();
  } catch {
    return null;
  }
  if (raw.length < tw * th * 3) return null;

  // Luminance, matching the PHP formula: 0.299R + 0.587G + 0.114B
  const lum = new Float64Array(tw * th);
  for (let i = 0; i < tw * th; i++) {
    const r = raw[i * 3];
    const g = raw[i * 3 + 1];
    const b = raw[i * 3 + 2];
    lum[i] = 0.299 * r + 0.587 * g + 0.114 * b;
  }
  let sum = 0;
  let sumSq = 0;
  let n = 0;
  for (let y = 1; y < th - 1; y++) {
    for (let x = 1; x < tw - 1; x++) {
      const i = y * tw + x;
      const lap = 4 * lum[i] - lum[i - tw] - lum[i + tw] - lum[i - 1] - lum[i + 1];
      sum += lap;
      sumSq += lap * lap;
      n++;
    }
  }
  if (n === 0) return null;

  const mean = sum / n;
  return Math.round((sumSq / n - mean * mean) * 1e4) / 1e4;
}

export type BlurCheck = { blurry: boolean; score: number | null; reason: string };

/** Whether a photo is considered blurry. */
export async function imageBlurCheck(absPath: string, threshold: number | null = null): Promise<BlurCheck> {
  const limit = threshold ?? BLUR_THRESHOLD;
  const score = await imageBlurScore(absPath);
  if (score === null) {
    return {
      blurry: false,
      score: null,
      reason: 'Foto tidak bisa dianalisa (format tidak didukung / terlalu kecil).',
    };
  }
  if (score < limit) {
    return {
      blurry: true,
      score,
      reason: `Foto terblur (skor ${score.toFixed(1)} < ${limit.toFixed(0)}). Silakan foto ulang dengan kamera fokus & upload ulang.`,
    };
  }
  return { blurry: false, score, reason: '' };
}

export type UsageCheck = { ok: boolean; error?: string; max: number };

/**
 * Validate a material quantity against the per-ticket limit
 * (materials.max_use_per_ticket).
 */
export async function materialUsageCheck(materialId: number, qty: number): Promise<UsageCheck> {
  const m = await q1<{ id: number; name: string; unit: string; max_use_per_ticket: number }>(
    'SELECT id, name, unit, max_use_per_ticket FROM materials WHERE id=?',
    [materialId]
  );
  if (!m) return { ok: false, error: 'Material tidak ditemukan.', max: 0 };
  const max = Number(m.max_use_per_ticket ?? 0);
  if (max > 0 && qty > max) {
    return {
      ok: false,
      max,
      error:
        `Jumlah ${m.name} melebihi batas per tiket (maks ${fmtQty(max)} ${m.unit}). ` +
        'Mohon konfirmasi ke CGS sebelum memakai material tersebut.',
    };
  }
  return { ok: true, max };
}

/** Total quantity of a material already used on a ticket. */
export async function materialUsedTotal(
  ticketId: number,
  materialId: number,
  excludeLineId = 0
): Promise<number> {
  const row = await q1<{ total: number }>(
    'SELECT COALESCE(SUM(qty_used),0) AS total FROM ticket_materials WHERE ticket_id=? AND material_id=? AND id<>?',
    [ticketId, materialId, excludeLineId]
  );
  return Number(row?.total ?? 0);
}
