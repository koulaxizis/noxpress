<?php
/**
 * NP_Updater — generic self-updater για ΟΛΑ τα plugins του «noxpress».
 *
 * ─────────────────────────────────────────────────────────────────────
 * SHARED DROP-IN — DO NOT EDIT PER-PLUGIN.
 * Αυτό το αρχείο αντιγράφεται byte-for-byte σε κάθε plugin του
 * οικοσυστήματος noxpress. Οι αλλαγές γίνονται στου κεντρικό master
 * και διαχέονται σε όλα τα plugins μαζί με το επόμενο release.
 * ─────────────────────────────────────────────────────────────────────
 *
 * Drop-in: ΤΟ ΙΔΙΟ αρχείο μπαίνει σε κάθε plugin του οικοσυστήματος.
 * Ρύθμιση από το bootstrapper του κάθε plugin:
 *
 *     NP_Updater::init( MY_FILE, MY_VERSION, MY_UPDATE_URL, 'My Plugin' );
 *
 * Χαρακτηριστικά:
 *  - Registry πολλαπλών plugins (slug => config) → όλα τα plugins του
 *    συστήματος μοιράζονται ένα hookup, χωρίς transient/object conflicts.
 *  - class_exists() guard: αν το ίδιο drop-in φορτώσει από δεύτερο
 *    plugin (κλασικό drop-in collision), δεν fatal — το πρώτο ορίζει.
 *  - Ξεχωριστό cache ανά plugin ('np_upd_' . md5(slug)).
 *  - Μελλοντικά plugins: μηδενική αλλαγή σε αυτό το αρχείο — μόνο init().
 *
 * v1.0.0 AUDIT FIX (#2): sanitize_key() στο $args->slug του
 * plugin_info() — trivial hardening του slug comparison.
 *
 * Remote JSON schema (το γράφει το release Action στο Pages branch):
 * {
 *   "version":      "1.1.2",
 *   "download_url": "https://github.com/<user>/<repo>/releases/download/<tag>/<slug>.zip",
 *   "details_url":  "https://noxpress.tech",
 *   "changelog":    "<p>Τι άλλαξε...</p>"
 * }
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'NP_Updater', false ) ) {

final class NP_Updater {

	/** Έκδοση του ίδιου του drop-in (για μελλοντικές αναβαθμίσεις του shared αρχείου). */
	const CLASS_VERSION = '1.0.0';

	/**
	 * Registry: plugin_basename => config.
	 *
	 * @var array[]
	 */
	private static $plugins = array();

	/** Έχουν μπει τα global hooks (μία φορά, ανεξαρτήτως πόσων plugins). */
	private static $hooks_added = false;

	/* ---------------------------------------------------------------------
	 * Registration
	 * ------------------------------------------------------------------- */

	/**
	 * Καταχώριση plugin στο updater.
	 *
	 * @param string $plugin_file __FILE__ του main plugin file.
	 * @param string $version     Τρέχουσα έκδοση (π.χ. MY_VERSION).
	 * @param string $update_url  URL του remote JSON (κενό = updates OFF).
	 * @param string $nice_name   Εμφανιζόμενο όνομα στο popup (προαιρετικό).
	 */
	public static function init( string $plugin_file, string $version, string $update_url, string $nice_name = '' ): void {

		$update_url = trim( $update_url );

		if ( '' === $update_url ) {
			return; // Δεν έχει ρυθμιστεί endpoint — updates OFF, πουθενά error.
		}

		$slug = plugin_basename( $plugin_file );

		// Double-init guard: το ίδιο plugin δεν καταχωρείται δύο φορές.
		if ( isset( self::$plugins[ $slug ] ) ) {
			return;
		}

		self::$plugins[ $slug ] = array(
			'version' => (string) $version,
			'url'     => esc_url_raw( $update_url ),
			'name'    => ( '' !== $nice_name )
				? $nice_name
				: ucwords( str_replace( array( '-', '_' ), ' ', (string) dirname( $slug ) ) ),
		);

		// Τα hooks μπαίνουν ΜΙΑ φορά, ανεξάρτητα από τον αριθμό των plugins.
		if ( ! self::$hooks_added ) {
			self::$hooks_added = true;
			add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'queue_updates' ) );
			add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		}
	}

	/* ---------------------------------------------------------------------
	 * Remote data (cached, per-plugin)
	 * ------------------------------------------------------------------- */

	/**
	 * Φέρνει (και cache-άρει 1 ώρα, ανά plugin) το remote JSON.
	 *
	 * @param string $slug Plugin basename (π.χ. 'revenue-splitter/revenue-splitter.php').
	 * @return array|null ['version','download_url','details_url','changelog'] ή null σε αποτυχία.
	 */
	public static function remote_data( string $slug ): ?array {

		if ( ! isset( self::$plugins[ $slug ] ) ) {
			return null;
		}

		$cache_key = 'np_upd_' . md5( $slug );

		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$resp = wp_remote_get(
			self::$plugins[ $slug ]['url'],
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( ! is_array( $data ) || empty( $data['version'] ) ) {
			return null;
		}

		$version = sanitize_text_field( (string) $data['version'] );
		$dl      = isset( $data['download_url'] ) ? esc_url_raw( (string) $data['download_url'] ) : '';

		// HARDENING: δεν δέχεται update package που δεν είναι .zip.
		if ( '' === $dl || ! preg_match( '/\.zip(\?.*)?$/i', $dl ) ) {
			return null;
		}

		$clean = array(
			'version'      => $version,
			'download_url' => $dl,

			// details_url: fallback στο host του update_url (π.χ. το repo Pages)
			// αν το JSON δεν δίνει εκδοτικό URL.
			'details_url'  => isset( $data['details_url'] ) && '' !== (string) $data['details_url']
				? esc_url_raw( (string) $data['details_url'] )
				: self::fallback_details_url( self::$plugins[ $slug ]['url'] ),

			'changelog'    => isset( $data['changelog'] ) ? wp_kses_post( (string) $data['changelog'] ) : '',
		);

		set_transient( $cache_key, $clean, HOUR_IN_SECONDS );

		return $clean;
	}

	/**
	 * Απλό fallback για details: scheme://host (η ρίζα του updates hub).
	 */
	private static function fallback_details_url( string $update_url ): string {
		$parts = wp_parse_url( $update_url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		return $parts['scheme'] . '://' . $parts['host'];
	}

	/* ---------------------------------------------------------------------
	 * Update queue (WP «Υπάρχει διαθέσιμη νέα έκδοση»)
	 * ------------------------------------------------------------------- */

	/**
	 * Για ΚΑΘΕ εγγεγραμμένο plugin: σύγκριση τοπικής/remote έκδοσης
	 * και τοποθέτηση στο transient του WP → one-click update + Auto-update.
	 */
	public static function queue_updates( $transient ) {

		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		foreach ( self::$plugins as $slug => $conf ) {

			if ( ! isset( $transient->checked[ $slug ] ) ) {
				continue;
			}

			$remote = self::remote_data( $slug );

			if ( null === $remote ) {
				continue;
			}

			if ( version_compare( $conf['version'], $remote['version'], '<' ) ) {

				$transient->response[ $slug ] = (object) array(
					'slug'        => dirname( $slug ),
					'plugin'      => $slug,
					'new_version' => $remote['version'],
					'url'         => $remote['details_url'],
					'package'     => $remote['download_url'],
				);

				// Καθαρισμός του cache καθώς το update μόλις μπήκε στη ροή του WP
				// (ώστε το επόμενο pass να δει fresh δεδομένα).
				delete_transient( 'np_upd_' . md5( $slug ) );
			}
		}

		return $transient;
	}

	/* ---------------------------------------------------------------------
	 * «View version x.x details» popup (page Plugins)
	 * ------------------------------------------------------------------- */

	public static function plugin_info( $result, $action, $args ) {

		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $result;
		}

		// AUDIT FIX (#2): slug πάντα sanitized πριν το comparison.
		$args->slug = sanitize_key( (string) $args->slug );

		foreach ( self::$plugins as $slug => $conf ) {

			if ( dirname( $slug ) !== (string) $args->slug ) {
				continue;
			}

			$remote = self::remote_data( $slug );

			if ( null === $remote ) {
				return $result;
			}

			return (object) array(
				'name'          => $conf['name'],
				'slug'          => dirname( $slug ),
				'version'       => $remote['version'],
				'download_link' => $remote['download_url'],
				'sections'      => array(
					'description' => sprintf(
						/* translators: %s: plugin name */
						__( 'Αυτόματες ενημερώσεις μέσω του noxpress updates hub.', 'revenue-splitter' ),
						$conf['name']
					),
					'changelog'   => $remote['changelog'],
				),
			);
		}

		return $result;
	}
}

} // class_exists( 'NP_Updater' ) guard