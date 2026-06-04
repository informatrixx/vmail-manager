(() => {
    const localPartPattern = /^[a-z0-9][a-z0-9._+-]*$/;
    const domainPattern = /^[a-z0-9.-]+\.[a-z0-9.-]+$/;

    const showError = (form, message, field = null) => {
        const box = form.querySelector('[data-form-error]');
        if (box) {
            box.textContent = message;
            box.hidden = false;
        }
        if (field) {
            field.focus();
        }
    };

    const clearError = (form) => {
        const box = form.querySelector('[data-form-error]');
        if (box) {
            box.textContent = '';
            box.hidden = true;
        }
    };

    const normalize = (value) => value.trim().toLowerCase();

    const validateLocalPart = (value) => {
        const normalized = normalize(value);
        return normalized.length > 0 && normalized.length <= 64 && localPartPattern.test(normalized);
    };

    const validateEmail = (value) => {
        const parts = value.trim().split('@');
        return parts.length === 2 && validateLocalPart(parts[0]) && domainPattern.test(normalize(parts[1]));
    };

    const syncAliasSourceType = (form) => {
        const select = form.querySelector('[data-source-type]');
        const localField = form.querySelector('[data-source-local]');
        if (!select || !localField) {
            return;
        }

        const isCatchAll = select.value === 'catchall';
        localField.hidden = isCatchAll;
        const input = localField.querySelector('input');
        if (input) {
            input.required = !isCatchAll;
        }
    };

    const resetForCreate = (modal) => {
        const form = modal.querySelector('form');
        if (!form) {
            return;
        }

        clearError(form);
        form.reset();

        const id = form.querySelector('input[name="id"]');
        if (id) {
            id.value = '0';
        }

        if (form.matches('[data-account-form]')) {
            const title = modal.querySelector('h2');
            const username = form.querySelector('input[name="username"]');
            const password = form.querySelector('[data-password-field]');
            const quota = form.querySelector('input[name="quota"]');
            const enabled = form.querySelector('input[name="enabled"]');
            const sendonly = form.querySelector('input[name="sendonly"]');

            if (title) {
                title.textContent = 'Benutzer anlegen';
            }
            if (username) {
                username.value = '';
                username.readOnly = false;
                username.required = true;
            }
            if (password) {
                password.value = '';
                password.required = true;
            }
            if (quota) {
                quota.value = '0';
            }
            if (enabled) {
                enabled.checked = true;
            }
            if (sendonly) {
                sendonly.checked = false;
            }
        }

        if (form.matches('[data-alias-form]')) {
            const title = modal.querySelector('h2');
            const sourceType = form.querySelector('select[name="source_type"]');
            const sourceUsername = form.querySelector('input[name="source_username"]');
            const destination = form.querySelector('input[name="destination"]');
            const enabled = form.querySelector('input[name="enabled"]');

            if (title) {
                title.textContent = 'Alias anlegen';
            }
            if (sourceType) {
                sourceType.value = 'address';
            }
            if (sourceUsername) {
                sourceUsername.value = '';
            }
            if (destination) {
                destination.value = '';
            }
            if (enabled) {
                enabled.checked = true;
            }
            syncAliasSourceType(form);
        }
    };

    const openModal = (modal, options = {}) => {
        if (!modal) {
            return;
        }
        if (options.reset) {
            resetForCreate(modal);
        } else {
            const form = modal.querySelector('form');
            if (form) {
                clearError(form);
            }
        }
        modal.classList.add('is-open');
        document.body.classList.add('modal-open');
        const first = modal.querySelector('input:not([type="hidden"]):not([readonly]), select, button');
        if (first) {
            first.focus();
        }
    };

    const closeModal = (modal) => {
        if (!modal) {
            return;
        }
        modal.classList.remove('is-open');
        document.body.classList.remove('modal-open');
    };

    document.querySelectorAll('[data-open-modal]').forEach((button) => {
        button.addEventListener('click', () => {
            openModal(document.getElementById(button.dataset.openModal), { reset: true });
        });
    });

    document.querySelectorAll('[data-close-modal]').forEach((button) => {
        button.addEventListener('click', () => {
            closeModal(button.closest('.modal'));
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        document.querySelectorAll('.modal.is-open').forEach(closeModal);
    });

    document.querySelectorAll('.modal.is-open').forEach((modal) => {
        document.body.classList.add('modal-open');
        const first = modal.querySelector('input:not([type="hidden"]):not([readonly]), select, button');
        if (first) {
            first.focus();
        }
    });

    document.querySelectorAll('[data-source-type]').forEach((select) => {
        const form = select.closest('form');
        select.addEventListener('change', () => {
            if (form) {
                syncAliasSourceType(form);
            }
        });
        if (form) {
            syncAliasSourceType(form);
        }
    });

    document.querySelectorAll('[data-account-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            clearError(form);

            const id = Number(form.querySelector('input[name="id"]')?.value || 0);
            const username = form.querySelector('input[name="username"]');
            const password = form.querySelector('[data-password-field]');
            const quota = form.querySelector('input[name="quota"]');
            const passwordValue = password ? password.value : '';
            const minLength = Number(form.dataset.passwordMin || 8);

            if (username && !username.readOnly && !validateLocalPart(username.value)) {
                event.preventDefault();
                showError(form, 'Der lokale Teil der Adresse ist ungültig. Erlaubt sind Buchstaben, Zahlen, Punkt, Unterstrich, Plus und Minus.', username);
                return;
            }

            if (quota && Number(quota.value) < 0) {
                event.preventDefault();
                showError(form, 'Quota darf nicht negativ sein.', quota);
                return;
            }

            if (id === 0 || passwordValue !== '') {
                if (passwordValue.length < minLength) {
                    event.preventDefault();
                    showError(form, `Das Passwort muss mindestens ${minLength} Zeichen lang sein.`, password);
                    return;
                }
                if (!/[a-z]/.test(passwordValue)) {
                    event.preventDefault();
                    showError(form, 'Das Passwort braucht mindestens einen Kleinbuchstaben.', password);
                    return;
                }
                if (!/[A-Z]/.test(passwordValue)) {
                    event.preventDefault();
                    showError(form, 'Das Passwort braucht mindestens einen Großbuchstaben.', password);
                    return;
                }
                if (!/[0-9]/.test(passwordValue)) {
                    event.preventDefault();
                    showError(form, 'Das Passwort braucht mindestens eine Zahl.', password);
                    return;
                }
            }
        });
    });

    document.querySelectorAll('[data-alias-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            clearError(form);

            const sourceType = form.querySelector('select[name="source_type"]');
            const sourceUsername = form.querySelector('input[name="source_username"]');
            const destination = form.querySelector('input[name="destination"]');

            if (sourceType && sourceType.value !== 'catchall' && sourceUsername && !validateLocalPart(sourceUsername.value)) {
                event.preventDefault();
                showError(form, 'Die Alias-Quelle ist ungültig.', sourceUsername);
                return;
            }

            if (destination && !validateEmail(destination.value)) {
                event.preventDefault();
                showError(form, 'Die Zieladresse muss eine gültige vollständige E-Mail-Adresse sein.', destination);
            }
        });
    });
})();
