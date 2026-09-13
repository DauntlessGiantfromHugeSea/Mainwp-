<?php

declare( strict_types = 1 );

namespace NorthLab\Core;

/**
 * CSRF-Schutz für alle schreibenden Formulare.
 */
final class Csrf {

	public static function token(): string {
		$token = Session::get( '_csrf' );

		if ( ! is_string( $token ) || 64 !== strlen( $token ) ) {
			$token = bin2hex( random_bytes( 32 ) );
			Session::set( '_csrf', $token );
		}

		return $token;
	}

	public static function check( ?string $provided ): bool {
		$token = Session::get( '_csrf' );

		return is_string( $token ) && is_string( $provided ) && '' !== $provided && hash_equals( $token, $provided );
	}
}
