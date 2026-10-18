<?php
/**
 * NM_DB — a small mysqli connection of our own (not $wpdb): the engine
 * also runs outside WordPress, needs unbuffered reads for large tables
 * and must set its own session variables.
 *
 * mysqli reporting is turned off so PHP 7.4 and PHP 8.1+ behave the same
 * (8.1 throws by default); every call checks the result itself.
 */

defined( 'ABSPATH' ) || defined( 'NM_STANDALONE' ) || exit;

final class NM_DB {

	/** @var mysqli|null */
	public $link = null;
	public $error = '';
	public $errno = 0;

	/** Split a WordPress DB_HOST value into host, port and socket. */
	public static function parse_host( string $host ): array {
		$port   = 0;
		$socket = null;
		$host   = trim( $host );
		if ( preg_match( '/^\[([^\]]+)\](?::(\d+))?$/', $host, $m ) ) {
			// [IPv6]:port
			$host = $m[1];
			$port = isset( $m[2] ) ? (int) $m[2] : 0;
		} elseif ( substr_count( $host, ':' ) === 1 ) {
			list( $h, $rest ) = explode( ':', $host, 2 );
			if ( ctype_digit( $rest ) ) {
				$port = (int) $rest;
			} elseif ( '' !== $rest ) {
				$socket = $rest;
			}
			$host = '' === $h ? 'localhost' : $h;
		}
		return array( $host, $port, $socket );
	}

	public function connect( string $host, string $user, string $pass, string $name ): bool {
		if ( ! class_exists( 'mysqli' ) ) {
			$this->error = 'mysqli';
			return false;
		}
		mysqli_report( MYSQLI_REPORT_OFF );
		list( $h, $port, $socket ) = self::parse_host( $host );
		$link                      = mysqli_init();
		if ( ! $link ) {
			$this->error = 'mysqli_init';
			return false;
		}
		@$link->options( MYSQLI_OPT_CONNECT_TIMEOUT, 10 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$flags = 0;
		if ( defined( 'MYSQL_CLIENT_FLAGS' ) ) {
			$flags = (int) MYSQL_CLIENT_FLAGS;
		}
		$ok = @$link->real_connect( $h, $user, $pass, $name, $port ? $port : null, $socket, $flags ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $ok ) {
			$this->errno = (int) mysqli_connect_errno();
			$this->error = (string) mysqli_connect_error();
			return false;
		}
		$this->link = $link;
		if ( ! @$link->set_charset( 'utf8mb4' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@$link->set_charset( 'utf8' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$this->query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
		return true;
	}

	public function close(): void {
		if ( $this->link ) {
			@$this->link->close(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$this->link = null;
	}

	/** @return mysqli_result|bool */
	public function query( string $sql, bool $unbuffered = false ) {
		$this->error = '';
		$this->errno = 0;
		if ( ! $this->link ) {
			$this->error = 'not connected';
			return false;
		}
		$res         = @$this->link->query( $sql, $unbuffered ? MYSQLI_USE_RESULT : MYSQLI_STORE_RESULT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $res ) {
			$this->errno = (int) $this->link->errno;
			$this->error = (string) $this->link->error;
		}
		return $res;
	}

	/** All rows as associative arrays (false on error). */
	public function rows( string $sql ) {
		$res = $this->query( $sql );
		if ( ! ( $res instanceof mysqli_result ) ) {
			return false;
		}
		$out = array();
		while ( $row = $res->fetch_assoc() ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$out[] = $row;
		}
		$res->free();
		return $out;
	}

	/** First column of the first row, or null. */
	public function one( string $sql ) {
		$res = $this->query( $sql );
		if ( ! ( $res instanceof mysqli_result ) ) {
			return null;
		}
		$row = $res->fetch_row();
		$res->free();
		return $row ? $row[0] : null;
	}

	public function esc( string $s ): string {
		return $this->link->real_escape_string( $s );
	}

	/** `name` with backticks doubled. */
	public static function ident( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/** 'string' quoted for LIKE (escape % and _ first). */
	public function like( string $prefix ): string {
		return "'" . $this->esc( addcslashes( $prefix, '\\%_' ) ) . "%'";
	}

	public function server_version(): string {
		return (string) $this->one( 'SELECT VERSION()' );
	}

	public function max_packet(): int {
		return (int) $this->one( 'SELECT @@max_allowed_packet' );
	}
}
