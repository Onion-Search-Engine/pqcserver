# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| Latest  | ✅ Yes    |

## Reporting a Vulnerability

**Please do NOT report security vulnerabilities through public GitHub issues.**

If you discover a security vulnerability in PQCServer, please report it responsibly:

**Email:** info@onionsearchengine.com

Include in your report:
- Type of vulnerability (XSS, SQL injection, cryptographic weakness, etc.)
- Location of the vulnerability (file, line number if known)
- Step-by-step reproduction instructions
- Potential impact
- Any suggested fix (optional but appreciated)

### What to expect

- **Acknowledgment:** within 48 hours
- **Initial assessment:** within 5 business days
- **Fix timeline:** depends on severity — critical issues within 7 days
- **Credit:** we will credit you in the release notes if you wish

We follow responsible disclosure — we ask that you give us reasonable
time to fix the issue before public disclosure.

---

## Cryptographic Implementation Notes

PQCServer uses the following cryptographic primitives:

| Algorithm | Standard | Purpose |
|-----------|----------|---------|
| ML-KEM-768 | NIST FIPS-203 | Key encapsulation (default) |
| ML-DSA-65 | NIST FIPS-204 | Digital signatures (default) |
| AES-256-GCM | NIST SP 800-38D | Symmetric encryption |
| SHA-256 / SHA-512 | FIPS 180-4 | Document hashing |

All cryptographic operations are performed **client-side** in the browser.
The server is zero-knowledge — it stores only ciphertext.

Implementation is via the [`pqc`](https://www.npmjs.com/package/pqc) JavaScript
library (pure JS implementation of NIST PQC standards) and the
[Web Crypto API](https://developer.mozilla.org/en-US/docs/Web/API/Web_Crypto_API)
for AES-256-GCM.

---

## Scope

In scope for security reports:
- Authentication and session management
- Cryptographic implementation errors
- Data exposure or privacy leaks
- XSS, CSRF, injection vulnerabilities
- Logic errors in access control
- Denial of service vulnerabilities

Out of scope:
- Social engineering attacks
- Physical security
- Third-party services (MongoDB, Nginx, Cloudflare)
- Self-hosted instances with modified configurations
