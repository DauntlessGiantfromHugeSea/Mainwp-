<?php
defined( 'ABSPATH' ) || exit;

/**
 * Branding der Agentur auf der Kundenseite.
 *
 * Drei Teile, einzeln schaltbar:
 *
 *  1. Support-Leiste im Backend — Logo, Ansprache und Kontaktwege oben im
 *     Adminbereich. Ausgabe an "in_admin_header", also vor #wpbody und damit
 *     vor allen admin_notices; bewusst ohne die Klassen notice/updated/error,
 *     weil WordPress solche Boxen sonst per JavaScript zu den uebrigen
 *     Hinweisen schiebt.
 *  2. Kundenlogo auf der Anmeldeseite statt des WordPress-Logos.
 *  3. Schmale Agenturleiste ganz oben auf der Anmeldeseite.
 *
 * Alles wird vom Panel gesetzt; hier steht nur, wie es aussieht.
 */
class NLC_Branding {

	const OPTION = 'nlc_branding';

	/**
	 * Wer die Support-Leiste zu sehen bekommt.
	 *
	 * @return array<string,string>
	 */
	public static function audiences() {
		return array(
			'read'           => 'Alle angemeldeten Benutzer',
			'edit_posts'     => 'Redakteure und höher',
			'manage_options' => 'Nur Administratoren',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			// Support-Leiste im Backend
			'bar_enabled'       => false,
			'logo'              => '',
			'site'              => '',
			'email'             => '',
			'phone'             => '',
			'text'              => 'Kontakt bei Fragen oder Problemen:',
			'capability'        => 'read',

			// Anmeldeseite
			'login_bar_enabled' => false,
			'login_bar_text'    => 'Betreut von',
			'login_logo'        => '',
			'login_logo_height' => 72,
			'login_logo_link'   => '',

			// Gemeinsame Farben
			'accent'            => '#e8917a',
			'tint'              => '#fdf3f0',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function state() {
		$stored = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * @param array<string,mixed> $values
	 * @return array<string,mixed>
	 */
	public static function save( array $values ) {
		$state = wp_parse_args( $values, self::state() );

		$state['bar_enabled']       = ! empty( $state['bar_enabled'] );
		$state['login_bar_enabled'] = ! empty( $state['login_bar_enabled'] );

		$state['logo']            = self::url( $state['logo'] );
		$state['site']            = self::url( $state['site'] );
		$state['login_logo']      = self::url( $state['login_logo'] );
		$state['login_logo_link'] = self::url( $state['login_logo_link'] );

		$state['email']          = sanitize_email( (string) $state['email'] );
		$state['phone']          = self::phone( (string) $state['phone'] );
		$state['text']           = sanitize_text_field( (string) $state['text'] );
		$state['login_bar_text'] = sanitize_text_field( (string) $state['login_bar_text'] );

		$defaults        = self::defaults();
		$state['accent'] = NLC_Maintenance_Mode::sanitize_color( (string) $state['accent'], $defaults['accent'] );
		$state['tint']   = NLC_Maintenance_Mode::sanitize_color( (string) $state['tint'], $defaults['tint'] );

		$state['login_logo_height'] = min( 240, max( 24, (int) $state['login_logo_height'] ) );

		$audiences           = self::audiences();
		$state['capability'] = isset( $audiences[ $state['capability'] ] ) ? (string) $state['capability'] : 'read';

		update_option( self::OPTION, $state, true );

		return $state;
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	protected static function url( $value ) {
		$value = trim( (string) $value );

		return '' === $value ? '' : (string) esc_url_raw( $value );
	}

	/**
	 * Telefonnummer fuer die Anzeige.
	 *
	 * @param string $value
	 * @return string
	 */
	public static function phone( $value ) {
		return trim( (string) preg_replace( '/[^0-9+()\/\- ]/', '', (string) $value ) );
	}

	/**
	 * Fassung fuer den tel:-Link — dort stoert alles ausser Plus und Ziffern.
	 *
	 * @param string $value
	 * @return string
	 */
	public static function dial( $value ) {
		$value = trim( (string) $value );

		// "+49 (0)30" heisst: die 0 in Klammern entfaellt beim internationalen
		// Waehlen. Sie stehen zu lassen ergaebe eine Nummer, die nicht durchgeht.
		// Kein str_starts_with — das Plugin laeuft laut Kopf ab PHP 7.4.
		$plus = 0 === strpos( $value, '+' );
		if ( $plus ) {
			$value = preg_replace( '/\(\s*0\s*\)/', '', $value );
		}

		$digits = preg_replace( '/[^0-9]/', '', (string) $value );

		// Ein Plus ergibt nur ganz vorn Sinn.
		return $plus ? '+' . $digits : (string) $digits;
	}

	public function hooks() {
		add_action( 'in_admin_header', array( $this, 'render_bar' ) );

		add_action( 'login_enqueue_scripts', array( $this, 'render_login_logo' ) );
		add_action( 'login_footer', array( $this, 'render_login_bar' ) );
		add_filter( 'login_headerurl', array( $this, 'login_logo_url' ) );
		add_filter( 'login_headertext', array( $this, 'login_logo_text' ) );
	}

	/* ------------------------------------------------- Support-Leiste */

	public function render_bar() {
		$state = self::state();

		if ( empty( $state['bar_enabled'] ) || ! current_user_can( (string) $state['capability'] ) ) {
			return;
		}

		echo self::bar_markup( $state ); // phpcs:ignore WordPress.Security.EscapeOutput -- dort maskiert.
	}

	/**
	 * @param array<string,mixed> $state
	 * @return string
	 */
	public static function bar_markup( array $state ) {
		$state = wp_parse_args( $state, self::defaults() );

		$links = self::contact_links( $state );

		// Ohne einen einzigen Kontaktweg waere die Leiste nur eine leere Zeile.
		if ( '' === $links ) {
			return '';
		}

		$logo = self::url( $state['logo'] );
		$text = (string) $state['text'];

		return '<div id="nl-supportbar" role="complementary" aria-label="Support">'
			. ( '' !== $logo ? '<img class="nl-supportbar__logo" src="' . esc_url( $logo ) . '" alt="" />' : '' )
			. ( '' !== $text ? '<span class="nl-supportbar__text">' . esc_html( $text ) . '</span>' : '' )
			. '<nav class="nl-supportbar__links">' . $links . '</nav>'
			. '</div>'
			. self::bar_styles( $state );
	}

	/**
	 * @param array<string,mixed> $state
	 * @return string
	 */
	protected static function contact_links( array $state ) {
		$site  = self::url( $state['site'] );
		$email = sanitize_email( (string) $state['email'] );
		$phone = self::phone( (string) $state['phone'] );
		$out   = '';

		if ( '' !== $site ) {
			$out .= '<a href="' . esc_url( $site ) . '" target="_blank" rel="noopener">'
				. self::icon( 'web' ) . '<span>' . esc_html( self::pretty( $site ) ) . '</span></a>';
		}
		if ( '' !== $email ) {
			$out .= '<a href="mailto:' . esc_attr( $email ) . '">'
				. self::icon( 'mail' ) . '<span>' . esc_html( $email ) . '</span></a>';
		}
		if ( '' !== $phone ) {
			$out .= '<a href="tel:' . esc_attr( self::dial( $phone ) ) . '">'
				. self::icon( 'tel' ) . '<span>' . esc_html( $phone ) . '</span></a>';
		}

		return $out;
	}

	/* --------------------------------------------------- Anmeldeseite */

	public function render_login_logo() {
		$state = self::state();
		$logo  = self::url( $state['login_logo'] );

		if ( '' === $logo ) {
			return;
		}

		$height = (int) $state['login_logo_height'];
		$url    = esc_url( $logo );

		// WordPress setzt hier fest 84x84 Pixel und seine eigene Grafik — beides muss weg.
		echo '<style id="nl-login-logo">
#login h1 a, .login h1 a{
	background-image: url("' . $url . '");
	background-size: contain;
	background-position: center center;
	background-repeat: no-repeat;
	width: 100%;
	height: ' . $height . 'px;
	margin: 0 auto 22px;
	padding: 0;
}
</style>';
	}

	/**
	 * @param string $url
	 * @return string
	 */
	public function login_logo_url( $url ) {
		$state = self::state();

		if ( '' === self::url( $state['login_logo'] ) ) {
			return $url;
		}

		$link = self::url( $state['login_logo_link'] );

		return '' !== $link ? $link : home_url( '/' );
	}

	/**
	 * @param string $text
	 * @return string
	 */
	public function login_logo_text( $text ) {
		$state = self::state();

		return '' === self::url( $state['login_logo'] ) ? $text : get_bloginfo( 'name' );
	}

	public function render_login_bar() {
		$state = self::state();

		if ( empty( $state['login_bar_enabled'] ) ) {
			return;
		}

		echo self::login_bar_markup( $state ); // phpcs:ignore WordPress.Security.EscapeOutput -- dort maskiert.
	}

	/**
	 * @param array<string,mixed> $state
	 * @return string
	 */
	public static function login_bar_markup( array $state ) {
		$state = wp_parse_args( $state, self::defaults() );

		$logo = self::url( $state['logo'] );
		$site = self::url( $state['site'] );
		$text = (string) $state['login_bar_text'];

		if ( '' === $logo && '' === $site ) {
			return '';
		}

		$link = '' !== $site
			? '<a class="nl-loginbar__link" href="' . esc_url( $site ) . '" target="_blank" rel="noopener">'
				. self::icon( 'web' ) . '<span>' . esc_html( self::pretty( $site ) ) . '</span></a>'
			: '';

		return '<div id="nl-loginbar">'
			. ( '' !== $logo ? '<img class="nl-loginbar__logo" src="' . esc_url( $logo ) . '" alt="" />' : '' )
			. ( '' !== $text ? '<span class="nl-loginbar__text">' . esc_html( $text ) . '</span>' : '' )
			. $link
			. '</div>'
			. self::login_bar_styles( $state );
	}

	/* -------------------------------------------------------- Bausteine */

	/**
	 * @param string $url
	 * @return string
	 */
	protected static function pretty( $url ) {
		return (string) preg_replace( '#^https?://(www\.)?#', '', untrailingslashit( $url ) );
	}

	/**
	 * Symbole als Inline-SVG statt Emoji — sonst sieht es auf jedem System anders aus.
	 *
	 * @param string $which
	 * @return string
	 */
	protected static function icon( $which ) {
		$icons = array(
			'web'  => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 2.5 15.4 0 18M12 3c-2.5 2.6-2.5 15.4 0 18"/>',
			'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 6.5 8.5 6 8.5-6"/>',
			'tel'  => '<path d="M6.5 3.5h3l1.5 4-2 1.5a12 12 0 0 0 6 6l1.5-2 4 1.5v3a2 2 0 0 1-2.2 2A17 17 0 0 1 4.5 5.7a2 2 0 0 1 2-2.2Z"/>',
		);

		return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ( $icons[ $which ] ?? '' ) . '</svg>';
	}

	/**
	 * @param array<string,mixed> $state
	 * @return string
	 */
	protected static function bar_styles( array $state ) {
		$defaults = self::defaults();
		$accent   = esc_attr( NLC_Maintenance_Mode::sanitize_color( (string) $state['accent'], $defaults['accent'] ) );
		$tint     = esc_attr( NLC_Maintenance_Mode::sanitize_color( (string) $state['tint'], $defaults['tint'] ) );

		return <<<CSS
<style id="nl-supportbar-css">
#nl-supportbar{
	--nl-accent: {$accent};
	--nl-tint:   {$tint};
	--nl-ink:    #1f1520;
	--nl-muted:  #6b6570;
	--nl-deep:   #2a0c2e;

	position: relative;
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 10px 18px;
	box-sizing: border-box;
	margin: 14px 20px 4px 0;
	padding: 12px 18px 12px 16px;
	background: var(--nl-tint);
	border: 0;
	border-left: 6px solid var(--nl-accent);
	border-radius: 4px;
	box-shadow: 0 1px 2px rgba(31,21,32,.06);
	font-size: 13px;
	line-height: 1.4;
	color: var(--nl-ink);
}
#nl-supportbar .nl-supportbar__logo{
	height: 26px; width: auto; max-width: 130px;
	object-fit: contain; display: block; flex: none;
}
#nl-supportbar .nl-supportbar__text{
	font-weight: 600; color: var(--nl-deep); margin-right: -6px;
}
#nl-supportbar .nl-supportbar__links{
	display: flex; align-items: center; flex-wrap: wrap; gap: 6px 16px;
}
#nl-supportbar .nl-supportbar__links a{
	display: inline-flex; align-items: center; gap: 7px;
	text-decoration: none; color: var(--nl-muted);
	border-radius: 4px; padding: 2px; transition: color .15s ease;
}
#nl-supportbar .nl-supportbar__links a:hover,
#nl-supportbar .nl-supportbar__links a:focus{
	color: var(--nl-deep); text-decoration: underline; text-underline-offset: 3px;
}
#nl-supportbar .nl-supportbar__links a:focus-visible{
	outline: 2px solid var(--nl-accent); outline-offset: 2px;
}
#nl-supportbar svg{
	width: 15px; height: 15px; flex: none;
	fill: none; stroke: var(--nl-accent); stroke-width: 1.6;
	stroke-linecap: round; stroke-linejoin: round;
}

@media screen and (max-width: 782px){
	#nl-supportbar{ margin-right: 10px; gap: 8px 12px; font-size: 14px; }
	#nl-supportbar .nl-supportbar__text{ display: none; }
}
@media screen and (max-width: 480px){
	#nl-supportbar .nl-supportbar__links{ flex-direction: column; align-items: flex-start; }
}

#wpbody-content > .notice:first-child,
#wpbody-content > .updated:first-child,
#wpbody-content > .error:first-child{ margin-top: 10px; }

@media print{ #nl-supportbar{ display: none; } }
</style>
CSS;
	}

	/**
	 * @param array<string,mixed> $state
	 * @return string
	 */
	protected static function login_bar_styles( array $state ) {
		$defaults = self::defaults();
		$accent   = esc_attr( NLC_Maintenance_Mode::sanitize_color( (string) $state['accent'], $defaults['accent'] ) );
		$tint     = esc_attr( NLC_Maintenance_Mode::sanitize_color( (string) $state['tint'], $defaults['tint'] ) );

		return <<<CSS
<style id="nl-loginbar-css">
#nl-loginbar{
	--nl-accent: {$accent};
	--nl-tint:   {$tint};
	--nl-muted:  #6b6570;
	--nl-deep:   #2a0c2e;

	position: fixed;
	top: 0; left: 0; right: 0;
	z-index: 100;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-wrap: wrap;
	gap: 6px 14px;
	box-sizing: border-box;
	padding: 10px 16px;
	background: var(--nl-tint);
	border-bottom: 3px solid var(--nl-accent);
	font-size: 13px;
	line-height: 1.4;
	color: var(--nl-deep);
}
#nl-loginbar .nl-loginbar__logo{
	height: 22px; width: auto; max-width: 110px;
	object-fit: contain; display: block; flex: none;
}
#nl-loginbar .nl-loginbar__text{ font-weight: 600; }
#nl-loginbar .nl-loginbar__link{
	display: inline-flex; align-items: center; gap: 7px;
	text-decoration: none; color: var(--nl-muted);
	transition: color .15s ease;
}
#nl-loginbar .nl-loginbar__link:hover,
#nl-loginbar .nl-loginbar__link:focus{
	color: var(--nl-deep); text-decoration: underline; text-underline-offset: 3px;
}
#nl-loginbar .nl-loginbar__link:focus-visible{
	outline: 2px solid var(--nl-accent); outline-offset: 2px;
}
#nl-loginbar svg{
	width: 15px; height: 15px; flex: none;
	fill: none; stroke: var(--nl-accent); stroke-width: 1.6;
	stroke-linecap: round; stroke-linejoin: round;
}

/* Platz schaffen, damit die Leiste nichts ueberdeckt. */
body.login{ padding-top: 52px; }
@media screen and (max-width: 420px){
	body.login{ padding-top: 76px; }
}

/* WordPress faerbt die Anmeldeseite im dunklen Modus um — die Leiste bleibt hell. */
@media (prefers-color-scheme: dark){
	#nl-loginbar{ --nl-tint: {$tint}; --nl-muted: #6b6570; }
}
</style>
CSS;
	}
}
