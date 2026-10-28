<?php
/**
 * LOP_App — the operator app at /live-operator/ (installable web app, and
 * the page the Android app opens).
 *
 * Rewrite rules (flushed on activation and when the version changes):
 *   live-operator/                      the app (login + manage_options)
 *   live-operator/sw.js                 service worker (scope /live-operator/)
 *   live-operator/manifest.webmanifest  web app manifest
 *   .well-known/assetlinks.json         Digital Asset Links for the Android
 *                                       app, only when fingerprints are set
 * The same answers with ?lop_app=page|sw|manifest|assetlinks (plain
 * permalinks; the service worker then needs pretty permalinks for its scope).
 *
 * Served on parse_request, before the main query and the theme. Never
 * cached (DONOTCACHEPAGE, no-store, CDN-Cache-Control). Not logged in:
 * auth_redirect() to the login page. Deviation from Bible §14.4 (no
 * front-end output outside the theme): this is the plugin's purpose, an
 * admin tool on the site's origin so the push service worker and the
 * Android app's verified link can live there.
 */

defined( 'ABSPATH' ) || exit;

final class LOP_App {

	const QV   = 'lop_app';
	const PATH = 'live-operator';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'parse_request', array( __CLASS__, 'serve' ), 0 );
	}

	public static function rules(): void {
		add_rewrite_rule( '^' . self::PATH . '/?$', 'index.php?' . self::QV . '=page', 'top' );
		add_rewrite_rule( '^' . self::PATH . '/sw\.js$', 'index.php?' . self::QV . '=sw', 'top' );
		add_rewrite_rule( '^' . self::PATH . '/manifest\.webmanifest$', 'index.php?' . self::QV . '=manifest', 'top' );
		add_rewrite_rule( '^\.well-known/assetlinks\.json$', 'index.php?' . self::QV . '=assetlinks', 'top' );
		if ( get_option( 'lop_rules' ) !== LOP_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'lop_rules', LOP_VERSION, true );
		}
	}

	public static function query_vars( $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::QV;
		return $vars;
	}

	/** Pretty permalinks are on (needed for the service worker scope). */
	public static function pretty(): bool {
		return '' !== (string) get_option( 'permalink_structure' );
	}

	/** The app's address, optionally opening one conversation. */
	public static function url( string $conversation_id = '' ): string {
		$base = self::pretty() ? home_url( '/' . self::PATH . '/' ) : add_query_arg( self::QV, 'page', home_url( '/' ) );
		return '' !== $conversation_id ? $base . '#c=' . rawurlencode( $conversation_id ) : $base;
	}

	public static function sw_url(): string {
		return self::pretty() ? home_url( '/' . self::PATH . '/sw.js' ) : add_query_arg( self::QV, 'sw', home_url( '/' ) );
	}

	public static function manifest_url(): string {
		return self::pretty() ? home_url( '/' . self::PATH . '/manifest.webmanifest' ) : add_query_arg( self::QV, 'manifest', home_url( '/' ) );
	}

	public static function assetlinks_url(): string {
		return home_url( '/.well-known/assetlinks.json' );
	}

	/** Path of the app (the service worker scope), e.g. /live-operator/. */
	public static function scope(): string {
		$path = (string) wp_parse_url( home_url( '/' . self::PATH . '/' ), PHP_URL_PATH );
		return '' !== $path ? $path : '/' . self::PATH . '/';
	}

	/** @param WP $wp The request. */
	public static function serve( $wp ): void {
		$what = isset( $wp->query_vars[ self::QV ] ) && is_scalar( $wp->query_vars[ self::QV ] ) ? (string) $wp->query_vars[ self::QV ] : '';
		if ( ! in_array( $what, array( 'page', 'sw', 'manifest', 'assetlinks' ), true ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( Live_Operator::killed() ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}
		switch ( $what ) {
			case 'sw':
				self::serve_sw();
				break;
			case 'manifest':
				self::serve_manifest();
				break;
			case 'assetlinks':
				self::serve_assetlinks();
				break;
			default:
				self::serve_page();
		}
		exit;
	}

	private static function no_store(): void {
		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
		header( 'CDN-Cache-Control: no-store' );
		header( 'X-Robots-Tag: noindex, nofollow' );
	}

	private static function serve_sw(): void {
		status_header( 200 );
		self::no_store();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: ' . self::scope() );
		readfile( LOP_PATH . 'assets/sw.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a static file of the plugin.
	}

	private static function icons(): array {
		$out = array();
		foreach ( array( 192, 512 ) as $size ) {
			$src   = function_exists( 'get_site_icon_url' ) ? (string) get_site_icon_url( $size ) : '';
			$out[] = array(
				'src'     => '' !== $src ? $src : LOP_URL . 'assets/icon-' . $size . '.png',
				'sizes'   => $size . 'x' . $size,
				'type'    => 'image/png',
				'purpose' => 'any',
			);
		}
		return $out;
	}

	private static function serve_manifest(): void {
		status_header( 200 );
		self::no_store();
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		$name = LOP_Settings::app_name();
		echo wp_json_encode(
			array(
				'name'             => $name,
				'short_name'       => $name,
				'id'               => self::scope(),
				'start_url'        => self::url(),
				'scope'            => self::scope(),
				'display'          => 'standalone',
				'background_color' => '#101218',
				'theme_color'      => '#101218',
				'lang'             => 'el' === LOP_Lang::from_locale( (string) get_locale() ) ? 'el' : 'en',
				'icons'            => self::icons(),
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}

	private static function serve_assetlinks(): void {
		$s = LOP_Settings::get();
		if ( ! $s['fingerprints'] ) {
			status_header( 404 );
			self::no_store();
			return;
		}
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		echo wp_json_encode(
			array(
				array(
					'relation' => array( 'delegate_permission/common.handle_all_urls' ),
					'target'   => array(
						'namespace'                => 'android_app',
						'package_name'             => $s['package'],
						'sha256_cert_fingerprints' => $s['fingerprints'],
					),
				),
			),
			JSON_UNESCAPED_SLASHES
		);
	}

	private static function serve_page(): void {
		if ( ! is_user_logged_in() ) {
			auth_redirect(); // Exits.
		}
		self::no_store();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'live-operator' ), '', array( 'response' => 403 ) );
		}
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Frame-Options: DENY' );
		header( 'Referrer-Policy: same-origin' );

		$lang   = LOP_Lang::lang();
		$config = array(
			'rest'     => esc_url_raw( rest_url() ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'nonceUrl' => admin_url( 'admin-ajax.php?action=rest-nonce' ),
			'ns'       => LOP_REST::NS,
			'purio'    => 'listeo/v1/live-handoff',
			'sw'       => self::sw_url(),
			'scope'    => self::scope(),
			'app'      => self::url(),
			'settings' => admin_url( 'admin.php?page=' . LOP_Settings::PAGE ),
			'inbox'    => admin_url( 'admin.php?page=ai-chat-live-chat' ),
			'user'     => get_current_user_id(),
			'lang'     => $lang,
			'state'    => LOP_REST::state_payload(),
			'key'      => LOP_Push::public_key(),
			'i18n'     => self::strings(),
			'days'     => LOP_Schedule::day_names(),
		);
		$icon   = function_exists( 'get_site_icon_url' ) ? (string) get_site_icon_url( 192 ) : '';
		$icon   = '' !== $icon ? $icon : LOP_URL . 'assets/icon-192.png';
		?>
<!doctype html>
<html lang="<?php echo esc_attr( $lang ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#101218">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( LOP_Settings::app_name() ); ?></title>
<link rel="manifest" href="<?php echo esc_url( self::manifest_url() ); ?>">
<link rel="icon" href="<?php echo esc_url( $icon ); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url( $icon ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( LOP_URL . 'assets/app.css?ver=' . LOP_VERSION ); ?>">
</head>
<body>
<div id="lop-app" class="lop-app" aria-live="polite"><p class="lop-boot"><?php esc_html_e( 'Φόρτωση…', 'live-operator' ); ?></p></div>
<noscript><p class="lop-boot"><?php esc_html_e( 'Η εφαρμογή χρειάζεται JavaScript.', 'live-operator' ); ?></p></noscript>
<script id="lop-config" type="application/json"><?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>
<script src="<?php echo esc_url( LOP_URL . 'assets/app.js?ver=' . LOP_VERSION ); ?>"></script>
</body>
</html>
		<?php
	}

	/** Every text of the app, from the plugin's msgids (Bible §9). */
	private static function strings(): array {
		return array(
			'online'          => __( 'Online', 'live-operator' ),
			'offline'         => __( 'Offline', 'live-operator' ),
			/* translators: %s: time, e.g. 18:40 */
			'until'           => __( 'έως τις %s', 'live-operator' ),
			'extend'          => __( 'Παράταση', 'live-operator' ),
			'goOnline'        => __( 'Γίνε online', 'live-operator' ),
			'goOffline'       => __( 'Γίνε offline', 'live-operator' ),
			'switchOff'       => __( 'Ο διακόπτης δεν είναι ενεργός. Άνοιξέ τον στις ρυθμίσεις του Live Operator.', 'live-operator' ),
			'settings'        => __( 'Ρυθμίσεις', 'live-operator' ),
			'noPurio'         => __( 'Το PurioChat Pro δεν είναι ενεργό ή το Live Chat του είναι κλειστό.', 'live-operator' ),
			'modeWarn'        => __( 'Το PurioChat δεν είναι στη διαθεσιμότητα «online»: ο διακόπτης δεν αλλάζει τίποτα για τους επισκέπτες.', 'live-operator' ),
			'pushOff'         => __( 'Οι ειδοποιήσεις δεν είναι ανοιχτές σε αυτή τη συσκευή.', 'live-operator' ),
			'pushOn'          => __( 'Άνοιγμα ειδοποιήσεων', 'live-operator' ),
			'pushDenied'      => __( 'Οι ειδοποιήσεις είναι μπλοκαρισμένες για αυτό το site. Άνοιξέ τες από τις ρυθμίσεις του browser ή της εφαρμογής.', 'live-operator' ),
			'pushNone'        => __( 'Αυτός ο browser δεν υποστηρίζει ειδοποιήσεις push.', 'live-operator' ),
			'pushServer'      => __( 'Ο server δεν μπορεί να στείλει ειδοποιήσεις (λείπει το OpenSSL με κλειδιά EC).', 'live-operator' ),
			'pushReady'       => __( 'Οι ειδοποιήσεις είναι ανοιχτές σε αυτή τη συσκευή.', 'live-operator' ),
			'pushTest'        => __( 'Δοκιμή', 'live-operator' ),
			'pushTestSent'    => __( 'Στάλθηκε. Αν δεν ήρθε, έλεγξε τις ειδοποιήσεις της εφαρμογής στο Android.', 'live-operator' ),
			'pushTestFail'    => __( 'Η δοκιμή δεν στάλθηκε. Άνοιξε ξανά τις ειδοποιήσεις.', 'live-operator' ),
			'pushOffBtn'      => __( 'Κλείσιμο ειδοποιήσεων', 'live-operator' ),
			'waiting'         => __( 'Σε αναμονή', 'live-operator' ),
			'active'          => __( 'Ενεργές', 'live-operator' ),
			'withAi'          => __( 'Με το AI τώρα', 'live-operator' ),
			'past'            => __( 'Πρόσφατες', 'live-operator' ),
			'empty'           => __( 'Καμία συνομιλία αυτή τη στιγμή.', 'live-operator' ),
			'visitor'         => __( 'Επισκέπτης', 'live-operator' ),
			'ai'              => __( 'AI', 'live-operator' ),
			'you'             => __( 'Εσύ', 'live-operator' ),
			'back'            => __( 'Πίσω', 'live-operator' ),
			'claim'           => __( 'Ανάληψη', 'live-operator' ),
			'takeOver'        => __( 'Ανάληψη από το AI', 'live-operator' ),
			'returnAi'        => __( 'Επιστροφή στο AI', 'live-operator' ),
			'returnConfirm'   => __( 'Να επιστρέψει η συνομιλία στο AI;', 'live-operator' ),
			'send'            => __( 'Αποστολή', 'live-operator' ),
			'placeholder'     => __( 'Γράψε απάντηση…', 'live-operator' ),
			/* translators: %s: seconds */
			'waitFor'         => __( 'περιμένει %s', 'live-operator' ),
			/* translators: %s: seconds */
			'leftFor'         => __( 'επιστρέφει στο AI σε %s', 'live-operator' ),
			'assignedOther'   => __( 'Την έχει άλλος χειριστής.', 'live-operator' ),
			'closed'          => __( 'Η συνομιλία είναι πάλι με το AI.', 'live-operator' ),
			'page'            => __( 'Σελίδα επισκέπτη', 'live-operator' ),
			'netError'        => __( 'Δεν υπάρχει σύνδεση με το site. Ξαναδοκιμάζει…', 'live-operator' ),
			'failed'          => __( 'Η ενέργεια δεν έγινε. Δοκίμασε ξανά.', 'live-operator' ),
			'loggedOut'       => __( 'Η σύνδεση έληξε. Ξαναφορτώνει…', 'live-operator' ),
			'inbox'           => __( 'Live Chat του PurioChat', 'live-operator' ),
			/* translators: %d: seconds */
			'timeoutHint'     => __( 'Ένα αίτημα περιμένει %d δευτερόλεπτα πριν γυρίσει στο AI.', 'live-operator' ),
			/* translators: %s: app version, e.g. 1.2.0 */
			'appUpdate'       => __( 'Νέα έκδοση της εφαρμογής: %s.', 'live-operator' ),
			'appGet'          => __( 'Λήψη', 'live-operator' ),
			// Weekly schedule (1.3.0).
			'loading'         => __( 'Φόρτωση…', 'live-operator' ),
			'schedule'        => __( 'Ωράριο', 'live-operator' ),
			'bySchedule'      => __( 'από το ωράριο', 'live-operator' ),
			/* translators: %s: time */
			'pausedUntil'     => __( 'Ωράριο σε παύση έως τις %s', 'live-operator' ),
			/* translators: %s: day and time, e.g. Tue 10:00 */
			'nextWindow'      => __( 'Επόμενο ωράριο: %s', 'live-operator' ),
			'schedNone'       => __( 'Το ωράριο δεν έχει κανένα διάστημα.', 'live-operator' ),
			'schedOff'        => __( 'Χωρίς αυτόματο ωράριο.', 'live-operator' ),
			'schedOn'         => __( 'Αυτόματο ωράριο', 'live-operator' ),
			'schedOnHint'     => __( 'Μέσα στο ωράριο είσαι online χωρίς να πατήσεις τίποτα. Το «Γίνε offline» κλείνει μόνο το τρέχον διάστημα.', 'live-operator' ),
			'schedNotify'     => __( 'Ειδοποίηση όταν ξεκινά ένα διάστημα', 'live-operator' ),
			'from'            => __( 'από', 'live-operator' ),
			'to'              => __( 'έως', 'live-operator' ),
			'schedRemove'     => __( 'Αφαίρεση', 'live-operator' ),
			'schedClosedDay'  => __( 'Κλειστά', 'live-operator' ),
			'schedAdd'        => __( '+ Διάστημα', 'live-operator' ),
			'schedCopy'       => __( 'Αντιγραφή της Δευτέρας σε όλες τις καθημερινές', 'live-operator' ),
			/* translators: %s: time zone */
			'schedTz'         => __( 'Ζώνη ώρας του site: %s. Ένα διάστημα που τελειώνει νωρίτερα από την αρχή του (π.χ. 22:00–02:00) τελειώνει την επόμενη μέρα.', 'live-operator' ),
			'schedClosed'     => __( 'Κλειστές ημερομηνίες', 'live-operator' ),
			'schedClosedAdd'  => __( 'Προσθήκη', 'live-operator' ),
			'schedClosedHint' => __( 'Αργίες ή άδεια: εκείνες τις μέρες το ωράριο δεν ανοίγει. Έως 30.', 'live-operator' ),
			/* translators: %d: number of entries */
			'schedDropped'    => __( '%d ώρες ή ημερομηνίες αγνοήθηκαν: δεν ήταν έγκυρες.', 'live-operator' ),
			'schedSaved'      => __( 'Το ωράριο αποθηκεύτηκε.', 'live-operator' ),
			'save'            => __( 'Αποθήκευση', 'live-operator' ),
		);
	}
}
