/**
 * Real gap found live 2026-09-30: guest customers applying from a phone (very
 * often through Facebook/Messenger's in-app browser, per production Nginx
 * logs) upload full-resolution camera photos for their ID and utility bill —
 * easily 10-20MB each, uncompressed. Combined with other form fields, that
 * routinely pushed the request over the server's body-size limit, and Nginx
 * rejected it with a bare 413 before Laravel ever got to return a helpful
 * field-specific error — the customer just saw "Could not submit the
 * application. Please try again." with no way to know why.
 *
 * Raising the server-side limit (see infra) helps, but re-encoding down to a
 * size that's still perfectly legible for document review fixes the actual
 * problem: faster, more reliable uploads on the cellular connections these
 * customers are usually on, not just a bigger ceiling to eventually hit again.
 */

const MAX_DIMENSION = 1920;
const JPEG_QUALITY = 0.82;
/** Below this, recompressing isn't worth the risk of a visible quality drop for negligible savings. */
const SKIP_BELOW_BYTES = 800 * 1024;

function withJpegExtension(name: string): string {
  const withoutExt = name.replace(/\.[^./\\]+$/, "");
  return `${withoutExt || "upload"}.jpg`;
}

/**
 * Re-encodes an oversized image client-side before it ever reaches the
 * network. Never throws — on any failure (unsupported format like HEIC in a
 * browser that can't decode it, a tiny/blank result, etc.) it resolves with
 * the original file untouched, so a compression hiccup can never block
 * someone from submitting their application.
 */
export async function compressImageIfNeeded(file: File): Promise<File> {
  if (!file.type.startsWith("image/") || file.size <= SKIP_BELOW_BYTES) {
    return file;
  }

  try {
    const bitmap = await loadImage(file);
    const scale = Math.min(1, MAX_DIMENSION / Math.max(bitmap.width, bitmap.height));
    const width = Math.round(bitmap.width * scale);
    const height = Math.round(bitmap.height * scale);

    const canvas = document.createElement("canvas");
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext("2d");
    if (!ctx) return file;
    ctx.drawImage(bitmap, 0, 0, width, height);
    if ("close" in bitmap) bitmap.close();

    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, "image/jpeg", JPEG_QUALITY));
    if (!blob || blob.size === 0 || blob.size >= file.size) {
      return file;
    }

    return new File([blob], withJpegExtension(file.name), { type: "image/jpeg", lastModified: Date.now() });
  } catch {
    return file;
  }
}

/** createImageBitmap handles orientation/HEIC support better where available; falls back to a plain <img> load. */
async function loadImage(file: File): Promise<ImageBitmap | HTMLImageElement> {
  if ("createImageBitmap" in window) {
    try {
      return await createImageBitmap(file);
    } catch {
      // Falls through to the <img> path below (some HEIC/HEIF files decode
      // there even when createImageBitmap refuses them, depending on browser).
    }
  }

  const url = URL.createObjectURL(file);
  try {
    return await new Promise<HTMLImageElement>((resolve, reject) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = () => reject(new Error("Could not decode image"));
      img.src = url;
    });
  } finally {
    URL.revokeObjectURL(url);
  }
}
