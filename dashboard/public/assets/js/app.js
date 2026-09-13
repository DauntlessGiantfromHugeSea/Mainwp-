/* NorthLab Control Panel — kleine Helfer, kein Framework. */

(function () {
	'use strict';

	/** Bestätigungsdialog für zerstörerische Aktionen. */
	document.addEventListener('submit', function (event) {
		var form = event.target;
		var message = form.getAttribute('data-confirm');

		if (message && !window.confirm(message)) {
			event.preventDefault();
			return;
		}

		// Doppelklicks auf Absende-Buttons verhindern.
		var button = form.querySelector('[type=submit]');
		if (button && !form.hasAttribute('data-no-lock')) {
			window.setTimeout(function () {
				button.disabled = true;
				if (button.dataset.busy) {
					button.textContent = button.dataset.busy;
				}
			}, 10);
		}
	});

	/** "Alle auswählen" in Tabellen. */
	document.addEventListener('change', function (event) {
		var toggle = event.target;
		if (!toggle.matches('[data-check-all]')) {
			return;
		}

		var scope = document.querySelector(toggle.getAttribute('data-check-all'));
		if (!scope) {
			return;
		}

		scope.querySelectorAll('input[type=checkbox][data-item]').forEach(function (box) {
			box.checked = toggle.checked;
		});

		updateSelectionCount(scope);
	});

	document.addEventListener('change', function (event) {
		if (event.target.matches('input[type=checkbox][data-item]')) {
			var scope = event.target.closest('[data-selection-scope]') || document;
			updateSelectionCount(scope);
		}
	});

	function updateSelectionCount(scope) {
		var count = scope.querySelectorAll('input[type=checkbox][data-item]:checked').length;

		document.querySelectorAll('[data-selection-count]').forEach(function (element) {
			element.textContent = String(count);
		});
		document.querySelectorAll('[data-selection-disable]').forEach(function (element) {
			element.disabled = count === 0;
		});
	}

	/** Tabs ohne Seitenwechsel. */
	document.addEventListener('click', function (event) {
		var tab = event.target.closest('[data-tab]');
		if (!tab) {
			return;
		}

		event.preventDefault();

		var group = tab.closest('.tabs');
		var name = tab.getAttribute('data-tab');

		group.querySelectorAll('[data-tab]').forEach(function (item) {
			item.classList.toggle('active', item === tab);
		});

		document.querySelectorAll('[data-tab-panel]').forEach(function (panel) {
			panel.classList.toggle('active', panel.getAttribute('data-tab-panel') === name);
		});

		if (window.history.replaceState) {
			window.history.replaceState(null, '', '#' + name);
		}
	});

	/** Beim Laden den Tab aus dem Anker wiederherstellen. */
	if (window.location.hash) {
		var target = document.querySelector('[data-tab="' + window.location.hash.slice(1).replace(/"/g, '') + '"]');
		if (target) {
			target.click();
		}
	}

	/** In die Zwischenablage kopieren. */
	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-copy]');
		if (!button) {
			return;
		}

		event.preventDefault();

		var input = document.querySelector(button.getAttribute('data-copy'));
		if (!input) {
			return;
		}

		var done = function () {
			var original = button.textContent;
			button.textContent = 'Kopiert';
			window.setTimeout(function () { button.textContent = original; }, 1400);
		};

		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(input.value).then(done);
		} else {
			input.select();
			document.execCommand('copy');
			done();
		}
	});

	/** Filterformulare bei Auswahländerung direkt absenden. */
	document.addEventListener('change', function (event) {
		if (event.target.matches('[data-auto-submit]')) {
			event.target.form.submit();
		}
	});

	/** Navigation auf schmalen Schirmen ein- und ausklappen. */
	document.addEventListener('click', function (event) {
		var toggle = event.target.closest('[data-nav-toggle]');
		if (!toggle) {
			return;
		}

		var sidebar = document.getElementById('sidebar');
		if (!sidebar) {
			return;
		}

		var open = sidebar.classList.toggle('open');
		toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
	});

	/** Nach der Auswahl eines Menüpunkts wieder einklappen. */
	document.addEventListener('click', function (event) {
		var link = event.target.closest('#sidebar-nav a');
		if (!link) {
			return;
		}

		var sidebar = document.getElementById('sidebar');
		if (sidebar) {
			sidebar.classList.remove('open');
		}
	});

	/** Aufklappbare Bereiche. */
	document.addEventListener('click', function (event) {
		var toggle = event.target.closest('[data-toggle]');
		if (!toggle) {
			return;
		}

		event.preventDefault();

		var panel = document.querySelector(toggle.getAttribute('data-toggle'));
		if (panel) {
			panel.hidden = !panel.hidden;
		}
	});
})();
