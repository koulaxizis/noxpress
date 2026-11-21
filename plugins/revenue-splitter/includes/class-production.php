<?php
/**
 * Revenue Splitter — production stage of a creator's current work (1.18.0).
 *
 * Asked by Chris on 2026-11-12: the Author Portal shows which production stage
 * the creator's current work is at, with notes and an explanation of the
 * process. Design: notes/rs-portal-profile/05-stadio-paragogis.md.
 *
 * Source of truth: the orOS Kanban board «Βιβλία». orOS maps its columns to
 * seven public phases and pushes the whole list (idempotent replacement) to
 *  POST /wp-json/rs/v1/production  { v:1, sent, works:[{id, ben, title,
 *       phase 1-7, since, release, note}] }
 * with the narrow contracts key; GET returns what is stored. Internal columns
 * and card comments never reach the site: only the phase and the note written
 * for the creator. The site keeps the date each phase was first seen, so the
 * portal can show the path so far.
 *
 * 1.21.0 (v2, asked by Chris on 2026-11-21): the steps are the publisher's
 * own. On each Kanban board the publisher marks which columns are steps of
 * the creator's production flow and writes, per step, an explanation for
 * the creator (EL, optional EN). orOS sends the flows with the works:
 *  POST { v:2, sent, flows:[{id, steps:[{id, name, name_en, about,
 *         about_en}]}], works:[{id, ben, title, flow, step, since, release,
 *         note, products:[product ids]}] }
 * The order of the steps is the order of the columns. Only the columns the
 * publisher marked as steps and the texts written for the creator reach
 * the site (Bible 1.105 still holds: no internal column, card notes or
 * comments). The portal shows the steps in order, the current one marked,
 * and each step's explanation on hover, focus or tap. A v1 push still
 * works and replaces a v2 list (and the other way round): the last push is
 * the list.
 *
 * @package RevenueSplitter
 */

defined( 'ABSPATH' ) || exit;

final class RS_Production {

	const OPT      = 'rs_production';
	const OPT_META = 'rs_production_meta';
	const OPT_HIST = 'rs_production_hist';
	const OPT_TXT  = 'rs_production_texts';
	const OPT_FLOW = 'rs_production_flows'; // 1.21.0: v2 flows (steps per board).
	const SLUG     = 'revenue-splitter-production';

	const MAX       = 500;     // Works per push.
	const MAX_BODY  = 1048576; // Bytes per push (1 MB).
	const TITLE_MAX = 200;
	const NOTE_MAX  = 1000;
	const TEXT_MAX  = 1500;
	const PHASES    = 7;
	const DONE_DAYS = 30;      // A released work stays this many days.
	const NEW_DAYS  = 14;      // «νέο» while the phase is this recent.
	const FLOWS_MAX = 50;      // v2: flows per push.
	const STEPS_MAX = 60;      // v2: steps per flow.
	const NAME_MAX  = 80;      // v2: a step's name.
	const ABOUT_MAX = 600;     // v2: a step's explanation.
	const PROD_MAX  = 5;       // v2: shop products per work.

	/** @var array[]|null */
	private static $cache = null;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 9 );
		add_action( 'admin_init', array( __CLASS__, 'route_admin' ) );
	}

	/* =====================================================================
	 * Storage
	 * =================================================================== */

	/** JSON text, or an array when a remote tool stored it as one. */
	private static function read_json( string $opt ): array {
		$raw = get_option( $opt, '' );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$d = json_decode( is_string( $raw ) ? $raw : '', true );
		// Encoded twice by a remote tool.
		if ( is_string( $d ) ) {
			$d = json_decode( $d, true );
		}
		return is_array( $d ) ? $d : array();
	}

	private static function write_json( string $opt, array $value ): void {
		update_option( $opt, (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE ), false );
	}

	/** @return array[] */
	public static function all(): array {
		if ( null === self::$cache ) {
			self::$cache = array_values( array_filter( self::read_json( self::OPT ), 'is_array' ) );
		}
		return self::$cache;
	}

	public static function meta(): array {
		return self::read_json( self::OPT_META );
	}

	/** @return array<string,array[]> id => [ { phase, at } ] oldest first. */
	public static function history(): array {
		return self::read_json( self::OPT_HIST );
	}

	/** @return array<string,array> v2 flows: flow id => [ steps ] in order. */
	public static function flows(): array {
		$out = array();
		foreach ( self::read_json( self::OPT_FLOW ) as $id => $steps ) {
			if ( is_string( $id ) && is_array( $steps ) ) {
				$out[ $id ] = array_values( array_filter( $steps, 'is_array' ) );
			}
		}
		return $out;
	}

	/** v2 row (flow and step) or v1 row (phase). */
	private static function is_v2( array $w ): bool {
		return isset( $w['flow'], $w['step'] );
	}

	/** Index of a v2 row's step in its flow, or -1. */
	private static function step_index( array $w, array $flows ): int {
		foreach ( (array) ( $flows[ (string) $w['flow'] ] ?? array() ) as $i => $st ) {
			if ( (string) ( $st['id'] ?? '' ) === (string) $w['step'] ) {
				return (int) $i;
			}
		}
		return -1;
	}

	/** True when a work is at the end of its flow (released). */
	private static function is_last( array $w, array $flows ): bool {
		if ( ! self::is_v2( $w ) ) {
			return self::PHASES === (int) ( $w['phase'] ?? 0 );
		}
		$i = self::step_index( $w, $flows );
		return $i >= 0 && count( (array) $flows[ (string) $w['flow'] ] ) - 1 === $i;
	}

	/** The step's text in the visitor's language (EN falls back to EL). */
	private static function pick( array $st, string $key ): string {
		$el = (string) ( $st[ $key ] ?? '' );
		if ( class_exists( 'RS_Lang' ) && 'en' === RS_Lang::get_lang() ) {
			$en = (string) ( $st[ $key . '_en' ] ?? '' );
			return '' !== $en ? $en : $el;
		}
		return $el;
	}

	public static function phase_names(): array {
		return array(
			1 => __( 'Αξιολόγηση και συμφωνία', 'revenue-splitter' ),
			2 => __( 'Επιμέλεια', 'revenue-splitter' ),
			3 => __( 'Εικονογράφηση και εξώφυλλο', 'revenue-splitter' ),
			4 => __( 'Σελιδοποίηση', 'revenue-splitter' ),
			5 => __( 'Διορθώσεις και τελικός έλεγχος', 'revenue-splitter' ),
			6 => __( 'Παραγωγή και τύπωμα', 'revenue-splitter' ),
			7 => __( 'Κυκλοφορία', 'revenue-splitter' ),
		);
	}

	public static function default_texts(): array {
		return array(
			1 => __( 'Διαβάζουμε το έργο και συμφωνούμε τους όρους της έκδοσης. Από σένα χρειάζεται το τελικό κείμενο και η υπογραφή του συμφωνητικού.', 'revenue-splitter' ),
			2 => __( 'Ο επιμελητής διαβάζει το κείμενο για ροή, συνέπεια και γλώσσα. Θα σου στείλουμε τις προτάσεις του για να τις δεις και να συμφωνήσουμε.', 'revenue-splitter' ),
			3 => __( 'Ετοιμάζονται οι εικόνες και το εξώφυλλο. Θα δεις τα σχέδια πριν κλειδώσουν.', 'revenue-splitter' ),
			4 => __( 'Το κείμενο στήνεται στις σελίδες του βιβλίου: γραμματοσειρές, περιθώρια, κεφάλαια.', 'revenue-splitter' ),
			5 => __( 'Σου στέλνουμε τις τελικές σελίδες για έναν τελευταίο έλεγχο. Οι αλλαγές σε αυτή τη φάση είναι μόνο διορθώσεις.', 'revenue-splitter' ),
			6 => __( 'Το βιβλίο πηγαίνει στο τυπογραφείο ή ετοιμάζεται το ψηφιακό αρχείο. Παίρνει συνήθως λίγες εβδομάδες.', 'revenue-splitter' ),
			7 => __( 'Το βιβλίο κυκλοφορεί. Στο «Οι τίτλοι σου» θα βλέπεις από εδώ και πέρα λήψεις, ενδιαφέρον και κριτικές.', 'revenue-splitter' ),
		);
	}

	/** Phase texts: the shop's own where set, else the defaults. */
	public static function texts(): array {
		$own = self::read_json( self::OPT_TXT );
		$out = array();
		foreach ( self::default_texts() as $n => $t ) {
			$v         = isset( $own[ (string) $n ] ) ? trim( (string) $own[ (string) $n ] ) : '';
			$out[ $n ] = '' !== $v ? $v : $t;
		}
		return $out;
	}

	/* =====================================================================
	 * Push from orOS
	 * =================================================================== */

	/** @return array|WP_Error { saved, skipped:[{id, why}] } */
	public static function ingest( $body ) {
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'rs_production_body', 'The body must be a JSON object.', array( 'status' => 400 ) );
		}
		if ( ! isset( $body['v'] ) || ( 1 !== $body['v'] && 2 !== $body['v'] ) ) {
			return new WP_Error( 'rs_production_v', 'Unsupported format: v must be 1 or 2.', array( 'status' => 400 ) );
		}
		$v2    = 2 === $body['v'];
		$flows = array();
		if ( $v2 ) {
			$flows = self::clean_flows( $body['flows'] ?? null );
			if ( is_wp_error( $flows ) ) {
				return $flows;
			}
		}
		$sent = isset( $body['sent'] ) && is_string( $body['sent'] ) ? $body['sent'] : '';
		if ( ! RS_Contracts::is_date( $sent ) ) {
			return new WP_Error( 'rs_production_sent', 'sent must be a date, YYYY-MM-DD.', array( 'status' => 400 ) );
		}
		$works = $body['works'] ?? null;
		if ( ! is_array( $works ) || ( array() !== $works && array_keys( $works ) !== range( 0, count( $works ) - 1 ) ) ) {
			return new WP_Error( 'rs_production_list', 'works must be a list.', array( 'status' => 400 ) );
		}
		if ( count( $works ) > self::MAX ) {
			return new WP_Error( 'rs_production_max', sprintf( 'At most %d works per request; nothing was saved.', self::MAX ), array( 'status' => 400 ) );
		}

		$names   = array_flip( RS_Beneficiaries::collect_names() );
		$seen    = array();
		$keep    = array();
		$skipped = array();
		foreach ( $works as $row ) {
			$r = $v2 ? self::clean_v2( $row, $names, $sent, $flows ) : self::clean( $row, $names, $sent );
			if ( is_string( $r ) ) {
				$skipped[] = array(
					'id'  => is_array( $row ) && isset( $row['id'] ) && is_scalar( $row['id'] ) ? substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $row['id'] ), 0, 64 ) : '',
					'why' => $r,
				);
				continue;
			}
			if ( isset( $seen[ $r['id'] ] ) ) {
				$skipped[] = array(
					'id'  => $r['id'],
					'why' => 'duplicate_id',
				);
				continue;
			}
			$seen[ $r['id'] ] = true;
			$keep[]           = $r;
		}

		// History: a phase (v1) or a step (v2) is recorded the first time
		// it is seen for a work. A v2 step is kept as "s" with its flow.
		$hist = self::history();
		$out  = array();
		foreach ( $keep as $r ) {
			$h    = isset( $hist[ $r['id'] ] ) && is_array( $hist[ $r['id'] ] ) ? $hist[ $r['id'] ] : array();
			$last = empty( $h ) ? array() : (array) end( $h );
			$now  = $v2 ? array( 's' => $r['flow'] . '/' . $r['step'] ) : array( 'phase' => $r['phase'] );
			$same = $v2 ? ( ( $last['s'] ?? '' ) === $now['s'] ) : ( (int) ( $last['phase'] ?? 0 ) === $now['phase'] && ! isset( $last['s'] ) );
			if ( ! $same ) {
				$h[] = $now + array( 'at' => $r['since'] );
			}
			$out[ $r['id'] ] = array_slice( $h, -30 );
		}
		self::write_json( self::OPT, $keep );
		self::write_json( self::OPT_FLOW, $flows );
		self::write_json( self::OPT_HIST, $out );
		self::write_json(
			self::OPT_META,
			array(
				'received' => gmdate( 'c' ),
				'v'        => $v2 ? 2 : 1,
				'sent'     => $sent,
				'saved'    => count( $keep ),
				'skipped'  => array_slice( $skipped, 0, 200 ),
				'skip_n'   => count( $skipped ),
			)
		);
		self::$cache = null;

		return array(
			'saved'   => count( $keep ),
			'skipped' => $skipped,
		);
	}

	/**
	 * @param mixed $row
	 * @return array|string The cleaned row, or why it was skipped.
	 */
	private static function clean( $row, array $names, string $sent ) {
		if ( ! is_array( $row ) ) {
			return 'not_an_object';
		}
		$id = isset( $row['id'] ) && is_scalar( $row['id'] ) ? (string) $row['id'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id ) ) {
			return 'bad_id';
		}
		$ben = isset( $row['ben'] ) && is_string( $row['ben'] ) ? trim( $row['ben'] ) : '';
		if ( '' === $ben || ! isset( $names[ $ben ] ) ) {
			return 'unknown_ben';
		}
		$title = isset( $row['title'] ) && is_string( $row['title'] ) ? trim( wp_strip_all_tags( $row['title'] ) ) : '';
		if ( '' === $title ) {
			return 'bad_title';
		}
		$phase = $row['phase'] ?? null;
		if ( ! is_int( $phase ) || $phase < 1 || $phase > self::PHASES ) {
			return 'bad_phase';
		}
		$since = isset( $row['since'] ) && is_string( $row['since'] ) && '' !== $row['since'] ? $row['since'] : $sent;
		if ( ! RS_Contracts::is_date( $since ) ) {
			return 'bad_since';
		}
		$release = isset( $row['release'] ) && is_string( $row['release'] ) ? $row['release'] : '';
		if ( '' !== $release && ! RS_Contracts::is_date( $release ) ) {
			return 'bad_release';
		}
		$note = isset( $row['note'] ) && is_string( $row['note'] ) ? trim( sanitize_textarea_field( $row['note'] ) ) : '';
		return array(
			'id'      => $id,
			'ben'     => $ben,
			'title'   => mb_substr( $title, 0, self::TITLE_MAX ),
			'phase'   => $phase,
			'since'   => $since,
			'release' => $release,
			'note'    => mb_substr( $note, 0, self::NOTE_MAX ),
		);
	}

	/** One-line text without tags or control characters. */
	private static function line( $v, int $max ): string {
		if ( ! is_string( $v ) ) {
			return '';
		}
		return mb_substr( trim( sanitize_text_field( $v ) ), 0, $max );
	}

	/**
	 * v2 flows: [{ id, steps:[{ id, name, name_en, about, about_en }] }].
	 *
	 * @param mixed $list
	 * @return array|WP_Error flow id => [ steps ].
	 */
	private static function clean_flows( $list ) {
		if ( ! is_array( $list ) || ( array() !== $list && array_keys( $list ) !== range( 0, count( $list ) - 1 ) ) ) {
			return new WP_Error( 'rs_production_flows', 'flows must be a list.', array( 'status' => 400 ) );
		}
		if ( count( $list ) > self::FLOWS_MAX ) {
			return new WP_Error( 'rs_production_flows', sprintf( 'At most %d flows per request; nothing was saved.', self::FLOWS_MAX ), array( 'status' => 400 ) );
		}
		$out = array();
		foreach ( $list as $f ) {
			$id = is_array( $f ) && isset( $f['id'] ) && is_string( $f['id'] ) ? $f['id'] : '';
			if ( ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id ) || isset( $out[ $id ] ) ) {
				return new WP_Error( 'rs_production_flows', 'Each flow needs its own id (A-Z a-z 0-9 _ -, at most 64).', array( 'status' => 400 ) );
			}
			$steps = $f['steps'] ?? null;
			if ( ! is_array( $steps ) || array() === $steps || array_keys( $steps ) !== range( 0, count( $steps ) - 1 ) || count( $steps ) > self::STEPS_MAX ) {
				return new WP_Error( 'rs_production_flows', sprintf( 'Each flow needs 1 to %d steps.', self::STEPS_MAX ), array( 'status' => 400 ) );
			}
			$seen = array();
			$rows = array();
			foreach ( $steps as $st ) {
				$sid  = is_array( $st ) && isset( $st['id'] ) && is_string( $st['id'] ) ? $st['id'] : '';
				$name = is_array( $st ) ? self::line( $st['name'] ?? '', self::NAME_MAX ) : '';
				if ( ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $sid ) || isset( $seen[ $sid ] ) || '' === $name ) {
					return new WP_Error( 'rs_production_flows', 'Each step needs its own id and a name.', array( 'status' => 400 ) );
				}
				$seen[ $sid ] = true;
				$rows[]       = array(
					'id'       => $sid,
					'name'     => $name,
					'name_en'  => self::line( $st['name_en'] ?? '', self::NAME_MAX ),
					'about'    => mb_substr( trim( sanitize_textarea_field( is_string( $st['about'] ?? null ) ? $st['about'] : '' ) ), 0, self::ABOUT_MAX ),
					'about_en' => mb_substr( trim( sanitize_textarea_field( is_string( $st['about_en'] ?? null ) ? $st['about_en'] : '' ) ), 0, self::ABOUT_MAX ),
				);
			}
			$out[ $id ] = $rows;
		}
		return $out;
	}

	/**
	 * A v2 row: v1's checks with flow and step in place of phase.
	 *
	 * @param mixed $row
	 * @return array|string The cleaned row, or why it was skipped.
	 */
	private static function clean_v2( $row, array $names, string $sent, array $flows ) {
		if ( ! is_array( $row ) ) {
			return 'not_an_object';
		}
		$flow = isset( $row['flow'] ) && is_string( $row['flow'] ) ? $row['flow'] : '';
		if ( ! isset( $flows[ $flow ] ) ) {
			return 'bad_flow';
		}
		$step = isset( $row['step'] ) && is_string( $row['step'] ) ? $row['step'] : '';
		$hit  = false;
		foreach ( $flows[ $flow ] as $st ) {
			$hit = $hit || $st['id'] === $step;
		}
		if ( ! $hit ) {
			return 'bad_step';
		}
		// v1's checks for the rest, with a placeholder phase.
		$r = self::clean( array( 'phase' => 1 ) + $row, $names, $sent );
		if ( is_string( $r ) ) {
			return $r;
		}
		unset( $r['phase'] );
		$products = array();
		foreach ( (array) ( $row['products'] ?? array() ) as $p ) {
			if ( is_int( $p ) && $p > 0 && ! in_array( $p, $products, true ) && count( $products ) < self::PROD_MAX ) {
				$products[] = $p;
			}
		}
		return $r + array(
			'flow'     => $flow,
			'step'     => $step,
			'products' => $products,
		);
	}

	public static function export( string $ben = '' ): array {
		$works = '' === $ben ? self::all() : array_values(
			array_filter(
				self::all(),
				static function ( $w ) use ( $ben ) {
					return $w['ben'] === $ben;
				}
			)
		);
		return array(
			'meta'  => self::meta(),
			'flows' => (object) self::flows(),
			'works' => $works,
		);
	}

	/**
	 * The works a creator sees: their own that are still in production.
	 * 1.21.0 (Chris 2026-11-21): a work at the last step (v1: phase 7,
	 * released) is no longer shown; it is on sale and in the analyses.
	 */
	public static function for_ben( string $ben ): array {
		$today = RS_Contracts::today();
		$flows = self::flows();
		$out   = array();
		foreach ( self::all() as $w ) {
			if ( $w['ben'] !== $ben ) {
				continue;
			}
			if ( self::is_v2( $w ) && self::step_index( $w, $flows ) < 0 ) {
				continue;
			}
			if ( self::is_last( $w, $flows ) ) {
				continue;
			}
			$out[] = $w;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( (string) $b['since'], (string) $a['since'] );
			}
		);
		return $out;
	}

	private static function days_between( string $from, string $to ): int {
		$a = strtotime( $from . ' 00:00:00 UTC' );
		$b = strtotime( $to . ' 00:00:00 UTC' );
		return ( false === $a || false === $b ) ? 0 : (int) floor( ( $b - $a ) / DAY_IN_SECONDS );
	}

	/* =====================================================================
	 * Portal: «Το έργο σου σε παραγωγή»
	 * =================================================================== */

	public static function render_portal( string $who ): void {
		$works = self::for_ben( $who );
		if ( empty( $works ) ) {
			return;
		}
		$names = self::phase_names();
		$texts = self::texts();
		$hist  = self::history();
		$flows = self::flows();
		$today = RS_Contracts::today();
		self::print_css();
		?>
		<h3 id="rs-production"><?php esc_html_e( 'Το έργο σου σε παραγωγή', 'revenue-splitter' ); ?></h3>
		<?php foreach ( $works as $w ) : ?>
			<?php
			if ( self::is_v2( $w ) ) {
				self::render_work_v2( $w, $flows, (array) ( $hist[ $w['id'] ] ?? array() ), $today );
				continue;
			}
			$ph    = (int) $w['phase'];
			$fresh = self::days_between( (string) $w['since'], $today ) <= self::NEW_DAYS;
			$past  = array();
			foreach ( (array) ( $hist[ $w['id'] ] ?? array() ) as $h ) {
				$past[ (int) $h['phase'] ] = (string) $h['at'];
			}
			?>
			<div class="rs-pd-work">
				<h4><?php echo esc_html( (string) $w['title'] ); ?>
					<?php if ( $fresh ) : ?>
						<span class="rs-pd-new"><?php esc_html_e( 'νέο', 'revenue-splitter' ); ?></span>
					<?php endif; ?>
				</h4>
				<ol class="rs-pd-steps" aria-label="<?php esc_attr_e( 'Φάσεις παραγωγής', 'revenue-splitter' ); ?>">
					<?php foreach ( $names as $n => $label ) : ?>
						<?php $cls = $n < $ph ? 'rs-pd-done' : ( $n === $ph ? 'rs-pd-now' : 'rs-pd-next' ); ?>
						<li class="<?php echo esc_attr( $cls ); ?>"<?php echo $n === $ph ? ' aria-current="step"' : ''; ?>>
							<span class="rs-pd-dot" aria-hidden="true"><?php echo esc_html( (string) $n ); ?></span>
							<span class="rs-pd-label"><?php echo esc_html( $label ); ?></span>
							<?php if ( isset( $past[ $n ] ) && $n <= $ph ) : ?>
								<small><?php echo esc_html( RS_Contracts::dmy( $past[ $n ] ) ); ?></small>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
				<p class="rs-pd-where">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: phase name, 2: date */
							__( 'Τώρα: %1$s, από %2$s.', 'revenue-splitter' ),
							$names[ $ph ] ?? '',
							RS_Contracts::dmy( (string) $w['since'] )
						)
					);
					if ( '' !== (string) $w['release'] ) {
						echo ' ' . esc_html(
							sprintf(
								/* translators: %s: date */
								__( 'Αναμενόμενη κυκλοφορία: %s.', 'revenue-splitter' ),
								RS_Contracts::dmy( (string) $w['release'] )
							)
						);
					}
					?>
				</p>
				<p class="rs-pd-about"><?php echo esc_html( $texts[ $ph ] ?? '' ); ?></p>
				<?php if ( '' !== (string) $w['note'] ) : ?>
					<div class="rs-pd-note">
						<strong><?php esc_html_e( 'Σημείωση από τον εκδότη', 'revenue-splitter' ); ?></strong>
						<p><?php echo nl2br( esc_html( (string) $w['note'] ) ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * v2: the publisher's own steps, in the order of the board's columns.
	 * Each step's explanation shows on hover, keyboard focus or tap (the
	 * step is a button; the tip is its accessible description), and the
	 * current step's explanation is also written out under the steps.
	 */
	private static function render_work_v2( array $w, array $flows, array $hist, string $today ): void {
		$steps = (array) ( $flows[ (string) $w['flow'] ] ?? array() );
		$cur   = self::step_index( $w, $flows );
		if ( $cur < 0 ) {
			return;
		}
		$fresh = self::days_between( (string) $w['since'], $today ) <= self::NEW_DAYS;
		$past  = array();
		foreach ( $hist as $h ) {
			if ( is_array( $h ) && isset( $h['s'] ) ) {
				$past[ (string) $h['s'] ] = (string) ( $h['at'] ?? '' );
			}
		}
		$uid  = 'rs-pd-' . substr( md5( (string) $w['id'] ), 0, 8 );
		$now  = $steps[ $cur ];
		$text = self::pick( $now, 'about' );
		?>
		<div class="rs-pd-work">
			<h4><?php echo esc_html( (string) $w['title'] ); ?>
				<?php if ( $fresh ) : ?>
					<span class="rs-pd-new"><?php esc_html_e( 'νέο', 'revenue-splitter' ); ?></span>
				<?php endif; ?>
			</h4>
			<ol class="rs-pd-steps rs-pd-flow" aria-label="<?php esc_attr_e( 'Φάσεις παραγωγής', 'revenue-splitter' ); ?>">
				<?php foreach ( $steps as $i => $st ) : ?>
					<?php
					$cls   = $i < $cur ? 'rs-pd-done' : ( $i === $cur ? 'rs-pd-now' : 'rs-pd-next' );
					$about = self::pick( $st, 'about' );
					$key   = (string) $w['flow'] . '/' . (string) $st['id'];
					$tip   = $uid . '-' . $i;
					?>
					<li class="<?php echo esc_attr( $cls ); ?>"<?php echo $i === $cur ? ' aria-current="step"' : ''; ?>>
						<button type="button" class="rs-pd-btn"<?php echo '' !== $about ? ' aria-describedby="' . esc_attr( $tip ) . '"' : ''; ?>>
							<span class="rs-pd-dot" aria-hidden="true"><?php echo esc_html( (string) ( $i + 1 ) ); ?></span>
							<span class="rs-pd-label"><?php echo esc_html( self::pick( $st, 'name' ) ); ?></span>
							<?php if ( isset( $past[ $key ] ) && $i <= $cur && '' !== $past[ $key ] ) : ?>
								<small><?php echo esc_html( RS_Contracts::dmy( $past[ $key ] ) ); ?></small>
							<?php endif; ?>
						</button>
						<?php if ( '' !== $about ) : ?>
							<span class="rs-pd-tip" role="tooltip" id="<?php echo esc_attr( $tip ); ?>"><?php echo esc_html( $about ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
			<p class="rs-pd-where">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: phase name, 2: date */
						__( 'Τώρα: %1$s, από %2$s.', 'revenue-splitter' ),
						self::pick( $now, 'name' ),
						RS_Contracts::dmy( (string) $w['since'] )
					)
				);
				if ( '' !== (string) $w['release'] ) {
					echo ' ' . esc_html(
						sprintf(
							/* translators: %s: date */
							__( 'Αναμενόμενη κυκλοφορία: %s.', 'revenue-splitter' ),
							RS_Contracts::dmy( (string) $w['release'] )
						)
					);
				}
				?>
			</p>
			<?php if ( '' !== $text ) : ?>
				<p class="rs-pd-about"><?php echo esc_html( $text ); ?></p>
			<?php endif; ?>
			<?php
			// Only published products without a password, by their post.
			$links = array();
			foreach ( (array) ( $w['products'] ?? array() ) as $pid ) {
				$pid = (int) $pid;
				if ( $pid > 0 && 'product' === get_post_type( $pid ) && 'publish' === get_post_status( $pid ) && '' === (string) get_post_field( 'post_password', $pid ) ) {
					$links[ $pid ] = (string) get_the_title( $pid );
				}
			}
			if ( count( $links ) > 0 ) :
				?>
				<p class="rs-pd-products">
					<?php foreach ( $links as $pid => $name ) : ?>
						<?php /* translators: %s: product name */ ?>
						<a href="<?php echo esc_url( (string) get_permalink( $pid ) ); ?>"><?php echo esc_html( sprintf( __( 'Στο κατάστημα: %s', 'revenue-splitter' ), $name ) ); ?></a>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>
			<?php if ( '' !== (string) $w['note'] ) : ?>
				<div class="rs-pd-note">
					<strong><?php esc_html_e( 'Σημείωση από τον εκδότη', 'revenue-splitter' ); ?></strong>
					<p><?php echo nl2br( esc_html( (string) $w['note'] ) ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function print_css(): void {
		?>
<style>
.rs-portal .rs-pd-work { margin: 0 0 1.4em; padding: 12px 14px; border: 1px solid #2e3240; border-radius: 6px; }
.rs-portal .rs-pd-work h4 { margin: 0 0 0.6em; }
.rs-portal .rs-pd-new { display: inline-block; margin-left: 6px; padding: 1px 8px; border-radius: 999px; font-size: 0.75em; font-weight: 600; color: #8fe6ab !important; background: rgba(143, 230, 171, 0.12) !important; border: 1px solid rgba(143, 230, 171, 0.4); }
.rs-portal .rs-pd-steps { list-style: none; margin: 0 0 0.8em; padding: 0; display: flex; flex-wrap: wrap; gap: 6px; }
.rs-portal .rs-pd-steps li { flex: 1 1 120px; display: flex; flex-direction: column; gap: 2px; padding: 6px 8px; border-radius: 4px; border: 1px solid #2e3240; font-size: 0.85em; margin: 0; }
.rs-portal .rs-pd-dot { font-weight: 700; }
.rs-portal .rs-pd-done { color: #b7b2d6 !important; background: rgba(183, 178, 214, 0.08) !important; }
.rs-portal .rs-pd-now { color: #f0eefc !important; background: rgba(190, 177, 255, 0.18) !important; border-color: #beb1ff !important; }
.rs-portal .rs-pd-next { color: #8a86a6 !important; }
.rs-portal .rs-pd-steps small { font-size: 0.85em; }
.rs-portal .rs-pd-where { margin: 0 0 0.4em; font-weight: 600; }
.rs-portal .rs-pd-about { margin: 0 0 0.6em; color: #b7b2d6 !important; }
.rs-portal .rs-pd-note { padding: 8px 10px; border-left: 3px solid #beb1ff; background: rgba(190, 177, 255, 0.08) !important; }
.rs-portal .rs-pd-note p { margin: 0.3em 0 0; }
.rs-portal .rs-pd-steps.rs-pd-flow { flex-wrap: nowrap; gap: 0; margin: 0 0 1em; }
.rs-portal .rs-pd-steps.rs-pd-flow li { position: relative; flex: 1 1 0; min-width: 0; padding: 0; border: 0 !important; background: none !important; text-align: center; font-size: 0.85em; }
.rs-portal .rs-pd-steps.rs-pd-flow li::before { content: ""; position: absolute; top: 13px; left: -50%; width: 100%; height: 3px; background: #2e3240; }
.rs-portal .rs-pd-steps.rs-pd-flow li:first-child::before { display: none; }
.rs-portal .rs-pd-steps.rs-pd-flow li.rs-pd-done::before, .rs-portal .rs-pd-steps.rs-pd-flow li.rs-pd-now::before { background: #beb1ff; }
.rs-portal .rs-pd-btn { all: unset; box-sizing: border-box; display: flex; flex-direction: column; align-items: center; gap: 4px; width: 100%; padding: 0 4px 4px; cursor: pointer; color: inherit !important; font: inherit; }
.rs-portal .rs-pd-btn:focus-visible { outline: 2px solid #beb1ff; outline-offset: 2px; border-radius: 4px; }
.rs-portal .rs-pd-flow .rs-pd-dot { position: relative; z-index: 1; box-sizing: border-box; width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.9em; border: 2px solid #3a3f52; color: #8a86a6 !important; background: #14161d !important; }
.rs-portal .rs-pd-flow .rs-pd-done .rs-pd-dot { border-color: #beb1ff; color: #14161d !important; background: #beb1ff !important; }
.rs-portal .rs-pd-flow .rs-pd-now .rs-pd-dot { border-color: #beb1ff; color: #f0eefc !important; background: #6d4aff !important; box-shadow: 0 0 0 4px rgba(190, 177, 255, 0.25); }
.rs-portal .rs-pd-flow .rs-pd-label { overflow-wrap: anywhere; hyphens: auto; line-height: 1.25; }
.rs-portal .rs-pd-flow .rs-pd-now .rs-pd-label { font-weight: 700; color: #f0eefc !important; }
.rs-portal .rs-pd-tip { display: none; position: absolute; z-index: 20; left: 50%; top: calc(100% + 2px); transform: translateX(-50%); width: max-content; max-width: min(300px, 80vw); padding: 8px 10px; border-radius: 4px; border: 1px solid #3a3f52; font-size: 1.05em; line-height: 1.4; text-align: left; color: #eae8fa !important; background: #1d2029 !important; box-shadow: 0 6px 18px rgba(0, 0, 0, 0.35); white-space: normal; }
.rs-portal .rs-pd-flow li:first-child .rs-pd-tip { left: 0; transform: none; }
.rs-portal .rs-pd-flow li:last-child .rs-pd-tip { left: auto; right: 0; transform: none; }
.rs-portal .rs-pd-flow li:hover .rs-pd-tip, .rs-portal .rs-pd-flow li:focus-within .rs-pd-tip { display: block; }
.rs-portal .rs-pd-products { margin: 0 0 0.6em; display: flex; flex-wrap: wrap; gap: 4px 14px; }
@media (max-width: 600px) {
	.rs-portal .rs-pd-steps.rs-pd-flow { flex-direction: column; }
	.rs-portal .rs-pd-steps.rs-pd-flow li { text-align: left; }
	.rs-portal .rs-pd-steps.rs-pd-flow li::before { top: -50%; left: 13px; width: 3px; height: 100%; }
	.rs-portal .rs-pd-btn { flex-direction: row; justify-content: flex-start; gap: 10px; padding: 4px 0; }
	.rs-portal .rs-pd-tip, .rs-portal .rs-pd-flow li:first-child .rs-pd-tip, .rs-portal .rs-pd-flow li:last-child .rs-pd-tip { position: static; transform: none; width: auto; max-width: none; margin: 0 0 6px 38px; box-shadow: none; }
}
</style>
		<?php
	}

	/* =====================================================================
	 * Admin: «RS Παραγωγή» (read-only list + phase texts)
	 * =================================================================== */

	/** The stage of a row for the admin list: «3. Name» (v1) or «3/9 Name» (v2). */
	private static function admin_stage( array $w, array $names, array $flows ): string {
		if ( ! self::is_v2( $w ) ) {
			return (int) $w['phase'] . '. ' . ( $names[ (int) $w['phase'] ] ?? '' );
		}
		$i = self::step_index( $w, $flows );
		if ( $i < 0 ) {
			return '—';
		}
		$steps = (array) $flows[ (string) $w['flow'] ];
		return ( $i + 1 ) . '/' . count( $steps ) . ' ' . (string) $steps[ $i ]['name'];
	}

	public static function admin_menu(): void {
		add_submenu_page(
			RS_Admin_UI::SLUG_MENU,
			__( 'Revenue Splitter — Παραγωγή', 'revenue-splitter' ),
			__( 'RS Παραγωγή', 'revenue-splitter' ),
			RS_Admin_UI::CAP,
			self::SLUG,
			array( __CLASS__, 'render_admin' )
		);
	}

	public static function route_admin(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked right below.
		if ( ! isset( $_POST['rs_pd_admin'] ) ) {
			return;
		}
		if ( ! isset( $_POST['rs_pd_admin_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rs_pd_admin_nonce'] ) ), 'rs_pd_admin' )
			|| ! current_user_can( RS_Admin_UI::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}
		$in  = isset( $_POST['rs_pd_text'] ) && is_array( $_POST['rs_pd_text'] ) ? wp_unslash( $_POST['rs_pd_text'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per item below.
		$def = self::default_texts();
		$own = array();
		foreach ( array_keys( $def ) as $n ) {
			$v = isset( $in[ $n ] ) ? trim( sanitize_textarea_field( (string) $in[ $n ] ) ) : '';
			if ( '' !== $v && $v !== $def[ $n ] ) {
				$own[ (string) $n ] = mb_substr( $v, 0, self::TEXT_MAX );
			}
		}
		self::write_json( self::OPT_TXT, $own );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&rs_pd_done=texts' ) );
		exit;
	}

	public static function render_admin(): void {
		if ( ! current_user_can( RS_Admin_UI::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}
		$meta  = self::meta();
		$works = self::all();
		$names = self::phase_names();
		$texts = self::texts();
		$flows = self::flows();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only message flag.
		$done = isset( $_GET['rs_pd_done'] ) ? sanitize_key( wp_unslash( $_GET['rs_pd_done'] ) ) : '';
		?>
		<div class="wrap rs-wrap">
			<h1><?php esc_html_e( 'Revenue Splitter — Παραγωγή', 'revenue-splitter' ); ?></h1>
			<?php if ( 'texts' === $done ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Τα κείμενα των φάσεων αποθηκεύτηκαν.', 'revenue-splitter' ); ?></p></div>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Η φάση κάθε έργου έρχεται από το orOS (πίνακας Kanban «Βιβλία»). Εδώ τη βλέπεις μόνο· την αλλάζεις στο orOS.', 'revenue-splitter' ); ?></p>
			<ul>
				<li><code><?php echo esc_html( 'POST ' . rest_url( 'rs/v1/production' ) ); ?></code> <code>{"v":1,"sent":"YYYY-MM-DD","works":[{"id","ben","title","phase":1-7,"since","release","note"}]}</code></li>
				<li><code>{"v":2,"sent","flows":[{"id","steps":[{"id","name","name_en","about","about_en"}]}],"works":[{"id","ben","title","flow","step","since","release","note","products"}]}</code></li>
				<li><code><?php echo esc_html( 'GET ' . rest_url( 'rs/v1/production' ) ); ?></code></li>
			</ul>
			<p>
				<?php
				if ( empty( $meta['received'] ) ) {
					esc_html_e( 'Δεν έχει έρθει ακόμα λίστα από το orOS.', 'revenue-splitter' );
				} else {
					echo esc_html(
						sprintf(
							/* translators: 1: date and time, 2: saved, 3: skipped */
							__( 'Τελευταία λίστα: %1$s · αποθηκεύτηκαν %2$d · παραλείφθηκαν %3$d', 'revenue-splitter' ),
							wp_date( 'd/m/Y H:i', (int) strtotime( (string) $meta['received'] ) ),
							(int) ( $meta['saved'] ?? 0 ),
							(int) ( $meta['skip_n'] ?? 0 )
						)
					);
				}
				?>
			</p>
			<?php if ( ! empty( $works ) ) : ?>
				<table class="widefat striped rs-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Τίτλος', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Δικαιούχος', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Φάση', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Από', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Κυκλοφορία', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Σημείωση', 'revenue-splitter' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $works as $w ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $w['title'] ); ?></td>
							<td><?php echo esc_html( (string) $w['ben'] ); ?></td>
							<td><?php echo esc_html( self::admin_stage( $w, $names, $flows ) ); ?></td>
							<td><?php echo esc_html( RS_Contracts::dmy( (string) $w['since'] ) ); ?></td>
							<td><?php echo esc_html( '' !== (string) $w['release'] ? RS_Contracts::dmy( (string) $w['release'] ) : '—' ); ?></td>
							<td><?php echo esc_html( (string) $w['note'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( ! empty( $flows ) ) : ?>
				<h2 class="rs-h2"><?php esc_html_e( 'Βήματα από το orOS', 'revenue-splitter' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Οι στήλες που σημείωσες στο Kanban του orOS ως βήματα, με τη σειρά τους και την επεξήγηση που βλέπει ο δημιουργός. Τα αλλάζεις στο orOS.', 'revenue-splitter' ); ?></p>
				<?php foreach ( $flows as $steps ) : ?>
					<ol>
						<?php foreach ( $steps as $st ) : ?>
							<li><strong><?php echo esc_html( (string) $st['name'] ); ?></strong><?php echo '' !== (string) $st['about'] ? ' — ' . esc_html( (string) $st['about'] ) : ''; ?></li>
						<?php endforeach; ?>
					</ol>
				<?php endforeach; ?>
			<?php endif; ?>

			<h2 class="rs-h2"><?php esc_html_e( 'Τι γίνεται σε κάθε φάση', 'revenue-splitter' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Ο δημιουργός βλέπει αυτό το κείμενο κάτω από τη φάση του έργου του. Κενό = το προεπιλεγμένο.', 'revenue-splitter' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'rs_pd_admin', 'rs_pd_admin_nonce' ); ?>
				<input type="hidden" name="rs_pd_admin" value="texts" />
				<?php foreach ( $names as $n => $label ) : ?>
					<p>
						<label for="rs-pd-text-<?php echo esc_attr( (string) $n ); ?>"><strong><?php echo esc_html( $n . '. ' . $label ); ?></strong></label><br />
						<textarea id="rs-pd-text-<?php echo esc_attr( (string) $n ); ?>" name="rs_pd_text[<?php echo esc_attr( (string) $n ); ?>]" rows="3" class="large-text" maxlength="<?php echo esc_attr( (string) self::TEXT_MAX ); ?>"><?php echo esc_textarea( $texts[ $n ] ); ?></textarea>
					</p>
				<?php endforeach; ?>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση κειμένων', 'revenue-splitter' ); ?></button></p>
			</form>
		</div>
		<?php
	}
}
