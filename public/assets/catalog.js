// Catalog client scripts: show/hide variables, prefill from an existing rule, hints per match type.
(function () {
  const form = document.getElementById('pac-form');
  if (!form) return;
  const rules = window.PAC_RULES || {};
  const hints = {
    host_exact: ['intranet.corp.local', 'One host name, no https://, port, path or *'],
    domain_suffix: ['corp.local', 'Matches the domain and all its sub-domains'],
    ip_cidr: ['10.20.0.0/16', 'IPv4 network address and prefix (/8 to /32)'],
    url_pattern: ['https://sso.partner-example.com/*', 'Starts with http:// or https://, up to 3 *'],
  };

  const value = (name) => {
    const els = form.querySelectorAll('[name="' + name + '"]');
    for (const el of els) {
      if ((el.type === 'radio' || el.type === 'checkbox')) { if (el.checked) return el.value; }
      else return el.value;
    }
    return '';
  };

  function setValue(name, v) {
    form.querySelectorAll('[name="' + name + '"]').forEach((el) => {
      if (el.type === 'radio') el.checked = el.value === v;
      else el.value = v || '';
    });
  }

  function refresh() {
    form.querySelectorAll('[data-show]').forEach((el) => {
      const ok = el.dataset.show.split('&').every((cond) => {
        const [name, vals] = cond.split('=');
        return vals.split('|').includes(value(name));
      });
      el.hidden = !ok;
    });
    const type = value('request_type');
    const lock = type === 'extend';
    form.querySelectorAll('#rule-fields .lockable').forEach((el) => {
      el.classList.toggle('locked', lock);
      el.querySelectorAll('input, select, textarea').forEach((i) => { i.disabled = lock; });
    });
    form.querySelectorAll('.extend-only').forEach((el) => { el.hidden = !lock; });
    const mt = value('u_match_type');
    const input = document.getElementById('u_value');
    if (hints[mt]) {
      input.placeholder = hints[mt][0];
      document.getElementById('value-hint').textContent = hints[mt][1];
    }
  }

  function prefill() {
    const r = rules[value('target_rule')];
    if (!r || value('request_type') === 'new') return;
    ['u_action', 'u_match_type', 'u_value', 'u_proxy_profile', 'u_scope', 'u_priority', 'u_valid_from', 'u_valid_to',
      'u_application', 'u_owner_group', 'u_change_request'].forEach((f) => setValue(f, r[f]));
    form.querySelectorAll('[name="u_regions[]"]').forEach((cb) => { cb.checked = r.u_regions.includes(cb.value); });
  }

  form.addEventListener('change', (e) => {
    if (e.target.name === 'target_rule' || e.target.name === 'request_type') prefill();
    refresh();
  });
  form.addEventListener('submit', () => {
    // disabled fields are not posted; the server takes them from the rule being extended
    form.querySelectorAll('button[type=submit]').forEach((b) => { b.disabled = true; b.textContent = 'Submitting…'; });
  });
  if (value('target_rule') && !form.querySelector('.flash.error')) prefill();
  refresh();
})();
