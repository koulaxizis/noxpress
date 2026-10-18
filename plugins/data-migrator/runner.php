<?php
/**
 * Data Migrator — runner.php: carries out a restore (and its undo) that
 * NM_Restore_Prep prepared in the admin.
 *
 * Why outside the admin: the restore replaces the database, the users
 * and maybe WordPress itself, so the admin session and the plugins that
 * run on every admin request cannot be relied on halfway. This file
 * loads WordPress with SHORTINIT (database, object cache, no plugins, no
 * theme), with maintenance mode bypassed for this request only.
 *
 * Security (deviation from Bible §11, written in the bootstrap docblock):
 *  - POST only; anything else answers 404 with no body.
 *  - job id (pattern) + token (32 hex). Only sha256(token) is stored in
 *    the job, compared with hash_equals; a wrong token waits 1 s.
 *  - The job file lives in the private storage folder; jobs older than
 *    7 days are refused.
 *  - No input reaches a path, a query or the page unescaped.
 */

define( 'NM_STANDALONE', true );

if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	http_response_code( 404 );
	exit;
}

$nm_job_id = isset( $_POST['job'] ) && is_string( $_POST['job'] ) && preg_match( '/^r-[a-f0-9]{32}$/', $_POST['job'] ) ? $_POST['job'] : ''; // phpcs:ignore
$nm_token  = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? strtolower( trim( $_POST['token'] ) ) : ''; // phpcs:ignore
$nm_token  = preg_match( '/^[a-f0-9]{32}$/', str_replace( array( '-', ' ' ), '', $nm_token ) ) ? str_replace( array( '-', ' ' ), '', $nm_token ) : '';
$nm_do     = isset( $_POST['do'] ) && in_array( $_POST['do'], array( 'page', 'step', 'undo', 'finalize' ), true ) ? $_POST['do'] : 'page'; // phpcs:ignore

// WordPress, minimal: find wp-load.php above the plugin folder.
$nm_wpload = '';
$nm_dir    = __DIR__;
for ( $nm_i = 0; $nm_i < 8; $nm_i++ ) {
	$nm_dir = dirname( $nm_dir );
	if ( is_file( $nm_dir . '/wp-load.php' ) ) {
		$nm_wpload = $nm_dir . '/wp-load.php';
		break;
	}
}
if ( '' === $nm_wpload ) {
	http_response_code( 500 );
	echo 'Data Migrator: wp-load.php not found. Use WP-CLI: wp data-migrator restore';
	exit;
}

define( 'SHORTINIT', true );
// Our own .maintenance must not stop this request.
$GLOBALS['wp_filter']['enable_maintenance_mode'][10][] = array(
	'function'      => static function () {
		return false;
	},
	'accepted_args' => 1,
);

// A fatal error in the middle of a step still answers in JSON.
register_shutdown_function(
	static function () {
		$e = error_get_last();
		if ( $e && in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) && ! headers_sent() ) {
			http_response_code( 500 );
			header( 'Content-Type: application/json; charset=utf-8' );
			echo json_encode(
				array(
					'status' => 'running',
					'text'   => 'PHP: ' . $e['message'],
				)
			);
		}
	}
);

require $nm_wpload;

foreach ( array( 'util', 'crypt', 'zip-reader', 'db', 'dumper', 'replacer', 'importer', 'restorer', 'page' ) as $nm_c ) {
	require_once __DIR__ . '/engine/class-nm-' . $nm_c . '.php';
}
require_once __DIR__ . '/includes/class-lang.php';

NM_Util::$lang = 'el';

/** Stop with a plain page (or JSON for steps). */
function nm_runner_deny( string $do, int $code, string $msg ): void {
	if ( 'step' === $do ) {
		NM_Page::json(
			array(
				'status' => 'failed',
				'text'   => $msg,
			),
			$code
		);
	} else {
		http_response_code( $code );
		NM_Page::open( 'Data Migrator' );
		echo '<p class="fail">' . NM_Page::h( $msg ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
		NM_Page::close();
	}
	exit;
}

// Storage folder from nm_settings.
global $wpdb;
$nm_raw  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'nm_settings' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$nm_set  = is_string( $nm_raw ) ? @unserialize( $nm_raw, array( 'allowed_classes' => false ) ) : null; // phpcs:ignore
$nm_base = is_array( $nm_set ) && ! empty( $nm_set['storage'] ) && is_string( $nm_set['storage'] ) ? rtrim( $nm_set['storage'], '/' ) : '';
$nm_path = ( '' !== $nm_base && '' !== $nm_job_id ) ? $nm_base . '/jobs/' . $nm_job_id . '.php' : '';
$nm_job  = '' !== $nm_path ? NM_Util::read_state( $nm_path ) : null;

if ( ! $nm_job || '' === $nm_token || ! hash_equals( (string) ( $nm_job['token_hash'] ?? '' ), hash( 'sha256', $nm_token ) ) || time() - (int) $nm_job['created'] > 7 * 86400 ) {
	sleep( 1 );
	nm_runner_deny( $nm_do, 403, 'Data Migrator: wrong or expired recovery code. / Λάθος ή ληγμένος κωδικός ανάκτησης.' );
}

NM_Util::$lang     = 'en' === ( $nm_job['lang'] ?? '' ) ? 'en' : 'el';
NM_Util::$log_file = $nm_base . '/logs/' . $nm_job_id . '.log';
$nm_url            = strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
$nm_fields         = array(
	'job'   => $nm_job_id,
	'token' => $nm_token,
);

$nm_db = new NM_DB();
if ( ! $nm_db->connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ) ) {
	nm_runner_deny( $nm_do, 500, NM_Util::t( 'Δεν έγινε σύνδεση με τη βάση δεδομένων.' ) . ' ' . $nm_db->error );
}
$nm_save = static function ( $j ) use ( $nm_path ) {
	NM_Util::write_state( $nm_path, $j );
};

if ( 'undo' === $nm_do && in_array( $nm_job['status'], array( 'done', 'failed' ), true ) ) {
	$nm_job['mode']   = 'rollback';
	$nm_job['rb']     = array();
	$nm_job['status'] = 'rolling-back';
	$nm_save( $nm_job );
	$nm_do = 'page';
}
if ( 'finalize' === $nm_do && in_array( $nm_job['status'], array( 'done', 'failed', 'rolledback' ), true ) ) {
	$nm_r = new NM_Restorer( $nm_job, $nm_db, $nm_save );
	$nm_r->finalize();
	$nm_job = $nm_r->job;
	$nm_do  = 'page';
}

if ( 'step' === $nm_do ) {
	$nm_lock = NM_Util::lock( $nm_path . '.lock' );
	if ( ! $nm_lock ) {
		NM_Page::json(
			array(
				'status' => 'busy',
				'text'   => NM_Util::t( 'Άλλο αίτημα δουλεύει ήδη σε αυτή την εργασία…' ),
			)
		);
		exit;
	}
	NM_Util::prepare_request();
	$nm_job = NM_Util::read_state( $nm_path );
	$nm_out = $nm_job['status'];
	if ( in_array( $nm_job['status'], array( 'running', 'rolling-back' ), true ) ) {
		$nm_r   = new NM_Restorer( $nm_job, $nm_db, $nm_save );
		$nm_out = $nm_r->run( microtime( true ) + NM_Util::budget() );
		$nm_job = $nm_r->job;
	}
	NM_Util::unlock( $nm_lock );
	$nm_steps = NM_Restorer::STEPS;
	$nm_idx   = (int) array_search( $nm_job['step'], $nm_steps, true );
	$nm_label = array(
		'extract' => NM_Util::t( 'Αποσυμπίεση αρχείων' ),
		'import'  => NM_Util::t( 'Εισαγωγή βάσης σε προσωρινούς πίνακες' ),
		'fixup'   => NM_Util::t( 'Προσαρμογή ρυθμίσεων' ),
		'maint'   => NM_Util::t( 'Λειτουργία συντήρησης' ),
		'files'   => NM_Util::t( 'Αντικατάσταση αρχείων' ),
		'tables'  => NM_Util::t( 'Αλλαγή πινάκων' ),
		'post'    => NM_Util::t( 'Τελικά βήματα' ),
		'done'    => NM_Util::t( 'Έτοιμο' ),
	);
	$nm_text  = $nm_label[ $nm_job['step'] ] ?? '';
	if ( 'import' === $nm_job['step'] && ! empty( $nm_job['ss']['chunks'] ) ) {
		$nm_text .= ' · ' . (int) $nm_job['ss']['ci'] . ' / ' . count( (array) $nm_job['ss']['chunks'] );
	}
	if ( 'rolling-back' === $nm_job['status'] ) {
		$nm_text = NM_Util::t( 'Αναίρεση: επιστρέφουν τα αρχεία και οι πίνακες…' );
	}
	NM_Page::json(
		array(
			'status' => $nm_job['status'],
			'pct'    => 'done' === $nm_job['status'] ? 100 : (int) round( 100 * $nm_idx / ( count( $nm_steps ) - 1 ) ),
			'text'   => $nm_text,
		)
	);
	exit;
}

// The page.
NM_Page::open( NM_Util::t( 'Data Migrator: επαναφορά' ) );
$nm_status = (string) $nm_job['status'];
if ( in_array( $nm_status, array( 'running', 'rolling-back' ), true ) ) {
	echo '<p class="desc">' . NM_Page::h( NM_Util::t( 'Μην κλείσεις αυτή τη σελίδα. Αν κλείσει, άνοιξε ξανά τον σύνδεσμο και δώσε τον κωδικό ανάκτησης: η εργασία συνεχίζει από εκεί που έμεινε.' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<div class="bar"><span id="nm-bar"></span></div><p id="nm-text" class="muted">…</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	NM_Page::reload_form( $nm_url, $nm_fields );
	NM_Page::stepper( $nm_url, $nm_fields );
} elseif ( 'done' === $nm_status ) {
	$nm_home = (string) $nm_job['target']['home'];
	echo '<p class="ok">' . NM_Page::h( NM_Util::t( 'Η επαναφορά ολοκληρώθηκε.' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	NM_Page::report( $nm_job );
	echo '<p><a class="button primary" href="' . NM_Page::h( $nm_home . '/wp-login.php' ) . '">' . NM_Page::h( NM_Util::t( 'Σύνδεση στο site' ) ) . '</a> <a class="button" href="' . NM_Page::h( $nm_home . '/' ) . '" target="_blank" rel="noopener noreferrer">' . NM_Page::h( NM_Util::t( 'Άνοιγμα του site' ) ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<h2>' . NM_Page::h( NM_Util::t( 'Αναίρεση ή οριστικοποίηση' ) ) . '</h2><p class="desc">' . NM_Page::h( NM_Util::t( 'Τα προηγούμενα αρχεία και πίνακες κρατήθηκαν. Έλεγξε το site: αν κάτι δεν πάει καλά, η αναίρεση τα επαναφέρει. Όταν όλα είναι εντάξει, η οριστικοποίηση τα σβήνει και ελευθερώνει χώρο.' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	foreach ( array(
		'undo'     => array( NM_Util::t( 'Αναίρεση επαναφοράς' ), 'danger', NM_Util::t( 'Να επιστρέψει το site όπως ήταν πριν την επαναφορά;' ) ),
		'finalize' => array( NM_Util::t( 'Οριστικοποίηση' ), '', NM_Util::t( 'Να σβηστούν τα στοιχεία αναίρεσης; Μετά δεν γίνεται αναίρεση.' ) ),
	) as $nm_k => $nm_b ) {
		echo '<form method="post" action="' . NM_Page::h( $nm_url ) . '" style="display:inline" onsubmit="return confirm(' . NM_Page::h( NM_Util::json( $nm_b[2] ) ) . ')"><input type="hidden" name="job" value="' . NM_Page::h( $nm_job_id ) . '"><input type="hidden" name="token" value="' . NM_Page::h( $nm_token ) . '"><input type="hidden" name="do" value="' . NM_Page::h( $nm_k ) . '"><button class="' . NM_Page::h( $nm_b[1] ) . '">' . NM_Page::h( $nm_b[0] ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
} elseif ( 'rolledback' === $nm_status ) {
	echo '<p class="ok">' . NM_Page::h( NM_Util::t( 'Η επαναφορά αναιρέθηκε: το site είναι όπως πριν.' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	if ( ! empty( $nm_job['error'] ) ) {
		echo '<p class="fail">' . NM_Page::h( NM_Util::tf( 'Αιτία: %s', (string) $nm_job['error'] ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '<p><a class="button" href="' . NM_Page::h( (string) $nm_job['target']['home'] . '/wp-admin/' ) . '">' . NM_Page::h( NM_Util::t( 'Πίσω στη διαχείριση' ) ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
} elseif ( 'finalized' === $nm_status ) {
	echo '<p class="ok">' . NM_Page::h( NM_Util::t( 'Οριστικοποιήθηκε: τα στοιχεία αναίρεσης σβήστηκαν.' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<p><a class="button primary" href="' . NM_Page::h( (string) $nm_job['target']['home'] . '/wp-admin/' ) . '">' . NM_Page::h( NM_Util::t( 'Πίσω στη διαχείριση' ) ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
} else {
	echo '<p class="fail">' . NM_Page::h( NM_Util::tf( 'Η επαναφορά απέτυχε πριν αλλάξει οτιδήποτε στο site: %s', (string) ( $nm_job['error'] ?? '' ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<form method="post" action="' . NM_Page::h( $nm_url ) . '"><input type="hidden" name="job" value="' . NM_Page::h( $nm_job_id ) . '"><input type="hidden" name="token" value="' . NM_Page::h( $nm_token ) . '"><input type="hidden" name="do" value="finalize"><button>' . NM_Page::h( NM_Util::t( 'Καθαρισμός προσωρινών δεδομένων' ) ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<p><a class="button" href="' . NM_Page::h( (string) $nm_job['target']['home'] . '/wp-admin/' ) . '">' . NM_Page::h( NM_Util::t( 'Πίσω στη διαχείριση' ) ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
NM_Page::close();
