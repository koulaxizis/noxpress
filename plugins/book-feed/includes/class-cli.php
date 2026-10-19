<?php
/**
 * BKF_CLI — wp book-feed status | search | sync.
 *
 * search only reads the feed; sync changes products and asks for --yes.
 */

defined( 'ABSPATH' ) || exit;

final class BKF_CLI {

	/**
	 * Connection, products from the feed, the job and the last run.
	 *
	 * ## EXAMPLES
	 *
	 *     wp book-feed status
	 */
	public function status( $args, $assoc ): void {
		$s    = BKF_Settings::get();
		$job  = BKF_Sync::job();
		$last = BKF_Sync::last();
		$rows = array(
			array(
				'key'   => 'user',
				'value' => '' !== $s['user'] ? $s['user'] : '-',
			),
			array(
				'key'   => 'password',
				'value' => BKF_Settings::password_source(),
			),
			array(
				'key'   => 'scheme',
				'value' => '' !== BKF_Client::scheme() ? BKF_Client::scheme() : '-',
			),
			array(
				'key'   => 'rules',
				'value' => (string) count( BKF_Settings::rules() ),
			),
			array(
				'key'   => 'daily',
				'value' => $s['sync_on'] ? wp_date( 'Y-m-d H:i', BKF_Sync::next_daily_time() ) : 'off',
			),
			array(
				'key'   => 'job',
				'value' => $job ? $job['phase'] . ' — ' . self::stats( $job['stats'] ) : '-',
			),
			array(
				'key'   => 'last',
				'value' => $last ? wp_date( 'Y-m-d H:i', $last['t'] ) . ' — ' . self::stats( $last['stats'] ) . ( '' !== $last['error'] ? ' — ' . $last['error'] : '' ) : '-',
			),
		);
		WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'value' ) );
	}

	/**
	 * Searches the feed (read only).
	 *
	 * ## OPTIONS
	 *
	 * [--title=<text>]
	 * : Words of the title.
	 *
	 * [--author=<text>]
	 * : Words of a contributor's name.
	 *
	 * [--isbn=<isbn>]
	 * : ISBN.
	 *
	 * [--publisher=<id>]
	 * : Publisher id (pub_id).
	 *
	 * [--rows=<n>]
	 * : Results, up to 100.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : table, csv, json.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp book-feed search --title="μικρός πρίγκιπας"
	 */
	public function search( $args, $assoc ): void {
		$f   = array(
			'title'  => (string) ( $assoc['title'] ?? '' ),
			'author' => (string) ( $assoc['author'] ?? '' ),
			'isbn'   => (string) ( $assoc['isbn'] ?? '' ),
			'pub_id' => (string) ( $assoc['publisher'] ?? '' ),
		);
		$res = BKF_Client::query(
			array(
				'q'    => BKF_Client::book_query( $f ),
				'fl'   => BKF_Client::BOOK_FIELDS,
				'rows' => (int) ( $assoc['rows'] ?? 20 ),
			)
		);
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}
		$recs  = BKF_Record::from_docs( $res['docs'] );
		$marks = BKF_Importer::lookup( $recs );
		$rows  = array();
		foreach ( $recs as $r ) {
			$m      = $marks[ $r['id'] ] ?? null;
			$rows[] = array(
				'id'        => $r['id'],
				'isbn'      => $r['isbn'],
				'title'     => $r['title'],
				'publisher' => $r['pub_name'],
				'year'      => $r['year'] ? $r['year'] : '',
				'price'     => null === $r['price'] ? '' : $r['price'],
				'status'    => $r['status'],
				'store'     => $m ? ( $m['ours'] ? 'ours:' : 'isbn:' ) . $m['pid'] : '',
			);
		}
		WP_CLI::log( sprintf( '%d found', $res['num'] ) );
		WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, array( 'id', 'isbn', 'title', 'publisher', 'year', 'price', 'status', 'store' ) );
	}

	/**
	 * Runs the sync now, in this process (products change).
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Run without asking.
	 *
	 * ## EXAMPLES
	 *
	 *     wp book-feed sync --yes
	 */
	public function sync( $args, $assoc ): void {
		if ( ! BKF_Settings::ready() ) {
			WP_CLI::error( 'Missing user name or password (settings).' );
		}
		WP_CLI::confirm( 'Sync the products with the feed now?', $assoc );
		if ( ! BKF_Sync::job() && ! BKF_Sync::start( 'cli', false ) ) {
			WP_CLI::error( 'A sync is already running.' );
		}
		$last = BKF_Sync::run_inline(
			static function ( array $job ) {
				WP_CLI::log( $job['phase'] . ': ' . self::stats( $job['stats'] ) );
			}
		);
		if ( null === $last ) {
			WP_CLI::error( 'Nothing to run.' );
		}
		if ( '' !== ( $last['error'] ?? '' ) ) {
			WP_CLI::error( $last['error'] );
		}
		WP_CLI::success( self::stats( $last['stats'] ) );
	}

	/** Plain English stats for the console (the admin text is translated). */
	private static function stats( array $st ): string {
		$out = array();
		foreach ( array( 'checked', 'updated', 'gone', 'imported', 'exists', 'errors' ) as $k ) {
			$out[] = $k . ' ' . (int) ( $st[ $k ] ?? 0 );
		}
		return implode( ', ', $out );
	}
}
