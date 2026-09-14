<?php
defined( 'ABSPATH' ) || exit;

/**
 * Wartungsmodus: zeigt Besuchern eine gebrandete Seite mit HTTP 503,
 * während Redaktion und Administration normal weiterarbeiten.
 *
 * Nicht zu verwechseln mit NLC_Maintenance — das sind die Aufräumaufgaben.
 */
class NLC_Maintenance_Mode {

	const OPTION = 'nlc_maintenance_mode';

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'     => false,
			'until'       => 0,      // Unix-Zeit; 0 bedeutet "bis auf Widerruf".
			'headline'    => 'Wartungsmodus',
			'message'     => 'Wir sind gleich wieder da.',
			'color'       => '#f9907a',
			'logo'        => '',
			'retry_after' => 3600,
			'started_at'  => 0,
		);
	}

	/**
	 * Aktueller Zustand, bereits um abgelaufene Fenster bereinigt.
	 *
	 * @return array<string,mixed>
	 */
	public static function state() {
		$stored = get_option( self::OPTION, array() );
		$state  = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );

		// Ein abgelaufenes Fenster gilt sofort als beendet, auch wenn kein Cron lief.
		if ( $state['enabled'] && $state['until'] > 0 && $state['until'] <= time() ) {
			$state['enabled'] = false;
			update_option( self::OPTION, $state, true );
		}

		return $state;
	}

	/**
	 * @param array<string,mixed> $values
	 * @return array<string,mixed> Neuer Zustand.
	 */
	public static function save( array $values ) {
		$current = self::state();
		$state   = wp_parse_args( $values, $current );

		$state['enabled']     = ! empty( $state['enabled'] );
		$state['until']       = max( 0, (int) $state['until'] );
		$state['headline']    = sanitize_text_field( (string) $state['headline'] );
		$state['message']     = sanitize_textarea_field( (string) $state['message'] );
		$state['color']       = self::sanitize_color( (string) $state['color'] );
		$state['logo']        = $state['logo'] ? esc_url_raw( (string) $state['logo'] ) : '';
		$state['retry_after'] = min( 86400, max( 60, (int) $state['retry_after'] ) );

		if ( '' === $state['headline'] ) {
			$state['headline'] = 'Wartungsmodus';
		}

		// Einschaltzeitpunkt nur beim Wechsel von aus auf an neu setzen.
		if ( $state['enabled'] && empty( $current['enabled'] ) ) {
			$state['started_at'] = time();
		} elseif ( ! $state['enabled'] ) {
			$state['started_at'] = 0;
		}

		update_option( self::OPTION, $state, true );

		return $state;
	}

	/**
	 * @param string $value
	 * @return string
	 */
	public static function sanitize_color( $value ) {
		$value = trim( (string) $value );

		if ( preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ) {
			return strtolower( $value );
		}
		if ( preg_match( '/^#[0-9a-fA-F]{3}$/', $value ) ) {
			$value = strtolower( $value );
			return '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
		}

		return '#f9907a';
	}

	public function hooks() {
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 0 );
	}

	/**
	 * @return bool
	 */
	public static function should_hide() {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return false;
		}
		if ( is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
			return false;
		}
		// Angemeldete Redakteure sollen die Seite weiter bearbeiten koennen.
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return false;
		}

		$state = self::state();

		return ! empty( $state['enabled'] );
	}

	public function maybe_render() {
		if ( ! self::should_hide() ) {
			return;
		}

		$state = self::state();

		if ( ! headers_sent() ) {
			status_header( 503 );
			nocache_headers();
			header( 'Retry-After: ' . (int) $state['retry_after'] );
			header( 'Content-Type: text/html; charset=utf-8' );
		}

		echo self::page( $state ); // phpcs:ignore WordPress.Security.EscapeOutput -- in page() escaped.
		exit;
	}

	/**
	 * Vollständiges, eigenständiges HTML — keine Themes, keine externen Dateien.
	 *
	 * @param array<string,mixed> $state
	 * @return string
	 */
	public static function page( array $state ) {
		$state    = wp_parse_args( $state, self::defaults() );
		$color    = self::sanitize_color( (string) $state['color'] );
		$headline = (string) $state['headline'];
		$message  = (string) $state['message'];
		$logo     = (string) $state['logo'];
		$name     = get_bloginfo( 'name' );

		$logoTag = '' !== $logo
			? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $name ) . '" class="logo">'
			: '<div class="wordmark">' . esc_html( $name ) . '</div>';

		$until = '';
		if ( ! empty( $state['until'] ) && $state['until'] > time() ) {
			$until = sprintf(
				'<p class="until">Voraussichtlich bis %s Uhr</p>',
				esc_html( wp_date( 'H:i', (int) $state['until'] ) )
			);
		}

		return '<!doctype html>
<html lang="' . esc_attr( str_replace( '_', '-', get_locale() ) ) . '">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>' . esc_html( $headline ) . ' — ' . esc_html( $name ) . '</title>
<style>
	:root { --brand: ' . esc_attr( $color ) . '; }
	* { box-sizing: border-box; }
	html, body { height: 100%; }
	body {
		margin: 0; background: #08080a; color: #f1f1f4;
		font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
		display: flex; align-items: center; justify-content: center;
		padding: 32px 20px; text-align: center;
		background-image:
			radial-gradient(circle at 20% 15%, color-mix(in srgb, var(--brand) 22%, transparent), transparent 55%),
			radial-gradient(circle at 82% 85%, rgba(124, 199, 255, .12), transparent 55%);
	}
	.card {
		width: 100%; max-width: 520px;
		background: rgba(24, 24, 29, .72);
		border: 1px solid rgba(255, 255, 255, .1);
		border-radius: 18px; padding: 44px 34px;
		box-shadow: 0 1px 2px rgba(0, 0, 0, .4), 0 24px 64px rgba(0, 0, 0, .45);
	}
	.logo { max-width: 190px; max-height: 64px; margin: 0 auto 26px; display: block; }
	.wordmark { font-size: 19px; font-weight: 700; letter-spacing: -.02em; margin-bottom: 26px; }
	h1 {
		margin: 0 0 12px; font-size: 27px; line-height: 1.25;
		letter-spacing: -.02em; color: var(--brand);
	}
	p { margin: 0; color: #a3a3af; }
	.until { margin-top: 16px; font-size: 14px; color: #6c6c7a; }
	.pulse {
		width: 9px; height: 9px; border-radius: 50%; background: var(--brand);
		display: inline-block; margin-right: 9px; vertical-align: middle;
		animation: pulse 1.8s ease-in-out infinite;
	}
	@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .28; } }
	@media (prefers-reduced-motion: reduce) { .pulse { animation: none; } }
	@media (max-width: 420px) {
		.card { padding: 34px 22px; }
		h1 { font-size: 23px; }
	}
</style>
</head>
<body>
	<main class="card">
		' . $logoTag . '
		<h1><span class="pulse"></span>' . esc_html( $headline ) . '</h1>
		' . ( '' !== $message ? '<p>' . nl2br( esc_html( $message ) ) . '</p>' : '' ) . '
		' . $until . '
	</main>
</body>
</html>';
	}
}
