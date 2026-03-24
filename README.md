<p align="center">
  <img src="https://pqcserver.com/assets/logo.png" alt="PQCServer" width="120" />
</p>

<h1 align="center">PQCServer</h1>

<p align="center">
  <strong>Post-Quantum Encrypted Messaging, File Vault & Document Notary</strong><br>
  Built on NIST FIPS-203 (ML-KEM) and FIPS-204 (ML-DSA) — the 2024 post-quantum standards
</p>

<p align="center">
  <a href="https://github.com/onionsearchengine/pqcserver/blob/main/LICENSE">
    <img src="https://img.shields.io/badge/license-AGPL--v3-blue.svg" alt="License: AGPL v3">
  </a>
  <a href="https://csrc.nist.gov/pubs/fips/203/final">
    <img src="https://img.shields.io/badge/ML--KEM-NIST%20FIPS--203-green.svg" alt="ML-KEM FIPS-203">
  </a>
  <a href="https://csrc.nist.gov/pubs/fips/204/final">
    <img src="https://img.shields.io/badge/ML--DSA-NIST%20FIPS--204-green.svg" alt="ML-DSA FIPS-204">
  </a>
  <img src="https://img.shields.io/badge/zero--knowledge-server-orange.svg" alt="Zero Knowledge">
  <img src="https://img.shields.io/badge/PHP-8.3-purple.svg" alt="PHP 8.3">
  <img src="https://img.shields.io/badge/MongoDB-7.0-green.svg" alt="MongoDB 7.0">
</p>

<p align="center">
  <a href="https://pqcserver.com">Live Service</a> ·
  <a href="https://pqctoolkit.com">PQCToolkit</a> ·
  <a href="https://postquantum.tools">PostQuantum.Tools</a> ·
  <a href="https://onionmail.org">OnionMail</a> ·
  <a href="https://oniondrive.org">OnionDrive</a>
</p>

---

## What is PQCServer?

PQCServer is a **zero-knowledge post-quantum cryptography platform** that lets anyone send end-to-end encrypted messages, store files in a permanent encrypted vault, and notarize documents — all using NIST-standardized post-quantum algorithms.

**The server never sees your data.** All cryptographic operations happen in the browser. The server stores only ciphertext it cannot decrypt.

### Part of the OnionSearchEngine LLC Privacy Ecosystem

```
onionsearchengine.com   →  Privacy-focused search engine
onionmail.org           →  Anonymous encrypted email
oniondrive.org          →  Encrypted cloud storage
pqcserver.com           →  Post-quantum encryption platform  ← this repo
pqctoolkit.com          →  Standalone browser crypto tool
postquantum.tools       →  Educational hub
```

---

## Features

### 🔒 Encrypted Messaging
- End-to-end encryption with **ML-KEM + AES-256-GCM**
- Share via shortlink (`pqcserver.com/m/xxxxxxxx`) — paste anywhere
- Works in any email, chat, SMS — no app required for recipients
- **Burn after read** and configurable TTL
- **Embeddable widget** — one line of code for any website

### 📁 Zero-Knowledge File Vault
- Permanent encrypted file storage with **MongoDB GridFS**
- **No file size limits** — chunked upload (3MB/chunk)
- Files encrypted in browser before upload
- Generate shareable shortlinks per file
- Share with specific usernames or via public link

### 🔏 Document Notary
- Hash document locally (SHA-256 + SHA-512) — file never leaves browser
- Sign with **ML-DSA** private key
- Server co-signs with its own ML-DSA key + timestamp
- Publicly verifiable **Notary Receipt** (JSON)
- Permanent URL: `pqcserver.com/verify/NTR-xxxxxxxxxx`

### 🔑 Key Management
- Generate **ML-KEM** (encryption) + **ML-DSA** (signature) keypairs in browser
- Public keys stored on server — shareable profile `pqcserver.com/u/username`
- Private keys downloaded locally — **never transmitted**
- Auto-detect key variant from key length

---

## Zero-Knowledge Architecture

```
Browser (sender)           PQCServer              Browser (recipient)
      │                        │                          │
      │  ML-KEM encapsulate    │                          │
      │  AES-256-GCM encrypt   │                          │
      ├──── ciphertext ───────►│  MongoDB stores          │
      │◄─── shortlink ─────────│  encrypted envelope      │
      │                        │                          │
      │                        │◄── GET /m/:id ───────────│
      │                        ├──── ciphertext ─────────►│
      │                        │     ML-KEM decapsulate   │
      │                        │     AES-256-GCM decrypt  │
      │                        │     in browser      ◄────│
      │                        │  delete if burn=true     │
```

**The server stores:** encrypted JSON envelope, file chunks (GridFS), notary receipts  
**The server never sees:** plaintext, private keys, decrypted content

---

## Technology Stack

| Layer | Technology |
|-------|-----------|
| Web server | Nginx |
| Backend | PHP 8.3 |
| Database | MongoDB 7.0 + GridFS |
| Crypto (browser) | [pqc](https://www.npmjs.com/package/pqc) — pure JS implementation of ML-KEM + ML-DSA |
| Symmetric encryption | AES-256-GCM (Web Crypto API) |
| File chunking | MongoDB GridFS (3MB chunks) |
| CDN/SSL | Cloudflare Flexible |
| Hashing | SHA-256 + SHA-512 (Web Crypto API) |

---

## Algorithms

| Standard | Algorithm | Use |
|----------|-----------|-----|
| NIST FIPS-203 | **ML-KEM** (Kyber) 512/768/1024 | Key encapsulation, encryption |
| NIST FIPS-204 | **ML-DSA** (Dilithium) 44/65/87 | Digital signatures |
| NIST FIPS-205 | **SLH-DSA** (SPHINCS+) | Alternative signatures |
| — | **AES-256-GCM** | Symmetric encryption |

---

## Project Structure

```
pqcserver/
├── config/
│   ├── db.php                  MongoDB connection + session management
│   └── server_keys.php         Server ML-DSA signing keys (env vars)
│
├── api/
│   ├── register.php            Create account
│   ├── login.php               Login → session cookie
│   ├── logout.php              Destroy session
│   ├── session.php             Current user info
│   ├── update_keys.php         Save public keys to profile
│   ├── pubkey.php              Get public key by username
│   ├── store.php               Save encrypted message → shortlink
│   ├── fetch.php               Retrieve + burn ciphertext
│   ├── file_upload.php         Chunked GridFS upload
│   ├── file_download.php       Stream encrypted file from GridFS
│   ├── vault_upload.php        Register file in permanent vault
│   ├── vault_list.php          List vault files
│   ├── vault_delete.php        Delete vault file
│   ├── vault_share.php         Generate shortlink / share with user
│   ├── notary_sign.php         Sign document + server timestamp
│   ├── notary_verify.php       Public receipt verification
│   └── notary_list.php         User's notarized documents
│
├── public/
│   ├── index.html              Landing page
│   ├── register.html           Create account
│   ├── login.html              Login
│   ├── dashboard.html          User dashboard
│   ├── keygen.html             Generate keypairs + register profile
│   ├── encrypt.html            Encrypt message/file → shortlink
│   ├── vault.html              File vault dashboard
│   ├── vault_upload.html       Upload file to vault
│   ├── notary.html             Notary service landing
│   ├── sign.html               Sign document
│   ├── verify.html             Verify receipt
│   ├── about.html              About + architecture
│   ├── m/index.html            Decrypt page (/m/:id)
│   ├── u/index.html            Public profile (/u/:username)
│   ├── verify/index.html       Receipt page (/verify/NTR-xxx)
│   ├── features/
│   │   ├── messaging.html      Messaging feature landing
│   │   └── vault.html          Vault feature landing
│   ├── sitemap.xml
│   ├── robots.txt
│   └── assets/
│       ├── style.css           Global dark theme
│       ├── auth.js             Client session management
│       ├── crypto.js           ML-KEM + ML-DSA + GridFS crypto module
│       ├── gridfs.js           Chunked upload/download helpers
│       ├── seo.js              Open Graph + Schema.org injection
│       └── widget.js           Embeddable encrypt widget
│
├── scripts/
│   ├── cleanup.py              Daily cleanup + stats (cron)
│   └── generate_server_keys.php  One-time server key generation
│
├── mongo_indexes.js            MongoDB indexes setup
├── nginx.conf                  Nginx virtual host config
├── composer.json               PHP dependencies
├── install.sh                  Automated install script
├── INSTALL.md                  Manual installation guide
├── SECURITY.md                 Security policy + vulnerability reporting
└── CONTRIBUTING.md             Contribution guidelines
```

---

## Quick Install

**Automated (recommended):**
```bash
git clone https://github.com/onionsearchengine/pqcserver.git
cd pqcserver
chmod +x install.sh
sudo bash install.sh
```

**Requirements:** Ubuntu 22.04/24.04 · PHP 8.3 · MongoDB 7.0 · Nginx · Composer

See [INSTALL.md](INSTALL.md) for the complete manual installation guide.

---

## Widget Integration

Add post-quantum encryption to any website with one line:

```html
<!-- Known recipient -->
<script src="https://pqcserver.com/assets/widget.js"
        data-recipient="alice_smith"></script>

<!-- Unknown recipient — user picks -->
<script src="https://pqcserver.com/assets/widget.js"></script>

<!-- Direct public key -->
<script src="https://pqcserver.com/assets/widget.js"
        data-pubkey="BASE64_ML_KEM_PUBLIC_KEY"></script>
```

The widget opens an inline modal with a 3-step flow: recipient → message → shortlink.  
No redirect, no new tab, no installation required for the recipient.

---

## API Reference

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/api/register.php` | — | Create account |
| POST | `/api/login.php` | — | Login |
| GET | `/api/session.php` | — | Session info |
| POST | `/api/update_keys.php` | ✓ | Save public keys |
| GET | `/api/pubkey.php?u=user` | — | Get public key |
| POST | `/api/store.php` | — | Save ciphertext → shortlink |
| GET | `/api/fetch.php?id=xxx` | — | Retrieve + burn message |
| POST | `/api/file_upload.php` | — | Upload encrypted chunk |
| GET | `/api/file_download.php?id=xxx` | — | Stream encrypted file |
| POST | `/api/vault_upload.php` | ✓ | Add file to vault |
| GET | `/api/vault_list.php` | ✓ | List vault files |
| DELETE | `/api/vault_delete.php` | ✓ | Delete vault file |
| POST | `/api/vault_share.php` | ✓ | Share vault file |
| POST | `/api/notary_sign.php` | ✓ | Notarize document |
| GET | `/api/notary_verify.php?id=xxx` | — | Verify receipt |

---

## Server Key Setup

After installation, generate the server's ML-DSA signing keys (required for Notary):

```bash
php scripts/generate_server_keys.php
```

Set the output as environment variables:
```bash
export PQCS_SERVER_DSA_SECRET="base64..."
export PQCS_SERVER_DSA_PUBLIC="base64..."
```

---

## Security

All cryptographic operations happen **client-side** in the browser.  
The server only stores ciphertext it cannot decrypt.

To report a vulnerability, see [SECURITY.md](SECURITY.md).

---

## Contributing

We welcome contributions! See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

Areas where help is especially welcome:
- Security audit of the cryptographic implementation
- PHP / MongoDB performance improvements
- Additional language support (i18n)
- Mobile-friendly UI improvements
- Integration examples (onionmail, other privacy tools)

---

## License

**GNU Affero General Public License v3.0 (AGPL-3.0)**

Copyright © 2026 [OnionSearchEngine LLC](https://onionsearchengine.com)

This program is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

See [LICENSE](LICENSE) for full text.

---

## Related Projects

| Project | Description |
|---------|-------------|
| [OnionMail](https://onionmail.org) | Anonymous encrypted email |
| [OnionDrive](https://oniondrive.org) | Encrypted cloud storage |
| [PQCToolkit](https://pqctoolkit.com) | Standalone browser PQC tool |
| [PostQuantum.Tools](https://postquantum.tools) | Educational hub |
| [OnionSearchEngine](https://onionsearchengine.com) | Privacy search engine |

---

<p align="center">
  Made with ❤️ by <a href="https://onionsearchengine.com">OnionSearchEngine LLC</a>
  <br>
  <a href="https://pqcserver.com">pqcserver.com</a> ·
  <a href="https://postquantum.tools">postquantum.tools</a>
</p>
