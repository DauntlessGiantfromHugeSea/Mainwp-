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

	/**
	 * Formular „Seite hinzufügen“: WordPress-Felder gegen die reine Überwachung
	 * tauschen. Ein verstecktes Pflichtfeld blockiert den Absenden-Versuch, also
	 * wird required mitgeschaltet statt nur die Sichtbarkeit.
	 */
	function applySiteType() {
		var checked = document.querySelector('[data-site-type]:checked');
		if (!checked) {
			return;
		}

		var type = checked.value;

		document.querySelectorAll('[data-when]').forEach(function (element) {
			var matches = element.getAttribute('data-when') === type;
			element.hidden = !matches;

			element.querySelectorAll('[required], [data-required]').forEach(function (input) {
				if (matches) {
					if (input.hasAttribute('data-required')) {
						input.setAttribute('required', 'required');
						input.removeAttribute('data-required');
					}
				} else if (input.hasAttribute('required')) {
					input.removeAttribute('required');
					input.setAttribute('data-required', '1');
				}
			});
		});

		document.querySelectorAll('[data-site-type]').forEach(function (radio) {
			var option = radio.closest('.type-option');
			if (option) {
				option.classList.toggle('selected', radio.checked);
			}
		});

		var submit = document.querySelector('[data-site-form] [data-label-' + type + ']');
		if (submit) {
			submit.textContent = submit.getAttribute('data-label-' + type);
		}
	}

	document.addEventListener('change', function (event) {
		if (event.target.matches('[data-site-type]')) {
			applySiteType();
		}
	});

	applySiteType();

	/**
	 * Auswahlgruppen: [data-choice="name"] schaltet die Bloecke
	 * [data-choice-when="name:wert"]. Getrennt vom Seitentyp oben, damit beide
	 * Formulare unabhaengig voneinander bleiben.
	 */
	function applyChoice(name) {
		var checked = document.querySelector('[data-choice="' + name + '"]:checked');
		if (!checked) {
			return;
		}

		document.querySelectorAll('[data-choice-when^="' + name + ':"]').forEach(function (element) {
			element.hidden = element.getAttribute('data-choice-when') !== name + ':' + checked.value;
		});

		document.querySelectorAll('[data-choice="' + name + '"]').forEach(function (radio) {
			var option = radio.closest('.type-option');
			if (option) {
				option.classList.toggle('selected', radio.checked);
			}
		});
	}

	document.addEventListener('change', function (event) {
		if (event.target.matches('[data-choice]')) {
			applyChoice(event.target.getAttribute('data-choice'));
		}
	});

	document.querySelectorAll('[data-choice]').forEach(function (radio) {
		applyChoice(radio.getAttribute('data-choice'));
	});

	/**
	 * Service Worker anmelden — nur über HTTPS oder auf localhost, sonst lehnt
	 * der Browser ab und wirft eine Ausnahme in die Konsole.
	 */
	if ('serviceWorker' in navigator && (window.isSecureContext || location.hostname === 'localhost')) {
		window.addEventListener('load', function () {
			navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {
				// Ohne Service Worker läuft das Panel ganz normal weiter.
			});
		});
	}

	/** Im installierten Fenster einen Zurück-Weg anbieten, den der Browser sonst stellt. */
	if (window.matchMedia('(display-mode: standalone)').matches) {
		document.documentElement.classList.add('standalone');
	}
})();
