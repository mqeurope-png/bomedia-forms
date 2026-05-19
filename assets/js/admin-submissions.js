/**
 * Bomedia Forms — submissions admin.
 *
 * Vanilla JS: select-all, row detail modal, and bulk delete. No jQuery.
 */
(function () {
	'use strict';

	var CFG = window.BomediaFormsSub || {};

	function modal() {
		return document.getElementById('bf-sub-modal');
	}

	function openModal(html) {
		var m = modal();
		if (!m) {
			return;
		}
		m.querySelector('.bf-preview-modal__body').innerHTML = html;
		m.hidden = false;
	}

	function closeModal() {
		var m = modal();
		if (m) {
			m.hidden = true;
			m.querySelector('.bf-preview-modal__body').innerHTML = '';
		}
	}

	function selectedIds() {
		var ids = [];
		document.querySelectorAll('.bf-sub-cb:checked').forEach(function (cb) {
			ids.push(cb.value);
		});
		return ids;
	}

	function post(action, extra) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', CFG.nonce || '');
		Object.keys(extra || {}).forEach(function (k) {
			var v = extra[k];
			if (Array.isArray(v)) {
				v.forEach(function (item) {
					body.append(k + '[]', item);
				});
			} else {
				body.append(k, v);
			}
		});
		return fetch(CFG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) {
			return r.json();
		});
	}

	function init() {
		var all = document.getElementById('bf-sub-all');
		if (all) {
			all.addEventListener('change', function () {
				document.querySelectorAll('.bf-sub-cb').forEach(function (cb) {
					cb.checked = all.checked;
				});
			});
		}

		document.querySelectorAll('.bf-sub-view').forEach(function (btn) {
			btn.addEventListener('click', function () {
				openModal('<p>' + (CFG.loading || 'Loading…') + '</p>');
				post('bf_submission_detail', { id: btn.getAttribute('data-id') })
					.then(function (res) {
						if (res && res.success && res.data && res.data.html) {
							openModal(res.data.html);
						} else {
							openModal('<p>' + (CFG.err || 'Error') + '</p>');
						}
					})
					.catch(function () {
						openModal('<p>' + (CFG.err || 'Error') + '</p>');
					});
			});
		});

		var m = modal();
		if (m) {
			m.addEventListener('click', function (e) {
				if (e.target.getAttribute('data-close') === '1') {
					closeModal();
				}
			});
			document.addEventListener('keydown', function (e) {
				if (e.key === 'Escape' && !m.hidden) {
					closeModal();
				}
			});
		}

		var del = document.getElementById('bf-sub-delete');
		if (del) {
			del.addEventListener('click', function () {
				var ids = selectedIds();
				if (!ids.length) {
					window.alert(CFG.none || 'Nothing selected.');
					return;
				}
				if (!window.confirm(CFG.confirmDel || 'Delete selected?')) {
					return;
				}
				del.disabled = true;
				post('bf_delete_submissions', { ids: ids })
					.then(function (res) {
						del.disabled = false;
						if (res && res.success) {
							window.location.reload();
						} else {
							window.alert((res && res.data && res.data.message) || CFG.err || 'Error');
						}
					})
					.catch(function () {
						del.disabled = false;
						window.alert(CFG.err || 'Error');
					});
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
