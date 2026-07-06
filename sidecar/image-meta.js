// Minimal image dimension reader for the zca-js imageMetadataGetter option.
// Reads width/height from PNG and JPEG headers without any dependency.
// ponytail: PNG + JPEG only (the two formats the CRM composer accepts as image/*
//   from browsers in practice). Add GIF/WEBP parsing if agents send those.
import { promises as fs } from 'node:fs';

function pngSize(buf) {
  // PNG: 8-byte signature, then IHDR chunk with width/height as big-endian u32.
  if (buf.length < 24) return null;
  if (buf.readUInt32BE(0) !== 0x89504e47) return null;
  return { width: buf.readUInt32BE(16), height: buf.readUInt32BE(20) };
}

function jpegSize(buf) {
  // JPEG: scan SOFn markers for the frame that carries height/width.
  if (buf.length < 4 || buf[0] !== 0xff || buf[1] !== 0xd8) return null;
  let i = 2;
  while (i < buf.length - 8) {
    if (buf[i] !== 0xff) { i++; continue; }
    const marker = buf[i + 1];
    // SOF0..SOF15 except DHT(c4)/DAC(c8)/DRI(cc) hold the dimensions.
    if (marker >= 0xc0 && marker <= 0xcf && marker !== 0xc4 && marker !== 0xc8 && marker !== 0xcc) {
      return { height: buf.readUInt16BE(i + 5), width: buf.readUInt16BE(i + 7) };
    }
    const len = buf.readUInt16BE(i + 2);
    i += 2 + len;
  }
  return null;
}

export async function imageMetadataGetter(filePath) {
  const buf = await fs.readFile(filePath);
  const dim = pngSize(buf) || jpegSize(buf);
  if (!dim) return null;
  return { width: dim.width, height: dim.height, size: buf.length };
}

// Runnable self-check: node image-meta.js
if (import.meta.url === `file://${process.argv[1]}`) {
  const assert = (await import('node:assert')).default;
  const png1x1 = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC',
    'base64',
  );
  assert.deepStrictEqual(pngSize(png1x1), { width: 1, height: 1 }, 'png 1x1');
  console.log('image-meta self-check ok');
}
