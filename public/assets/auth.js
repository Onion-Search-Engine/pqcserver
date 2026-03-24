/**
 * PQCServer — Shared Auth & UI helpers
 * Include in every page: <script src="/assets/auth.js"></script>
 */
window.PQC = window.PQC || {};
PQC.session = null;

// Check session status from server
PQC.checkSession = async function() {
  try {
    const r = await fetch('/api/session.php');
    const d = await r.json();
    PQC.session = d.ok ? d : null;
  } catch(e) { PQC.session = null; }
  return PQC.session;
};

// Render topbar nav based on session state
PQC.renderNav = function(session) {
  const nav = document.getElementById('topbar-nav');
  if (!nav) return;
  if (session && session.ok) {
    const u = session.user;
    nav.innerHTML = `
      <a href="/encrypt.html" class="hide-mobile">Encrypt</a>
      <a href="/vault.html" class="hide-mobile">Vault</a>
      <a href="/notary.html" class="hide-mobile" style="color:#a78bfa">Notary</a>
      <a href="/dashboard.html" class="hide-mobile">Dashboard</a>
      <a href="/u/${u.username}" class="hide-mobile" style="color:var(--accent)">@${u.username}</a>
      <button onclick="PQC.logout()"
        style="background:transparent;border:1px solid var(--border);color:var(--text-dim);
               cursor:pointer;font-family:var(--mono);font-size:.7rem;padding:5px 14px;
               border-radius:3px;letter-spacing:.3px;transition:all .2s"
        onmouseover="this.style.borderColor='rgba(0,212,255,.3)';this.style.color='var(--text)'"
        onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--text-dim)'">
        Log out
      </button>`;
  } else {
    nav.innerHTML = `
      <a href="/encrypt.html" class="hide-mobile">Encrypt</a>
      <a href="/login.html" class="hide-mobile">Log in</a>
      <a href="/register.html" class="btn-nav">Register free</a>`;
  }
};

// Check session and render nav — use in every page
PQC.initTopbar = async function() {
  const session = await PQC.checkSession();
  PQC.renderNav(session);
  return session;
};

// Logout
PQC.logout = async function() {
  await fetch('/api/logout.php', { method: 'POST' });
  PQC.session = null;
  PQC.toast('Logged out successfully', 'info');
  setTimeout(() => window.location.href = '/', 600);
};

// Redirect to login if not authenticated
PQC.requireAuth = async function(redirectBack = true) {
  const session = await PQC.checkSession();
  if (!session) {
    const back = redirectBack
      ? '?next=' + encodeURIComponent(window.location.pathname)
      : '';
    window.location.href = '/login.html' + back;
    return null;
  }
  return session;
};

// Toast notification
PQC.toast = function(msg, type = 'info') {
  const colors = {
    ok:   'background:rgba(0,229,160,.12);border:1px solid rgba(0,229,160,.3);color:#00e5a0;',
    fail: 'background:rgba(255,71,87,.1);border:1px solid rgba(255,71,87,.3);color:#ff4757;',
    info: 'background:rgba(0,212,255,.08);border:1px solid rgba(0,212,255,.2);color:#00d4ff;',
    warn: 'background:rgba(255,165,2,.08);border:1px solid rgba(255,165,2,.2);color:#ffa502;',
  };
  const el = document.createElement('div');
  el.style.cssText = `
    position:fixed;bottom:24px;right:24px;z-index:9999;
    padding:10px 18px;border-radius:5px;
    font-family:'DM Mono','Courier New',monospace;font-size:.75rem;
    animation:pqcToastIn .3s ease;max-width:340px;line-height:1.5;
    ${colors[type] || colors.info}`;
  el.textContent = msg;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 3500);
};

// Toast animation
const _s = document.createElement('style');
_s.textContent = `
  @keyframes pqcToastIn {
    from { opacity:0; transform:translateY(10px); }
    to   { opacity:1; transform:translateY(0); }
  }`;
document.head.appendChild(_s);
