/**
 * SparkSpend Elbil - manage vehicles and providers
 *
 * The manage modal: rename vehicles, create / rename / delete providers.
 * Part of the Elbil module (includes/elbil/*.js, loaded in the order listed in index.php);
 * shared state is Elbil.state, other parts are reached through Elbil.modules.
 */
(() => {
    'use strict';
    const S = Elbil.state;
    const M = Elbil.modules;
    const _esc = Elbil.esc;

    // -------------------------------------------------------------------------
    // Manage modal: vehicles + providers
    // -------------------------------------------------------------------------


    function _showManageMsg(msg, type = 'danger') {
        const el = document.getElementById('manageMsg');
        if (el) el.innerHTML = `<span class="text-${type}">${_esc(msg)}</span>`;
    }

    function openManageModal() {
        _renderManageVehicles(S.vehicles);
        _renderManageProviders(S.providers);
        document.getElementById('manageMsg').textContent = '';
        document.getElementById('newProviderName').value = '';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('manageModal')).show();
    }

    function _renderManageVehicles(list) {
        const el = document.getElementById('manageVehiclesList');
        if (!el) return;
        if (!list.length) {
            el.innerHTML = '<p class="text-muted small">Ingen køretøjer registreret.</p>';
            return;
        }
        el.innerHTML = list.map(v => `
            <div class="d-flex align-items-center gap-2 mb-2">
              <span class="flex-grow-1" id="vname-${v.id}">${_esc(v.vehicleName)}</span>
              <input type="text" class="form-control form-control-sm d-none" id="vinput-${v.id}"
                     value="${_esc(v.vehicleName)}" maxlength="80">
              <button class="btn btn-sm btn-outline-secondary" id="vedit-${v.id}"
                      data-action="vehicle-edit" data-id="${v.id}">
                <i class="fas fa-pencil"></i>
              </button>
              <button class="btn btn-sm btn-success d-none" id="vsave-${v.id}"
                      data-action="vehicle-save" data-id="${v.id}">
                <i class="fas fa-check"></i>
              </button>
              <button class="btn btn-sm btn-outline-secondary d-none" id="vcancel-${v.id}"
                      data-action="vehicle-cancel" data-id="${v.id}" data-name="${_esc(v.vehicleName)}">
                <i class="fas fa-times"></i>
              </button>
            </div>`).join('');
    }

    function _renderManageProviders(list) {
        const el = document.getElementById('manageProvidersList');
        if (!el) return;
        if (!list.length) {
            el.innerHTML = '<p class="text-muted small mb-0">Ingen udbydere registreret.</p>';
            return;
        }
        el.innerHTML = list.map(p => `
            <div class="d-flex align-items-center gap-2 mb-2">
              <span class="flex-grow-1" id="pname-${p.id}">${_esc(p.providerName)}</span>
              <input type="text" class="form-control form-control-sm d-none" id="pinput-${p.id}"
                     value="${_esc(p.providerName)}" maxlength="80">
              <button class="btn btn-sm btn-outline-secondary" id="pedit-${p.id}"
                      data-action="provider-edit" data-id="${p.id}">
                <i class="fas fa-pencil"></i>
              </button>
              <button class="btn btn-sm btn-success d-none" id="psave-${p.id}"
                      data-action="provider-save" data-id="${p.id}">
                <i class="fas fa-check"></i>
              </button>
              <button class="btn btn-sm btn-outline-secondary d-none" id="pcancel-${p.id}"
                      data-action="provider-cancel" data-id="${p.id}" data-name="${_esc(p.providerName)}">
                <i class="fas fa-times"></i>
              </button>
              <button class="btn btn-sm btn-outline-danger" id="pdel-${p.id}"
                      data-action="provider-delete" data-id="${p.id}">
                <i class="fas fa-trash"></i>
              </button>
            </div>`).join('');
    }

    function startEditVehicle(id) {
        document.getElementById('vname-' + id)?.classList.add('d-none');
        document.getElementById('vinput-' + id)?.classList.remove('d-none');
        document.getElementById('vedit-' + id)?.classList.add('d-none');
        document.getElementById('vsave-' + id)?.classList.remove('d-none');
        document.getElementById('vcancel-' + id)?.classList.remove('d-none');
        document.getElementById('vinput-' + id)?.focus();
    }

    function cancelEditVehicle(id, originalName) {
        document.getElementById('vname-' + id)?.classList.remove('d-none');
        document.getElementById('vinput-' + id)?.classList.add('d-none');
        document.getElementById('vedit-' + id)?.classList.remove('d-none');
        document.getElementById('vsave-' + id)?.classList.add('d-none');
        document.getElementById('vcancel-' + id)?.classList.add('d-none');
        const inp = document.getElementById('vinput-' + id);
        if (inp) inp.value = originalName;
    }

    async function saveVehicle(id) {
        const inp  = document.getElementById('vinput-' + id);
        const name = inp?.value.trim();
        if (!name) { _showManageMsg('Navn må ikke være tomt'); return; }
        try {
            await Api.post('update-vehicle', { id, name });
            const v = S.vehicles.find(x => x.id == id);
            if (v) v.vehicleName = name;
            document.getElementById('vname-' + id).textContent = name;
            cancelEditVehicle(id, name);
            M.main._populateVehicleFilter();
            _showManageMsg('Køretøj opdateret', 'success');
        } catch (e) { _showManageMsg(e.message); }
    }

    function startEditProvider(id) {
        document.getElementById('pname-' + id)?.classList.add('d-none');
        document.getElementById('pinput-' + id)?.classList.remove('d-none');
        document.getElementById('pedit-' + id)?.classList.add('d-none');
        document.getElementById('psave-' + id)?.classList.remove('d-none');
        document.getElementById('pcancel-' + id)?.classList.remove('d-none');
        document.getElementById('pdel-' + id)?.classList.add('d-none');
        document.getElementById('pinput-' + id)?.focus();
    }

    function cancelEditProvider(id, originalName) {
        document.getElementById('pname-' + id)?.classList.remove('d-none');
        document.getElementById('pinput-' + id)?.classList.add('d-none');
        document.getElementById('pedit-' + id)?.classList.remove('d-none');
        document.getElementById('psave-' + id)?.classList.add('d-none');
        document.getElementById('pcancel-' + id)?.classList.add('d-none');
        document.getElementById('pdel-' + id)?.classList.remove('d-none');
        const inp = document.getElementById('pinput-' + id);
        if (inp) inp.value = originalName;
    }

    async function saveProvider(id) {
        const inp  = document.getElementById('pinput-' + id);
        const name = inp?.value.trim();
        if (!name) { _showManageMsg('Navn må ikke være tomt'); return; }
        try {
            await Api.post('update-provider', { id, name });
            const p = S.providers.find(x => x.id == id);
            if (p) p.providerName = name;
            document.getElementById('pname-' + id).textContent = name;
            cancelEditProvider(id, name);
            M.main._populateProviderSelects();
            _showManageMsg('Udbyder opdateret', 'success');
        } catch (e) { _showManageMsg(e.message); }
    }

    async function createProvider() {
        const inp  = document.getElementById('newProviderName');
        const name = inp?.value.trim();
        if (!name) { _showManageMsg('Navn må ikke være tomt'); return; }
        try {
            const data = await Api.post('create-provider', { name });
            S.providers.push({ id: data.id, providerName: data.providerName });
            S.providers.sort((a, b) => a.providerName.localeCompare(b.providerName, 'da'));
            if (inp) inp.value = '';
            _renderManageProviders(S.providers);
            M.main._populateProviderSelects();
            _showManageMsg('Udbyder oprettet', 'success');
        } catch (e) { _showManageMsg(e.message); }
    }

    async function deleteProvider(id) {
        const ok = await appUtils.confirmDialog({
            title: 'Slet udbyder',
            message: 'Slet denne udbyder?',
            confirmLabel: 'Slet',
        });
        if (!ok) return;
        try {
            await Api.post('delete-provider', { id });
            S.providers = S.providers.filter(p => p.id != id);
            _renderManageProviders(S.providers);
            M.main._populateProviderSelects();
            _showManageMsg('Udbyder slettet', 'success');
            appUtils.showToast('Udbyder slettet', 'success');
        } catch (e) { _showManageMsg(e.message); }
    }

    M.manage = { _showManageMsg, openManageModal, _renderManageVehicles, _renderManageProviders, startEditVehicle, cancelEditVehicle, saveVehicle, startEditProvider, cancelEditProvider, saveProvider, createProvider, deleteProvider };
})();
