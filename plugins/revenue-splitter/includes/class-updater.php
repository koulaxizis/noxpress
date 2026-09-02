<?php
/**
 * Custom updater — GitHub Releases / δικό σου JSON endpoint.
 *
 * Ροή:
 *  1. Το WP (cron, 2×/ημέρα) χτίζει το transient 'update_plugins'.
 *  2. Εμείς hook-αρουμε το 'pre_set_site_transient_update_plugins' και
 *     συγκρίνουμε την RS_VERSION με το 'version' του remote JSON
 *     (που δίνουμε στο RS_UPDATE_URL — δες revenue-splitter.php).
 *  3. Αν υπάρχει νεότερη, γράφουμε response στο transient → το WP δείχνει
 *     «Υπάρχει διαθέσιμη νέα έκδοση» + one-click update (Ajax) + Auto-update toggle.
 *  4. Το 'plugins_api' filter δίνει το «View version x.x details» popup.
 *
 * Το remote JSON πρέπει να έχει:
 *  {
 *    "version":      "1.2.0",
 *    "download_url": "https://github.com/<user>/<repo>/releases/download/v1.2.0/revenue-splitter.zip",
 *    "details_url":  "https://github.com/<user>/<repo>/releases",
 *    "changelog":    "<p>Τι άλλαξε...</p>"
 *  }
 *
 * Το zip πρέπει να περιέχει φάκελο 'revenue-splitter/' με την ίδια δομή
 * (includes/, assets/) και main file 'revenue-splitter.php'.
 */

defined( 'ABSPATH' ) || exit;

class RS_Updater {

	const TRANSIENT = 'rs_update_data';

	public static function init(): void {

		if ( ! defined( 'RS_UPDATE_URL' ) || '' === RS_UPDATE_URL ) {
			return; // Δεν έχει ρυθμιστεί endpoint — updater αδρανής, πουθενά error.
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'queue_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
	}

	private static function slug(): string {
		return plugin_basename( RS_FILE );
	}

	/**
	 * Φέρνει (και cache-άρει 1 ώρα) το remote JSON.
	 *
	 * @return array|null ['version','download_url','details_url','changelog'] ή null σε αποτυχία.
	 */
	public static function remote_data(): ?array {

		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$resp = wp_remote_get(
			RS_UPDATE_URL,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( ! is_array( $data )
			|| empty( $data['version'] )
			|| empty( $data['download_url'] )
			|| ! is_string( $data['download_url'] ) ) {
			return null;
		}

		// Κρατάμε μόνο επικυρωμένα πεδία.
		$data = array(
			'version'      => sanitize_text_field( (string) $data['version'] ),
			'download_url' => esc_url_raw( $data['download_url'] ),
			'details_url'  => isset( $data['details_url'] ) ? esc_url_raw( $data['details_url'] ) : '',
			'changelog'    => isset( $data['changelog'] ) ? wp_kses_post( $data['changelog'] ) : '',
		);

		set_transient( self::TRANSIENT, $data, HOUR_IN_SECONDS );

		return $data;
	}

	/**
	 * Ελέγχει για νεότερη έκδοση και τη «σβήνει» στη ροή updates του WP.
	 */
	public static function queue_update( $transient ) {

		if ( empty( $transient->checked ) || ! is_object( $transient ) ) {
			return $transient;
		}

		$slug = self::slug();

		if ( ! isset( $transient->checked[ $slug ] ) ) {
			return $transient;
		}

		$remote = self::remote_data();

		if ( null === $remote ) {
			return $transient;
		}

		if ( version_compare( RS_VERSION, $remote['version'], '<' ) ) {

			$transient->response[ $slug ] = (object) array(
				'slug'        => dirname( $slug ),
				'plugin'      => $slug,
				'new_version' => $remote['version'],
				'url'         => $remote['details_url'],
				'package'     => $remote['download_url'],
			);

			// Καθαρισμός του δικού μας cache καθώς το transient του WP ανανεώθηκε.
			delete_transient( self::TRANSIENT );
		}

		return $transient;
	}

	/**
	 * Το «View version details» popup από τη σελίδα Plugins.
	 */
	public static function plugin_info( $result, $action, $args ) {

		if ( 'plugin_information' !== $action
			|| empty( $args->slug )
			|| 'revenue-splitter' !== $args->slug ) {
			return $result;
		}

		$remote = self::remote_data();

		if ( null === $remote ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Revenue Splitter',
			'slug'          => 'revenue-splitter',
			'version'       => $remote['version'],
			'download_link' => $remote['download_url'],
			'sections'      => array(
				'description' => __( 'Revenue Splitter — πωλήσεις, ΦΠΑ ανά προϊόν και καταμερισμός σε δικαιούχους για WooCommerce.', 'revenue-splitter' ),
				'changelog'   => $remote['changelog'],
			),
		);
	}
}