/**
 * Sync status modal (header button): per-source status list and "Sync alle".
 * Moved out of index.php so the Content-Security-Policy needs no inline scripts.
 */
(function () {
    // Server text goes into innerHTML templates below: escape it.
    const _escHtml = appUtils.escapeHtml;

    const modal       = document.getElementById('syncModal');
    const listEl      = document.getElementById('syncStatusList');
    const msgEl       = document.getElementById('syncModalMsg');
    const syncAllBtn  = document.getElementById('syncAllBtn');
    const logToggle   = document.getElementById('syncLogToggle');
    const logPanel    = document.getElementById('syncLogPanel');
    const logContent  = document.getElementById('syncLogContent');
    const logMeta     = document.getElementById('syncLogMeta');
    const logRefresh  = document.getElementById('syncLogRefresh');
    const logChevron  = document.getElementById('syncLogChevron');
    if (!modal) return;

    let logLoaded = false; // lazy: only fetch on first expand

    // Load status whenever modal opens
    modal.addEventListener('show.bs.modal', _loadStatus);
    // Reset log panel on close so it re-fetches if sync was triggered
    modal.addEventListener('hidden.bs.modal', () => {
        logLoaded = false;
        logPanel.style.display = 'none';
        logToggle.setAttribute('aria-expanded', 'false');
        logChevron.style.transform = '';
    });

    // Log toggle
    logToggle?.addEventListener('click', () => {
        const open = logPanel.style.display !== 'none';
        logPanel.style.display = open ? 'none' : '';
        logToggle.setAttribute('aria-expanded', String(!open));
        logChevron.style.transform = open ? '' : 'rotate(180deg)';
        if (!open && !logLoaded) _loadLog();
    });

    logRefresh?.addEventListener('click', _loadLog);

    // "Sync alle" button
    syncAllBtn?.addEventListener('click', () => _triggerSync('all'));

    // Per-source sync buttons (event delegation)
    listEl?.addEventListener('click', e => {
        const btn = e.target.closest('[data-sync-source]');
        if (btn) _triggerSync(btn.dataset.syncSource);
    });

    function _loadStatus() {
        listEl.innerHTML = '<p class="text-muted text-center mb-0 py-2">Indlæser\u2026</p>';
        msgEl.textContent = '';
        Api.get('sync-status')
            .then(d => _render(d.sources || []))
            .catch(() => {
                listEl.innerHTML = '<p class="text-danger mb-0 py-2">Fejl ved hentning af status.</p>';
            });
    }

    function _loadLog() {
        logContent.innerHTML = '<span class="text-muted">Indlæser log\u2026</span>';
        logMeta.textContent = '';
        Api.get('system-log', { lines: 80 })
            .then(d => {
                logLoaded = true;
                if (d.missing) {
                    logContent.innerHTML = '<span style="color:#64748b">Ingen log endnu — kør en synkronisering for at generere output.</span>';
                    logMeta.textContent = '';
                    return;
                }
                if (!d.lines || d.lines.length === 0) {
                    logContent.innerHTML = '<span style="color:#64748b">Logfilen er tom.</span>';
                    return;
                }
                // Colour-code lines by severity
                const html = d.lines.map(line => {
                    const esc = _escHtml(line);
                    const lower = line.toLowerCase();
                    if (lower.includes('error') || lower.includes('fejl') || lower.includes('fatal'))
                        return `<span style="color:#f87171">${esc}</span>`;
                    if (lower.includes('warning') || lower.includes('advarsel'))
                        return `<span style="color:#fbbf24">${esc}</span>`;
                    if (lower.includes('ok') || lower.includes('success') || lower.includes('synced') || lower.includes('inserted'))
                        return `<span style="color:#4ade80">${esc}</span>`;
                    return `<span style="color:#94a3b8">${esc}</span>`;
                }).join('\n');
                logContent.innerHTML = html;
                // Scroll to bottom (newest entries)
                logContent.scrollTop = logContent.scrollHeight;
                logMeta.textContent = d.truncated
                    ? `Viser de seneste 80 af ${d.total} linjer`
                    : `${d.total} linjer`;
            })
            .catch(err => {
                // The server's own message (e.g. admin key missing) is worth showing; anything else is a plain failure.
                const known = err instanceof Api.ApiError && err.data && err.data.error;
                logLoaded = !!known;
                logContent.innerHTML = `<span style="color:#f87171">${known ? _escHtml(err.message) : 'Kunne ikke hente log.'}</span>`;
            });
    }

    function _render(sources) {
        if (!sources.length) {
            listEl.innerHTML = '<p class="text-muted mb-0 py-2">Ingen data.</p>';
            return;
        }
        listEl.innerHTML = sources.map((s, i) => {
            const isLast = i === sources.length - 1;
            const badge =
                s.status === 'ok'      ? '<span class="badge bg-success">OK</span>' :
                s.status === 'error'   ? '<span class="badge bg-danger">Fejl</span>' :
                                         '<span class="badge bg-secondary">Ukendt</span>';
            const ago   = s.last_sync ? _relativeTime(s.last_sync) : '—';
            const extra = s.records_synced != null
                ? `<span class="text-muted"> &middot; ${s.records_synced} poster</span>`
                : '';
            return `
            <div class="d-flex align-items-center justify-content-between py-2${isLast ? '' : ' border-bottom'}">
              <div>
                <div class="fw-semibold small">${_escHtml(s.label)}</div>
                <small class="text-muted">${ago}${extra}</small>
              </div>
              <div class="d-flex align-items-center gap-2 ms-3">
                ${badge}
                <button class="btn btn-outline-secondary btn-sm py-0 px-2"
                        data-sync-source="${_escHtml(s.id)}" title="Sync ${_escHtml(s.label)}">
                  <i class="fas fa-sync-alt fa-xs"></i>
                </button>
              </div>
            </div>`;
        }).join('');
    }

    function _relativeTime(dateStr) {
        const diff = Math.floor((Date.now() - new Date(dateStr.replace(' ', 'T'))) / 1000);
        if (diff < 60)    return 'Lige nu';
        if (diff < 3600)  return Math.floor(diff / 60) + ' min. siden';
        if (diff < 86400) return Math.floor(diff / 3600) + ' t. siden';
        return Math.floor(diff / 86400) + ' dage siden';
    }

    function _triggerSync(source) {
        const _syncBtnHtml = syncAllBtn ? syncAllBtn.innerHTML : '';
        if (syncAllBtn) {
            syncAllBtn.disabled = true;
            syncAllBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Synkroniserer\u2026';
        }
        msgEl.textContent = 'Starter sync\u2026';
        Api.post('trigger-sync', { source })
        .then(() => {
            msgEl.textContent = 'Sync startet — opdaterer om 20 sek.';
            window.appUtils?.showToast('Synkronisering startet', 'info');
            setTimeout(() => { _loadStatus(); msgEl.textContent = ''; }, 20000);
        })
        .catch(err => {
            if (err instanceof Api.ApiError) {
                msgEl.textContent = err.message === 'Fejl' ? 'Ukendt fejl.' : err.message;
                window.appUtils?.showToast(err.message === 'Fejl' ? 'Synkronisering fejlede' : err.message, 'danger');
                return;
            }
            msgEl.textContent = 'Netværksfejl.';
            window.appUtils?.showToast('Netværksfejl under synkronisering', 'danger');
        })
        .finally(() => {
            if (syncAllBtn) {
                syncAllBtn.disabled = false;
                syncAllBtn.innerHTML = _syncBtnHtml;
            }
        });
    }
})();
