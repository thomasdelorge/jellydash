(function () {
    'use strict';

    const dialog = document.querySelector('[data-history-dialog]');
    const form = document.querySelector('[data-history-form]');
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');

    if (!dialog || !form || !csrfMeta || !csrfMeta.content || typeof dialog.showModal !== 'function') {
        return;
    }

    const csrfToken = csrfMeta.content;
    const userEl = dialog.querySelector('[data-history-user]');
    const titleEl = dialog.querySelector('[data-history-title]');
    const subEl = dialog.querySelector('[data-history-sub]');
    const metaEl = dialog.querySelector('[data-history-meta]');
    const hoursInput = dialog.querySelector('[data-history-hours]');
    const minutesInput = dialog.querySelector('[data-history-minutes]');
    const secondsInput = dialog.querySelector('[data-history-seconds]');
    const mergeSection = dialog.querySelector('[data-history-merge]');
    const siblingsEl = dialog.querySelector('[data-history-siblings]');
    const mergeBtn = dialog.querySelector('[data-history-merge-btn]');
    const errorEl = dialog.querySelector('[data-history-error]');
    const deleteBtn = dialog.querySelector('[data-history-delete]');
    const closeButtons = dialog.querySelectorAll('[data-history-close]');
    const confirmDialog = document.querySelector('[data-history-confirm]');
    const confirmForm = document.querySelector('[data-history-confirm-form]');
    const confirmTitle = document.querySelector('[data-history-confirm-title]');
    const confirmMeta = document.querySelector('[data-history-confirm-meta]');
    const confirmError = document.querySelector('[data-history-confirm-error]');
    const confirmCancel = document.querySelector('[data-history-confirm-cancel]');
    const confirmDelete = document.querySelector('[data-history-confirm-delete]');

    if (
        !userEl || !titleEl || !subEl || !metaEl
        || !hoursInput || !minutesInput || !secondsInput
        || !mergeSection || !siblingsEl || !mergeBtn || !errorEl || !deleteBtn
        || closeButtons.length === 0
        || !confirmDialog || !confirmForm || !confirmTitle || !confirmMeta
        || !confirmError || !confirmCancel || !confirmDelete
        || typeof confirmDialog.showModal !== 'function'
    ) {
        return;
    }

    let current = null;
    let busy = false;
    let requestSeq = 0;
    let pendingDeleteId = 0;

    function setBusy(next) {
        busy = next;
        form.classList.toggle('is-busy', next);
        confirmForm.classList.toggle('is-busy', next);
    }

    function showError(message) {
        errorEl.hidden = !message;
        errorEl.textContent = message || '';
    }

    function showConfirmError(message) {
        confirmError.hidden = !message;
        confirmError.textContent = message || '';
    }

    function parseJson(response) {
        return response.json().catch(function () {
            return {};
        }).then(function (body) {
            if (!response.ok) {
                throw new Error(body.error || 'Request failed.');
            }

            return body;
        });
    }

    function getPlay(id) {
        return fetch('/api/history.php?id=' + encodeURIComponent(String(id)), {
            credentials: 'same-origin',
        }).then(parseJson);
    }

    function postAction(body) {
        return fetch('/api/history.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        }).then(parseJson);
    }

    function reload() {
        window.location.reload();
    }

    function toInt(value) {
        const parsed = parseInt(String(value), 10);

        return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
    }

    function watchedFromInputs() {
        return (toInt(hoursInput.value) * 3600)
            + (toInt(minutesInput.value) * 60)
            + toInt(secondsInput.value);
    }

    function fillDuration(seconds) {
        const total = Math.max(0, seconds);
        hoursInput.value = String(Math.floor(total / 3600));
        minutesInput.value = String(Math.floor((total % 3600) / 60));
        secondsInput.value = String(total % 60);
    }

    function rowText(row, selector) {
        const node = row ? row.querySelector(selector) : null;

        return node ? node.textContent.trim() : '';
    }

    function openConfirm(id, title, meta) {
        if (!id) {
            return;
        }

        pendingDeleteId = id;
        confirmTitle.textContent = title || 'Unknown title';
        confirmMeta.textContent = meta || '';
        confirmMeta.hidden = !meta;
        showConfirmError('');
        if (!confirmDialog.open) {
            confirmDialog.showModal();
        }
    }

    function closeConfirm() {
        pendingDeleteId = 0;
        if (confirmDialog.open) {
            confirmDialog.close();
        }
    }

    function renderSiblings(siblings) {
        siblingsEl.replaceChildren();

        if (!siblings || siblings.length === 0) {
            mergeSection.hidden = true;

            return;
        }

        mergeSection.hidden = false;
        siblings.forEach(function (sibling) {
            const label = document.createElement('label');
            label.className = 'history-sibling';

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = String(sibling.id);
            checkbox.checked = true;

            const copy = document.createElement('span');
            const strong = document.createElement('strong');
            strong.textContent = sibling.started_label + ' · ' + sibling.watched_label;
            const small = document.createElement('small');
            small.textContent = [sibling.client, sibling.device].filter(Boolean).join(' · ');
            copy.appendChild(strong);
            if (small.textContent) {
                copy.appendChild(small);
            }

            label.appendChild(checkbox);
            label.appendChild(copy);
            siblingsEl.appendChild(label);
        });
    }

    function fill(play) {
        current = play;
        userEl.textContent = play.user || 'Unknown user';
        titleEl.textContent = play.title || 'Unknown title';
        subEl.textContent = play.sub || '';
        metaEl.textContent = [play.started_label, play.client, play.device]
            .filter(Boolean)
            .join(' · ');
        fillDuration(play.watched_sec);
        renderSiblings(play.siblings || []);
        showError('');
    }

    function openPlay(id) {
        if (!id) {
            return;
        }

        const seq = ++requestSeq;
        setBusy(true);
        showError('');
        current = null;
        titleEl.textContent = 'Loading…';
        subEl.textContent = '';
        metaEl.textContent = '';
        userEl.textContent = '';
        mergeSection.hidden = true;
        if (!dialog.open) {
            dialog.showModal();
        }

        getPlay(id).then(function (play) {
            if (seq !== requestSeq) {
                return;
            }
            fill(play);
        }).catch(function (error) {
            if (seq !== requestSeq) {
                return;
            }
            showError(error.message || 'Could not load this play.');
        }).finally(function () {
            if (seq === requestSeq) {
                setBusy(false);
            }
        });
    }

    function selectedSiblingIds() {
        return Array.prototype.slice.call(siblingsEl.querySelectorAll('input[type="checkbox"]:checked'))
            .map(function (input) {
                return toInt(input.value);
            })
            .filter(function (id) {
                return id > 0;
            });
    }

    function runMutation(body) {
        if (busy || !current) {
            return Promise.resolve();
        }

        return mutate(body);
    }

    function mutate(body) {
        if (busy) {
            return Promise.resolve();
        }

        setBusy(true);
        showError('');

        return postAction(body).then(function () {
            reload();
        }).catch(function (error) {
            const message = error.message || 'Could not update history.';
            if (confirmDialog.open) {
                showConfirmError(message);
            } else {
                showError(message);
            }
            setBusy(false);
        });
    }

    document.addEventListener('click', function (event) {
        if (!(event.target instanceof Element)) {
            return;
        }

        const edit = event.target.closest('[data-history-edit]');
        if (edit) {
            openPlay(toInt(edit.getAttribute('data-history-edit')));

            return;
        }

        const remove = event.target.closest('[data-history-delete-row]');
        if (remove) {
            const id = toInt(remove.getAttribute('data-history-delete-row'));
            const row = remove.closest('[data-history-id]');
            const title = rowText(row, '.history-item strong');
            const sub = rowText(row, '.history-item small');
            const user = rowText(row, '.history-user strong');
            const meta = [user, sub].filter(Boolean).join(' · ');
            openConfirm(id, title, meta);
        }
    });

    closeButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            dialog.close();
        });
    });

    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            dialog.close();
        }
    });

    dialog.addEventListener('close', function () {
        current = null;
        showError('');
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!current) {
            return;
        }

        runMutation({
            action: 'update',
            id: current.id,
            watched_sec: watchedFromInputs(),
        });
    });

    mergeBtn.addEventListener('click', function () {
        if (!current) {
            return;
        }

        const sourceIds = selectedSiblingIds();
        if (sourceIds.length === 0) {
            showError('Select at least one play to merge.');

            return;
        }

        runMutation({
            action: 'merge',
            id: current.id,
            source_ids: sourceIds,
        });
    });

    deleteBtn.addEventListener('click', function () {
        if (!current) {
            return;
        }

        const meta = [current.user, current.started_label].filter(Boolean).join(' · ');
        openConfirm(current.id, current.title, meta);
    });

    confirmCancel.addEventListener('click', closeConfirm);

    confirmDialog.addEventListener('click', function (event) {
        if (event.target === confirmDialog) {
            closeConfirm();
        }
    });

    confirmDialog.addEventListener('cancel', function () {
        pendingDeleteId = 0;
    });

    confirmDelete.addEventListener('click', function () {
        if (!pendingDeleteId || busy) {
            return;
        }

        mutate({
            action: 'delete',
            id: pendingDeleteId,
        });
    });
})();
