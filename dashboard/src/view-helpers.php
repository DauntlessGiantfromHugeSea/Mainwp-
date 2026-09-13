<?php
/**
 * Darstellungs-Helfer für die Templates.
 */

declare( strict_types = 1 );

if ( ! function_exists( 'nl_status_badge' ) ) {
	/**
	 * Verbindungszustand einer Seite.
	 *
	 * @param array<string,mixed> $site
	 */
	function nl_status_badge( array $site ): string {
		if ( ! empty( $site['is_paused'] ) ) {
			return '<span class="badge"><span class="dot"></span>Pausiert</span>';
		}

		return match ( (string) $site['status'] ) {
			'connected' => '<span class="badge ok"><span class="dot"></span>Verbunden</span>',
			'error'     => '<span class="badge bad"><span class="dot"></span>Fehler</span>',
			default     => '<span class="badge warn"><span class="dot"></span>Ausstehend</span>',
		};
	}
}

if ( ! function_exists( 'nl_uptime_badge' ) ) {
	/**
	 * @param array<string,mixed> $site
	 */
	function nl_uptime_badge( array $site ): string {
		return match ( (string) $site['uptime_status'] ) {
			'up'    => '<span class="badge ok"><span class="dot"></span>Online</span>',
			'down'  => '<span class="badge bad"><span class="dot"></span>Offline</span>',
			default => '<span class="badge"><span class="dot"></span>Unbekannt</span>',
		};
	}
}

if ( ! function_exists( 'nl_percent_class' ) ) {
	function nl_percent_class( float $percent ): string {
		if ( $percent >= 99.5 ) {
			return 'ok';
		}
		return $percent >= 98.0 ? 'warn' : 'bad';
	}
}

if ( ! function_exists( 'nl_score_class' ) ) {
	function nl_score_class( int $score ): string {
		if ( $score >= 85 ) {
			return 'ok';
		}
		return $score >= 65 ? 'warn' : 'bad';
	}
}

if ( ! function_exists( 'nl_spark' ) ) {
	/**
	 * Kleine Balkengrafik der täglichen Verfügbarkeit.
	 *
	 * @param array<int,array{day:string,percent:float,downtime:int}> $series
	 */
	function nl_spark( array $series ): string {
		if ( ! $series ) {
			return '<span class="muted small">keine Daten</span>';
		}

		$bars = '';

		foreach ( $series as $entry ) {
			$percent = (float) $entry['percent'];
			$height  = max( 3, (int) round( $percent / 100 * 24 ) );
			$class   = nl_percent_class( $percent );

			$bars .= sprintf(
				'<i class="%s" style="height:%dpx" title="%s: %s %%"></i>',
				e( 100.0 === $percent ? '' : $class ),
				$height,
				e( $entry['day'] ),
				e( nl_number( $percent, 2 ) )
			);
		}

		return '<span class="spark">' . $bars . '</span>';
	}
}

if ( ! function_exists( 'nl_meter' ) ) {
	function nl_meter( float $percent, string $class = '' ): string {
		$percent = max( 0.0, min( 100.0, $percent ) );

		return sprintf(
			'<span class="meter"><span class="%s" style="width:%s%%"></span></span>',
			e( $class ),
			e( (string) round( $percent, 2 ) )
		);
	}
}

if ( ! function_exists( 'nl_level_badge' ) ) {
	function nl_level_badge( string $level ): string {
		return match ( $level ) {
			'error'   => '<span class="badge bad">Fehler</span>',
			'warning' => '<span class="badge warn">Warnung</span>',
			default   => '<span class="badge">Info</span>',
		};
	}
}

if ( ! function_exists( 'nl_update_type' ) ) {
	function nl_update_type( string $type ): string {
		return match ( $type ) {
			'core'        => 'WordPress',
			'plugin'      => 'Plugin',
			'theme'       => 'Theme',
			'translation' => 'Übersetzung',
			default       => $type,
		};
	}
}

if ( ! function_exists( 'nl_host' ) ) {
	function nl_host( string $url ): string {
		return (string) ( parse_url( $url, PHP_URL_HOST ) ?: $url );
	}
}

if ( ! function_exists( 'nl_old' ) ) {
	/**
	 * Wert aus zuvor abgesendeten Formulardaten.
	 *
	 * @param array<string,mixed> $old
	 */
	function nl_old( array $old, string $key, string $default = '' ): string {
		$value = $old[ $key ] ?? $default;

		return is_scalar( $value ) ? (string) $value : $default;
	}
}
