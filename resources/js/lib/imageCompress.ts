/**
 * Client-side image compression.
 *
 * Why this exists
 * ---------------
 * The Inbox composer accepts up to 9 images per reply. Phone-camera JPEGs are
 * 4–8 MB each (often 12+ MB for new iPhones) so a 9-image reply can hit
 * 70–110 MB raw — that smashes the nginx `client_max_body_size` and PHP's
 * `post_max_size` cap, and the upload takes forever over slow connections.
 *
 * Resizing to 1920 px on the long edge and re-encoding as WebP @ 0.82
 * shrinks a 5 MB iPhone photo to ~300–500 KB with no perceptible quality
 * loss for the operator/agent view (and provider SDKs re-compress anyway
 * before sending to the customer).
 *
 * Behaviors:
 *   - Tiny files (< 500 KB) pass through unchanged — no point re-encoding
 *     and we keep the original mime type for screenshots / GIFs.
 *   - Animated / non-image files pass through unchanged (defensive — caller
 *     already gates on `accept="image/*"` but server validation is the
 *     source of truth).
 *   - Falls back to JPEG when OffscreenCanvas / WebP encoding is unavailable
 *     (older Safari builds).
 *   - Parallel-safe: callers can `Promise.all(files.map(compressImage))`.
 */

export type CompressOptions = {
    /** Max long-edge dimension in pixels. Defaults to 1920. */
    maxDim?: number;
    /** WebP/JPEG quality 0..1. Defaults to 0.82. */
    quality?: number;
    /** Preferred output mime type. Defaults to image/webp. */
    mime?: 'image/webp' | 'image/jpeg';
    /** Files smaller than this (bytes) skip re-encoding. Defaults to 500 KB. */
    passthroughBytes?: number;
};

const DEFAULTS: Required<CompressOptions> = {
    maxDim: 1920,
    quality: 0.82,
    mime: 'image/webp',
    passthroughBytes: 500_000,
};

export async function compressImage(
    file: File,
    opts: CompressOptions = {},
): Promise<File> {
    const cfg = { ...DEFAULTS, ...opts };

    if (!file.type.startsWith('image/')) {
return file;
}

    if (file.size < cfg.passthroughBytes) {
return file;
}

    if (typeof createImageBitmap !== 'function') {
return file;
}

    let bitmap: ImageBitmap;

    try {
        bitmap = await createImageBitmap(file);
    } catch {
        // Some browsers throw on malformed images — let the server reject it.
        return file;
    }

    const longEdge = Math.max(bitmap.width, bitmap.height);
    const scale = longEdge > cfg.maxDim ? cfg.maxDim / longEdge : 1;
    const w = Math.max(1, Math.round(bitmap.width * scale));
    const h = Math.max(1, Math.round(bitmap.height * scale));

    const blob = await renderToBlob(bitmap, w, h, cfg);
    bitmap.close?.();

    if (!blob) {
return file;
}

    if (blob.size >= file.size) {
return file;
} // compression didn't help, keep original

    const ext = cfg.mime === 'image/webp' ? 'webp' : 'jpg';
    const baseName = file.name.replace(/\.[^.]+$/, '');

    return new File([blob], `${baseName}.${ext}`, {
        type: blob.type,
        lastModified: Date.now(),
    });
}

async function renderToBlob(
    bitmap: ImageBitmap,
    w: number,
    h: number,
    cfg: Required<CompressOptions>,
): Promise<Blob | null> {
    // OffscreenCanvas path — Chrome / Firefox / Safari 16.4+.
    if (typeof OffscreenCanvas !== 'undefined') {
        const canvas = new OffscreenCanvas(w, h);
        const ctx = canvas.getContext('2d');

        if (!ctx) {
return null;
}

        ctx.drawImage(bitmap, 0, 0, w, h);

        try {
            return await canvas.convertToBlob({
                type: cfg.mime,
                quality: cfg.quality,
            });
        } catch {
            // Some browsers throw on WebP for certain source content — fall
            // back to JPEG on the SAME canvas.
            return await canvas.convertToBlob({
                type: 'image/jpeg',
                quality: cfg.quality,
            });
        }
    }

    // HTMLCanvasElement fallback (older Safari).
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');

    if (!ctx) {
return null;
}

    ctx.drawImage(bitmap, 0, 0, w, h);

    return new Promise((resolve) => {
        canvas.toBlob(
            (blob) => resolve(blob),
            cfg.mime,
            cfg.quality,
        );
    });
}

export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
return `${bytes} B`;
}

    if (bytes < 1024 * 1024) {
return `${(bytes / 1024).toFixed(0)} KB`;
}

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}