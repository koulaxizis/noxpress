<?php
/**
 * FRM_Submit — one submission, end to end, for the REST route and the
 * no-JS posts alike.
 *
 * Order (cheap checks first, nothing is written before the last step):
 *  1. The form exists, is active and shows to this request.
 *  2. Honeypot → thanked and dropped (counted).
 *  3. REST: the signed token (time window, one use) and the proof of work
 *     → thanked and dropped (counted). No-JS first post: the values are
 *     checked and the visitor gets the «Confirm sending» step with a sealed
 *     blob; the confirmation post opens the blob (time window, one use).
 *  4. Limits per visitor → a visible message (counted).
 *  5. Field validation → messages per field.
 *  6. Content rules → a visible message (counted); stored in Spam when the
 *     form stores anything (Chris 2026-11-16, Q6).
 *  7. Duplicate within 10 minutes → thanked, nothing more.
 *  8. Accepted: the token is spent, the limits counted, the message stored
 *     as the form says, the notification sent, the auto-reply and the orOS
 *     sign-up queued, frm_submission / frm_entry_created fired.
 */

defined( 'ABSPATH' ) || exit;

final class FRM_Submit {

	/**
	 * A result.
	 *
	 * @param bool   $ok      Accepted (or silently dropped).
	 * @param string $message Text for the visitor.
	 * @param array  $errors  key => text.
	 * @param array  $more    state, entry, blob, values.
	 */
	private static function res( bool $ok, string $message, array $errors = array(), array $more = array() ): array {
		return array_merge(
			array(
				'ok'      => $ok,
				'message' => $message,
				'errors'  => $errors,
				'state'   => $ok ? 'sent' : ( $errors ? 'invalid' : 'error' ),
				'entry'   => 0,
			),
			$more
		);
	}

	/** Drop a bot: count the reason, answer as if all went well. */
	private static function drop( array $form, string $reason, string $lang ): array {
		FRM_Spam::count( $reason );
		return self::res( true, FRM_Forms::text( $form, 'thanks', $lang ), array(), array( 'state' => 'dropped' ) );
	}

	/** A page address of this site, or ''. */
	public static function clean_page( $url ): string {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}
		$url = esc_url_raw( mb_substr( trim( $url ), 0, 1000 ), array( 'http', 'https' ) );
		if ( '' === $url ) {
			return '';
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$this_host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || ! is_string( $this_host ) || strtolower( $host ) !== strtolower( $this_host ) ) {
			return '';
		}
		return mb_substr( remove_query_arg( array( 'frm_sent', 'frm' ), $url ), 0, 500 );
	}

	/** A published product for the enquiry field, or null. */
	public static function product( int $id ): ?array {
		if ( $id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$p = wc_get_product( $id );
		if ( ! $p || 'publish' !== get_post_status( $p->get_id() ) ) {
			return null;
		}
		return array(
			'id'   => (int) $p->get_id(),
			'name' => wp_strip_all_tags( (string) $p->get_name() ),
			'sku'  => (string) $p->get_sku(),
			'url'  => (string) get_permalink( $p->get_id() ),
		);
	}

	/** True when the form has a hidden field filled with the product. */
	public static function has_product_field( array $form ): bool {
		foreach ( $form['fields'] as $f ) {
			if ( 'hidden' === $f['type'] && 'product' === $f['source'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Validate every field.
	 *
	 * @return array values (key => string), errors (key => text).
	 */
	public static function validate( array $form, array $raw, string $lang ): array {
		$values = array();
		$errors = array();
		foreach ( $form['fields'] as $f ) {
			if ( ! in_array( $f['type'], FRM_Fields::INPUTS, true ) ) {
				continue;
			}
			list( $v, $err ) = FRM_Fields::validate( $f, $raw[ $f['key'] ] ?? '', $lang );
			if ( '' !== $err ) {
				$errors[ $f['key'] ] = FRM_Settings::text( $err, $lang );
			} else {
				$values[ $f['key'] ] = $v;
			}
		}
		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/** The submission record (e-mail, storage, API). */
	public static function build( array $form, array $values, string $lang, string $page, ?array $product ): array {
		$fields  = array();
		$consent = null;
		foreach ( $form['fields'] as $f ) {
			if ( 'note' === $f['type'] ) {
				continue;
			}
			if ( 'hidden' === $f['type'] ) {
				if ( 'product' === $f['source'] ) {
					$v = $product ? $product['name'] . ( '' !== $product['sku'] ? ' (' . $product['sku'] . ')' : '' ) : '';
				} elseif ( 'page' === $f['source'] ) {
					$v = $page;
				} else {
					$v = $f['value'];
				}
			} else {
				$v = isset( $values[ $f['key'] ] ) ? (string) $values[ $f['key'] ] : '';
			}
			if ( 'consent' === $f['type'] && '' !== $v ) {
				$consent = array(
					'time' => time(),
					'text' => FRM_Fields::plain_label( $f, $lang ),
				);
			}
			$fields[] = array(
				'key'   => $f['key'],
				'label' => 'consent' === $f['type'] ? __( 'Συναίνεση', 'forms' ) : FRM_Fields::plain_label( $f, $lang ),
				'type'  => $f['type'],
				'value' => $v,
			);
		}
		$email = '' !== $form['mail']['email_key'] && isset( $values[ $form['mail']['email_key'] ] ) ? (string) $values[ $form['mail']['email_key'] ] : '';
		$name  = '' !== $form['mail']['name_key'] && isset( $values[ $form['mail']['name_key'] ] ) ? (string) $values[ $form['mail']['name_key'] ] : '';
		return array(
			'v'       => 1,
			'time'    => time(),
			'form'    => (int) $form['id'],
			'title'   => $form['title'],
			'lang'    => $lang,
			'fields'  => $fields,
			'name'    => $name,
			'email'   => $email,
			'page'    => $page,
			'product' => $product && self::has_product_field( $form ) ? $product : null,
			'consent' => $consent,
			'user'    => get_current_user_id(),
			'reason'  => '',
			'entry'   => 0,
			'version' => FRM_VERSION,
		);
	}

	/**
	 * Run a submission.
	 *
	 * @param int   $form_id Form id.
	 * @param array $in      fields (frm[] array), website (honeypot), lang, page, product,
	 *                       via: rest | nojs | confirm; rest: token, answer; confirm: blob.
	 * @return array ok, message, errors (key => text), state (sent | dropped | invalid | error |
	 *               confirm | spam), entry, and for state confirm: blob.
	 */
	public static function run( int $form_id, array $in ): array {
		$form = FRM_Forms::get( $form_id );
		$lang = isset( $in['lang'] ) && in_array( $in['lang'], array( 'el', 'en' ), true ) ? $in['lang'] : FRM_Settings::visitor_lang();
		if ( ! $form || ! $form['active'] || ! FRM_Settings::visible() ) {
			return self::res( false, FRM_Settings::text( 'err_generic', $lang ) );
		}
		$via = isset( $in['via'] ) && in_array( $in['via'], array( 'rest', 'nojs', 'confirm' ), true ) ? $in['via'] : 'rest';

		// 2. Honeypot.
		if ( isset( $in['website'] ) && is_scalar( $in['website'] ) && '' !== trim( (string) $in['website'] ) ) {
			return self::drop( $form, 'honeypot', $lang );
		}

		$raw   = isset( $in['fields'] ) && is_array( $in['fields'] ) ? $in['fields'] : array();
		$page  = self::clean_page( $in['page'] ?? '' );
		$prod  = self::product( isset( $in['product'] ) ? absint( $in['product'] ) : 0 );
		$spend = '';

		// 3. Proof that a person (or at least a browser that paid) sends this.
		if ( 'rest' === $via ) {
			$token  = isset( $in['token'] ) && is_string( $in['token'] ) ? $in['token'] : '';
			$reason = FRM_Spam::check_token( $token, $form );
			if ( '' !== $reason ) {
				return self::drop( $form, $reason, $lang );
			}
			$bits = $form['spam']['pow'] ? (int) FRM_Settings::get()['pow'] : 0;
			if ( ! FRM_Spam::check_pow( $token, isset( $in['answer'] ) && is_scalar( $in['answer'] ) ? (string) $in['answer'] : '', $bits ) ) {
				return self::drop( $form, 'pow', $lang );
			}
			$spend = $token;
		} elseif ( 'nojs' === $via ) {
			if ( ! $form['spam']['nojs'] ) {
				return self::res( false, FRM_Settings::text( 'err_generic', $lang ) );
			}
			$check = self::validate( $form, $raw, $lang );
			if ( $check['errors'] ) {
				return self::res( false, FRM_Settings::text( 'err_fields', $lang ), $check['errors'], array( 'values' => $raw ) );
			}
			$blob = FRM_Spam::seal(
				array(
					'f' => (int) $form['id'],
					'l' => $lang,
					'v' => $raw,
					'p' => $page,
					'r' => $prod ? $prod['id'] : 0,
				)
			);
			return self::res( false, FRM_Settings::text( 'confirm_text', $lang ), array(), array( 'state' => 'confirm', 'blob' => $blob ) );
		} else {
			if ( ! $form['spam']['nojs'] ) {
				return self::res( false, FRM_Settings::text( 'err_generic', $lang ) );
			}
			$blob = isset( $in['blob'] ) && is_string( $in['blob'] ) ? $in['blob'] : '';
			$data = FRM_Spam::unseal( $blob );
			if ( 'expired' === $data ) {
				return self::res( false, FRM_Settings::text( 'err_expired', $lang ) );
			}
			if ( 'time' === $data ) {
				// A real person rarely confirms within seconds: show the step again.
				FRM_Spam::count( 'time' );
				return self::res( false, FRM_Settings::text( 'confirm_text', $lang ), array(), array( 'state' => 'confirm', 'blob' => $blob ) );
			}
			if ( ! is_array( $data ) || (int) ( $data['f'] ?? 0 ) !== (int) $form['id'] ) {
				return self::drop( $form, 'token', $lang );
			}
			$lang  = 'en' === ( $data['l'] ?? '' ) ? 'en' : 'el';
			$raw   = isset( $data['v'] ) && is_array( $data['v'] ) ? $data['v'] : array();
			$page  = self::clean_page( $data['p'] ?? '' );
			$prod  = self::product( absint( $data['r'] ?? 0 ) );
			$spend = $blob;
		}

		// One send at a time per visitor, so parallel requests cannot all pass the
		// one-use, duplicate and limit checks before any of them is recorded.
		$lock = 'ip|' . FRM_Spam::ip_hash();
		if ( ! FRM_Spam::lock( $lock ) ) {
			return self::drop( $form, 'limit', $lang );
		}
		try {
			if ( '' !== $spend && FRM_Spam::is_spent( $spend ) ) {
				return self::drop( $form, 'token', $lang );
			}
			return self::accept( $form, $raw, $lang, $page, $prod, $spend );
		} finally {
			FRM_Spam::unlock( $lock );
		}
	}

	/**
	 * Steps 4 to 8, under the visitor's lock.
	 *
	 * @param array      $form  Form.
	 * @param array      $raw   Posted field values.
	 * @param string     $lang  el | en.
	 * @param string     $page  Clean page URL.
	 * @param array|null $prod  Product of the page.
	 * @param string     $spend Token or sealed blob to mark as used.
	 */
	private static function accept( array $form, array $raw, string $lang, string $page, ?array $prod, string $spend ): array {
		// 4. Limits.
		if ( ! FRM_Spam::within_limits( (int) $form['id'] ) ) {
			FRM_Spam::count( 'limit' );
			return self::res( false, FRM_Settings::text( 'err_limit', $lang ) );
		}

		// 5. Fields.
		$check = self::validate( $form, $raw, $lang );
		if ( $check['errors'] ) {
			return self::res( false, FRM_Settings::text( 'err_fields', $lang ), $check['errors'], array( 'values' => $raw ) );
		}
		$values = $check['values'];

		// 6. Content rules.
		$reason = FRM_Spam::content( $form, $values );
		if ( '' !== $reason ) {
			FRM_Spam::count( $reason );
			$data           = self::build( $form, $values, $lang, $page, $prod );
			$data['reason'] = $reason;
			if ( 'off' !== $form['store'] ) {
				FRM_Entries::store( $data, 'spam', 'none' );
			}
			if ( 0 === strpos( $reason, 'custom:' ) ) {
				$msg = substr( $reason, 7 );
			} else {
				$msg = FRM_Settings::text( 'err_' . $reason, $lang );
			}
			return self::res( false, $msg, array(), array( 'state' => 'spam', 'values' => $raw ) );
		}

		// 7. Duplicate.
		if ( FRM_Spam::is_dup( (int) $form['id'], $values ) ) {
			return self::res( true, FRM_Forms::text( $form, 'thanks', $lang ), array(), array( 'state' => 'dropped' ) );
		}

		// 8. Accepted.
		if ( '' !== $spend ) {
			FRM_Spam::spend( $spend );
		}
		FRM_Spam::hit( (int) $form['id'] );
		FRM_Spam::mark_dup( (int) $form['id'], $values );

		$data  = self::build( $form, $values, $lang, $page, $prod );
		$entry = 0;
		if ( 'all' === $form['store'] ) {
			$entry         = FRM_Entries::store( $data, 'new', 'none' );
			$data['entry'] = $entry;
		}
		$sent = FRM_Mailer::notify( $form, $data );
		if ( $entry ) {
			FRM_Entries::set_mail( $entry, $sent ? 'sent' : 'failed' );
		} elseif ( ! $sent && 'fail' === $form['store'] ) {
			$entry         = FRM_Entries::store( $data, 'new', 'failed' );
			$data['entry'] = $entry;
		}

		if ( ! $sent && ! $entry ) {
			// Nothing kept it: the visitor must know.
			return self::res( false, FRM_Settings::text( 'err_mail', $lang ) );
		}

		FRM_Mailer::auto_reply( $form, $data );
		FRM_Oros::queue( $form, $data );

		/**
		 * After an accepted submission (stored or not).
		 *
		 * @param int   $form_id  Form id.
		 * @param array $data     The submission: fields [ key, label, type, value ], name, email, page,
		 *                        product, lang, consent, time.
		 * @param int   $entry_id Stored message id, 0 when not stored.
		 */
		do_action( 'frm_submission', (int) $form['id'], $data, $entry );
		if ( $entry ) {
			/**
			 * After a message was stored.
			 *
			 * @param int   $entry_id Message id.
			 * @param int   $form_id  Form id.
			 * @param array $data     The submission (as frm_submission).
			 */
			do_action( 'frm_entry_created', $entry, (int) $form['id'], $data );
		}

		return self::res( true, FRM_Forms::text( $form, 'thanks', $lang ), array(), array( 'entry' => $entry ) );
	}
}
