/**
 * GitHub Sync admin screens.
 *
 * A sync is driven from here: the browser asks the server for one small step at
 * a time and shows progress, so a repository of any size can be synced without
 * a background scheduler and without hitting the PHP time limit.
 */
(function () {
    'use strict';

    var data = window.githubSyncData || {};
    var i18n = data.i18n || {};

    /* ---------------------------------------------------------------- utils */

    function t(key, fallback) {
        return i18n[key] || fallback || '';
    }

    function esc(value) {
        if (value === null || value === undefined) {
            return '';
        }

        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function wait(ms) {
        return new Promise(function (resolve) {
            window.setTimeout(resolve, ms);
        });
    }

    /**
     * Call the plugin REST API. Rejects with an Error carrying the server message.
     */
    function api(endpoint, method, body) {
        var options = {
            method: method || 'GET',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': data.nonce
            }
        };

        if (body) {
            options.body = JSON.stringify(body);
        }

        return window.fetch(data.restUrl + endpoint, options).then(function (response) {
            return response.text().then(function (text) {
                var payload = null;

                if (text) {
                    try {
                        payload = JSON.parse(text);
                    } catch (error) {
                        payload = null;
                    }
                }

                if (!response.ok) {
                    var message = payload && payload.message ? payload.message : t('genericError');
                    var failure = new Error(message);
                    failure.status = response.status;
                    failure.code = payload && payload.code ? payload.code : '';
                    throw failure;
                }

                return payload;
            });
        });
    }

    function notice(container, type, message) {
        if (!container) {
            return;
        }

        var icons = { success: 'yes-alt', error: 'dismiss', warning: 'warning', info: 'info' };
        var icon = icons[type] || 'info';

        container.innerHTML = '<div class="github-sync-flash github-sync-flash--' + esc(type) + '">'
            + '<span class="dashicons dashicons-' + esc(icon) + '" aria-hidden="true"></span>'
            + '<span>' + esc(message) + '</span>'
            + '<button type="button" class="github-sync-flash__close" aria-label="' + esc(t('close')) + '">&times;</button>'
            + '</div>';

        var close = container.querySelector('.github-sync-flash__close');

        if (close) {
            close.addEventListener('click', function () {
                container.innerHTML = '';
            });
        }
    }

    /* ------------------------------------------------------------ run driver */

    /**
     * Repeatedly step a run until it finishes, reporting progress as it goes.
     */
    function driveRun(runId, onProgress, shouldStop) {
        var networkRetries = 0;

        function nextStep() {
            if (shouldStop && shouldStop()) {
                return Promise.resolve(null);
            }

            return api('runs/' + runId + '/step', 'POST').then(function (run) {
                networkRetries = 0;
                onProgress(run);

                if (run && run.is_running) {
                    return nextStep();
                }

                return run;
            }).catch(function (error) {
                // A dropped connection or a hiccup on the host should not throw
                // away a sync that is already half done.
                var recoverable = !error.status || error.status >= 500;

                if (recoverable && networkRetries < 3) {
                    networkRetries++;

                    return wait(2000 * networkRetries).then(nextStep);
                }

                throw error;
            });
        }

        return nextStep();
    }

    /* -------------------------------------------------------------- dashboard */

    function initDashboard() {
        var container = document.querySelector('.github-sync-dashboard');

        if (!container) {
            return;
        }

        var state = {
            mappings: [],
            activeRun: null,
            cancelRequested: false
        };

        var commitModal = document.getElementById('github-sync-commit-modal');
        var editModal = document.getElementById('github-sync-edit-modal');

        function findMapping(id) {
            return state.mappings.filter(function (item) {
                return item.id === Number(id);
            })[0];
        }

        function messages() {
            return container.querySelector('.github-sync-messages');
        }

        function statusBadge(mapping) {
            if (!mapping.enabled) {
                return '<span class="github-sync-badge github-sync-badge--paused">'
                    + '<span class="dashicons dashicons-controls-pause" aria-hidden="true"></span>'
                    + esc(t('paused')) + '</span>';
            }

            return '<span class="github-sync-badge github-sync-badge--active">'
                + '<span class="dashicons dashicons-yes" aria-hidden="true"></span>'
                + esc(t('active')) + '</span>';
        }

        function lastRunLine(mapping) {
            var run = mapping.latest_run;

            if (!run) {
                return '<span class="github-sync-muted">' + esc(t('lastSync')) + ': ' + esc(t('never')) + '</span>';
            }

            var label = run.status === 'completed' ? t('completed')
                : run.status === 'failed' ? t('failed')
                : run.status === 'cancelled' ? t('cancelled')
                : t('syncing');

            var arrow = run.type === 'push' ? 'dashicons-upload' : 'dashicons-download';
            var when = run.completed_at || run.created_at || '';

            return '<span class="github-sync-runline github-sync-runline--' + esc(run.status) + '">'
                + '<span class="dashicons ' + esc(arrow) + '" aria-hidden="true"></span>'
                + esc(label) + '</span> '
                + '<span class="github-sync-muted">' + esc(when) + '</span>';
        }

        function actionButtons(mapping) {
            var buttons = [];

            if (mapping.enabled && mapping.allows_pull) {
                buttons.push('<button type="button" class="button button-primary" data-action="pull" data-id="' + mapping.id + '">'
                    + '<span class="dashicons dashicons-download" aria-hidden="true"></span>' + esc(t('pull')) + '</button>');
            }

            if (mapping.enabled && mapping.allows_push) {
                buttons.push('<button type="button" class="button" data-action="push" data-id="' + mapping.id + '">'
                    + '<span class="dashicons dashicons-upload" aria-hidden="true"></span>' + esc(t('push')) + '</button>');
            }

            buttons.push('<button type="button" class="button" data-action="edit" data-id="' + mapping.id + '" title="'
                + esc(t('editHint')) + '"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>'
                + esc(t('edit')) + '</button>');

            buttons.push('<button type="button" class="button github-sync-icon-button" data-action="toggle" data-id="' + mapping.id
                + '" title="' + esc(mapping.enabled ? t('pauseHint') : t('resumeHint')) + '">'
                + esc(mapping.enabled ? t('pause') : t('resume')) + '</button>');

            buttons.push('<button type="button" class="button github-sync-danger" data-action="delete" data-id="' + mapping.id
                + '" title="' + esc(t('deleteHint')) + '">' + esc(t('delete')) + '</button>');

            return buttons.join('');
        }

        function mappingCard(mapping) {
            var source = mapping.source_path ? '/' + mapping.source_path : '/';

            return '<article class="github-sync-mapping" data-mapping-row="' + mapping.id + '">'
                + '<header class="github-sync-mapping__head">'
                + '<div class="github-sync-mapping__title">'
                + '<span class="dashicons dashicons-cloud" aria-hidden="true"></span>'
                + '<h2>' + esc(mapping.repo_full_name) + '</h2>'
                + statusBadge(mapping)
                + '</div>'
                + '<div class="github-sync-mapping__actions">' + actionButtons(mapping) + '</div>'
                + '</header>'
                + '<div class="github-sync-mapping__flow">'
                + '<div class="github-sync-node">'
                + '<span class="github-sync-node__label">' + esc(t('repository')) + '</span>'
                + '<code>' + esc(mapping.branch) + '</code> <code>' + esc(source) + '</code>'
                + '</div>'
                + '<span class="github-sync-node__arrow dashicons dashicons-leftright" aria-hidden="true"></span>'
                + '<div class="github-sync-node">'
                + '<span class="github-sync-node__label">' + esc(t('destination')) + '</span>'
                + '<code>' + esc(destinationLabel(mapping)) + '</code>'
                + '</div>'
                + '</div>'
                + '<footer class="github-sync-mapping__meta">'
                + lastRunLine(mapping)
                + '<span class="github-sync-muted">' + esc(t('trackedFiles')) + ': ' + esc(mapping.tracked_files) + '</span>'
                + '<a class="github-sync-muted" href="' + esc(data.logsUrl) + '&mapping_id=' + mapping.id + '">' + esc(t('viewLogs')) + '</a>'
                + '</footer>'
                + '<div class="github-sync-progress" data-progress-row="' + mapping.id + '" hidden>'
                + '<div class="github-sync-progress__bar"><span style="width:0%"></span></div>'
                + '<p class="github-sync-progress__label"></p>'
                + '<button type="button" class="button button-small" data-action="cancel" data-id="' + mapping.id + '">'
                + esc(t('cancel')) + '</button>'
                + '</div>'
                + '</article>';
        }

        function destinationLabel(mapping) {
            var base = mapping.dest_type === 'plugin' ? 'wp-content/plugins/'
                : mapping.dest_type === 'theme' ? 'wp-content/themes/'
                : 'wp-content/';

            return base + String(mapping.dest_path || '').replace(/^\/+/, '');
        }

        function render() {
            if (!state.mappings.length) {
                container.innerHTML = '<div class="github-sync-empty">'
                    + '<span class="dashicons dashicons-cloud" aria-hidden="true"></span>'
                    + '<p>' + esc(t('noMappings')) + '</p>'
                    + '<p><a class="button button-primary" href="' + esc(data.wizardUrl) + '">' + esc(t('addMapping')) + '</a></p>'
                    + '</div><div class="github-sync-messages"></div>';
                return;
            }

            container.innerHTML = '<div class="github-sync-list">'
                + state.mappings.map(mappingCard).join('')
                + '</div><div class="github-sync-messages"></div>';

            state.mappings.forEach(function (mapping) {
                if (mapping.active_run) {
                    runSteps(mapping, mapping.active_run);
                }
            });
        }

        function load() {
            return api('mappings').then(function (mappings) {
                state.mappings = mappings || [];
                render();
            }).catch(function (error) {
                container.innerHTML = '<div class="github-sync-messages"></div>';
                notice(messages(), 'error', error.message);
            });
        }

        function setButtonsDisabled(mappingId, disabled) {
            var row = container.querySelector('[data-mapping-row="' + mappingId + '"]');

            if (!row) {
                return;
            }

            row.querySelectorAll('.github-sync-mapping__actions button').forEach(function (button) {
                button.disabled = disabled;
            });
        }

        function showProgress(mappingId, run) {
            var row = container.querySelector('[data-progress-row="' + mappingId + '"]');

            if (!row) {
                return;
            }

            row.hidden = false;

            var bar = row.querySelector('.github-sync-progress__bar span');
            var label = row.querySelector('.github-sync-progress__label');

            if (bar) {
                bar.style.width = (run.percent || 0) + '%';
            }

            if (label) {
                var counts = run.total_items ? ' ' + run.processed_items + ' / ' + run.total_items : '';
                label.textContent = (run.phase_label || '') + counts;
            }
        }

        function hideProgress(mappingId) {
            var row = container.querySelector('[data-progress-row="' + mappingId + '"]');

            if (row) {
                row.hidden = true;
            }
        }

        function summaryText(run) {
            var summary = run.summary || {};
            var parts = [];

            if (summary.added) {
                parts.push(summary.added + ' ' + t('added'));
            }

            if (summary.updated) {
                parts.push(summary.updated + ' ' + t('updated'));
            }

            if (summary.deleted) {
                parts.push(summary.deleted + ' ' + t('deleted'));
            }

            return parts.length ? parts.join(', ') : t('nothingChanged');
        }

        function finishRun(mapping, run) {
            state.activeRun = null;
            hideProgress(mapping.id);
            setButtonsDisabled(mapping.id, false);

            var type = 'warning';
            var text = t('cancelled');

            if (run && run.status === 'completed') {
                type = 'success';
                text = t('completed') + ': ' + summaryText(run);
            } else if (run && run.status === 'failed') {
                type = 'error';
                text = run.error || t('genericError');
            }

            // The list is redrawn first, otherwise the redraw would wipe the
            // message the user is meant to read.
            load().then(function () {
                notice(messages(), type, text);
            });
        }

        function runSteps(mapping, run) {
            state.activeRun = run;
            state.cancelRequested = false;
            setButtonsDisabled(mapping.id, true);
            showProgress(mapping.id, run);

            return driveRun(
                run.id,
                function (update) {
                    showProgress(mapping.id, update);
                },
                function () {
                    return state.cancelRequested;
                }
            ).then(function (final) {
                finishRun(mapping, final);
            }).catch(function (error) {
                state.activeRun = null;
                hideProgress(mapping.id);
                setButtonsDisabled(mapping.id, false);
                notice(messages(), 'error', error.message);
                load();
            });
        }

        function startSync(mapping, direction, message) {
            return api('mappings/' + mapping.id + '/sync', 'POST', {
                direction: direction,
                message: message || ''
            }).then(function (run) {
                return runSteps(mapping, run);
            }).catch(function (error) {
                notice(messages(), 'error', error.message);
            });
        }

        /* modals */

        function openModal(modal, mapping) {
            if (!modal) {
                return;
            }

            var target = modal.querySelector('.github-sync-modal__target');
            var error = modal.querySelector('.github-sync-modal__error');

            if (target) {
                target.textContent = mapping.repo_full_name + ' · ' + mapping.branch;
            }

            if (error) {
                error.textContent = '';
            }

            modal.hidden = false;
            modal.dataset.mappingId = String(mapping.id);
        }

        function closeModal(modal) {
            if (modal) {
                modal.hidden = true;
                delete modal.dataset.mappingId;
            }
        }

        function openCommitModal(mapping) {
            if (!commitModal) {
                var typed = window.prompt(t('commitLabel'), data.defaultCommitMessage || '');

                if (typed !== null) {
                    startSync(mapping, 'push', typed);
                }

                return;
            }

            var input = commitModal.querySelector('#github-sync-commit-message');

            if (input) {
                input.value = data.defaultCommitMessage || '';
            }

            openModal(commitModal, mapping);

            if (input) {
                input.focus();
                input.select();
            }
        }

        function openEditModal(mapping) {
            if (!editModal) {
                return;
            }

            var direction = editModal.querySelector('#github-sync-edit-direction');
            var deletePolicy = editModal.querySelector('#github-sync-edit-delete');
            var exclusions = editModal.querySelector('#github-sync-edit-exclusions');

            if (direction) {
                direction.value = mapping.sync_direction || 'both';
            }

            if (deletePolicy) {
                deletePolicy.checked = !!mapping.delete_policy;
            }

            if (exclusions) {
                exclusions.value = (mapping.exclusions || []).join('\n');
            }

            openModal(editModal, mapping);
        }

        [commitModal, editModal].forEach(function (modal) {
            if (!modal) {
                return;
            }

            modal.addEventListener('click', function (event) {
                if (event.target.hasAttribute('data-close-modal')) {
                    closeModal(modal);
                }
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            [commitModal, editModal].forEach(function (modal) {
                if (modal && !modal.hidden) {
                    closeModal(modal);
                }
            });
        });

        if (commitModal) {
            var confirmButton = commitModal.querySelector('#github-sync-commit-confirm');

            if (confirmButton) {
                confirmButton.addEventListener('click', function () {
                    var input = commitModal.querySelector('#github-sync-commit-message');
                    var error = commitModal.querySelector('.github-sync-modal__error');
                    var message = input ? input.value.trim() : '';

                    if (!message) {
                        if (error) {
                            error.textContent = t('commitRequired');
                        }

                        return;
                    }

                    var mapping = findMapping(commitModal.dataset.mappingId);
                    closeModal(commitModal);

                    if (mapping) {
                        startSync(mapping, 'push', message);
                    }
                });
            }
        }

        if (editModal) {
            var saveButton = editModal.querySelector('#github-sync-edit-save');

            if (saveButton) {
                saveButton.addEventListener('click', function () {
                    var mapping = findMapping(editModal.dataset.mappingId);

                    if (!mapping) {
                        return;
                    }

                    var direction = editModal.querySelector('#github-sync-edit-direction');
                    var deletePolicy = editModal.querySelector('#github-sync-edit-delete');
                    var exclusions = editModal.querySelector('#github-sync-edit-exclusions');
                    var error = editModal.querySelector('.github-sync-modal__error');

                    saveButton.disabled = true;

                    api('mappings/' + mapping.id, 'PATCH', {
                        sync_direction: direction ? direction.value : 'both',
                        delete_policy: deletePolicy ? deletePolicy.checked : false,
                        exclusions: exclusions ? exclusions.value : ''
                    }).then(function () {
                        saveButton.disabled = false;
                        closeModal(editModal);
                        return load().then(function () {
                            notice(messages(), 'success', t('settingsSaved'));
                        });
                    }).catch(function (failure) {
                        saveButton.disabled = false;

                        if (error) {
                            error.textContent = failure.message;
                        }
                    });
                });
            }
        }

        container.addEventListener('click', function (event) {
            var button = event.target.closest('button[data-action]');

            if (!button) {
                return;
            }

            var mapping = findMapping(button.dataset.id);

            if (!mapping) {
                return;
            }

            var action = button.dataset.action;

            if (action === 'pull') {
                if (window.confirm(t('confirmPull'))) {
                    startSync(mapping, 'pull');
                }

                return;
            }

            if (action === 'push') {
                openCommitModal(mapping);
                return;
            }

            if (action === 'edit') {
                openEditModal(mapping);
                return;
            }

            if (action === 'cancel') {
                if (!window.confirm(t('confirmCancel'))) {
                    return;
                }

                // The step loop notices the flag and stops on its own, so the
                // dialog is not torn down while a request is still in flight.
                state.cancelRequested = true;
                button.disabled = true;

                if (state.activeRun) {
                    api('runs/' + state.activeRun.id + '/cancel', 'POST').catch(function (error) {
                        notice(messages(), 'error', error.message);
                    });
                }

                return;
            }

            if (action === 'toggle') {
                button.disabled = true;
                api('mappings/' + mapping.id, 'PATCH', { enabled: !mapping.enabled }).then(load).catch(function (error) {
                    button.disabled = false;
                    notice(messages(), 'error', error.message);
                });

                return;
            }

            if (action === 'delete') {
                if (!window.confirm(t('confirmDelete'))) {
                    return;
                }

                button.disabled = true;
                api('mappings/' + mapping.id, 'DELETE').then(load).catch(function (error) {
                    button.disabled = false;
                    notice(messages(), 'error', error.message);
                });
            }
        });

        window.addEventListener('beforeunload', function (event) {
            if (state.activeRun) {
                event.preventDefault();
                event.returnValue = '';
            }
        });

        load();
    }

    /* ----------------------------------------------------------------- wizard */

    function initWizard() {
        var container = document.getElementById('github-sync-wizard-app');

        if (!container) {
            return;
        }

        var state = {
            repos: [],
            branches: [],
            repo: '',
            branch: '',
            sourcePath: '',
            destType: 'plugin',
            destPath: '',
            destTouched: false,
            direction: 'both',
            deletePolicy: false,
            exclusions: (data.suggestedExclusions || []).join('\n'),
            busy: false
        };

        function repoName() {
            var parts = state.repo.split('|');
            return parts.length > 1 ? parts[1] : '';
        }

        function destBase() {
            if (state.destType === 'plugin') {
                return 'wp-content/plugins/';
            }

            if (state.destType === 'theme') {
                return 'wp-content/themes/';
            }

            return 'wp-content/';
        }

        function destValue() {
            return state.destTouched ? state.destPath : repoName();
        }

        function stepCard(number, title, body, done) {
            return '<section class="github-sync-card' + (done ? ' is-done' : '') + '">'
                + '<h2><span class="github-sync-step">' + number + '</span>' + esc(title) + '</h2>'
                + body
                + '</section>';
        }

        function render() {
            var html = stepCard(1, t('repository'),
                '<select id="gs-repo"><option value="">' + esc(t('selectRepository')) + '</option>'
                + state.repos.map(function (repo) {
                    var value = repo.owner + '|' + repo.name;
                    return '<option value="' + esc(value) + '"' + (state.repo === value ? ' selected' : '') + '>'
                        + esc(repo.full_name) + (repo.private ? ' ' + esc(t('privateSuffix')) : '') + '</option>';
                }).join('')
                + '</select>',
                !!state.repo);

            if (state.branches.length) {
                html += stepCard(2, t('branch'),
                    '<select id="gs-branch"><option value="">' + esc(t('selectBranch')) + '</option>'
                    + state.branches.map(function (branch) {
                        return '<option value="' + esc(branch) + '"' + (state.branch === branch ? ' selected' : '') + '>'
                            + esc(branch) + '</option>';
                    }).join('')
                    + '</select>',
                    !!state.branch);
            }

            if (state.branch) {
                html += stepCard(3, t('foldersRules'),
                    '<div class="github-sync-field">'
                    + '<label for="gs-source">' + esc(t('repoFolder')) + '</label>'
                    + '<input type="text" id="gs-source" value="' + esc(state.sourcePath) + '" placeholder="' + esc(t('repoFolderHint')) + '">'
                    + '<p class="github-sync-hint">' + esc(t('repoFolderHint')) + '</p>'
                    + '</div>'

                    + '<div class="github-sync-field">'
                    + '<label for="gs-dest-type">' + esc(t('destTypeLabel')) + '</label>'
                    + '<select id="gs-dest-type">'
                    + '<option value="plugin"' + (state.destType === 'plugin' ? ' selected' : '') + '>' + esc(t('pluginFolder')) + '</option>'
                    + '<option value="theme"' + (state.destType === 'theme' ? ' selected' : '') + '>' + esc(t('themeFolder')) + '</option>'
                    + '<option value="custom"' + (state.destType === 'custom' ? ' selected' : '') + '>' + esc(t('customFolder')) + '</option>'
                    + '</select>'
                    + '</div>'

                    + '<div class="github-sync-field">'
                    + '<label for="gs-dest">' + esc(t('destFolder')) + '</label>'
                    + '<div class="github-sync-path"><span class="github-sync-path__base">' + esc(destBase()) + '</span>'
                    + '<input type="text" id="gs-dest" value="' + esc(destValue()) + '" placeholder="'
                    + esc(state.destType === 'custom' ? t('destCustomPlaceholder') : repoName()) + '"></div>'
                    + '<p class="github-sync-hint">' + esc(state.destType === 'custom' ? t('destCustomHint') : t('destFolderHint')) + '</p>'
                    + '</div>'

                    + '<div class="github-sync-field">'
                    + '<label for="gs-direction">' + esc(t('directions')) + '</label>'
                    + '<select id="gs-direction">'
                    + '<option value="both"' + (state.direction === 'both' ? ' selected' : '') + '>' + esc(t('dirBoth')) + '</option>'
                    + '<option value="pull"' + (state.direction === 'pull' ? ' selected' : '') + '>' + esc(t('dirPull')) + '</option>'
                    + '<option value="push"' + (state.direction === 'push' ? ' selected' : '') + '>' + esc(t('dirPush')) + '</option>'
                    + '</select>'
                    + '</div>'

                    + '<div class="github-sync-field">'
                    + '<label class="github-sync-checkbox"><input type="checkbox" id="gs-delete"'
                    + (state.deletePolicy ? ' checked' : '') + '> ' + esc(t('deletionsLabel')) + '</label>'
                    + '</div>'

                    + '<div class="github-sync-field">'
                    + '<label for="gs-exclusions">' + esc(t('ignorePaths')) + '</label>'
                    + '<textarea id="gs-exclusions" rows="8" class="code">' + esc(state.exclusions) + '</textarea>'
                    + '<p class="github-sync-hint">' + esc(t('ignoreHint')) + '</p>'
                    + '</div>'

                    + '<div class="github-sync-actions">'
                    + '<button type="button" class="button button-primary button-hero" id="gs-create"'
                    + (state.busy ? ' disabled' : '') + '>' + esc(t('createMapping')) + '</button>'
                    + '<span class="github-sync-wizard-error"></span>'
                    + '</div>',
                    false);
            }

            container.innerHTML = html;
            bind();
        }

        function bind() {
            var repo = document.getElementById('gs-repo');

            if (repo) {
                repo.addEventListener('change', function (event) {
                    state.repo = event.target.value;
                    state.branch = '';
                    state.branches = [];
                    state.destTouched = false;

                    if (!state.repo) {
                        render();
                        return;
                    }

                    var parts = state.repo.split('|');
                    container.classList.add('is-loading');

                    api('github/branches?owner=' + encodeURIComponent(parts[0]) + '&repo=' + encodeURIComponent(parts[1]))
                        .then(function (branches) {
                            state.branches = branches || [];

                            var match = state.repos.filter(function (item) {
                                return item.owner + '|' + item.name === state.repo;
                            })[0];

                            if (match && match.default_branch && state.branches.indexOf(match.default_branch) !== -1) {
                                state.branch = match.default_branch;
                            }

                            render();
                        })
                        .catch(function (error) {
                            container.innerHTML = '<div class="github-sync-callout github-sync-callout--error"><p>'
                                + esc(error.message) + '</p></div>';
                        })
                        .then(function () {
                            container.classList.remove('is-loading');
                        });
                });
            }

            var branch = document.getElementById('gs-branch');

            if (branch) {
                branch.addEventListener('change', function (event) {
                    state.branch = event.target.value;
                    render();
                });
            }

            var destType = document.getElementById('gs-dest-type');

            if (destType) {
                destType.addEventListener('change', function (event) {
                    state.destType = event.target.value;
                    render();
                });
            }

            var source = document.getElementById('gs-source');

            if (source) {
                source.addEventListener('input', function (event) {
                    state.sourcePath = event.target.value;
                });
            }

            var dest = document.getElementById('gs-dest');

            if (dest) {
                dest.addEventListener('input', function (event) {
                    state.destTouched = true;
                    state.destPath = event.target.value;
                });
            }

            var exclusions = document.getElementById('gs-exclusions');

            if (exclusions) {
                exclusions.addEventListener('input', function (event) {
                    state.exclusions = event.target.value;
                });
            }

            var direction = document.getElementById('gs-direction');

            if (direction) {
                direction.addEventListener('change', function (event) {
                    state.direction = event.target.value;
                });
            }

            var deletePolicy = document.getElementById('gs-delete');

            if (deletePolicy) {
                deletePolicy.addEventListener('change', function (event) {
                    state.deletePolicy = event.target.checked;
                });
            }

            var create = document.getElementById('gs-create');

            if (create) {
                create.addEventListener('click', function () {
                    var parts = state.repo.split('|');
                    var errorBox = container.querySelector('.github-sync-wizard-error');

                    state.busy = true;
                    create.disabled = true;

                    api('mappings', 'POST', {
                        repo_owner: parts[0],
                        repo_name: parts[1],
                        branch: state.branch,
                        source_path: state.sourcePath,
                        dest_type: state.destType,
                        dest_path: destValue(),
                        sync_direction: state.direction,
                        delete_policy: state.deletePolicy,
                        exclusions: state.exclusions
                    }).then(function () {
                        window.location.href = data.dashboardUrl;
                    }).catch(function (error) {
                        state.busy = false;
                        create.disabled = false;

                        if (errorBox) {
                            errorBox.textContent = error.message;
                        }
                    });
                });
            }
        }

        api('github/repos').then(function (repos) {
            state.repos = repos || [];

            if (!state.repos.length) {
                container.innerHTML = '<div class="github-sync-callout github-sync-callout--warning"><p>'
                    + esc(t('noRepos')) + '</p></div>';
                return;
            }

            render();
        }).catch(function (error) {
            container.innerHTML = '<div class="github-sync-callout github-sync-callout--error"><p>' + esc(error.message)
                + ' <a href="' + esc(data.settingsUrl) + '">' + esc(t('openSettings')) + '</a></p></div>';
        });
    }

    /* --------------------------------------------------------------- settings */

    function initSettings() {
        var button = document.getElementById('github-sync-test-connection');
        var result = document.querySelector('.github-sync-connection-result');

        if (button && result) {
            button.addEventListener('click', function () {
                button.disabled = true;
                result.className = 'github-sync-connection-result is-testing';
                result.textContent = t('testing');

                api('connection').then(function (response) {
                    result.className = 'github-sync-connection-result is-success';
                    result.textContent = response.message || t('completed');
                }).catch(function (error) {
                    result.className = 'github-sync-connection-result is-error';
                    result.textContent = error.message;
                }).then(function () {
                    button.disabled = false;
                });
            });
        }

        var panels = document.querySelectorAll('[data-auth-panel]');
        var toggles = document.querySelectorAll('.github-sync-auth-toggle');

        function syncPanels() {
            var selected = 'app';

            toggles.forEach(function (toggle) {
                if (toggle.checked) {
                    selected = toggle.value;
                }
            });

            panels.forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-auth-panel') !== selected;
            });
        }

        if (panels.length && toggles.length) {
            toggles.forEach(function (toggle) {
                toggle.addEventListener('change', syncPanels);
            });

            syncPanels();
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        initDashboard();
        initWizard();
        initSettings();
    });
}());
