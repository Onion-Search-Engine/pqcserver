/**
 * PQCServer — gridfs.js
 * Client-side helpers for chunked file upload to GridFS and streaming download.
 *
 * Flow (upload):
 *   1. Encrypt file in browser (AES-256-GCM)
 *   2. Split encrypted binary into 3MB chunks
 *   3. POST each chunk as base64 to /api/file_upload.php
 *   4. Server assembles chunks into GridFS
 *   5. Returns file_id to include in message envelope
 *
 * Flow (download):
 *   1. GET /api/file_download.php?id=FILE_ID (streaming)
 *   2. Receive encrypted binary
 *   3. Decrypt in browser (AES-256-GCM)
 *   4. Trigger browser download of plaintext file
 */

window.PQCGFS = (function () {
  'use strict';

  const CHUNK_SIZE = 3 * 1024 * 1024; // 3MB per chunk (well under Cloudflare 100MB limit)

  // ── Generate upload session ID ─────────────────────────────────────────────
  function genUploadId() {
    return Array.from(crypto.getRandomValues(new Uint8Array(16)))
      .map(b => b.toString(16).padStart(2, '0'))
      .join('');
  }

  // ── Base64 helpers ─────────────────────────────────────────────────────────
  function toB64(bytes) {
    let binary = '';
    const len = bytes.byteLength;
    for (let i = 0; i < len; i++) binary += String.fromCharCode(bytes[i]);
    return btoa(binary);
  }

  function fromB64(b64) {
    const binary = atob(b64);
    const bytes  = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return bytes;
  }

  // ── AES-256-GCM encrypt ────────────────────────────────────────────────────
  async function aesEncryptBytes(sharedSecret, plainBytes) {
    const key = await crypto.subtle.importKey(
      'raw', sharedSecret.slice(0, 32), 'AES-GCM', false, ['encrypt']
    );
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, plainBytes);
    // Prepend IV to ciphertext so we can recover it during decryption
    const result = new Uint8Array(12 + ct.byteLength);
    result.set(iv, 0);
    result.set(new Uint8Array(ct), 12);
    return result;
  }

  // ── AES-256-GCM decrypt ────────────────────────────────────────────────────
  async function aesDecryptBytes(sharedSecret, encryptedBytes) {
    const iv = encryptedBytes.slice(0, 12);
    const ct = encryptedBytes.slice(12);
    const key = await crypto.subtle.importKey(
      'raw', sharedSecret.slice(0, 32), 'AES-GCM', false, ['decrypt']
    );
    const pt = await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ct);
    return new Uint8Array(pt);
  }

  /**
   * Upload a file using GridFS chunked upload.
   *
   * @param {File}       file         - The File object from input[type=file]
   * @param {Uint8Array} sharedSecret - ML-KEM shared secret (32 bytes used for AES)
   * @param {Function}   onProgress   - (percent, statusText) => void
   * @returns {Promise<string>}       - file_id (MongoDB ObjectId string)
   */
  async function uploadFile(file, sharedSecret, onProgress) {
    const uploadId    = genUploadId();
    const fileBuffer  = await file.arrayBuffer();
    const plainBytes  = new Uint8Array(fileBuffer);

    onProgress && onProgress(0, `Encrypting ${file.name}…`);

    // Encrypt entire file as one AES-GCM operation
    // (IV prepended to output — see aesEncryptBytes)
    const encryptedBytes = await aesEncryptBytes(sharedSecret, plainBytes);

    // Split into chunks
    const totalChunks = Math.ceil(encryptedBytes.length / CHUNK_SIZE);
    onProgress && onProgress(5, `Uploading ${file.name} (${totalChunks} chunk${totalChunks>1?'s':''})…`);

    let fileId = null;

    for (let i = 0; i < totalChunks; i++) {
      const start     = i * CHUNK_SIZE;
      const end       = Math.min(start + CHUNK_SIZE, encryptedBytes.length);
      const chunkData = encryptedBytes.slice(start, end);

      const payload = {
        upload_id:    uploadId,
        chunk_index:  i,
        total_chunks: totalChunks,
        chunk_data:   toB64(chunkData),
      };

      // Include file metadata on first chunk
      if (i === 0) {
        payload.filename   = file.name;
        payload.mime_type  = file.type || 'application/octet-stream';
        payload.total_size = file.size;
      }

      const response = await fetch('/api/file_upload.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(payload),
      });

      const result = await response.json();

      if (!result.ok) {
        throw new Error('Upload failed on chunk ' + i + ': ' + (result.error || 'unknown error'));
      }

      // Update progress (5% to 95% during upload)
      const pct = 5 + Math.round(((i + 1) / totalChunks) * 90);
      onProgress && onProgress(pct, `Uploading… ${pct}%`);

      if (result.done) {
        fileId = result.file_id;
      }
    }

    if (!fileId) {
      throw new Error('Upload complete but no file_id returned');
    }

    onProgress && onProgress(100, `✓ ${file.name} uploaded`);
    return fileId;
  }

  /**
   * Download and decrypt a file from GridFS.
   *
   * @param {string}     fileId       - MongoDB ObjectId string
   * @param {Uint8Array} sharedSecret - ML-KEM shared secret
   * @param {Function}   onProgress   - (percent, statusText) => void
   * @returns {Promise<{blob: Blob, filename: string, mimeType: string}>}
   */
  async function downloadFile(fileId, sharedSecret, onProgress) {
    onProgress && onProgress(0, 'Downloading encrypted file…');

    const response = await fetch(`/api/file_download.php?id=${encodeURIComponent(fileId)}`);

    if (!response.ok) {
      const err = await response.json().catch(() => ({ error: 'Download failed' }));
      throw new Error(err.error || `HTTP ${response.status}`);
    }

    const filename = decodeURIComponent(
      response.headers.get('X-File-Name') || 'downloaded_file'
    );
    const mimeType = response.headers.get('X-Mime-Type') || 'application/octet-stream';
    const totalSize = parseInt(response.headers.get('Content-Length') || '0');

    // Read stream with progress
    const reader = response.body.getReader();
    const chunks = [];
    let received = 0;

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      chunks.push(value);
      received += value.length;
      if (totalSize > 0) {
        const pct = Math.round((received / totalSize) * 70); // 0-70%
        onProgress && onProgress(pct, `Downloading… ${pct}%`);
      }
    }

    onProgress && onProgress(75, 'Decrypting file…');

    // Reassemble encrypted bytes
    const encryptedBytes = new Uint8Array(received);
    let offset = 0;
    for (const chunk of chunks) {
      encryptedBytes.set(chunk, offset);
      offset += chunk.length;
    }

    // Decrypt
    const plainBytes = await aesDecryptBytes(sharedSecret, encryptedBytes);

    onProgress && onProgress(100, `✓ ${filename} ready`);

    return {
      blob:     new Blob([plainBytes], { type: mimeType }),
      filename: filename,
      mimeType: mimeType,
    };
  }

  /**
   * Trigger browser download of a Blob.
   */
  function saveBlobAs(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a   = document.createElement('a');
    a.href     = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(() => URL.revokeObjectURL(url), 10000);
  }

  /**
   * Format file size for display.
   */
  function formatSize(bytes) {
    if (bytes < 1024)        return bytes + ' B';
    if (bytes < 1048576)     return (bytes / 1024).toFixed(1) + ' KB';
    if (bytes < 1073741824)  return (bytes / 1048576).toFixed(1) + ' MB';
    return (bytes / 1073741824).toFixed(2) + ' GB';
  }

  // Public API
  return { uploadFile, downloadFile, saveBlobAs, formatSize, toB64, fromB64 };

})();
