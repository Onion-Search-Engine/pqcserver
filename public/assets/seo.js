/**
 * PQCServer — seo.js
 * Injects Open Graph, Twitter Card and Schema.org tags dynamically.
 * Include in every page: <script src="/assets/seo.js"></script>
 *
 * Usage: set window.PQC_PAGE before including this script, e.g.:
 *   <script>
 *     window.PQC_PAGE = {
 *       title: 'PQCServer — Encrypt a Message',
 *       description: 'Send post-quantum encrypted messages...',
 *       type: 'website',        // website | article | product
 *       image: '/assets/og-messaging.png',  // optional
 *     };
 *   </script>
 */
(function () {
  const BASE  = 'https://pqcserver.com';
  const BRAND = 'PQCServer';
  const page  = window.PQC_PAGE || {};

  const title = page.title       || BRAND + ' — Post-Quantum Encrypted Messaging & Vault';
  const desc  = page.description || 'End-to-end encrypted messaging, quantum-safe file vault and document notarization. Powered by NIST FIPS-203 ML-KEM and FIPS-204 ML-DSA. Zero-knowledge server. Free.';
  const url   = BASE + window.location.pathname;
  const image = BASE + (page.image || '/assets/og-default.png');
  const type  = page.type || 'website';

  function meta(prop, content, isName) {
    const el = document.createElement('meta');
    el[isName ? 'name' : 'property'] = prop;
    el.content = content;
    document.head.appendChild(el);
  }

  // ── Open Graph ──────────────────────────────────────────────────────────
  meta('og:type',        type);
  meta('og:url',         url);
  meta('og:title',       title);
  meta('og:description', desc);
  meta('og:image',       image);
  meta('og:site_name',   BRAND);
  meta('og:locale',      'en_US');

  // ── Twitter Card ────────────────────────────────────────────────────────
  meta('twitter:card',        'summary_large_image', true);
  meta('twitter:title',       title,                 true);
  meta('twitter:description', desc,                  true);
  meta('twitter:image',       image,                 true);

  // ── Canonical ───────────────────────────────────────────────────────────
  const canonical = document.createElement('link');
  canonical.rel  = 'canonical';
  canonical.href = url;
  document.head.appendChild(canonical);

  // ── Schema.org SoftwareApplication ──────────────────────────────────────
  const schema = {
    '@context': 'https://schema.org',
    '@type': 'SoftwareApplication',
    'name': BRAND,
    'url': BASE,
    'description': desc,
    'applicationCategory': 'SecurityApplication',
    'operatingSystem': 'Web Browser',
    'offers': { '@type': 'Offer', 'price': '0', 'priceCurrency': 'EUR' },
    'featureList': [
      'Post-quantum encryption (ML-KEM NIST FIPS-203)',
      'Digital signatures (ML-DSA NIST FIPS-204)',
      'Zero-knowledge server architecture',
      'Encrypted file vault with GridFS',
      'Document notarization with timestamp',
      'Embeddable widget for third-party sites',
    ],
  };

  if (page.type === 'article' && page.datePublished) {
    schema['@type'] = 'Article';
    schema.datePublished = page.datePublished;
  }

  const schemaEl = document.createElement('script');
  schemaEl.type = 'application/ld+json';
  schemaEl.textContent = JSON.stringify(schema);
  document.head.appendChild(schemaEl);

})();
