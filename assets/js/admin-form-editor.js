/**
 * Bomedia Forms — admin field editor.
 *
 * Vanilla JS, no build step, no jQuery. Builds the collapsible field rows
 * from the JSON payload printed by PHP, supports HTML5 drag-and-drop
 * reordering via the row handle, auto-derives field keys from labels,
 * validates key uniqueness on save, and renders a server-side preview of
 * the unsaved configuration in a modal.
 */
(function () {
	'use strict';

	var CFG = window.BomediaFormsAdmin || {};
	var TYPES = CFG.fieldTypes || {};
	var WIDTHS = CFG.widths || { full: 'Full', half: 'Half', third: 'Third' };
	var T = CFG.i18n || {};
	var OPTION_TYPES = ['select', 'radio'];

	var listEl, hiddenEl, emptyEl, dragSrc = null;

	function slugify(str) {
		return String(str || '')
			.toLowerCase()
			.replace(/[^a-z0-9]+/g, '_')
			.replace(/^_+|_+$/g, '')
			.replace(/_{2,}/g, '_');
	}

	function el(tag, attrs, html) {
		var n = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				n.setAttribute(k, attrs[k]);
			});
		}
		if (html != null) {
			n.innerHTML = html;
		}
		return n;
	}

	function optionsToText(options) {
		if (!Array.isArray(options)) {
			return '';
		}
		return options
			.map(function (o) {
				if (o && typeof o === 'object') {
					return o.value + '|' + (o.label != null ? o.label : o.value);
				}
				return String(o);
			})
			.join('\n');
	}

	function textToOptions(text) {
		return String(text || '')
			.split('\n')
			.map(function (line) {
				return line.trim();
			})
			.filter(Boolean)
			.map(function (line) {
				var p = line.split('|');
				var v = p[0].trim();
				return { value: v, label: (p[1] != null ? p[1] : p[0]).trim() };
			});
	}

	function typeOptions(selected) {
		return Object.keys(TYPES)
			.map(function (v) {
				return (
					'<option value="' +
					v +
					'"' +
					(v === selected ? ' selected' : '') +
					'>' +
					TYPES[v] +
					'</option>'
				);
			})
			.join('');
	}

	function widthOptions(selected) {
		return Object.keys(WIDTHS)
			.map(function (v) {
				return (
					'<option value="' +
					v +
					'"' +
					(v === selected ? ' selected' : '') +
					'>' +
					WIDTHS[v] +
					'</option>'
				);
			})
			.join('');
	}

	function buildRow(field, isNew) {
		field = field || {};
		var row = el('div', { class: 'bf-field-row', 'data-auto-key': isNew && !field.name ? '1' : '0' });

		var header = el('div', { class: 'bf-field-row__header' });
		header.appendChild(
			el('span', { class: 'bf-field-row__handle', title: 'Drag to reorder', 'aria-hidden': 'true' }, '&#8942;&#8942;')
		);
		var title = el('button', { type: 'button', class: 'bf-field-row__title' });
		title.textContent = field.label || T.untitled || 'Untitled field';
		header.appendChild(title);
		header.appendChild(el('span', { class: 'bf-field-row__badge' }, TYPES[field.type] || field.type || 'text'));
		var del = el('button', { type: 'button', class: 'bf-field-row__del', 'aria-label': 'Remove' }, '&times;');
		header.appendChild(del);
		row.appendChild(header);

		var body = el('div', { class: 'bf-field-row__body' });
		body.innerHTML =
			'<div class="bf-field-grid">' +
			'<label class="bf-fcol bf-fcol--6">' + 'Label' +
			'<input type="text" class="widefat bf-f-label" value="" /></label>' +
			'<label class="bf-fcol bf-fcol--3">' + 'Field key' +
			'<input type="text" class="widefat bf-f-key" value="" /></label>' +
			'<label class="bf-fcol bf-fcol--3">' + 'Type' +
			'<select class="bf-f-type">' + typeOptions(field.type || 'text') + '</select></label>' +
			'<label class="bf-fcol bf-fcol--3">' + 'Width' +
			'<select class="bf-f-width">' + widthOptions(field.width || 'full') + '</select></label>' +
			'<label class="bf-fcol bf-fcol--3 bf-f-reqwrap">' +
			'<input type="checkbox" class="bf-f-required" /> Required</label>' +
			'<label class="bf-fcol bf-fcol--6">' + 'Placeholder' +
			'<input type="text" class="widefat bf-f-placeholder" value="" /></label>' +
			'<label class="bf-fcol bf-fcol--6">' + 'Validation pattern (regex)' +
			'<input type="text" class="widefat bf-f-pattern" value="" /></label>' +
			'<label class="bf-fcol bf-fcol--6">' + 'Default value' +
			'<input type="text" class="widefat bf-f-default" value="" /></label>' +
			'<label class="bf-fcol bf-fcol--12 bf-f-optwrap">' + 'Options (one per line, "value|label")' +
			'<textarea class="widefat bf-f-options" rows="4"></textarea></label>' +
			'</div>';
		row.appendChild(body);

		// Populate values safely (avoid HTML injection via attribute strings).
		body.querySelector('.bf-f-label').value = field.label || '';
		body.querySelector('.bf-f-key').value = field.name || '';
		body.querySelector('.bf-f-placeholder').value = field.placeholder || '';
		body.querySelector('.bf-f-pattern').value = field.pattern || '';
		body.querySelector('.bf-f-default').value = field['default'] || '';
		body.querySelector('.bf-f-required').checked = !!field.required;
		body.querySelector('.bf-f-options').value = optionsToText(field.options);

		wireRow(row);
		toggleOptions(row);
		return row;
	}

	function wireRow(row) {
		var header = row.querySelector('.bf-field-row__header');
		var handle = row.querySelector('.bf-field-row__handle');
		var title = row.querySelector('.bf-field-row__title');
		var labelInput = row.querySelector('.bf-f-label');
		var keyInput = row.querySelector('.bf-f-key');
		var typeSel = row.querySelector('.bf-f-type');
		var badge = row.querySelector('.bf-field-row__badge');

		title.addEventListener('click', function () {
			row.classList.toggle('is-open');
		});

		row.querySelector('.bf-field-row__del').addEventListener('click', function () {
			if (window.confirm(T.confirmRm || 'Remove this field?')) {
				row.parentNode.removeChild(row);
				refreshEmpty();
			}
		});

		labelInput.addEventListener('input', function () {
			title.textContent = labelInput.value || T.untitled || 'Untitled field';
			if (row.getAttribute('data-auto-key') === '1') {
				keyInput.value = slugify(labelInput.value);
			}
		});

		keyInput.addEventListener('input', function () {
			row.setAttribute('data-auto-key', '0');
			keyInput.value = slugify(keyInput.value);
		});

		typeSel.addEventListener('change', function () {
			badge.textContent = TYPES[typeSel.value] || typeSel.value;
			toggleOptions(row);
		});

		// Drag-and-drop limited to the handle.
		handle.addEventListener('mousedown', function () {
			row.setAttribute('draggable', 'true');
		});
		header.addEventListener('mouseup', function () {
			row.setAttribute('draggable', 'false');
		});

		row.addEventListener('dragstart', function (e) {
			dragSrc = row;
			row.classList.add('is-dragging');
			e.dataTransfer.effectAllowed = 'move';
			try {
				e.dataTransfer.setData('text/plain', 'bf');
			} catch (err) {}
		});
		row.addEventListener('dragend', function () {
			row.classList.remove('is-dragging');
			row.setAttribute('draggable', 'false');
			dragSrc = null;
		});
		row.addEventListener('dragover', function (e) {
			e.preventDefault();
			if (!dragSrc || dragSrc === row) {
				return;
			}
			var rect = row.getBoundingClientRect();
			var after = e.clientY > rect.top + rect.height / 2;
			listEl.insertBefore(dragSrc, after ? row.nextSibling : row);
		});
	}

	function toggleOptions(row) {
		var type = row.querySelector('.bf-f-type').value;
		var wrap = row.querySelector('.bf-f-optwrap');
		wrap.style.display = OPTION_TYPES.indexOf(type) !== -1 ? '' : 'none';
	}

	function refreshEmpty() {
		if (emptyEl) {
			emptyEl.style.display = listEl.children.length ? 'none' : '';
		}
	}

	function collect() {
		var fields = [];
		listEl.querySelectorAll('.bf-field-row').forEach(function (row) {
			var type = row.querySelector('.bf-f-type').value;
			fields.push({
				type: type,
				name: slugify(row.querySelector('.bf-f-key').value),
				label: row.querySelector('.bf-f-label').value,
				placeholder: row.querySelector('.bf-f-placeholder').value,
				required: row.querySelector('.bf-f-required').checked,
				pattern: row.querySelector('.bf-f-pattern').value,
				'default': row.querySelector('.bf-f-default').value,
				width: row.querySelector('.bf-f-width').value,
				options:
					OPTION_TYPES.indexOf(type) !== -1
						? textToOptions(row.querySelector('.bf-f-options').value)
						: []
			});
		});
		return fields;
	}

	function findDuplicateKeys(fields) {
		var seen = {};
		var dups = {};
		fields.forEach(function (f) {
			if (f.name && seen[f.name]) {
				dups[f.name] = true;
			}
			if (f.name) {
				seen[f.name] = true;
			}
		});
		return Object.keys(dups);
	}

	function serializeInto(target) {
		target.value = JSON.stringify(collect());
	}

	/* ---- Preview modal ---- */

	function openPreview() {
		var modal = document.getElementById('bf-preview-modal');
		var bodyEl = modal.querySelector('.bf-preview-modal__body');
		modal.hidden = false;
		bodyEl.innerHTML = '<p>' + (T.loading || 'Loading…') + '</p>';

		var form = document.getElementById('post');
		var formId = form ? (form.querySelector('#post_ID') || {}).value || 0 : 0;

		var body = new FormData();
		body.append('action', 'bf_get_form');
		body.append('nonce', CFG.previewNonce || '');
		body.append('form_id', formId);
		body.append('bf_preview_fields', JSON.stringify(collect()));

		fetch(CFG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) {
				return r.json();
			})
			.then(function (res) {
				if (res && res.success && res.data && res.data.html) {
					bodyEl.innerHTML = res.data.html;
				} else {
					bodyEl.innerHTML = '<p>' + (T.prevErr || 'Could not load preview.') + '</p>';
				}
			})
			.catch(function () {
				bodyEl.innerHTML = '<p>' + (T.prevErr || 'Could not load preview.') + '</p>';
			});
	}

	function closePreview() {
		var modal = document.getElementById('bf-preview-modal');
		if (modal) {
			modal.hidden = true;
			modal.querySelector('.bf-preview-modal__body').innerHTML = '';
		}
	}

	function init() {
		var editor = document.getElementById('bf-fields-editor');
		if (!editor) {
			return;
		}
		listEl = document.getElementById('bf-fields-list');
		hiddenEl = document.getElementById('bf_fields_json');
		emptyEl = editor.querySelector('.bf-fields-empty');

		var data = [];
		var dataEl = document.getElementById('bf-fields-data');
		if (dataEl) {
			try {
				data = JSON.parse(dataEl.textContent.trim()) || [];
			} catch (e) {
				data = [];
			}
		}
		data.forEach(function (f) {
			listEl.appendChild(buildRow(f, false));
		});
		refreshEmpty();

		document.getElementById('bf-add-field').addEventListener('click', function () {
			var type = document.getElementById('bf-add-type').value || 'text';
			var row = buildRow({ type: type, width: 'full' }, true);
			listEl.appendChild(row);
			row.classList.add('is-open');
			refreshEmpty();
			row.querySelector('.bf-f-label').focus();
		});

		document.getElementById('bf-preview-btn').addEventListener('click', openPreview);

		var modal = document.getElementById('bf-preview-modal');
		if (modal) {
			modal.addEventListener('click', function (e) {
				if (e.target.getAttribute('data-close') === '1') {
					closePreview();
				}
			});
			document.addEventListener('keydown', function (e) {
				if (e.key === 'Escape' && !modal.hidden) {
					closePreview();
				}
			});
		}

		// Serialise + validate on save.
		var postForm = document.getElementById('post');
		if (postForm) {
			postForm.addEventListener('submit', function (e) {
				var fields = collect();
				var dups = findDuplicateKeys(fields);
				if (dups.length) {
					e.preventDefault();
					window.alert((T.dupKeys || 'Duplicate field keys are not allowed.') + '\n\n' + dups.join(', '));
					listEl.querySelectorAll('.bf-f-key').forEach(function (inp) {
						if (dups.indexOf(inp.value) !== -1) {
							inp.closest('.bf-field-row').classList.add('is-open', 'has-error');
						}
					});
					return;
				}
				serializeInto(hiddenEl);
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
