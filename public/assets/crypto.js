/**
 * PQCServer — crypto.js
 * Shared post-quantum crypto + chunked file upload helpers.
 * Loaded as ES module in encrypt.html, u/index.html, m/index.html, widget.js
 *
 * Exports:
 *   loadCrypto()                          → loads pqc library
 *   encryptText(pubKeyB64, text)          → { envelope, sharedSecret }
 *   encryptAndUploadFile(sharedSecret, file, onProgress) → file_id
 *   downloadAndDecryptFile(fileUrl, sharedSecret, iv) → { name, type, blob }
 *   toB64(bytes) / fromB64(str)
 *   detectKemScheme(pubKey)
 */

// ── Library loader ─────────────────────────────────────────────────────────────
let _ml_kem = null;
let _ml_dsa = null;
let _loading = null;

export async function loadCrypto() {
    if (_ml_kem) return { ml_kem: _ml_kem, ml_dsa: _ml_dsa };
    if (_loading) return _loading;
    _loading = (async () => {
        try {
            const m = await import('https://esm.sh/pqc@1.0.13');
            _ml_kem = m.ml_kem; _ml_dsa = m.ml_dsa;
        } catch (e) {
            const m = await import('https://esm.run/pqc@1.0.13');
            _ml_kem = m.ml_kem; _ml_dsa = m.ml_dsa;
        }
        return { ml_kem: _ml_kem, ml_dsa: _ml_dsa };
    })();
    return _loading;
}

// ── Base64 helpers ─────────────────────────────────────────────────────────────
export function toB64(bytes) {
    let s = '';
    for (let i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
    return btoa(s);
}

export function fromB64(str) {
    const b = atob(str.trim());
    const a = new Uint8Array(b.length);
    for (let i = 0; i < b.length; i++) a[i] = b.charCodeAt(i);
    return a;
}

// ── Detect KEM scheme from key length ─────────────────────────────────────────
export function detectKemScheme(pubKeyBytes) {
    if (!_ml_kem) throw new Error('Crypto library not loaded');
    if      (pubKeyBytes.length === 800)  return { scheme: _ml_kem.ml_kem512,  variant: 'ML-KEM-512'  };
    else if (pubKeyBytes.length === 1184) return { scheme: _ml_kem.ml_kem768,  variant: 'ML-KEM-768'  };
    else if (pubKeyBytes.length === 1568) return { scheme: _ml_kem.ml_kem1024, variant: 'ML-KEM-1024' };
    else throw new Error(`Unknown ML-KEM public key size: ${pubKeyBytes.length} bytes`);
}

export function detectKemSchemeFromSecret(secKeyBytes) {
    if (!_ml_kem) throw new Error('Crypto library not loaded');
    if      (secKeyBytes.length === 1632) return { scheme: _ml_kem.ml_kem512,  variant: 'ML-KEM-512'  };
    else if (secKeyBytes.length === 2400) return { scheme: _ml_kem.ml_kem768,  variant: 'ML-KEM-768'  };
    else if (secKeyBytes.length === 3168) return { scheme: _ml_kem.ml_kem1024, variant: 'ML-KEM-1024' };
    else throw new Error(`Unknown ML-KEM secret key size: ${secKeyBytes.length} bytes. Make sure you are using your ML-KEM private key.`);
}

// ── AES-256-GCM encrypt ────────────────────────────────────────────────────────
export async function aesEncrypt(sharedSecret, data) {
    const key = await crypto.subtle.importKey(
        'raw', sharedSecret.slice(0, 32), 'AES-GCM', false, ['encrypt']
    );
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, data);
    return { iv: toB64(iv), ct: toB64(new Uint8Array(ct)), ivBytes: iv };
}

// ── AES-256-GCM decrypt ────────────────────────────────────────────────────────
export async function aesDecrypt(sharedSecret, ivB64, ctB64) {
    const key = await crypto.subtle.importKey(
        'raw', sharedSecret.slice(0, 32), 'AES-GCM', false, ['decrypt']
    );
    const iv = fromB64(ivB64);
    const ct = fromB64(ctB64);
    return await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ct);
}

// ── Encrypt text → ML-KEM envelope ───────────────────────────────────────────
export async function encryptText(pubKeyB64, text) {
    const pubKey = fromB64(pubKeyB64);
    const { scheme, variant } = detectKemScheme(pubKey);

    const { cipherText, sharedSecret } = scheme.encapsulate(pubKey);

    const payload = new TextEncoder().encode(JSON.stringify({ text }));
    const { iv, ct } = await aesEncrypt(sharedSecret, payload);

    const envelope = JSON.stringify({
        v:   'pqcserver-1',
        alg: variant + '+AES-256-GCM',
        kem: toB64(cipherText),
        iv,
        ct,
        file: false,
    });

    return { envelope, sharedSecret, cipherText };
}

// ── Encrypt file + upload to GridFS via chunked POST ─────────────────────────
// Returns file_id from server, or null if no file
export async function encryptAndUploadFile(sharedSecret, file, onProgress) {
    if (!file) return null;

    onProgress?.('reading', 0);

    // Read entire file as ArrayBuffer
    const ab = await file.arrayBuffer();
    const fileBytes = new Uint8Array(ab);

    onProgress?.('encrypting', 20);

    // Encrypt file with AES-256-GCM using same shared secret
    // Use a different IV from the text envelope
    const { iv, ct, ivBytes } = await aesEncrypt(sharedSecret, fileBytes);

    onProgress?.('uploading', 40);

    // Convert encrypted bytes back to binary for upload
    const encryptedBytes = fromB64(ct);

    // Upload as multipart form — nginx has no size limit (GridFS handles chunking)
    const formData = new FormData();
    formData.append('file', new Blob([encryptedBytes], { type: 'application/octet-stream' }), 'encrypted_file');
    formData.append('iv', iv);
    formData.append('original_name', file.name);
    formData.append('mime_type', file.type || 'application/octet-stream');

    const xhr = new XMLHttpRequest();

    await new Promise((resolve, reject) => {
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const pct = 40 + Math.round((e.loaded / e.total) * 55);
                onProgress?.('uploading', pct);
            }
        });
        xhr.addEventListener('load', () => {
            if (xhr.status >= 200 && xhr.status < 300) resolve();
            else reject(new Error(`Upload failed: HTTP ${xhr.status}`));
        });
        xhr.addEventListener('error', () => reject(new Error('Network error during upload')));
        xhr.open('POST', '/api/file.php');
        xhr.send(formData);
    });

    const result = JSON.parse(xhr.responseText);
    if (!result.ok) throw new Error(result.error || 'File upload failed');

    onProgress?.('done', 100);
    return result.file_id;
}

// ── Download + decrypt file from GridFS ───────────────────────────────────────
export async function downloadAndDecryptFile(fileUrl, sharedSecret, onProgress) {
    onProgress?.('downloading', 0);

    const response = await fetch(fileUrl);
    if (!response.ok) throw new Error(`File download failed: HTTP ${response.status}`);

    // Read IV from response header
    const ivB64       = response.headers.get('X-PQC-IV')       || '';
    const origNameB64 = response.headers.get('X-PQC-Filename') || '';
    const mimeB64     = response.headers.get('X-PQC-Mime')     || '';

    if (!ivB64) throw new Error('Missing decryption IV in server response');

    const originalName = origNameB64 ? new TextDecoder().decode(fromB64(origNameB64)) : 'decrypted_file';
    const mimeType     = mimeB64     ? new TextDecoder().decode(fromB64(mimeB64))     : 'application/octet-stream';

    onProgress?.('downloading', 30);

    // Stream response body
    const encryptedBytes = new Uint8Array(await response.arrayBuffer());

    onProgress?.('decrypting', 70);

    // Decrypt
    const decryptedBuffer = await aesDecrypt(sharedSecret, ivB64, toB64(encryptedBytes));
    const blob = new Blob([decryptedBuffer], { type: mimeType });

    onProgress?.('done', 100);

    return { name: originalName, type: mimeType, blob };
}

// ── Decrypt text envelope ──────────────────────────────────────────────────────
export async function decryptEnvelope(secKeyB64, ciphertext) {
    const secKey   = fromB64(secKeyB64);
    const envelope = JSON.parse(ciphertext);

    if (envelope.v !== 'pqcserver-1') throw new Error('Unknown envelope format');

    const { scheme } = detectKemSchemeFromSecret(secKey);
    const kemCT       = fromB64(envelope.kem);
    const sharedSecret = scheme.decapsulate(kemCT, secKey);

    const plainBuffer = await aesDecrypt(sharedSecret, envelope.iv, envelope.ct);
    const payload     = JSON.parse(new TextDecoder().decode(plainBuffer));

    return { text: payload.text || '', sharedSecret };
}

// ═══════════════════════════════════════════════════════════════════════════
// ML-DSA — Digital Signature functions (used by Notary module)
// ═══════════════════════════════════════════════════════════════════════════

// ── Detect DSA scheme from key length ─────────────────────────────────────
export function detectDsaScheme(keyBytes) {
    if (!_ml_dsa) throw new Error('Crypto library not loaded');
    // Public key lengths
    if      (keyBytes.length === 1312) return { scheme: _ml_dsa.ml_dsa44, variant: 'ML-DSA-44' };
    else if (keyBytes.length === 1952) return { scheme: _ml_dsa.ml_dsa65, variant: 'ML-DSA-65' };
    else if (keyBytes.length === 2592) return { scheme: _ml_dsa.ml_dsa87, variant: 'ML-DSA-87' };
    // Secret key lengths
    else if (keyBytes.length === 2560) return { scheme: _ml_dsa.ml_dsa44, variant: 'ML-DSA-44' };
    else if (keyBytes.length === 4032) return { scheme: _ml_dsa.ml_dsa65, variant: 'ML-DSA-65' };
    else if (keyBytes.length === 4896) return { scheme: _ml_dsa.ml_dsa87, variant: 'ML-DSA-87' };
    else throw new Error(`Unknown ML-DSA key size: ${keyBytes.length} bytes`);
}

// ── SHA-256 hash of arbitrary bytes (returns hex string) ──────────────────
export async function sha256hex(bytes) {
    const hashBuf = await crypto.subtle.digest('SHA-256', bytes);
    return Array.from(new Uint8Array(hashBuf))
        .map(b => b.toString(16).padStart(2, '0')).join('');
}

// ── SHA-512 hash of arbitrary bytes (returns hex string) ──────────────────
export async function sha512hex(bytes) {
    const hashBuf = await crypto.subtle.digest('SHA-512', bytes);
    return Array.from(new Uint8Array(hashBuf))
        .map(b => b.toString(16).padStart(2, '0')).join('');
}

/**
 * Hash a File object locally (never leaves browser).
 * Returns both SHA-256 and SHA-512 hex strings.
 *
 * @param {File} file
 * @returns {Promise<{sha256: string, sha512: string, bytes: Uint8Array}>}
 */
export async function hashFile(file) {
    const ab    = await file.arrayBuffer();
    const bytes = new Uint8Array(ab);
    const [sha256, sha512] = await Promise.all([
        sha256hex(bytes),
        sha512hex(bytes),
    ]);
    return { sha256, sha512, bytes };
}

/**
 * Sign a document hash with ML-DSA secret key.
 * The payload signed is a canonical JSON string containing
 * the document hashes, filename, size, and timestamp.
 *
 * @param {string} secKeyDsaB64 - ML-DSA secret key (base64)
 * @param {object} docInfo      - { sha256, sha512, filename, size_bytes, mime_type }
 * @returns {Promise<{signature: string, signedPayload: string, algorithm: string}>}
 */
export async function signDocument(secKeyDsaB64, docInfo) {
    const { ml_dsa: dsa } = await loadCrypto();
    if (!dsa) throw new Error('ML-DSA library not loaded');

    const secKey = fromB64(secKeyDsaB64);
    const { scheme, variant } = detectDsaScheme(secKey);

    // Canonical payload — what is actually signed
    const signedPayload = JSON.stringify({
        v:         'pqcnotary-1',
        sha256:    docInfo.sha256,
        sha512:    docInfo.sha512,
        filename:  docInfo.filename,
        size_bytes:docInfo.size_bytes,
        mime_type: docInfo.mime_type,
        signed_at: new Date().toISOString(),
    });

    const msgBytes = new TextEncoder().encode(signedPayload);
    const sigBytes = scheme.sign(secKey, msgBytes);

    return {
        signature:    toB64(sigBytes),
        signedPayload,
        algorithm:    variant,
    };
}

/**
 * Verify a document signature using ML-DSA public key.
 *
 * @param {string} pubKeyDsaB64  - ML-DSA public key (base64)
 * @param {string} signedPayload - The canonical JSON string that was signed
 * @param {string} signatureB64  - The signature (base64)
 * @returns {Promise<boolean>}
 */
export async function verifyDocumentSignature(pubKeyDsaB64, signedPayload, signatureB64) {
    const { ml_dsa: dsa } = await loadCrypto();
    if (!dsa) throw new Error('ML-DSA library not loaded');

    const pubKey = fromB64(pubKeyDsaB64);
    const { scheme } = detectDsaScheme(pubKey);

    const msgBytes = new TextEncoder().encode(signedPayload);
    const sigBytes = fromB64(signatureB64);

    return scheme.verify(pubKey, msgBytes, sigBytes);
}

/**
 * Verify a document against a Notary Receipt.
 * Checks: user signature + server signature + document hashes match.
 *
 * @param {File}   file    - The document to verify
 * @param {object} receipt - The full Notary Receipt JSON object
 * @returns {Promise<{valid: boolean, checks: object}>}
 */
export async function verifyReceipt(file, receipt) {
    const checks = {
        hash_match:       false,
        user_signature:   false,
        server_signature: false,
    };

    try {
        // 1. Hash the file
        const { sha256, sha512 } = await hashFile(file);

        // 2. Check hashes match
        checks.hash_match = (
            sha256 === receipt.document.hash_sha256 &&
            sha512 === receipt.document.hash_sha512
        );

        if (!checks.hash_match) {
            return { valid: false, checks };
        }

        // 3. Verify user signature
        checks.user_signature = await verifyDocumentSignature(
            receipt.signer.public_key_dsa,
            receipt.signature.signed_payload,
            receipt.signature.value
        );

        // 4. Verify server timestamp signature
        checks.server_signature = await verifyDocumentSignature(
            receipt.timestamp.server_public_key,
            receipt.timestamp.signed_payload,
            receipt.timestamp.server_signature
        );

        const valid = checks.hash_match && checks.user_signature && checks.server_signature;
        return { valid, checks };

    } catch (e) {
        return { valid: false, checks, error: e.message };
    }
}
