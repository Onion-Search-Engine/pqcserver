# Contributing to PQCServer

Thank you for your interest in contributing to PQCServer!

PQCServer is an open-source post-quantum cryptography platform built on
NIST FIPS-203 (ML-KEM) and FIPS-204 (ML-DSA) standards. It is part of
the [OnionSearchEngine LLC](https://onionsearchengine.com) privacy ecosystem,
alongside [OnionMail](https://onionmail.org) and [OnionDrive](https://oniondrive.org).

---

## Ways to Contribute

### 🐛 Bug Reports
Open an issue with:
- Description of the bug
- Steps to reproduce
- Expected vs actual behavior
- Browser/OS if frontend issue
- PHP/MongoDB version if backend issue

### 💡 Feature Requests
Open an issue with the `enhancement` label. Describe:
- The use case
- Proposed solution
- Any security implications

### 🔒 Security Vulnerabilities
**Do NOT open a public issue for security vulnerabilities.**
Email: info@onionsearchengine.com

We follow responsible disclosure — we'll respond within 48 hours
and coordinate a fix before public disclosure.

### 🔧 Pull Requests
1. Fork the repository
2. Create a branch: `git checkout -b feature/your-feature`
3. Make your changes
4. Test thoroughly
5. Submit a pull request with a clear description

---

## Development Setup

```bash
# Clone
git clone https://github.com/onionsearchengine/pqcserver.git
cd pqcserver

# Install PHP dependencies
composer install

# Copy environment file
cp .env.example .env
# Edit .env with your MongoDB URI and other settings

# Generate server signing keys
php scripts/generate_server_keys.php

# Set up MongoDB indexes
mongosh pqcserver mongo_indexes.js

# Configure Nginx
cp nginx.conf /etc/nginx/sites-available/pqcserver
# Edit domain name if needed
nginx -t && systemctl reload nginx
```

---

## Code Standards

### PHP
- PSR-12 code style
- All API endpoints must use `requireAuth()` for protected routes
- Never log or store plaintext content — server is zero-knowledge by design
- Rate limit all public endpoints

### JavaScript
- ES modules for crypto operations
- All cryptographic operations client-side only
- Never send private keys to the server under any circumstances

### Security
- Any change to cryptographic code requires explicit review
- ML-KEM and ML-DSA implementations via the `pqc` npm library — do not implement custom crypto
- All user-supplied data must be validated and sanitized

---

## Architecture Principles

PQCServer is built on three core principles that must be preserved in all contributions:

**1. Zero Knowledge**
The server must never see plaintext content, private keys, or decrypted data.
All cryptographic operations happen in the browser.

**2. Standards-based**
Use only NIST-standardized algorithms: ML-KEM (FIPS-203), ML-DSA (FIPS-204).
No proprietary or experimental cryptography.

**3. Interoperability**
Output formats (JSON envelopes, Notary Receipts) must remain stable.
Breaking changes require a version bump in the envelope format.

---

## Project Structure

```
pqcserver/
├── api/           PHP API endpoints
├── config/        Configuration (db.php, server_keys.php)
├── public/        HTML pages + assets
│   ├── assets/    JS modules (crypto.js, auth.js, widget.js, gridfs.js)
│   └── features/  SEO landing pages
└── scripts/       Setup and maintenance scripts
```

---

## License

By contributing, you agree that your contributions will be licensed
under the GNU Affero General Public License v3.0.

See [LICENSE](LICENSE) for details.
