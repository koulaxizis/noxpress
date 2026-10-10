<?php
/**
 * EWD_Form — the withdrawal function, shortcode [nox_withdrawal] (design §3).
 *
 * Steps (the same for guests and customers):
 *  1. find   order number + billing email (guests), or a list of the
 *            customer's open orders. Links from emails, the thank-you page
 *            and My Account skip this step (order key or ownership).
 *  2. items  products and quantities, name, optional reason
 *  3. review the statement with the button "Επιβεβαίωση υπαναχώρησης"
 *  4. done   the statement is recorded at once (EWD_Requests::add), the
 *            receipt goes to the order's billing email, the page shows it
 *
 * State between the steps travels in a signed token (HMAC-SHA256 with
 * wp_salt, 30 minutes): nothing is written before the confirmation.
 * Failed lookups are limited per IP (hashed): 5 per 15 minutes.
 * The page is never cached (DONOTCACHEPAGE + nocache headers).
 * Logged-in users also carry a nonce (CSRF).
 */

defined( 'ABSPATH' ) || exit;

final class EWD_Form {

	const SHORTCODE = 'nox_withdrawal';
	const TOKEN_TTL = 1800;
	const DONE_TTL  = DAY_IN_SECONDS;
	const RL_MAX    = 5;
	const RL_WIN    = 900;

	/** @var string Error of the confirm step, shown by the shortcode. */
	private static $confirm_error = '';

	/** @var bool Assets already printed in this request. */
	private static $css_done = false;

	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'template_redirect' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_early' ) );
	}

	/* =====================================================================
	 * Tokens
	 * =================================================================== */

	private static function sign( string $payload ): string {
		return hash_hmac( 'sha256', 'ewd|' . $payload, wp_salt( 'auth' ) );
	}

	/** Token for steps 2–3: the order may be shown to whoever holds it. */
	public static function token( int $order_id, string $source ): string {
		$payload = 'o|' . $order_id . '|' . $source . '|' . ( time() + self::TOKEN_TTL );
		return rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ) . '.' . self::sign( $payload ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe transport, not obfuscation.
	}

	/**
	 * Verify a token of the given kind ('o' | 'd').
	 *
	 * @return string[]|null The payload parts after the kind, or null.
	 */
	private static function verify( string $token, string $kind ): ?array {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) || strlen( $parts[0] ) > 200 ) {
			return null;
		}
		$payload = base64_decode( strtr( $parts[0], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( ! is_string( $payload ) || ! hash_equals( self::sign( $payload ), $parts[1] ) ) {
			return null;
		}
		$f = explode( '|', $payload );
		if ( $kind !== $f[0] || (int) end( $f ) < time() ) {
			return null;
		}
		return array_slice( $f, 1, -1 );
	}

	/** Receipt token (step 4), valid for a day. */
	private static function done_token( int $order_id, string $req_id ): string {
		$payload = 'd|' . $order_id . '|' . $req_id . '|' . ( time() + self::DONE_TTL );
		return rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ) . '.' . self::sign( $payload ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/* =====================================================================
	 * Request helpers
	 * =================================================================== */

	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- token / nonce verified by the callers.
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	private static function get( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- links carry their own proof (key, ownership or token).
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? (string) wp_unslash( $_GET[ $key ] ) : '';
	}

	/** Posted quantities item_id => qty (ints only). */
	private static function posted_qty(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified by the callers, cast to ints below.
		$raw = isset( $_POST['ewd_q'] ) && is_array( $_POST['ewd_q'] ) ? wp_unslash( $_POST['ewd_q'] ) : array();
		$out = array();
		foreach ( $raw as $k => $v ) {
			if ( ctype_digit( (string) $k ) && is_scalar( $v ) && ctype_digit( (string) $v ) && (int) $v > 0 ) {
				$out[ (int) $k ] = min( 9999, (int) $v );
			}
		}
		return $out;
	}

	private static function nonce_ok(): bool {
		if ( ! is_user_logged_in() ) {
			return true;
		}
		$n = self::post( 'ewd_nonce' );
		return '' !== $n && false !== wp_verify_nonce( $n, 'ewd_form' );
	}

	/**
	 * Button classes: "button" for classic themes plus WooCommerce's
	 * element class for block themes (wp-element-button), so the form's
	 * buttons look like the theme's own.
	 */
	public static function btn( string $extra = '' ): string {
		$cls = 'button';
		if ( function_exists( 'wc_wp_theme_get_element_class_name' ) ) {
			$el  = (string) wc_wp_theme_get_element_class_name( 'button' );
			$cls = '' !== $el ? $cls . ' ' . $el : $cls;
		}
		return trim( $cls . ' ' . $extra );
	}

	/** URL of the page the form is on (falls back to the configured page). */
	private static function here(): string {
		$id = get_queried_object_id();
		if ( $id && is_singular() ) {
			$url = get_permalink( $id );
			if ( is_string( $url ) ) {
				return $url;
			}
		}
		$url = EWD_Settings::page_url();
		return '' !== $url ? $url : home_url( '/' );
	}

	private static function ip_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		return 'ewd_rl_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
	}

	private static function limited(): bool {
		return (int) get_transient( self::ip_key() ) >= self::RL_MAX;
	}

	private static function fail(): void {
		$k = self::ip_key();
		set_transient( $k, (int) get_transient( $k ) + 1, self::RL_WIN );
	}

	/** True on the page that holds the form. */
	private static function on_form_page(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$id = (int) get_queried_object_id();
		if ( $id && $id === (int) EWD_Settings::get()['page_id'] ) {
			return true;
		}
		$post = get_post( $id );
		return $post && has_shortcode( (string) $post->post_content, self::SHORTCODE );
	}

	/**
	 * An order the current visitor may open without the lookup step:
	 * order key (email and thank-you links) or ownership (My Account).
	 *
	 * @return array{0: WC_Order|null, 1: string} Order and source.
	 */
	private static function order_from_link(): array {
		$id = self::get( 'ewd_order' );
		if ( '' === $id || ! ctype_digit( $id ) ) {
			return array( null, '' );
		}
		$order = wc_get_order( (int) $id );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
			return array( null, '' );
		}
		$key = self::get( 'key' );
		if ( '' !== $key && hash_equals( (string) $order->get_order_key(), $key ) ) {
			return array( $order, 'link' );
		}
		$uid = get_current_user_id();
		if ( $uid && (int) $order->get_customer_id() === $uid ) {
			return array( $order, 'account' );
		}
		return array( null, '' );
	}

	/* =====================================================================
	 * template_redirect: no cache + the confirm step (PRG)
	 * =================================================================== */

	public static function template_redirect(): void {
		try {
			if ( ! self::on_form_page() ) {
				return;
			}
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();

			if ( ! EWD_Settings::visible() || 'confirm' !== self::post( 'ewd_step' ) ) {
				return;
			}
			if ( '' !== self::post( 'ewd_website' ) ) {
				return; // Honeypot.
			}
			if ( ! self::nonce_ok() ) {
				self::$confirm_error = __( 'Η φόρμα έληξε. Ξεκίνα ξανά.', 'easy-withdrawal' );
				return;
			}
			$t = self::verify( self::post( 'ewd_t' ), 'o' );
			if ( null === $t ) {
				self::$confirm_error = __( 'Η φόρμα έληξε. Ξεκίνα ξανά.', 'easy-withdrawal' );
				return;
			}
			$order = wc_get_order( (int) $t[0] );
			if ( ! $order instanceof WC_Order ) {
				self::$confirm_error = __( 'Η φόρμα έληξε. Ξεκίνα ξανά.', 'easy-withdrawal' );
				return;
			}
			$name = trim( sanitize_text_field( self::post( 'ewd_name' ) ) );
			if ( '' === $name ) {
				self::$confirm_error = __( 'Συμπλήρωσε το όνομά σου.', 'easy-withdrawal' );
				return;
			}
			$req = EWD_Requests::add( $order, self::posted_qty(), $name, sanitize_textarea_field( self::post( 'ewd_reason' ) ), (string) ( $t[1] ?? 'form' ) );
			if ( is_wp_error( $req ) ) {
				self::$confirm_error = $req->get_error_message();
				return;
			}
			wp_safe_redirect( add_query_arg( 'ewd_done', self::done_token( $order->get_id(), (string) $req['id'] ), self::here() ) );
			exit;
		} catch ( \Throwable $e ) {
			self::$confirm_error = __( 'Κάτι πήγε στραβά και η δήλωση δεν καταγράφηκε. Δοκίμασε ξανά ή επικοινώνησε με το κατάστημα.', 'easy-withdrawal' );
		}
	}

	/* =====================================================================
	 * Shortcode
	 * =================================================================== */

	public static function shortcode( $atts = array() ): string {
		if ( ! EWD_Settings::visible() ) {
			return '';
		}
		try {
			self::assets();
			ob_start();
			echo '<div class="ewd-root" id="ewd-form">';
			if ( 'test' === EWD_Settings::get()['mode'] ) {
				echo '<p class="ewd-note ewd-note--test">' . esc_html__( 'Λειτουργία δοκιμής: τη φόρμα τη βλέπουν μόνο οι διαχειριστές του καταστήματος.', 'easy-withdrawal' ) . '</p>';
			}
			self::route();
			echo '</div>';
			return (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			if ( ob_get_level() ) {
				ob_end_clean();
			}
			return '';
		}
	}

	private static function route(): void {

		// Step 4: receipt.
		$done = self::get( 'ewd_done' );
		if ( '' !== $done ) {
			$d = self::verify( $done, 'd' );
			if ( null !== $d ) {
				$order = wc_get_order( (int) $d[0] );
				$req   = $order instanceof WC_Order ? EWD_Requests::get( $order, (string) $d[1] ) : null;
				if ( $req ) {
					self::render_done( $order, $req );
					return;
				}
			}
		}

		$step = self::post( 'ewd_step' );

		// Error of the confirm step: back to the items with the message.
		if ( 'confirm' === $step ) {
			$t     = self::verify( self::post( 'ewd_t' ), 'o' );
			$order = null !== $t ? wc_get_order( (int) $t[0] ) : null;
			if ( $order instanceof WC_Order ) {
				self::render_items( $order, self::post( 'ewd_t' ), self::$confirm_error, self::posted_qty() );
				return;
			}
			self::render_find( '' !== self::$confirm_error ? self::$confirm_error : __( 'Η φόρμα έληξε. Ξεκίνα ξανά.', 'easy-withdrawal' ) );
			return;
		}

		if ( 'find' === $step ) {
			self::step_find();
			return;
		}

		if ( 'items' === $step || 'back' === $step ) {
			self::step_items( 'back' === $step );
			return;
		}

		// Links: email / thank-you (order key) or My Account (ownership).
		list( $order, $source ) = self::order_from_link();
		if ( $order instanceof WC_Order ) {
			self::render_items( $order, self::token( $order->get_id(), $source ), '', array() );
			return;
		}

		self::render_find( '' );
	}

	private static function step_find(): void {
		if ( '' !== self::post( 'ewd_website' ) || ! self::nonce_ok() ) {
			self::render_find( __( 'Η φόρμα έληξε. Ξεκίνα ξανά.', 'easy-withdrawal' ) );
			return;
		}
		if ( self::limited() ) {
			self::render_find( __( 'Πολλές αποτυχημένες προσπάθειες. Δοκίμασε ξανά σε 15 λεπτά.', 'easy-withdrawal' ) );
			return;
		}
		$order = EWD_Rules::find_order( sanitize_text_field( self::post( 'ewd_number' ) ), sanitize_email( self::post( 'ewd_email' ) ) );
		if ( ! $order ) {
			self::fail();
			self::render_find( __( 'Δεν βρέθηκε παραγγελία με αυτόν τον αριθμό και αυτό το email. Έλεγξε το email επιβεβαίωσης της παραγγελίας.', 'easy-withdrawal' ) );
			return;
		}
		self::render_items( $order, self::token( $order->get_id(), 'form' ), '', array() );
	}

	private static function step_items( bool $back ): void {
		$token = self::post( 'ewd_t' );
		$t     = self::verify( $token, 'o' );
		$order = null !== $t ? wc_get_order( (int) $t[0] ) : null;
		if ( ! $order instanceof WC_Order || ! self::nonce_ok() ) {
			self::render_find( __( 'Η φόρμα έληξε. Ξεκίνα ξανά.', 'easy-withdrawal' ) );
			return;
		}

		$rows = EWD_Rules::items( $order );
		if ( '' !== self::post( 'ewd_all' ) ) {
			$qty = array();
			foreach ( $rows as $iid => $row ) {
				if ( $row['available'] > 0 ) {
					$qty[ $iid ] = $row['available'];
				}
			}
		} else {
			$qty = self::posted_qty();
		}

		if ( $back ) {
			self::render_items( $order, $token, '', $qty );
			return;
		}

		$name = trim( sanitize_text_field( self::post( 'ewd_name' ) ) );
		if ( '' === $name ) {
			self::render_items( $order, $token, __( 'Συμπλήρωσε το όνομά σου.', 'easy-withdrawal' ), $qty );
			return;
		}
		$clean = array();
		foreach ( $qty as $iid => $q ) {
			if ( isset( $rows[ $iid ] ) && $rows[ $iid ]['available'] > 0 ) {
				$clean[ $iid ] = min( $q, $rows[ $iid ]['available'] );
			}
		}
		if ( ! $clean ) {
			self::render_items( $order, $token, __( 'Διάλεξε τουλάχιστον ένα προϊόν.', 'easy-withdrawal' ), $qty );
			return;
		}
		self::render_review( $order, $token, $clean, $name, sanitize_textarea_field( self::post( 'ewd_reason' ) ) );
	}

	/* =====================================================================
	 * Views
	 * =================================================================== */

	private static function form_open( string $step ): void {
		echo '<form method="post" action="' . esc_url( self::here() ) . '#ewd-form" class="ewd-form">';
		echo '<input type="hidden" name="ewd_step" value="' . esc_attr( $step ) . '" />';
		if ( is_user_logged_in() ) {
			wp_nonce_field( 'ewd_form', 'ewd_nonce', false );
		}
		echo '<p class="ewd-hp" aria-hidden="true"><label>Website <input type="text" name="ewd_website" value="" tabindex="-1" autocomplete="off" /></label></p>';
	}

	private static function error( string $msg ): void {
		if ( '' !== $msg ) {
			echo '<p class="ewd-note ewd-note--error" role="alert">' . esc_html( $msg ) . '</p>';
		}
	}

	private static function steps( int $current ): void {
		$labels = array(
			1 => __( 'Παραγγελία', 'easy-withdrawal' ),
			2 => __( 'Προϊόντα', 'easy-withdrawal' ),
			3 => __( 'Επιβεβαίωση', 'easy-withdrawal' ),
			4 => __( 'Απόδειξη', 'easy-withdrawal' ),
		);
		echo '<ol class="ewd-steps">';
		foreach ( $labels as $n => $l ) {
			$cls = $n === $current ? ' class="is-current" aria-current="step"' : ( $n < $current ? ' class="is-done"' : '' );
			echo '<li' . $cls . '>' . esc_html( $l ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute strings.
		}
		echo '</ol>';
	}

	private static function render_find( string $error ): void {
		self::steps( 1 );
		echo '<h2 class="ewd-h">' . esc_html( EWD_Settings::text( 'label' ) ) . '</h2>';
		echo '<p>' . esc_html__( 'Μπορείς να υπαναχωρήσεις από την αγορά σου μέσα στην προθεσμία, χωρίς να δώσεις αιτιολογία. Βρες την παραγγελία σου για να ξεκινήσεις.', 'easy-withdrawal' ) . '</p>';
		self::error( $error );

		$uid = get_current_user_id();
		if ( $uid ) {
			$own = wc_get_orders(
				array(
					'customer_id' => $uid,
					'status'      => EWD_Rules::statuses(),
					'limit'       => 20,
					'orderby'     => 'date',
					'order'       => 'DESC',
					'type'        => 'shop_order',
				)
			);
			$open = array();
			foreach ( (array) $own as $o ) {
				if ( $o instanceof WC_Order && EWD_Rules::order_open( $o ) ) {
					$open[] = $o;
				}
			}
			if ( $open ) {
				echo '<h3 class="ewd-h3">' . esc_html__( 'Οι παραγγελίες σου', 'easy-withdrawal' ) . '</h3>';
				echo '<table class="ewd-table shop_table"><tbody>';
				foreach ( $open as $o ) {
					$created = $o->get_date_created();
					echo '<tr><td>#' . esc_html( $o->get_order_number() ) . '</td><td>' . ( $created ? esc_html( EWD_Lang::date( $created->getTimestamp() ) ) : '' ) . '</td><td>' . wp_kses_post( $o->get_formatted_order_total() ) . '</td>';
					echo '<td class="ewd-right"><a class="' . esc_attr( self::btn() ) . '" href="' . esc_url( add_query_arg( 'ewd_order', $o->get_id(), self::here() ) . '#ewd-form' ) . '">' . esc_html__( 'Επιλογή', 'easy-withdrawal' ) . '</a></td></tr>';
				}
				echo '</tbody></table>';
				echo '<h3 class="ewd-h3">' . esc_html__( 'Άλλη παραγγελία', 'easy-withdrawal' ) . '</h3>';
			}
		}

		self::form_open( 'find' );
		echo '<p class="ewd-field"><label for="ewd-number">' . esc_html__( 'Αριθμός παραγγελίας', 'easy-withdrawal' ) . '</label>';
		echo '<input type="text" id="ewd-number" name="ewd_number" required maxlength="40" inputmode="numeric" autocomplete="off" value="' . esc_attr( sanitize_text_field( self::post( 'ewd_number' ) ) ) . '" /></p>';
		echo '<p class="ewd-field"><label for="ewd-email">' . esc_html__( 'Email της παραγγελίας', 'easy-withdrawal' ) . '</label>';
		echo '<input type="email" id="ewd-email" name="ewd_email" required maxlength="190" autocomplete="email" value="' . esc_attr( sanitize_email( self::post( 'ewd_email' ) ) ) . '" /></p>';
		echo '<p class="ewd-actions"><button type="submit" class="' . esc_attr( self::btn() ) . '">' . esc_html__( 'Εύρεση παραγγελίας', 'easy-withdrawal' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * @param array $qty item_id => qty to pre-select.
	 */
	private static function render_items( WC_Order $order, string $token, string $error, array $qty ): void {
		$rows = EWD_Rules::items( $order );
		$any  = false;
		foreach ( $rows as $row ) {
			if ( $row['available'] > 0 ) {
				$any = true;
				break;
			}
		}

		self::steps( 2 );
		echo '<h2 class="ewd-h">' . esc_html( sprintf( /* translators: %s: order number */ __( 'Παραγγελία #%s', 'easy-withdrawal' ), $order->get_order_number() ) ) . '</h2>';
		self::error( $error );
		self::render_previous( $order );

		if ( ! $any ) {
			echo '<p class="ewd-note">' . esc_html__( 'Δεν υπάρχουν προϊόντα αυτής της παραγγελίας για τα οποία μπορείς να υπαναχωρήσεις: η προθεσμία έληξε, έχουν ήδη ζητηθεί ή επιστραφεί, ή εξαιρούνται.', 'easy-withdrawal' ) . '</p>';
			self::render_rows_readonly( $rows );
			return;
		}

		$name = self::post( 'ewd_name' );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}

		self::form_open( 'items' );
		echo '<input type="hidden" name="ewd_t" value="' . esc_attr( $token ) . '" />';
		echo '<table class="ewd-table shop_table"><thead><tr>';
		echo '<th>' . esc_html__( 'Προϊόν', 'easy-withdrawal' ) . '</th><th>' . esc_html__( 'Αγοράστηκαν', 'easy-withdrawal' ) . '</th><th>' . esc_html__( 'Υπαναχώρηση για', 'easy-withdrawal' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $iid => $row ) {
			$cls = $row['available'] > 0 ? '' : ' class="is-off"';
			echo '<tr' . $cls . '><td>' . esc_html( $row['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string.
			$why = self::row_reason( $row );
			if ( '' !== $why ) {
				echo '<br /><small class="ewd-why">' . esc_html( $why ) . '</small>';
			}
			echo '</td><td>' . (int) $row['qty'] . '</td><td>';
			if ( $row['available'] > 0 ) {
				$sel = isset( $qty[ $iid ] ) ? min( (int) $qty[ $iid ], $row['available'] ) : 0;
				echo '<select name="ewd_q[' . (int) $iid . ']" aria-label="' . esc_attr( sprintf( /* translators: %s: product name */ __( 'Ποσότητα για %s', 'easy-withdrawal' ), $row['name'] ) ) . '">';
				for ( $i = 0; $i <= $row['available']; $i++ ) {
					echo '<option value="' . (int) $i . '"' . selected( $sel, $i, false ) . '>' . (int) $i . '</option>';
				}
				echo '</select>';
			} else {
				echo '—';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<p class="ewd-field"><label for="ewd-name">' . esc_html__( 'Ονοματεπώνυμο', 'easy-withdrawal' ) . '</label>';
		echo '<input type="text" id="ewd-name" name="ewd_name" required maxlength="120" autocomplete="name" value="' . esc_attr( $name ) . '" /></p>';
		echo '<p class="ewd-field"><label for="ewd-reason">' . esc_html__( 'Αιτιολογία (προαιρετική)', 'easy-withdrawal' ) . '</label>';
		echo '<textarea id="ewd-reason" name="ewd_reason" rows="3" maxlength="1000">' . esc_textarea( self::post( 'ewd_reason' ) ) . '</textarea></p>';
		echo '<p class="ewd-hint">' . esc_html( sprintf( /* translators: %s: email address */ __( 'Η απόδειξη θα σταλεί στο %s.', 'easy-withdrawal' ), (string) $order->get_billing_email() ) ) . '</p>';
		echo '<p class="ewd-actions">';
		echo '<button type="submit" name="ewd_next" value="1" class="' . esc_attr( self::btn() ) . '">' . esc_html__( 'Συνέχεια με τις ποσότητες που διάλεξα', 'easy-withdrawal' ) . '</button> ';
		echo '<button type="submit" name="ewd_all" value="1" class="' . esc_attr( self::btn() ) . '">' . esc_html__( 'Συνέχεια με όλα τα προϊόντα', 'easy-withdrawal' ) . '</button>';
		echo '</p></form>';
	}

	/** Why a row cannot be chosen (or a note on its deadline). */
	private static function row_reason( array $row ): string {
		if ( '' !== $row['excluded'] ) {
			return __( $row['excluded'], 'easy-withdrawal' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- msgid from EWD_Rules.
		}
		if ( ! $row['open'] ) {
			return __( 'Η προθεσμία υπαναχώρησης έληξε.', 'easy-withdrawal' );
		}
		if ( $row['available'] <= 0 ) {
			return __( 'Έχει ήδη ζητηθεί ή επιστραφεί.', 'easy-withdrawal' );
		}
		if ( $row['deadline'] > 0 ) {
			return sprintf( /* translators: %s: date */ __( 'Προθεσμία έως %s.', 'easy-withdrawal' ), EWD_Lang::date( $row['deadline'] ) );
		}
		return '';
	}

	private static function render_rows_readonly( array $rows ): void {
		if ( ! $rows ) {
			return;
		}
		echo '<table class="ewd-table shop_table"><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr class="is-off"><td>' . esc_html( $row['name'] ) . '<br /><small class="ewd-why">' . esc_html( self::row_reason( $row ) ) . '</small></td><td>' . (int) $row['qty'] . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Requests already made on this order (status for the customer). */
	private static function render_previous( WC_Order $order ): void {
		$reqs = EWD_Requests::for_order( $order );
		if ( ! $reqs ) {
			return;
		}
		echo '<div class="ewd-previous"><h3 class="ewd-h3">' . esc_html__( 'Προηγούμενες δηλώσεις', 'easy-withdrawal' ) . '</h3><ul>';
		foreach ( $reqs as $r ) {
			echo '<li>' . esc_html( $r['id'] . ' · ' . EWD_Lang::datetime( (int) $r['created'] ) . ' · ' . EWD_Requests::status_label( (string) $r['status'] ) . ' · ' . EWD_Requests::items_text( $r['items'] ) ) . '</li>';
		}
		echo '</ul></div>';
	}

	private static function render_review( WC_Order $order, string $token, array $qty, string $name, string $reason ): void {
		$rows = EWD_Rules::items( $order );

		self::steps( 3 );
		echo '<h2 class="ewd-h">' . esc_html__( 'Έλεγχος και επιβεβαίωση', 'easy-withdrawal' ) . '</h2>';
		echo '<div class="ewd-statement">';
		echo '<p>' . esc_html( sprintf( /* translators: 1: name, 2: order number, 3: order date */ __( 'Εγώ, %1$s, δηλώνω ότι υπαναχωρώ από τη σύμβαση αγοράς των παρακάτω προϊόντων της παραγγελίας #%2$s της %3$s:', 'easy-withdrawal' ), $name, $order->get_order_number(), $order->get_date_created() ? EWD_Lang::date( $order->get_date_created()->getTimestamp() ) : '' ) ) . '</p>';
		echo '<table class="ewd-table shop_table"><thead><tr><th>' . esc_html__( 'Προϊόν', 'easy-withdrawal' ) . '</th><th>' . esc_html__( 'Ποσότητα', 'easy-withdrawal' ) . '</th></tr></thead><tbody>';
		foreach ( $qty as $iid => $q ) {
			echo '<tr><td>' . esc_html( $rows[ $iid ]['name'] ?? '' ) . '</td><td>' . (int) $q . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( '' !== $reason ) {
			echo '<p><strong>' . esc_html__( 'Αιτιολογία', 'easy-withdrawal' ) . ':</strong> ' . nl2br( esc_html( $reason ) ) . '</p>';
		}
		echo '<p>' . esc_html( sprintf( /* translators: %s: email address */ __( 'Η απόδειξη θα σταλεί στο %s.', 'easy-withdrawal' ), (string) $order->get_billing_email() ) ) . '</p>';
		echo '</div>';

		self::form_open( 'confirm' );
		echo '<input type="hidden" name="ewd_t" value="' . esc_attr( $token ) . '" />';
		echo '<input type="hidden" name="ewd_name" value="' . esc_attr( $name ) . '" />';
		echo '<input type="hidden" name="ewd_reason" value="' . esc_attr( $reason ) . '" />';
		foreach ( $qty as $iid => $q ) {
			echo '<input type="hidden" name="ewd_q[' . (int) $iid . ']" value="' . (int) $q . '" />';
		}
		echo '<p class="ewd-actions">';
		echo '<button type="submit" class="' . esc_attr( self::btn( 'alt ewd-confirm' ) ) . '">' . esc_html__( 'Επιβεβαίωση υπαναχώρησης', 'easy-withdrawal' ) . '</button> ';
		echo '<button type="submit" name="ewd_step" value="back" class="' . esc_attr( self::btn( 'ewd-back' ) ) . '" formnovalidate>' . esc_html__( 'Πίσω', 'easy-withdrawal' ) . '</button>';
		echo '</p></form>';
	}

	private static function render_done( WC_Order $order, array $req ): void {
		self::steps( 4 );
		echo '<h2 class="ewd-h">' . esc_html__( 'Η δήλωσή σου καταγράφηκε', 'easy-withdrawal' ) . '</h2>';
		echo '<p class="ewd-note ewd-note--ok">' . esc_html( sprintf( /* translators: 1: request id, 2: date and time, 3: email */ __( 'Αίτημα %1$s, %2$s. Στείλαμε την απόδειξη στο %3$s.', 'easy-withdrawal' ), $req['id'], EWD_Lang::datetime( (int) $req['created'] ), (string) $req['email'] ) ) . '</p>';
		echo '<table class="ewd-table shop_table"><thead><tr><th>' . esc_html__( 'Προϊόν', 'easy-withdrawal' ) . '</th><th>' . esc_html__( 'Ποσότητα', 'easy-withdrawal' ) . '</th></tr></thead><tbody>';
		foreach ( $req['items'] as $it ) {
			echo '<tr><td>' . esc_html( (string) $it['name'] ) . '</td><td>' . (int) $it['qty'] . '</td></tr>';
		}
		echo '</tbody></table>';
		$instr = EWD_Settings::text( 'instr', (string) $req['lang'] );
		if ( '' !== trim( $instr ) ) {
			echo '<h3 class="ewd-h3">' . esc_html__( 'Επόμενα βήματα', 'easy-withdrawal' ) . '</h3><p>' . nl2br( esc_html( $instr ) ) . '</p>';
		}
	}

	/* =====================================================================
	 * Assets (Bible §14.9: only when the form is on the page)
	 * =================================================================== */

	private static function assets(): void {
		if ( self::$css_done ) {
			return;
		}
		self::$css_done = true;
		wp_enqueue_style( 'ewd-front', EWD_URL . 'assets/front.css', array(), EWD_VERSION );
	}

	/** Enqueue early on the form page so the CSS lands in the <head>. */
	public static function enqueue_early(): void {
		if ( self::on_form_page() && EWD_Settings::visible() ) {
			self::assets();
		}
	}
}
