/**
 * Bomedia Forms — frontend enhancement.
 *
 * Progressive enhancement only: the form submits normally without JS.
 * When JS is available we add inline validation, a loading state, and an
 * AJAX submit with inline success/error messaging. No jQuery.
 */
(function () {
	'use strict';

	var cfg = window.BomediaForms || {};

	function qs(form, sel) {
		return form.querySelector(sel);
	}

	function setMessage(form, text, type) {
		var box = qs(form, '.bf-form__messages');
		if (!box) {
			return;
		}
		box.textContent = text || '';
		box.classList.remove('is-success', 'is-error');
		if (type) {
			box.classList.add('is-' + type);
		}
	}

	function clearFieldErrors(form) {
		form.querySelectorAll('.bf-form__error').forEach(function (el) {
			el.textContent = '';
		});
		form.querySelectorAll('.bf-form__row.has-error').forEach(function (el) {
			el.classList.remove('has-error');
		});
	}

	function showFieldError(form, name, message) {
		var slot = form.querySelector('.bf-form__error[data-for="' + name + '"]');
		if (slot) {
			slot.textContent = message;
			var row = slot.closest('.bf-form__row');
			if (row) {
				row.classList.add('has-error');
			}
		}
	}

	function validate(form) {
		var ok = true;
		clearFieldErrors(form);

		form.querySelectorAll('[required]').forEach(function (el) {
			var empty =
				(el.type === 'checkbox' || el.type === 'radio')
					? !form.querySelector('[name="' + el.name + '"]:checked')
					: el.value.trim() === '';
			if (empty) {
				ok = false;
				var key = nameKey(el.name);
				showFieldError(form, key, el.getAttribute('data-error') || 'This field is required.');
			} else if (el.type === 'email' && el.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(el.value)) {
				ok = false;
				showFieldError(form, nameKey(el.name), 'Please enter a valid email address.');
			} else if (el.pattern && el.value && !new RegExp('^(?:' + el.pattern + ')$').test(el.value)) {
				ok = false;
				showFieldError(form, nameKey(el.name), 'Please match the requested format.');
			}
		});

		return ok;
	}

	// Extract the logical field name from bf_field[name] / bf_field[name][].
	function nameKey(name) {
		var m = /bf_field\[([^\]]+)\]/.exec(name || '');
		return m ? m[1] : name;
	}

	function setLoading(form, loading) {
		var btn = qs(form, '.bf-form__submit');
		form.classList.toggle('is-loading', loading);
		if (btn) {
			btn.disabled = loading;
		}
	}

	function onSubmit(e) {
		var form = e.currentTarget;

		if (!validate(form)) {
			e.preventDefault();
			setMessage(form, 'Please correct the highlighted fields.', 'error');
			var firstErr = form.querySelector('.has-error .bf-form__input, .has-error input');
			if (firstErr) {
				firstErr.focus();
			}
			return;
		}

		// No fetch support: let the browser submit normally.
		if (!window.fetch || !window.FormData) {
			return;
		}

		e.preventDefault();
		setMessage(form, '', null);
		setLoading(form, true);

		// reCAPTCHA v3: fetch a fresh token before submitting.
		var v3 = form.querySelector('input[data-v3="1"]');
		if (v3 && window.grecaptcha && typeof grecaptcha.execute === 'function') {
			var box = form.querySelector('.bf-captcha[data-provider="recaptcha_v3"]');
			var siteKey = box ? box.getAttribute('data-sitekey') : '';
			grecaptcha.ready(function () {
				grecaptcha
					.execute(siteKey, { action: v3.getAttribute('data-action') || 'submit' })
					.then(function (token) {
						v3.value = token;
						sendForm(form);
					})
					.catch(function () {
						setLoading(form, false);
						setMessage(form, 'Captcha error. Please try again.', 'error');
					});
			});
			return;
		}

		sendForm(form);
	}

	function sendForm(form) {
		var endpoint = cfg.ajaxUrl || form.getAttribute('action');

		fetch(endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			body: new FormData(form)
		})
			.then(function (r) {
				return r.json().then(function (json) {
					return { ok: r.ok, json: json };
				});
			})
			.then(function (res) {
				setLoading(form, false);
				var payload = res.json && res.json.data ? res.json.data : {};

				if (res.json && res.json.success) {
					if (payload.redirect) {
						window.location.assign(payload.redirect);
						return;
					}
					form.reset();
					clearFieldErrors(form);
					setMessage(form, payload.message || 'Thank you!', 'success');
					return;
				}

				if (payload.fields) {
					Object.keys(payload.fields).forEach(function (k) {
						showFieldError(form, k, payload.fields[k]);
					});
				}
				setMessage(form, payload.message || 'Something went wrong. Please try again.', 'error');
			})
			.catch(function () {
				setLoading(form, false);
				setMessage(form, 'Network error. Please try again.', 'error');
			});
	}

	function init() {
		document.querySelectorAll('form.bf-form').forEach(function (form) {
			form.addEventListener('submit', onSubmit);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
