<?php
defined( 'ABSPATH' ) || exit;

/**
 * Kleiner Hinweis unten in der Ecke, solange eine Sicherung laeuft.
 *
 * Wer ihn zu sehen bekommt, ist einstellbar und steht standardmaessig auf
 * "nur angemeldete Benutzer". Jedem Besucher einer Kundenseite mitzuteilen,
 * sie sei gerade langsam, schadet dem Kunden mehr als es nuetzt — darum ist
 * das eine bewusste Entscheidung und keine Voreinstellung.
 */
class NLC_Banner {

	const OPTION = 'nlc_banner';

	/**
	 * @return array<string,string>
	 */
	public static function audiences() {
		return array(
			'loggedin' => 'Alle angemeldeten Benutzer',
			'editors'  => 'Nur Redakteure und höher',
			'all'      => 'Alle Besucher der Seite',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'  => true,
			'audience' => 'loggedin',
			'text'     => 'Es läuft gerade eine Sicherung dieser Website. Sie kann kurzzeitig etwas langsamer reagieren.',
			'logo'     => '',
			'accent'   => '#e8917a',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	/**
	 * Was wirklich gespeichert ist — ohne das, was vom Branding geliehen wird.
	 *
	 * @return array<string,mixed>
	 */
	protected static function stored() {
		$stored = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	public static function state() {
		$state = self::stored();

		// Logo und Farbe liegen durch das Branding ohnehin schon auf dieser
		// Seite. Sie hier noch einmal zu verlangen hiesse, dass der Hinweis
		// ohne Logo erscheint, bloss weil ihn niemand zweimal eingetragen hat.
		if ( '' === trim( (string) $state['logo'] ) && class_exists( 'NLC_Branding' ) ) {
			$branding = NLC_Branding::state();

			if ( '' !== trim( (string) $branding['logo'] ) ) {
				$state['logo'] = (string) $branding['logo'];
			}
			if ( ! empty( $branding['accent'] ) ) {
				$state['accent'] = (string) $branding['accent'];
			}
		}

		return $state;
	}

	/**
	 * @param array<string,mixed> $values
	 * @return array<string,mixed>
	 */
	public static function save( array $values ) {
		// Bewusst stored() und nicht state(): sonst wird das Logo, das nur
		// vom Branding geliehen ist, beim ersten Speichern festgeschrieben —
		// und bliebe dann stehen, wenn das Branding spaeter ein anderes bekommt.
		$state = wp_parse_args( $values, self::stored() );

		$state['enabled'] = ! empty( $state['enabled'] );
		$state['text']    = sanitize_text_field( (string) $state['text'] );
		$state['logo']    = '' !== trim( (string) $state['logo'] ) ? esc_url_raw( (string) $state['logo'] ) : '';
		$state['accent']  = NLC_Maintenance_Mode::sanitize_color( (string) $state['accent'], '#e8917a' );

		$audiences          = self::audiences();
		$state['audience']  = isset( $audiences[ $state['audience'] ] ) ? (string) $state['audience'] : 'loggedin';

		$state = array_intersect_key( $state, self::defaults() );

		update_option( self::OPTION, $state, true );

		return $state;
	}

	public function hooks() {
		add_action( 'wp_footer', array( $this, 'render' ), 99 );
		add_action( 'admin_footer', array( $this, 'render' ), 99 );
	}

	/**
	 * Darf der aktuelle Betrachter den Hinweis sehen?
	 *
	 * @return bool
	 */
	public static function visible_now() {
		$state = self::state();

		if ( empty( $state['enabled'] ) || null === NLC_Backuplog::running() ) {
			return false;
		}

		if ( 'all' === $state['audience'] ) {
			return true;
		}

		if ( ! is_user_logged_in() ) {
			return false;
		}

		return 'editors' === $state['audience'] ? current_user_can( 'edit_posts' ) : true;
	}

	public function render() {
		if ( ! self::visible_now() ) {
			return;
		}

		echo self::markup( self::state(), (array) NLC_Backuplog::running() ); // phpcs:ignore WordPress.Security.EscapeOutput -- in markup() maskiert.
	}

	/**
	 * Eigenstaendiges Markup — kein Theme, keine fremden Dateien, kein jQuery.
	 *
	 * Alle Eigenschaften werden ausdruecklich gesetzt, weil die Leiste in
	 * beliebigen Themes landet und sonst deren Regeln erbt.
	 *
	 * @param array<string,mixed> $state
	 * @param array<string,mixed> $running
	 * @return string
	 */
	public static function markup( array $state, array $running ) {
		$state  = wp_parse_args( $state, self::defaults() );
		$accent = NLC_Maintenance_Mode::sanitize_color( (string) $state['accent'], '#e8917a' );
		$text   = (string) $state['text'];
		$logo   = (string) $state['logo'];
		$seit   = (int) ( $running['started'] ?? time() );

		// Wie weit der Lauf ist. Steht als zweite Zeile darunter, nicht im
		// Satz: der Satz erklaert, warum die Seite langsamer ist, die Zahl
		// sagt wie lange noch.
		$fortschritt = NLC_Backuplog::progress_text( $running );
		$anteil      = isset( $running['percent'] ) ? $running['percent'] : null;

		// Ein Schluessel je Lauf: wird der Hinweis weggeklickt, bleibt er fuer
		// diesen Lauf weg — die naechste Sicherung zeigt ihn wieder.
		$key = 'nlc-backup-' . $seit;

		$bild = '' !== $logo
			? '<img src="' . esc_url( $logo ) . '" alt="" width="20" height="20">'
			: '<span class="nlc-bb-dot"></span>';

		return '<div id="nlc-backup-banner" role="status" aria-live="polite" data-key="' . esc_attr( $key ) . '">'
			. '<style>'
			// Erst alles zuruecksetzen, dann aufbauen. Ohne das schlaegt das
			// Theme der Kundenseite durch: ein "p { font-size:34px;
			// line-height:3 }" macht aus dem Hinweis einen Block, der aus dem
			// Bild ragt. Die Reihenfolge zaehlt — gleiche Gewichtung, der
			// spaetere Block gewinnt.
			. '#nlc-backup-banner,#nlc-backup-banner *{'
			. 'box-sizing:border-box;margin:0;padding:0;border:0;outline:0;'
			. 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;'
			. 'font-size:13px;line-height:1.45;font-weight:400;font-style:normal;'
			. 'letter-spacing:normal;word-spacing:normal;text-transform:none;text-align:left;'
			. 'text-decoration:none;text-indent:0;text-shadow:none;white-space:normal;'
			. 'position:static;float:none;vertical-align:baseline;'
			. 'width:auto;height:auto;min-width:0;min-height:0;max-width:none;max-height:none;'
			. 'background:none;box-shadow:none;border-radius:0;opacity:1;visibility:visible;'
			. 'transform:none;letter-spacing:normal}'
			. '#nlc-backup-banner{position:fixed;right:16px;bottom:16px;z-index:999999;max-width:330px;'
			. 'display:flex;gap:10px;align-items:flex-start;padding:12px 14px;'
			. 'background:#17171b;color:#f1f1f4;border:1px solid rgba(255,255,255,.12);border-radius:12px;'
			. 'box-shadow:0 8px 28px rgba(0,0,0,.35)}'
			. '#nlc-backup-banner img{display:block;width:20px;height:20px;object-fit:contain;flex:0 0 auto;margin-top:1px;border-radius:4px}'
			. '#nlc-backup-banner .nlc-bb-dot{display:block;flex:0 0 auto;width:9px;height:9px;margin-top:5px;'
			. 'border-radius:50%;background:' . esc_attr( $accent ) . ';animation:nlc-bb-pulse 1.8s ease-in-out infinite}'
			. '#nlc-backup-banner .nlc-bb-body{display:block;flex:1 1 auto;min-width:0}'
			. '#nlc-backup-banner .nlc-bb-text{display:block;color:#dcdce2}'
			. '#nlc-backup-banner .nlc-bb-step{display:block;margin-top:5px;font-size:12px;color:#8b8b96}'
			. '#nlc-backup-banner .nlc-bb-bar{display:block;margin-top:6px;height:3px;border-radius:2px;'
			. 'background:rgba(255,255,255,.14);overflow:hidden}'
			. '#nlc-backup-banner .nlc-bb-bar > span{display:block;height:3px;border-radius:2px;'
			. 'background:' . esc_attr( $accent ) . '}'
			. '#nlc-backup-banner .nlc-bb-close{display:block;flex:0 0 auto;appearance:none;cursor:pointer;'
			. 'color:#8b8b96;font-size:17px;line-height:1;padding:0 2px;margin:-2px -4px 0 0}'
			. '#nlc-backup-banner .nlc-bb-close:hover{color:#f1f1f4}'
			. '@keyframes nlc-bb-pulse{0%,100%{opacity:1}50%{opacity:.28}}'
			. '@media (prefers-reduced-motion:reduce){#nlc-backup-banner .nlc-bb-dot{animation:none}}'
			. '@media (max-width:480px){#nlc-backup-banner{left:16px;right:16px;max-width:none}}'
			. '</style>'
			. $bild
			. '<div class="nlc-bb-body">'
			. '<p class="nlc-bb-text">' . esc_html( $text ) . '</p>'
			. ( '' !== $fortschritt
				? '<p class="nlc-bb-step">' . esc_html( $fortschritt ) . '</p>'
					. ( null !== $anteil
						? '<span class="nlc-bb-bar"><span style="width:' . (int) $anteil . '%"></span></span>'
						: '' )
				: '' )
			. '</div>'
			. '<button type="button" class="nlc-bb-close" aria-label="Hinweis ausblenden">&times;</button>'
			. '<script>(function(){'
			. 'var b=document.getElementById("nlc-backup-banner");if(!b)return;'
			. 'var k=b.getAttribute("data-key");'
			// sessionStorage kann in privaten Fenstern werfen - dann bleibt der
			// Hinweis eben stehen, statt dass die Seite einen Fehler wirft.
			. 'try{if(sessionStorage.getItem(k)){b.parentNode.removeChild(b);return;}}catch(e){}'
			. 'b.querySelector(".nlc-bb-close").addEventListener("click",function(){'
			. 'try{sessionStorage.setItem(k,"1");}catch(e){}'
			. 'b.parentNode.removeChild(b);});'
			. '})();</script>'
			. '</div>';
	}
}
