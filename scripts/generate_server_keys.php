#!/usr/bin/env php
<?php
/**
 * PQCServer — Generate Server ML-DSA Signing Keys
 *
 * Run once during setup:
 *   php scripts/generate_server_keys.php
 *
 * Output: base64 keypair to paste into config/server_keys.php
 * or set as environment variables PQCS_SERVER_DSA_SECRET / PQCS_SERVER_DSA_PUBLIC
 *
 * Uses the same pqc JS library via a small Node.js wrapper,
 * OR generates keys using openssl + custom encoding.
 *
 * Since ML-DSA is not yet in PHP's openssl, we call the JS library via Node.js.
 * If Node.js is not available, instructions are printed to use the web UI.
 */

echo "PQCServer — Server Key Generator\n";
echo "==================================\n\n";

// Check if Node.js is available
exec('node --version 2>&1', $output, $returnCode);
if ($returnCode !== 0) {
    echo "Node.js not found. Please generate server keys manually:\n\n";
    echo "1. Open https://pqcserver.com/keygen.html in your browser\n";
    echo "2. Select ML-DSA-65 and generate a keypair\n";
    echo "3. Copy the secret key and public key (base64)\n";
    echo "4. Set environment variables:\n";
    echo "   export PQCS_SERVER_DSA_SECRET=\"<paste secret key>\"\n";
    echo "   export PQCS_SERVER_DSA_PUBLIC=\"<paste public key>\"\n";
    echo "5. Or paste directly into config/server_keys.php\n";
    exit(1);
}

// Write temporary Node.js keygen script
$nodeScript = sys_get_temp_dir() . '/pqcs_keygen.mjs';
file_put_contents($nodeScript, <<<'JS'
import { ml_dsa } from 'https://esm.sh/pqc@1.0.13';

function toB64(bytes) {
  let s = '';
  for (let i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
  return Buffer.from(s, 'binary').toString('base64');
}

// ML-DSA-65 — 192-bit security
const keys = ml_dsa.ml_dsa65.keygen();
console.log('PUBLIC_KEY=' + toB64(keys.publicKey));
console.log('SECRET_KEY=' + toB64(keys.secretKey));
JS);

// Try running with node (ESM)
exec("node --input-type=module < $nodeScript 2>&1", $lines, $rc);

if ($rc === 0) {
    $pub = $sec = '';
    foreach ($lines as $line) {
        if (str_starts_with($line, 'PUBLIC_KEY=')) $pub = substr($line, 11);
        if (str_starts_with($line, 'SECRET_KEY=')) $sec = substr($line, 11);
    }
    if ($pub && $sec) {
        echo "✓ ML-DSA-65 server keypair generated\n\n";
        echo "Add to your environment:\n";
        echo "  export PQCS_SERVER_DSA_PUBLIC=\"$pub\"\n";
        echo "  export PQCS_SERVER_DSA_SECRET=\"$sec\"\n\n";
        echo "Or paste into config/server_keys.php:\n";
        echo "  SERVER_DSA_PUBLIC_FALLBACK = \"$pub\"\n";
        echo "  SERVER_DSA_SECRET_FALLBACK = \"$sec\"\n\n";
        echo "⚠️  Keep the SECRET key private. Back it up securely.\n";
        echo "    The PUBLIC key can be shared — publish it on your site\n";
        echo "    so users can independently verify server signatures.\n";
        unlink($nodeScript);
        exit(0);
    }
}

unlink($nodeScript);
echo "Could not auto-generate keys. Please use the web UI method above.\n";
exit(1);
