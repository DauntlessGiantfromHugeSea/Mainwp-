<?php

declare( strict_types = 1 );

namespace NorthLab\Core;

/**
 * Mailversand über mail() oder einen SMTP-Server (ohne externe Abhängigkeiten).
 */
final class Mailer {

	public static function send( string $to, string $subject, string $html, ?string $plain = null ): bool {
		if ( ! filter_var( $to, FILTER_VALIDATE_EMAIL ) ) {
			return false;
		}

		$fromEmail = (string) Config::get( 'mail.from_email', '' );
		if ( '' === $fromEmail ) {
			$fromEmail = 'northlab@' . (string) ( parse_url( (string) Config::get( 'app.url', 'http://localhost' ), PHP_URL_HOST ) ?: 'localhost' );
		}
		$fromName = (string) Config::get( 'mail.from_name', 'NorthLab' );

		$transport = (string) Config::get( 'mail.transport', 'mail' );

		$sent = 'smtp' === $transport
			? self::sendSmtp( $to, $subject, $html, $fromEmail, $fromName )
			: self::sendMail( $to, $subject, $html, $fromEmail, $fromName );

		if ( ! $sent ) {
			Logger::error( 'Mailversand fehlgeschlagen', array( 'to' => $to, 'subject' => $subject, 'transport' => $transport ) );
		}

		return $sent;
	}

	private static function sendMail( string $to, string $subject, string $html, string $fromEmail, string $fromName ): bool {
		$headers = array(
			'MIME-Version: 1.0',
			'Content-Type: text/html; charset=UTF-8',
			'Content-Transfer-Encoding: 8bit',
			sprintf( 'From: %s <%s>', self::encodeHeader( $fromName ), $fromEmail ),
			sprintf( 'Reply-To: %s', $fromEmail ),
		);

		return @mail( $to, self::encodeHeader( $subject ), $html, implode( "\r\n", $headers ) );
	}

	private static function sendSmtp( string $to, string $subject, string $html, string $fromEmail, string $fromName ): bool {
		$host = (string) Config::get( 'mail.smtp.host', '' );
		$port = (int) Config::get( 'mail.smtp.port', 587 );
		$enc  = (string) Config::get( 'mail.smtp.encryption', 'tls' );
		$user = (string) Config::get( 'mail.smtp.user', '' );
		$pass = (string) Config::get( 'mail.smtp.pass', '' );

		if ( '' === $host ) {
			return false;
		}

		$transport = ( 'ssl' === $enc ? 'ssl://' : '' ) . $host;
		$socket    = @stream_socket_client( $transport . ':' . $port, $errno, $errstr, 20 );

		if ( ! $socket ) {
			Logger::error( 'SMTP-Verbindung fehlgeschlagen', array( 'error' => $errstr ) );
			return false;
		}

		stream_set_timeout( $socket, 20 );

		$read = static function () use ( $socket ): string {
			$data = '';
			while ( $line = fgets( $socket, 515 ) ) {
				$data .= $line;
				if ( ' ' === substr( $line, 3, 1 ) ) {
					break;
				}
			}
			return $data;
		};

		$write = static function ( string $command ) use ( $socket, $read ): string {
			fwrite( $socket, $command . "\r\n" );
			return $read();
		};

		$read();
		$hostname = (string) ( parse_url( (string) Config::get( 'app.url', 'localhost' ), PHP_URL_HOST ) ?: 'localhost' );

		$write( 'EHLO ' . $hostname );

		if ( 'tls' === $enc ) {
			$write( 'STARTTLS' );
			if ( ! @stream_socket_enable_crypto( $socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT ) ) {
				fclose( $socket );
				Logger::error( 'SMTP: STARTTLS fehlgeschlagen' );
				return false;
			}
			$write( 'EHLO ' . $hostname );
		}

		if ( '' !== $user ) {
			$write( 'AUTH LOGIN' );
			$write( base64_encode( $user ) );
			$response = $write( base64_encode( $pass ) );
			if ( ! str_starts_with( trim( $response ), '235' ) ) {
				fclose( $socket );
				Logger::error( 'SMTP-Anmeldung abgelehnt', array( 'response' => trim( $response ) ) );
				return false;
			}
		}

		$write( 'MAIL FROM:<' . $fromEmail . '>' );
		$write( 'RCPT TO:<' . $to . '>' );
		$write( 'DATA' );

		$message = implode(
			"\r\n",
			array(
				'From: ' . self::encodeHeader( $fromName ) . ' <' . $fromEmail . '>',
				'To: <' . $to . '>',
				'Subject: ' . self::encodeHeader( $subject ),
				'Date: ' . date( 'r' ),
				'MIME-Version: 1.0',
				'Content-Type: text/html; charset=UTF-8',
				'Content-Transfer-Encoding: base64',
				'',
				chunk_split( base64_encode( $html ) ),
				'.',
			)
		);

		$response = $write( $message );
		$write( 'QUIT' );
		fclose( $socket );

		return str_starts_with( trim( $response ), '250' );
	}

	private static function encodeHeader( string $value ): string {
		return preg_match( '/[\x80-\xFF]/', $value )
			? '=?UTF-8?B?' . base64_encode( $value ) . '?='
			: $value;
	}
}
