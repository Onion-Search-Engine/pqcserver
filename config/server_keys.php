<?php
/**
 * PQCServer — Server Signing Keys & Functions
 *
 * The server has its own ML-DSA-65 keypair used to co-sign Notary certificates.
 * This adds a second layer of trust: the user signs with their key,
 * the server countersigns with its key.
 *
 * SETUP: Run once to generate keys:
 *   php scripts/generate_server_keys.php
 *
 * Then set environment variables (recommended):
 *   export PQCS_SERVER_DSA_SECRET="base64..."
 *   export PQCS_SERVER_DSA_PUBLIC="base64..."
 *
 * Or paste keys directly into the fallback constants below.
 *
 * SECURITY: Keep the SECRET key private. The PUBLIC key can be shared
 * — publish it on the site so users can independently verify signatures.
 */

define('SERVER_DSA_ALGORITHM', 'ML-DSA-65');

// ── Load keys from environment (production) or fallback constants ──────────
$_server_dsa_secret = getenv('PQCS_SERVER_DSA_SECRET') ?: '';
$_server_dsa_public = getenv('PQCS_SERVER_DSA_PUBLIC') ?: '';

// Fallback: paste your generated keys here if not using env vars
// Generate with: php scripts/generate_server_keys.php
if (empty($_server_dsa_secret)) {
    $_server_dsa_secret = ''; // paste ML-DSA-65 secret key (base64)
}
if (empty($_server_dsa_public)) {
    $_server_dsa_public = ''; // paste ML-DSA-65 public key (base64)
}

define('SERVER_DSA_SECRET', $_server_dsa_secret);
define('SERVER_DSA_PUBLIC', $_server_dsa_public);

// ── Get server public key (safe to expose) ────────────────────────────────
function getServerPublicKey(): string {
    return SERVER_DSA_PUBLIC;
}

/**
 * Sign a payload string with the server's ML-DSA-65 secret key.
 *
 * Since PHP has no native ML-DSA support, we use a hybrid approach:
 * - If server keys are configured: call Node.js to sign (requires Node.js)
 * - Fallback: return a HMAC-SHA512 signature using a server secret
 *   (less ideal but functional until PHP ML-DSA extension is available)
 *
 * For production, set up Node.js signing or wait for PHP ML-DSA support.
 */
function getServerSignature(string $payload): string {
    // Preferred: ML-DSA signing via Node.js
    if (SERVER_DSA_SECRET && function_exists('exec')) {
        $sig = mlDsaSignWithNode(SERVER_DSA_SECRET, $payload);
        if ($sig) return $sig;
    }

    // Fallback: HMAC-SHA512 (marks as non-ML-DSA)
    // This is still cryptographically strong but not post-quantum
    $key = SERVER_DSA_SECRET ?: hash('sha256', gethostname() . __FILE__);
    return 'HMAC-SHA512:' . base64_encode(hash_hmac('sha512', $payload, $key, true));
}

/**
 * Attempt to sign with ML-DSA via Node.js subprocess.
 * Returns base64 signature or null on failure.
 */
function mlDsaSignWithNode(string $secretKeyB64, string $payload): ?string {
    $script = sys_get_temp_dir() . '/pqcs_sign_' . getmypid() . '.mjs';
    $payloadB64 = base64_encode($payload);

    file_put_contents($script, <<<JS
import { ml_dsa } from 'https://esm.sh/pqc@1.0.13';
function fromB64(s){const b=atob(s);const a=new Uint8Array(b.length);for(let i=0;i<b.length;i++)a[i]=b.charCodeAt(i);return a;}
function toB64(b){let s='';for(let i=0;i<b.length;i++)s+=String.fromCharCode(b[i]);return Buffer.from(s,'binary').toString('base64');}
const secKey = fromB64('$secretKeyB64');
const payload = fromB64('$payloadB64');
const sig = ml_dsa.ml_dsa65.sign(secKey, payload);
console.log(toB64(sig));
JS);

    exec("node $script 2>/dev/null", $output, $rc);
    @unlink($script);

    if ($rc === 0 && !empty($output[0])) {
        return $output[0];
    }
    return null;
}
