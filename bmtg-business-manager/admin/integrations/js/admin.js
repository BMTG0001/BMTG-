(function () {
  const root = document.getElementById('bmtg-integrations-root');
  if (!root || typeof BMTGIntegrations === 'undefined') return;

  let env = 'live';
  let state = { connectors: [], key_configured: true, key_error: null };

  function api(path, options = {}) {
    const url = BMTGIntegrations.restUrl + path + (path.includes('?') ? '&' : '?') + 'environment=' + encodeURIComponent(env);
    const headers = Object.assign({ 'X-WP-Nonce': BMTGIntegrations.nonce, 'Content-Type': 'application/json' }, options.headers || {});
    return fetch(url, Object.assign({}, options, { headers, credentials: 'same-origin' })).then(async (r) => {
      const data = await r.json().catch(() => ({}));
      if (!r.ok) throw Object.assign(new Error(data.error || data.message || 'Request failed'), { data, status: r.status });
      return data;
    });
  }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

  async function load() {
    root.innerHTML = '<p>Loading integrations…</p>';
    try {
      state = await api('');
      render();
    } catch (e) {
      root.innerHTML = '<div class="bmtg-notice error">' + esc(e.message) + '</div>';
    }
  }

  function render() {
    const banner = !state.key_configured ? `<div class="bmtg-banner error"><strong>Encryption key issue:</strong> ${esc(state.key_error || 'BMTG_ENCRYPTION_KEY is missing or invalid. Saving secrets is blocked.')}</div>` : '';
    const envSel = `<div class="bmtg-env"><label><strong>Environment:</strong> <select id="bmtg-env"><option value="live" ${env==='live'?'selected':''}>Live</option><option value="sandbox" ${env==='sandbox'?'selected':''}>Sandbox</option></select></label> ${env==='sandbox'?'<span class="bmtg-badge configured">Sandbox active</span>':''}</div>`;
    const intro = `<p class="bmtg-intro">Connect external services once. Secrets are encrypted at rest (sodium) and never shown back; only a hint and source are displayed. Constant values from <code>wp-config.php</code> override database values. A database backup without <code>wp-config.php</code> cannot reveal secrets, but full server access can.</p>`;
    const cards = (state.connectors || []).map(cardHtml).join('');
    root.innerHTML = banner + intro + envSel + `<div class="bmtg-grid">${cards || '<p>No connectors registered.</p>'}</div>`;
    bind();
  }

  function cardHtml(c) {
    const status = esc(c.status || 'not_configured');
    const fields = (c.fields || []).map(f => fieldHtml(c, f)).join('');
    const meta = [];
    if (c.last_checked) meta.push(`Last checked: ${esc(c.last_checked)}`);
    if (c.last_success) meta.push(`Last success: ${esc(c.last_success)}`);
    if (c.last_error) meta.push(`<span style="color:#b32d2e">Last error: ${esc(c.last_error)}</span>`);
    if (c.redirect_uri) meta.push(`Redirect URI (register with provider): <code>${esc(c.redirect_uri)}</code>`);
    const connectBtn = c.auth_type === 'oauth' ? `<button class="button" data-action="connect" data-id="${esc(c.id)}">Connect / Reconnect</button>` : '';
    const disableLabel = c.status === 'disabled' ? 'Enable' : 'Disable';
    return `<div class="bmtg-card" data-connector="${esc(c.id)}">
      <h2><span>${esc(c.label)}</span> <span class="bmtg-badge ${status}">${status.replace('_',' ')}</span></h2>
      <div class="bmtg-small">Auth: ${esc(c.auth_type)} · Environment: ${esc(c.environment)}</div>
      ${meta.length ? `<div class="bmtg-meta">${meta.join('<br>')}</div>` : ''}
      <div class="bmtg-fields">${fields}</div>
      <div class="bmtg-actions">
        <button class="button button-primary" data-action="save" data-id="${esc(c.id)}">Save & Test</button>
        <button class="button" data-action="test" data-id="${esc(c.id)}">Test</button>
        ${connectBtn}
        <button class="button" data-action="toggle" data-id="${esc(c.id)}" data-disabled="${c.status !== 'disabled'}">${disableLabel}</button>
      </div>
      <div class="bmtg-card-msg" aria-live="polite"></div>
    </div>`;
  }

  function fieldHtml(c, f) {
    const configured = f.configured;
    const readonly = f.source === 'wp-config';
    const hint = configured ? `<div class="bmtg-small">Configured (${esc(f.source || 'database')}${f.hint ? ', hint: '+esc(f.hint):''}${f.updated_at ? ', updated: '+esc(f.updated_at):''}). ${readonly ? 'Managed in wp-config.php.' : 'Enter a new value to replace.'}</div>` : '<div class="bmtg-small">Not configured.</div>';
    const input = readonly ? '' : `<input type="${f.secret ? 'password' : 'text'}" autocomplete="off" name="${esc(f.key)}" placeholder="${configured ? 'Leave blank to keep current' : ''}">`;
    const remove = configured && !readonly ? `<button class="button-link-delete" data-action="remove" data-id="${esc(c.id)}" data-field="${esc(f.key)}">Remove</button>` : '';
    return `<div class="bmtg-field"><label>${esc(f.label)}${f.required ? ' *':''}</label>${hint}${input}${f.help ? `<div class="bmtg-help">${esc(f.help)}</div>`:''}${remove}</div>`;
  }

  function bind() {
    const envSel = document.getElementById('bmtg-env');
    if (envSel) envSel.addEventListener('change', () => { env = envSel.value; load(); });
    root.querySelectorAll('[data-action]').forEach(btn => btn.addEventListener('click', onAction));
  }

  async function onAction(e) {
    const btn = e.currentTarget;
    const action = btn.dataset.action;
    const id = btn.dataset.id;
    const card = btn.closest('.bmtg-card');
    const msg = card ? card.querySelector('.bmtg-card-msg') : null;
    const setMsg = (text, cls) => { if (msg) msg.innerHTML = `<div class="bmtg-notice ${cls||''}">${esc(text)}</div>`; };
    btn.disabled = true;
    try {
      if (action === 'save') {
        const inputs = card.querySelectorAll('input[name]');
        const fields = {};
        inputs.forEach(i => { if (i.value) fields[i.name] = i.value; });
        const res = await api('/' + encodeURIComponent(id) + '/save', { method: 'POST', body: JSON.stringify({ fields }) });
        setMsg(res.test ? (res.test.ok ? 'Saved and test passed: ' + res.test.message : 'Saved, but test failed: ' + res.test.message) : 'Saved.', res.test && res.test.ok ? 'success' : (res.test ? 'error' : 'success'));
        await refreshCard(id);
      } else if (action === 'test') {
        const res = await api('/' + encodeURIComponent(id) + '/test', { method: 'POST', body: '{}' });
        setMsg(res.test && res.test.ok ? 'Test passed: ' + res.test.message : 'Test failed: ' + (res.test ? res.test.message : 'Unknown error'), res.test && res.test.ok ? 'success' : 'error');
        await refreshCard(id);
      } else if (action === 'connect') {
        const res = await api('/' + encodeURIComponent(id) + '/connect', { method: 'POST', body: '{}' });
        if (res.url) { window.location.href = res.url; return; }
        setMsg(res.error || 'Unable to start OAuth.', 'error');
      } else if (action === 'toggle') {
        const disabled = btn.dataset.disabled === 'true';
        await api('/' + encodeURIComponent(id) + '/toggle', { method: 'POST', body: JSON.stringify({ disabled }) });
        await load();
      } else if (action === 'remove') {
        if (!confirm('Remove this stored value?')) return;
        await api('/' + encodeURIComponent(id) + '/remove', { method: 'POST', body: JSON.stringify({ field: btn.dataset.field }) });
        await load();
      }
    } catch (err) {
      setMsg(err.message || 'Request failed.', 'error');
    } finally {
      btn.disabled = false;
    }
  }

  async function refreshCard(id) {
    try {
      const data = await api('/' + encodeURIComponent(id));
      const idx = state.connectors.findIndex(c => c.id === id);
      if (idx >= 0) state.connectors[idx] = data;
      render();
    } catch (_) { await load(); }
  }

  load();
})();
