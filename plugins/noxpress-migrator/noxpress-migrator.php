<?php
/**
 * Plugin Name:       Noxpress Migrator
 * Plugin URI:        https://noxpress.tech
 * Description:       Πλήρης μετανάστευση WordPress + WooCommerce σε άλλον server: streaming εξαγωγή βάσης (χωρίς mysqldump), αρχείων σε chunked ZIP με multi-volume, SHA-256 manifest, checkpoints για resume και self-contained restore.php. Μηδέν εξαρτήσεις από shell.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Christos Koulaxizis
 * License:           MIT
 * Text Domain:       noxpress-migrator
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'NM_VERSION', '1.0.0' );
define( 'NM_FILE', __FILE__ );
define( 'NM_PATH', plugin_dir_path( __FILE__ ) );
define( 'NM_URL', plugin_dir_url( __FILE__ ) );

/**
 * Ζώνη ασφαλείας για το staging του export στο /wp-content/uploads/.
 *
 * Το plugin ΔΕΝ γράφει ποτέ εκτός αυτού του prefix. Συνεννόηση με το
 * uninstall.php (καθαρίζει ό,τι ξεκινάει από 'noxpress-migrator-'):
 *  - noxpress-migrator-tmp/   → staging χώρος κατά το build του ZIP.
 *  - noxpress-migrator-*.log   → log χωρίς FTP (random filename).
 */
define( 'NM_UPLOAD_PREFIX', 'noxpress-migrator-' );

add_action( 'plugins_loaded', 'nm_bootstrap' );

/**
 * Mapping: αρχείο => class που περιμένουμε να υπάρξει μετά το require.
 *
 * Η σειρά έχει σημασία: το Zip_Writer χρησιμοποιείται από τα Engines,
 * το Admin_UI είναι το τελευταίο (consumer των πάντων).
 */
function nm_components(): array {
	return array(
		'includes/class-lang.php'          => 'NM_Lang',
		'includes/class-zip-writer.php'    => 'NM_Zip_Writer',
		'includes/class-export-engine.php' => 'NM_Export_Engine',
		'includes/class-files-engine.php'  => 'NM_Files_Engine',
		'includes/class-verify.php'        => 'NM_Verify',
		'includes/class-admin-ui.php'      => 'NM_Admin_UI',
	);
}

function nm_bootstrap(): void {

	// ---- i18n ----
	add_action(
		'init',
		function () {
			load_plugin_textdomain(
				'noxpress-migrator',
				false,
				dirname( plugin_basename( NM_FILE ) ) . '/languages'
			);
		}
	);

	// ---- Φόρτωση συνιστωσών (guarded — ποτέ fatal σε ημιτελές install) ----
	$missing = array();

	foreach ( nm_components() as $rel => $class ) {
		$path = NM_PATH . $rel;
		if ( is_readable( $path ) ) {
			require_once $path;
		} else {
			$missing[] = $rel;
		}
	}

	// ---- init όσων ΦΟΡΤΩΘΗΚΑΝ πραγματικά ----
	// (class_exists双重-guard: το require πέρασε, αλλά αν το αρχείο
	// περιέχει syntax error η PHP το απέφυγε — δεν κάνουμε فرغم
	// κλήση σε class που δεν υπάρχει.)
	foreach ( nm_components() as $rel => $class ) {
		if ( is_readable( NM_PATH . $rel ) && class_exists( $class ) ) {
			call_user_func( array( $class, 'init' ) );
		}
	}

	// ---- Ημιτελές install → ρητό notice, ποτέ σιωπή ----
	if ( ! empty( $missing ) ) {
		add_action(
			'admin_notices',
			function () use ( $missing ) {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				echo '<div class="notice notice-warning"><p><strong>Noxpress Migrator:</strong> ';
				echo esc_html__( 'Οι ακόλουθες συνιστώσες λείπουν από τον φάκελο του plugin (προστασία έναντι ημιτελούς μεταφοράς αρχείων — το site συνεχίζει κανονικά):', 'noxpress-migrator' );
				echo '</p><ul style="margin:6px 0 10px;list-style:disc;padding-left:22px;">';
				foreach ( $missing as $file ) {
					echo '<li><code>' . esc_html( 'wp-content/plugins/noxpress-migrator/' . $file ) . '</code></li>';
				}
				echo '</ul></div>';
			}
		);
	}

	// ---- WP-CLI (να έχουμε ένα σημείο από πού κινείται το batch) ----
	// nm export / nm resume / nm status — υλοποιείται στο class-cli.php.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		if ( is_readable( NM_PATH . 'includes/class-cli.php' ) ) {
			require_once NM_PATH . 'includes/class-cli.php';
			if ( class_exists( 'NM_CLI' ) ) {
				NM_CLI::init();
			}
		}
	}
}

register_activation_hook(
	__FILE__,
	function (): void {
		// Multi-volume όριο (MB) — το όριο ανά ZIP volume πριν κοπεί σε
		// .z01/.z02/... Η default τιμή (500) είναι φιλική σε shared hosts
		// και τυπικά PHP timeouts. Ρυθμίζεται από το admin UI αργότερα.
		add_option( 'nm_max_volume_mb', '500' );

		// Recording buffer/progress του τελευταίου export — structure:
		// {phase, table, table_offset, file, bytes_written, started,
		// updated, volumes} — καθαρίζεται από το uninstall και σε κάθε
		// νέο ξεκίνημα export (ΔΕΝ κρατάμε πολλά).
		add_option( 'nm_checkpoint', '' );
	}
);