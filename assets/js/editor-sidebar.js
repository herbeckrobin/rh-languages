/**
 * Sprach-Sidebar im Block-Editor (buildless, window.wp.*).
 *
 * registerPlugin + PluginSidebar pinnen ein Icon in die
 * .interface-pinned-items-Gruppe oben rechts im Editor-Header. Das Icon zeigt den
 * aktuellen Sprachcode als Badge, ein Klick öffnet die "Sprachen"-Sidebar mit der
 * Übersetzungsliste. "+ anlegen" ruft den REST-Endpoint (dupliziert als Draft) und
 * springt in den neuen Beitrag.
 */
(function (wp, data) {
	'use strict';

	if (!wp || !wp.plugins || !wp.element) {
		return;
	}
	if (!data || !Array.isArray(data.languages) || data.languages.length < 2) {
		return;
	}

	var registerPlugin = wp.plugins.registerPlugin;
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;

	var editorPkg = wp.editor || wp.editPost || {};
	var PluginSidebar = editorPkg.PluginSidebar;
	var PluginSidebarMoreMenuItem = editorPkg.PluginSidebarMoreMenuItem;

	var cmp = wp.components || {};
	var Button = cmp.Button;
	var apiFetch = wp.apiFetch;

	if (!registerPlugin || !PluginSidebar || !Button || !apiFetch) {
		return;
	}

	var NAME = 'rh-languages-sidebar';
	var s = data.strings || {};

	function badge() {
		return el('span', { className: 'rhlang-badge' }, String(data.current.lang || '').toUpperCase());
	}

	function Panel() {
		var busyState = useState('');
		var busy = busyState[0];
		var setBusy = busyState[1];
		var errState = useState('');
		var err = errState[0];
		var setErr = errState[1];

		function create(code) {
			setBusy(code);
			setErr('');
			apiFetch({
				url: data.rest.root,
				method: 'POST',
				headers: { 'X-WP-Nonce': data.rest.nonce },
				data: { source: data.current.id, lang: code }
			}).then(function (res) {
				if (res && res.editUrl) {
					window.location.href = res.editUrl;
				} else {
					setBusy('');
				}
			}).catch(function () {
				setBusy('');
				setErr(s.error || '');
			});
		}

		var rows = data.languages.map(function (lang) {
			var code = lang.code;
			var isCurrent = code === data.current.lang;
			var tr = data.translations[code];
			var right;

			if (isCurrent) {
				right = el('span', { className: 'rhlang-row__badge' }, s.currentLabel || '');
			} else if (tr && tr.editUrl) {
				right = el('a', { className: 'rhlang-row__link', href: tr.editUrl }, s.edit || '');
			} else if (data.current.isDefault) {
				right = el(Button, {
					variant: 'secondary',
					disabled: !!busy,
					onClick: function () { create(code); }
				}, busy === code ? (s.creating || '') : ('+ ' + lang.label + ' ' + (s.create || '')));
			} else {
				right = el('span', { className: 'rhlang-row__hint' }, s.onlyFromDefault || '');
			}

			var status = (tr && tr.status === 'draft')
				? el('span', { className: 'rhlang-row__status' }, s.draft || '')
				: null;

			return el(
				'li',
				{ key: code, className: 'rhlang-row' + (isCurrent ? ' is-current' : '') },
				el('span', { className: 'rhlang-row__lang' }, lang.label, status),
				right
			);
		});

		return el(
			'div',
			{ className: 'rhlang-panel' },
			err ? el('div', { className: 'rhlang-error' }, err) : null,
			el('ul', { className: 'rhlang-list' }, rows)
		);
	}

	registerPlugin(NAME, {
		render: function () {
			return el(
				Fragment,
				null,
				PluginSidebarMoreMenuItem
					? el(PluginSidebarMoreMenuItem, { target: NAME, icon: badge() }, s.title || '')
					: null,
				el(PluginSidebar, { name: NAME, title: s.title || '', icon: badge() }, el(Panel))
			);
		}
	});
})(window.wp, window.rhLangEditor);
