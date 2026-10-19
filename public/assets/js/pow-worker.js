/**
 * @module assets/js/pow-worker
 * @description Web Worker that solves a server-issued proof-of-work challenge off the main thread:
 *     finds a decimal nonce such that SHA-256("<purpose>:<id>:<salt>:<nonce>") has >= difficulty
 *     leading zero bits. Posts {progress} periodically and {nonce} when solved.
 * @dependencies lib/sha256.js (importScripts)
 */
/* eslint-env worker */
importScripts('/assets/js/lib/sha256.js');

self.onmessage = function (event) {
  var c = event.data;
  var prefix = new TextEncoder().encode(c.purpose + ':' + c.id + ':' + c.salt + ':');
  var msg = new Uint8Array(prefix.length + 16);
  msg.set(prefix);
  var expected = Math.pow(2, c.difficulty);
  for (var nonce = 0; nonce < 1e15; nonce++) {
    var digits = String(nonce);
    for (var i = 0; i < digits.length; i++) {
      msg[prefix.length + i] = digits.charCodeAt(i);
    }
    var digest = self.nhmSha256(msg.subarray(0, prefix.length + digits.length));
    if (self.nhmLeadingZeroBits(digest) >= c.difficulty) {
      self.postMessage({ nonce: digits, tries: nonce + 1 });
      return;
    }
    if (nonce % 4096 === 0) {
      self.postMessage({ progress: Math.min(0.97, nonce / (expected * 1.5)) });
    }
  }
};
