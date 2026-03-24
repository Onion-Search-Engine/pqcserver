/**
 * PQCServer Widget — Embeddable post-quantum encrypt button + modal
 *
 * Usage (known recipient):
 *   <script src="https://pqcserver.com/assets/widget.js" data-recipient="alice"><\/script>
 *
 * Usage (known public key):
 *   <script src="https://pqcserver.com/assets/widget.js" data-pubkey="BASE64..."><\/script>
 *
 * Usage (unknown recipient — user will be prompted):
 *   <script src="https://pqcserver.com/assets/widget.js"><\/script>
 *
 * Optional attributes:
 *   data-label="Custom button text"
 *   data-theme="dark|light"
 */
(function () {
  'use strict';

  // Self-hosters: change this to your own domain
  const BASE = 'https://pqcserver.com';

  const script    = document.currentScript || document.querySelector('script[src*="widget.js"]');
  const CFG_RECIP = script?.getAttribute('data-recipient') || '';
  const CFG_KEY   = script?.getAttribute('data-pubkey')    || '';
  const CFG_LABEL = script?.getAttribute('data-label')     || '🔒 Send Encrypted Message';
  const CFG_THEME = script?.getAttribute('data-theme')     || 'dark';

  // ── Crypto helpers (loaded lazily from PQCServer CDN) ─────────────────────
  let ml_kem = null;
  async function loadCrypto() {
    if (ml_kem) return;
    try {
      const m = await import('https://esm.sh/pqc@1.0.13');
      ml_kem = m.ml_kem;
    } catch (e) {
      const m = await import('https://esm.run/pqc@1.0.13');
      ml_kem = m.ml_kem;
    }
  }

  function toB64(b) { let s=''; for (let i=0;i<b.length;i++) s+=String.fromCharCode(b[i]); return btoa(s); }
  function fromB64(s) { const b=atob(s.trim()); const a=new Uint8Array(b.length); for(let i=0;i<b.length;i++) a[i]=b.charCodeAt(i); return a; }
  function utf8Enc(s) { return new TextEncoder().encode(s); }
  async function aesEncrypt(secret, data) {
    const key = await crypto.subtle.importKey('raw', secret.slice(0,32), 'AES-GCM', false, ['encrypt']);
    const iv  = crypto.getRandomValues(new Uint8Array(12));
    const ct  = await crypto.subtle.encrypt({name:'AES-GCM',iv}, key, data);
    return { iv: toB64(iv), ct: toB64(new Uint8Array(ct)) };
  }

  // ── CSS ───────────────────────────────────────────────────────────────────
  const css = `
    /* --- Trigger button --- */
    .pqcs-btn {
      display:inline-flex; align-items:center; gap:7px;
      padding:9px 18px; border-radius:4px; border:1px solid;
      font-family:'DM Mono','Courier New',monospace; font-size:13px;
      cursor:pointer; transition:all .2s; letter-spacing:.3px;
      white-space:nowrap; text-decoration:none; user-select:none;
    }
    .pqcs-btn.dark  { background:rgba(0,212,255,.12); border-color:rgba(0,212,255,.35); color:#00d4ff; }
    .pqcs-btn.dark:hover { background:rgba(0,212,255,.22); box-shadow:0 0 16px rgba(0,212,255,.12); }
    .pqcs-btn.light { background:rgba(0,120,160,.08); border-color:rgba(0,120,160,.3); color:#005f7a; }
    .pqcs-btn.light:hover { background:rgba(0,120,160,.15); }
    .pqcs-badge {
      font-size:9px; padding:1px 5px; border-radius:2px; letter-spacing:1px;
      background:rgba(0,212,255,.15); border:1px solid rgba(0,212,255,.25); color:#00d4ff;
    }
    .pqcs-badge.light { background:rgba(0,120,160,.1); border-color:rgba(0,120,160,.2); color:#005f7a; }

    /* --- Overlay --- */
    .pqcs-overlay {
      position:fixed; inset:0; background:rgba(0,0,0,.65);
      backdrop-filter:blur(4px); z-index:99999;
      display:flex; align-items:center; justify-content:center;
      padding:1rem; animation:pqcsOverlayIn .2s ease;
    }
    @keyframes pqcsOverlayIn { from{opacity:0} to{opacity:1} }

    /* --- Modal --- */
    .pqcs-modal {
      background:#0d1117; border:1px solid #1c2a38; border-radius:10px;
      width:100%; max-width:520px; max-height:90vh; overflow-y:auto;
      font-family:'DM Mono','Courier New',monospace;
      animation:pqcsModalIn .25s ease;
      scrollbar-width:thin; scrollbar-color:#1c2a38 transparent;
    }
    @keyframes pqcsModalIn { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }

    .pqcs-modal-head {
      padding:14px 18px; border-bottom:1px solid #1c2a38;
      display:flex; align-items:center; justify-content:space-between;
    }
    .pqcs-modal-title { font-size:13px; color:#eaf6ff; display:flex; align-items:center; gap:8px; }
    .pqcs-modal-title .dot { width:6px;height:6px;border-radius:50%;background:#00d4ff;box-shadow:0 0 7px #00d4ff; }
    .pqcs-close {
      background:none; border:none; color:#4a6a80; font-size:18px;
      cursor:pointer; padding:2px 6px; border-radius:3px; transition:color .2s;
    }
    .pqcs-close:hover { color:#eaf6ff; }

    /* Steps indicator */
    .pqcs-steps {
      display:flex; align-items:center; gap:6px;
      padding:12px 18px; border-bottom:1px solid #1c2a38;
    }
    .pqcs-step-dot {
      width:22px; height:22px; border-radius:50%; border:1px solid #1c2a38;
      display:flex; align-items:center; justify-content:center;
      font-size:10px; color:#4a6a80; transition:all .3s; flex-shrink:0;
    }
    .pqcs-step-dot.active { border-color:#00d4ff; color:#00d4ff; background:rgba(0,212,255,.08); }
    .pqcs-step-dot.done   { border-color:#00e5a0; color:#00e5a0; background:rgba(0,229,160,.08); }
    .pqcs-step-line { flex:1; height:1px; background:#1c2a38; }
    .pqcs-step-line.done { background:#00e5a0; }

    /* Step label */
    .pqcs-step-label {
      padding:10px 18px 0; font-size:10px; letter-spacing:1.5px;
      text-transform:uppercase; color:#4a6a80;
    }

    /* Body */
    .pqcs-body { padding:16px 18px; }

    /* Form elements */
    .pqcs-label {
      display:block; font-size:10px; letter-spacing:.5px; text-transform:uppercase;
      color:#4a6a80; margin-bottom:5px;
    }
    .pqcs-input, .pqcs-textarea {
      width:100%; background:#07090c; border:1px solid #1c2a38; border-radius:5px;
      color:#c5d8e8; font-family:'DM Mono','Courier New',monospace;
      font-size:12px; padding:8px 11px; outline:none; transition:border-color .2s;
      resize:vertical; box-sizing:border-box;
    }
    .pqcs-input:focus, .pqcs-textarea:focus {
      border-color:rgba(0,212,255,.4); box-shadow:0 0 0 2px rgba(0,212,255,.05);
    }
    .pqcs-textarea { min-height:90px; line-height:1.5; }
    .pqcs-group { margin-bottom:12px; }
    .pqcs-group:last-child { margin-bottom:0; }
    .pqcs-row { display:flex; gap:8px; }
    .pqcs-row .pqcs-input { flex:1; }

    /* Buttons */
    .pqcs-action {
      display:inline-flex; align-items:center; gap:6px;
      padding:9px 18px; border-radius:5px; border:none;
      font-family:'DM Mono','Courier New',monospace; font-size:12px;
      cursor:pointer; transition:all .2s; letter-spacing:.3px; width:100%;
      justify-content:center; margin-top:12px;
    }
    .pqcs-action-primary {
      background:linear-gradient(135deg,rgba(0,212,255,.18),rgba(124,58,237,.18));
      border:1px solid rgba(0,212,255,.38); color:#00d4ff;
    }
    .pqcs-action-primary:hover { background:linear-gradient(135deg,rgba(0,212,255,.28),rgba(124,58,237,.28)); }
    .pqcs-action-primary:disabled { opacity:.35; cursor:not-allowed; }
    .pqcs-action-ghost {
      background:transparent; border:1px solid #1c2a38; color:#4a6a80;
      margin-top:6px;
    }
    .pqcs-action-ghost:hover { border-color:rgba(0,212,255,.2); color:#c5d8e8; }
    .pqcs-action-success {
      background:rgba(0,229,160,.12); border:1px solid rgba(0,229,160,.3); color:#00e5a0;
    }

    /* Status messages */
    .pqcs-status {
      font-size:11px; padding:6px 10px; border-radius:3px; margin-top:8px;
      display:flex; align-items:center; gap:6px;
    }
    .pqcs-status-ok   { background:rgba(0,229,160,.08); border:1px solid rgba(0,229,160,.2); color:#00e5a0; }
    .pqcs-status-fail { background:rgba(255,71,87,.08);  border:1px solid rgba(255,71,87,.2);  color:#ff4757; }
    .pqcs-status-info { background:rgba(0,212,255,.06);  border:1px solid rgba(0,212,255,.18); color:#00d4ff; }
    .pqcs-status-warn { background:rgba(255,165,2,.06);  border:1px solid rgba(255,165,2,.2);  color:#ffa502; }

    /* Info box */
    .pqcs-info {
      background:rgba(0,212,255,.04); border:1px solid rgba(0,212,255,.12);
      border-radius:5px; padding:9px 12px; font-size:11px; color:#7a9ab0;
      line-height:1.6; margin-bottom:10px;
    }
    .pqcs-info.warn { background:rgba(255,165,2,.04); border-color:rgba(255,165,2,.15); }
    .pqcs-info a { color:#00d4ff; }

    /* Spinner */
    .pqcs-spin {
      display:inline-block; width:10px; height:10px;
      border:2px solid rgba(0,212,255,.2); border-top-color:#00d4ff;
      border-radius:50%; animation:pqcsSpin .6s linear infinite; flex-shrink:0;
    }
    @keyframes pqcsSpin { to{transform:rotate(360deg)} }

    /* Result shortlink */
    .pqcs-shortlink {
      background:#07090c; border:1px solid rgba(0,229,160,.3); border-radius:5px;
      padding:10px 12px; font-size:13px; color:#00e5a0; word-break:break-all;
      text-align:center; letter-spacing:.3px; cursor:pointer; transition:background .2s;
    }
    .pqcs-shortlink:hover { background:rgba(0,229,160,.05); }

    /* Divider */
    .pqcs-or {
      display:flex; align-items:center; gap:10px;
      font-size:10px; color:#4a6a80; margin:10px 0;
    }
    .pqcs-or::before, .pqcs-or::after {
      content:''; flex:1; height:1px; background:#1c2a38;
    }

    /* Footer */
    .pqcs-footer {
      padding:10px 18px; border-top:1px solid #1c2a38;
      font-size:10px; color:#2a4050; text-align:center;
    }
    .pqcs-footer a { color:#2a4050; text-decoration:none; }
    .pqcs-footer a:hover { color:#4a6a80; }

    /* Recipient found card */
    .pqcs-recipient-card {
      display:flex; align-items:center; gap:10px;
      background:rgba(0,229,160,.05); border:1px solid rgba(0,229,160,.2);
      border-radius:5px; padding:9px 12px; margin-bottom:10px;
    }
    .pqcs-recipient-avatar {
      width:30px; height:30px; border-radius:50%;
      background:rgba(0,212,255,.15); border:1px solid rgba(0,212,255,.3);
      display:flex; align-items:center; justify-content:center;
      font-size:13px; flex-shrink:0;
    }
    .pqcs-recipient-name { font-size:12px; color:#eaf6ff; }
    .pqcs-recipient-sub  { font-size:10px; color:#4a6a80; }
    .pqcs-change-link {
      margin-left:auto; font-size:10px; color:#4a6a80;
      cursor:pointer; text-decoration:underline;
    }
    .pqcs-change-link:hover { color:#00d4ff; }
  `;

  // ── Inject CSS ─────────────────────────────────────────────────────────────
  const styleEl = document.createElement('style');
  styleEl.textContent = css;
  document.head.appendChild(styleEl);

  // ── State ─────────────────────────────────────────────────────────────────
  let state = {
    step: 1,          // 1=recipient, 2=message, 3=result
    resolvedKey: CFG_KEY || '',
    resolvedName: '',
    shortlink: '',
    overlay: null,
    modal: null,
  };

  // ── Build trigger button ───────────────────────────────────────────────────
  const btn = document.createElement('button');
  btn.className = `pqcs-btn ${CFG_THEME}`;
  btn.innerHTML = `${CFG_LABEL} <span class="pqcs-badge ${CFG_THEME}">PQC</span>`;
  btn.onclick = openModal;

  if (script && script.parentNode) {
    script.parentNode.insertBefore(btn, script.nextSibling);
  } else {
    document.body.appendChild(btn);
  }

  // ── Open modal ─────────────────────────────────────────────────────────────
  async function openModal() {
    loadCrypto(); // start loading in background

    // If recipient or key already configured → skip step 1
    if (CFG_RECIP) {
      state.step = 1;
      state.resolvedKey = '';
      state.resolvedName = '';
      // auto-lookup
    } else if (CFG_KEY) {
      state.resolvedKey  = CFG_KEY;
      state.resolvedName = 'Direct key';
      state.step = 2;
    } else {
      state.step = 1;
    }

    renderModal();

    // Auto-lookup if recipient preconfigured
    if (CFG_RECIP && !CFG_KEY) {
      setTimeout(() => doLookup(CFG_RECIP, true), 200);
    }
  }

  // ── Render modal ───────────────────────────────────────────────────────────
  function renderModal() {
    // Remove existing
    if (state.overlay) state.overlay.remove();

    const overlay = document.createElement('div');
    overlay.className = 'pqcs-overlay';
    overlay.onclick = (e) => { if (e.target === overlay) closeModal(); };
    state.overlay = overlay;

    const modal = document.createElement('div');
    modal.className = 'pqcs-modal';
    state.modal = modal;
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    // Header
    modal.innerHTML = `
      <div class="pqcs-modal-head">
        <div class="pqcs-modal-title">
          <div class="dot"></div>
          PQCServer — Post-Quantum Encrypt
        </div>
        <button class="pqcs-close" id="pqcs-close">✕</button>
      </div>
      <div class="pqcs-steps" id="pqcs-steps-bar"></div>
      <div class="pqcs-step-label" id="pqcs-step-label"></div>
      <div class="pqcs-body" id="pqcs-step-body"></div>
      <div class="pqcs-footer">
        🔒 All encryption happens in your browser &nbsp;·&nbsp;
        <a href="${BASE}" target="_blank">pqcserver.com</a>
      </div>
    `;

    modal.querySelector('#pqcs-close').onclick = closeModal;
    renderStep();
  }

  function renderStep() {
    renderStepsBar();
    const body = document.getElementById('pqcs-step-body');
    const label = document.getElementById('pqcs-step-label');

    if (state.step === 1) {
      label.textContent = 'Step 1 — Recipient';
      body.innerHTML = renderStep1();
      bindStep1();
    } else if (state.step === 2) {
      label.textContent = 'Step 2 — Message';
      body.innerHTML = renderStep2();
      bindStep2();
    } else if (state.step === 3) {
      label.textContent = 'Step 3 — Done';
      body.innerHTML = renderStep3();
      bindStep3();
    }
  }

  function renderStepsBar() {
    const bar = document.getElementById('pqcs-steps-bar');
    const steps = [
      { n:1, label:'Recipient' },
      { n:2, label:'Message' },
      { n:3, label:'Done' },
    ];
    let html = '';
    steps.forEach((s, i) => {
      const cls = s.n < state.step ? 'done' : s.n === state.step ? 'active' : '';
      const icon = s.n < state.step ? '✓' : s.n;
      html += `<div class="pqcs-step-dot ${cls}" title="${s.label}">${icon}</div>`;
      if (i < steps.length - 1) {
        html += `<div class="pqcs-step-line ${s.n < state.step ? 'done' : ''}"></div>`;
      }
    });
    bar.innerHTML = html;
  }

  // ── STEP 1 — Recipient ─────────────────────────────────────────────────────
  function renderStep1() {
    const preRecip = CFG_RECIP ? `value="${CFG_RECIP}"` : '';
    return `
      ${state.resolvedKey
        ? `<div class="pqcs-recipient-card" id="pqcs-recip-card">
             <div class="pqcs-recipient-avatar">👤</div>
             <div>
               <div class="pqcs-recipient-name">${state.resolvedName}</div>
               <div class="pqcs-recipient-sub">Public key loaded ✓</div>
             </div>
             <span class="pqcs-change-link" id="pqcs-change">change</span>
           </div>
           <button class="pqcs-action pqcs-action-primary" id="pqcs-next1">Continue →</button>`
        : `
          <div class="pqcs-group">
            <label class="pqcs-label">PQCServer username</label>
            <div class="pqcs-row">
              <input class="pqcs-input" id="pqcs-username" placeholder="e.g. alice_smith" ${preRecip} autocomplete="off">
              <button class="pqcs-action pqcs-action-primary" id="pqcs-lookup"
                style="width:auto;margin-top:0;padding:8px 14px;white-space:nowrap">
                Look up →
              </button>
            </div>
            <div id="pqcs-lookup-status"></div>
          </div>

          <div class="pqcs-or">or paste their public key directly</div>

          <div class="pqcs-group">
            <label class="pqcs-label">ML-KEM Public Key (base64)</label>
            <textarea class="pqcs-textarea" id="pqcs-manualkey"
              placeholder="Paste the recipient's ML-KEM public key here…
They can find it at pqcserver.com/u/username or in their keys file."
              rows="4"></textarea>
            <div id="pqcs-manualkey-status"></div>
          </div>

          <div class="pqcs-info">
            💡 The recipient can share their key by registering at
            <a href="${BASE}/keygen.html" target="_blank">pqcserver.com/keygen</a>
            — then you only need their username.
          </div>

          <button class="pqcs-action pqcs-action-primary" id="pqcs-next1" disabled>Continue →</button>
        `
      }
    `;
  }

  function bindStep1() {
    const nextBtn = document.getElementById('pqcs-next1');
    if (nextBtn) nextBtn.onclick = goStep2;

    const changeBtn = document.getElementById('pqcs-change');
    if (changeBtn) changeBtn.onclick = () => {
      state.resolvedKey  = '';
      state.resolvedName = '';
      renderStep();
    };

    const lookupBtn = document.getElementById('pqcs-lookup');
    if (lookupBtn) lookupBtn.onclick = () => {
      const u = document.getElementById('pqcs-username')?.value.trim().toLowerCase();
      if (u) doLookup(u, false);
    };

    const usernameInput = document.getElementById('pqcs-username');
    if (usernameInput) {
      usernameInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          const u = usernameInput.value.trim().toLowerCase();
          if (u) doLookup(u, false);
        }
      });
    }

    const manualKey = document.getElementById('pqcs-manualkey');
    if (manualKey) {
      manualKey.addEventListener('input', () => {
        const val = manualKey.value.trim();
        const btn = document.getElementById('pqcs-next1');
        const statusEl = document.getElementById('pqcs-manualkey-status');
        if (val.length > 100) {
          // Basic validation: try to decode and check length
          try {
            const bytes = fromB64(val);
            if ([800,1184,1568].includes(bytes.length)) {
              setWidgetStatus('pqcs-manualkey-status', 'ok',
                `✓ Valid ML-KEM-${bytes.length===800?512:bytes.length===1184?768:1024} public key`);
              state.resolvedKey  = val;
              state.resolvedName = 'Manual key';
              if (btn) btn.disabled = false;
            } else {
              setWidgetStatus('pqcs-manualkey-status', 'warn',
                `⚠ Key length ${bytes.length}B — expected 800, 1184 or 1568 bytes`);
              state.resolvedKey = '';
              if (btn) btn.disabled = true;
            }
          } catch(e) {
            setWidgetStatus('pqcs-manualkey-status', 'fail', '✗ Invalid base64 key');
            state.resolvedKey = '';
            if (btn) btn.disabled = true;
          }
        } else {
          if (statusEl) statusEl.innerHTML = '';
          state.resolvedKey = '';
          if (btn) btn.disabled = true;
        }
      });
    }
  }

  async function doLookup(username, auto) {
    const statusEl = document.getElementById('pqcs-lookup-status');
    setWidgetStatus('pqcs-lookup-status', 'info',
      '<span class="pqcs-spin"></span> Looking up ' + username + '…');

    try {
      const r = await fetch(`${BASE}/api/pubkey.php?u=${encodeURIComponent(username)}`);
      const d = await r.json();

      if (d.ok) {
        state.resolvedKey  = d.public_key_kem;
        state.resolvedName = d.display_name || username;
        setWidgetStatus('pqcs-lookup-status', 'ok', `✓ Found: ${state.resolvedName}`);

        // Re-render step1 showing the recipient card
        renderStep();

        // If auto-configured and found → go straight to step 2
        if (auto) setTimeout(goStep2, 600);

      } else if (d.not_found) {
        setWidgetStatus('pqcs-lookup-status', 'warn',
          `⚠ "${username}" is not registered on PQCServer. Ask them to register at pqcserver.com/keygen — or paste their public key below.`);
        const nextBtn = document.getElementById('pqcs-next1');
        if (nextBtn) nextBtn.disabled = true;
      } else {
        setWidgetStatus('pqcs-lookup-status', 'fail', '✗ ' + d.error);
      }
    } catch(e) {
      setWidgetStatus('pqcs-lookup-status', 'fail', '✗ Network error — cannot reach PQCServer');
    }
  }

  function goStep2() {
    if (!state.resolvedKey) {
      setWidgetStatus('pqcs-lookup-status', 'fail', '✗ Enter a username or paste a public key first');
      return;
    }
    state.step = 2;
    renderStep();
  }

  // ── STEP 2 — Message ───────────────────────────────────────────────────────
  function renderStep2() {
    return `
      <div class="pqcs-recipient-card">
        <div class="pqcs-recipient-avatar">👤</div>
        <div>
          <div class="pqcs-recipient-name">To: ${state.resolvedName}</div>
          <div class="pqcs-recipient-sub">Key loaded · Encryption is end-to-end</div>
        </div>
        <span class="pqcs-change-link" id="pqcs-back">← change</span>
      </div>

      <div class="pqcs-group">
        <label class="pqcs-label">Message</label>
        <textarea class="pqcs-textarea" id="pqcs-msg" rows="5"
          placeholder="Type your message…"></textarea>
      </div>

      <div class="pqcs-group">
        <label class="pqcs-label">File attachment (optional)</label>
        <input type="file" id="pqcs-file" style="
          width:100%;background:#07090c;border:1px solid #1c2a38;border-radius:5px;
          color:#c5d8e8;font-family:'DM Mono',monospace;font-size:11px;
          padding:7px 10px;cursor:pointer;box-sizing:border-box">
        <div id="pqcs-file-status"></div>
      </div>

      <div class="pqcs-info warn">
        🔒 Your message is encrypted in this browser before being sent.
        PQCServer never sees the content.
      </div>

      <button class="pqcs-action pqcs-action-primary" id="pqcs-encrypt-btn">
        🔒 Encrypt &amp; Get Shortlink
      </button>
      <div id="pqcs-enc-status"></div>
    `;
  }

  function bindStep2() {
    document.getElementById('pqcs-back').onclick = () => {
      state.step = 1;
      if (!CFG_RECIP && !CFG_KEY) { state.resolvedKey=''; state.resolvedName=''; }
      renderStep();
    };
    document.getElementById('pqcs-encrypt-btn').onclick = doEncrypt;
  }

  async function doEncrypt() {
    if (!ml_kem) {
      setWidgetStatus('pqcs-enc-status','info','<span class="pqcs-spin"></span> Loading crypto library…');
      await loadCrypto();
      if (!ml_kem) { setWidgetStatus('pqcs-enc-status','fail','✗ Failed to load crypto library. Check connection.'); return; }
    }

    const msg      = document.getElementById('pqcs-msg')?.value || '';
    const fileInput= document.getElementById('pqcs-file');
    if (!msg && !fileInput?.files.length) {
      setWidgetStatus('pqcs-enc-status','fail','✗ Write a message or attach a file'); return;
    }

    const encBtn = document.getElementById('pqcs-encrypt-btn');
    encBtn.disabled = true;
    setWidgetStatus('pqcs-enc-status','info','<span class="pqcs-spin"></span> Encrypting…');

    try {
      const pubKey = fromB64(state.resolvedKey);

      let scheme;
      if(pubKey.length===800)       scheme=ml_kem.ml_kem512;
      else if(pubKey.length===1184) scheme=ml_kem.ml_kem768;
      else if(pubKey.length===1568) scheme=ml_kem.ml_kem1024;
      else throw new Error(`Invalid public key size: ${pubKey.length}B`);

      const {cipherText, sharedSecret} = scheme.encapsulate(pubKey);

      // ── Encrypt text envelope ──────────────────────────────────────────────
      const textPayload = new TextEncoder().encode(JSON.stringify({ text: msg }));
      const { iv, ct } = await aesEncrypt(sharedSecret, textPayload);
      const envelope = JSON.stringify({
        v:   'pqcserver-1',
        alg: `ML-KEM-${pubKey.length===800?512:pubKey.length===1184?768:1024}+AES-256-GCM`,
        kem: toB64(cipherText), iv, ct,
        file: !!(fileInput?.files.length),
      });

      // ── Upload file via GridFS if present ──────────────────────────────────
      let fileId = null;
      if (fileInput?.files.length) {
        const file = fileInput.files[0];
        setWidgetStatus('pqcs-enc-status','info',
          `<span class="pqcs-spin"></span> Uploading ${file.name}…`);

        // Use PQCGFS if loaded on page, otherwise fall back to inline upload
        if (typeof PQCGFS !== 'undefined') {
          fileId = await PQCGFS.uploadFile(file, sharedSecret, (pct, label) => {
            setWidgetStatus('pqcs-enc-status','info',
              `<span class="pqcs-spin"></span> ${label}`);
          });
        } else {
          // Inline GridFS upload (widget used standalone without gridfs.js on host page)
          fileId = await widgetUploadFile(file, sharedSecret, (pct, label) => {
            setWidgetStatus('pqcs-enc-status','info',
              `<span class="pqcs-spin"></span> ${label}`);
          });
        }
        setWidgetStatus('pqcs-enc-status','info',
          '<span class="pqcs-spin"></span> Saving message…');
      }

      // ── Store envelope + file_id on PQCServer ─────────────────────────────
      const r = await fetch(`${BASE}/api/store.php`, {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
          ciphertext:      envelope,
          file_id:         fileId,
          burn_after_read: true,
          ttl_days:        30,
        })
      });
      const d = await r.json();

      if (d.ok) {
        state.shortlink = d.shortlink;
        state.step = 3;
        renderStep();
      } else {
        setWidgetStatus('pqcs-enc-status','fail','✗ Server error: '+d.error);
        encBtn.disabled=false;
      }
    } catch(e) {
      setWidgetStatus('pqcs-enc-status','fail','✗ Encryption failed: '+e.message);
      encBtn.disabled=false;
    }
  }

  // ── Inline GridFS upload for standalone widget ─────────────────────────────
  // Used when gridfs.js is not loaded on the host page
  async function widgetUploadFile(file, sharedSecret, onProgress) {
    const CHUNK = 3 * 1024 * 1024; // 3MB chunks

    // Encrypt entire file
    onProgress(0, `Encrypting ${file.name}…`);
    const fileBytes = new Uint8Array(await file.arrayBuffer());
    const key = await crypto.subtle.importKey('raw', sharedSecret.slice(0,32), 'AES-GCM', false, ['encrypt']);
    const iv  = crypto.getRandomValues(new Uint8Array(12));
    const ct  = await crypto.subtle.encrypt({name:'AES-GCM',iv}, key, fileBytes);
    const enc = new Uint8Array(12 + ct.byteLength);
    enc.set(iv, 0);
    enc.set(new Uint8Array(ct), 12);

    // Generate upload session id
    const uploadId = Array.from(crypto.getRandomValues(new Uint8Array(16)))
      .map(b => b.toString(16).padStart(2,'0')).join('');

    const totalChunks = Math.ceil(enc.length / CHUNK);
    let fileId = null;

    for (let i = 0; i < totalChunks; i++) {
      const slice   = enc.slice(i * CHUNK, Math.min((i+1) * CHUNK, enc.length));
      const b64Data = toB64(slice);
      const pct     = 5 + Math.round(((i+1) / totalChunks) * 90);
      onProgress(pct, `Uploading chunk ${i+1}/${totalChunks}…`);

      const payload = {
        upload_id: uploadId, chunk_index: i,
        total_chunks: totalChunks, chunk_data: b64Data,
      };
      if (i === 0) {
        payload.filename  = file.name;
        payload.mime_type = file.type || 'application/octet-stream';
        payload.total_size = file.size;
      }

      const r = await fetch(`${BASE}/api/file_upload.php`, {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload),
      });
      const d = await r.json();
      if (!d.ok) throw new Error('Chunk upload failed: ' + (d.error||'unknown'));
      if (d.done) fileId = d.file_id;
    }

    onProgress(100, `✓ ${file.name} uploaded`);
    return fileId;
  }

  // ── STEP 3 — Result ────────────────────────────────────────────────────────
  function renderStep3() {
    return `
      <div style="text-align:center;padding:.5rem 0 1rem">
        <div style="font-size:2rem;margin-bottom:.5rem">✅</div>
        <div style="font-size:14px;color:#eaf6ff;margin-bottom:.3rem">Message encrypted!</div>
        <div style="font-size:11px;color:#4a6a80">Copy this shortlink and send it to the recipient</div>
      </div>

      <div class="pqcs-shortlink" id="pqcs-link-box" title="Click to copy">
        ${state.shortlink}
      </div>
      <div id="pqcs-copy-status" style="text-align:center"></div>

      <button class="pqcs-action pqcs-action-success" id="pqcs-copy-btn">
        📋 Copy shortlink
      </button>

      <div class="pqcs-info" style="margin-top:10px">
        🔥 <strong style="color:#ffa502">Burn after read</strong> is enabled —
        the message will be permanently deleted when the recipient opens it.<br>
        Expires in <strong style="color:#eaf6ff">7 days</strong>.
      </div>

      <button class="pqcs-action pqcs-action-ghost" id="pqcs-new-btn">
        ↺ Encrypt another message
      </button>
    `;
  }

  function bindStep3() {
    const linkBox = document.getElementById('pqcs-link-box');
    const copyBtn = document.getElementById('pqcs-copy-btn');
    const newBtn  = document.getElementById('pqcs-new-btn');

    const doCopy = () => {
      navigator.clipboard.writeText(state.shortlink).then(() => {
        setWidgetStatus('pqcs-copy-status','ok','✓ Copied to clipboard!');
        setTimeout(()=>{ const el=document.getElementById('pqcs-copy-status'); if(el)el.innerHTML=''; },2000);
      });
    };

    if (linkBox) linkBox.onclick = doCopy;
    if (copyBtn) copyBtn.onclick = doCopy;
    if (newBtn)  newBtn.onclick  = () => {
      state.step = CFG_KEY ? 2 : 1;
      state.shortlink = '';
      if (!CFG_RECIP && !CFG_KEY) { state.resolvedKey=''; state.resolvedName=''; }
      renderStep();
    };
  }

  // ── Helpers ────────────────────────────────────────────────────────────────
  function closeModal() {
    if (state.overlay) { state.overlay.remove(); state.overlay=null; }
  }

  function setWidgetStatus(id, type, msg) {
    const el = document.getElementById(id);
    if (el) el.innerHTML = `<div class="pqcs-status pqcs-status-${type}">${msg}</div>`;
  }

})();
