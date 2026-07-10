/**
 * Sprach-Switcher (buildless). Dynamischer Block: save gibt null zurück,
 * render.php macht die Ausgabe. Die edit-Ansicht zeigt eine statische Vorschau
 * der Sprach-Labels (kein ServerSideRender, darum kein REST-/400-Aufwand).
 *
 * registerBlockType bekommt die block.json-Metadaten (window.rhLanguageSwitcherMeta).
 */
(function (wp, metadata, labels) {
	'use strict';

	if (!wp || !wp.blocks || !wp.blockEditor || !wp.element) {
		return;
	}

	var registerBlockType = wp.blocks.registerBlockType;
	var el = wp.element.createElement;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var __ = (wp.i18n && wp.i18n.__) ? wp.i18n.__ : function (s) { return s; };

	var blockSpec = (metadata && metadata.name) ? metadata : 'rh/language-switcher';
	var list = Array.isArray(labels) && labels.length ? labels : [__('Keine Sprachen konfiguriert', 'rh-languages')];

	registerBlockType(blockSpec, {
		edit: function () {
			var blockProps = useBlockProps({ className: 'rh-language-switcher' });

			return el(
				'ul',
				blockProps,
				list.map(function (label, i) {
					return el(
						'li',
						{ key: i, className: 'rh-language-switcher__item' + (i === 0 ? ' is-current' : '') },
						el('a', { href: '#', onClick: function (e) { e.preventDefault(); } }, label)
					);
				})
			);
		},

		save: function () {
			return null;
		}
	});
})(window.wp, window.rhLanguageSwitcherMeta, window.rhLanguageSwitcherLabels);
