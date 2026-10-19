// Unit tests for public/assets/js/lib/sha256.js (classic script loaded into a VM context).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import vm from 'node:vm';

const ctx = { Uint8Array, Uint32Array, Math, String };
ctx.self = ctx;
vm.runInNewContext(readFileSync(new URL('../../public/assets/js/lib/sha256.js', import.meta.url), 'utf8'), ctx);
const hex = (b) => Buffer.from(b).toString('hex');
const enc = (s) => new TextEncoder().encode(s);

test('NIST vectors', () => {
  assert.equal(hex(ctx.nhmSha256(enc(''))), 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
  assert.equal(hex(ctx.nhmSha256(enc('abc'))), 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
  assert.equal(hex(ctx.nhmSha256(enc('abcdbcdecdefdefgefghfghighijhijkijkljklmklmnlmnomnopnopq'))), '248d6a61d20638b8e5c026930c3e6039a33ce45964ff2167f6ecedd419db06c1');
});

test('matches node:crypto for every length 0..300 (padding/block boundaries)', () => {
  for (let n = 0; n <= 300; n++) {
    const s = 'x'.repeat(n);
    assert.equal(hex(ctx.nhmSha256(enc(s))), createHash('sha256').update(s).digest('hex'), `length ${n}`);
  }
});

test('PoW message format matches the server (purpose:id:salt:nonce) and leading zero bits', () => {
  const msg = 'claim:6f1c2a52-7d8e-4c3b-9b0a-1d2e3f405162:0123456789abcdef0123456789abcdef:1234567';
  assert.equal(hex(ctx.nhmSha256(enc(msg))), createHash('sha256').update(msg).digest('hex'));
  assert.equal(ctx.nhmLeadingZeroBits(new Uint8Array([0, 0x0f])), 12);
  assert.equal(ctx.nhmLeadingZeroBits(new Uint8Array([0x80])), 0);
  assert.equal(ctx.nhmLeadingZeroBits(new Uint8Array([0, 0, 1])), 23);
});
