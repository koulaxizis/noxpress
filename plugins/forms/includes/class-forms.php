<?php
/**
 * FRM_Forms — the forms.
 *
 *  - Posts of the private type frm_form (no URL, no UI of its own: the
 *    list and the builder live in the plugin page). Title = form name,
 *    status publish = active, draft = off. Meta _frm_form = JSON schema
 *    (wp_slash()ed, the RS 1.9.3 lesson):
 *      v       1
 *      fields  FRM_Fields::sanitize() rows, unique keys
 *      mail    to (comma list, '' = site e-mail), subject_el/en with
 *              {placeholders}, email_key (Reply-To), name_key
 *      auto    on, subject_el/en, body_el/en (fixed text, {name} only)
 *      texts   submit_el/en, thanks_el/en ('' = the general text)
 *      store   fail (only when the e-mail fails) | all | off
 *      spam    pow, nojs, links (max, -1 = no limit), disallowed (WordPress
 *              «Disallowed Comment Keys»), words, letters, mx
 *      oros    sign up to orOS Newsletter (needs an email and a consent)
 *      cf7     id and hash of the Contact Form 7 form it came from
 *  - Templates: contact, signup (email + consent), product (enquiry with
 *    the product filled in on the server).
 */

defined( 'ABSPATH' ) || exit;

final class FRM_Forms {

	const PT   = 'frm_form';
	const META = '_frm_form';

	/** @var array<int, array|null> */
	private static $cache = array();

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		register_post_type(
			self::PT,
			array(
				'label'               => 'Forms',
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'show_in_nav_menus'   => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				'supports'            => array( 'title' ),
			)
		);
	}

	/** An empty schema. */
	public static function blank(): array {
		return array(
			'v'      => 1,
			'fields' => array(),
			'mail'   => array(
				'to'         => '',
				'subject_el' => '[{site}] {form}: {subject}',
				'subject_en' => '',
				'email_key'  => '',
				'name_key'   => '',
			),
			'auto'   => array(
				'on'         => false,
				'subject_el' => 'Λάβαμε το μήνυμά σας',
				'subject_en' => 'We received your message',
				'body_el'    => "Γεια σας {name},\n\nλάβαμε το μήνυμά σας και θα σας απαντήσουμε σύντομα.\n\n{site}",
				'body_en'    => "Hello {name},\n\nwe received your message and will answer you soon.\n\n{site}",
			),
			'texts'  => array(
				'submit_el' => '',
				'submit_en' => '',
				'thanks_el' => '',
				'thanks_en' => '',
			),
			'store'  => 'fail',
			'spam'   => array(
				'pow'        => true,
				'nojs'       => true,
				'links'      => 2,
				'disallowed' => false,
				'words'      => '',
				'letters'    => false,
				'mx'         => false,
			),
			'oros'   => false,
			'cf7'    => array(
				'id'   => 0,
				'hash' => '',
			),
		);
	}

	private static function str( array $raw, string $k, int $max, bool $multi = false ): string {
		if ( ! isset( $raw[ $k ] ) || ! is_scalar( $raw[ $k ] ) ) {
			return '';
		}
		$v = $multi ? sanitize_textarea_field( (string) $raw[ $k ] ) : sanitize_text_field( (string) $raw[ $k ] );
		return mb_substr( trim( $v ), 0, $max );
	}

	/** A comma list of e-mails, only the valid ones, at most 10. */
	public static function clean_emails( string $list ): string {
		$out = array();
		foreach ( preg_split( '/[,;\s]+/', $list ) as $e ) {
			$e = strtolower( sanitize_email( (string) $e ) );
			if ( '' !== $e && is_email( $e ) && ! in_array( $e, $out, true ) ) {
				$out[] = $e;
			}
			if ( count( $out ) >= 10 ) {
				break;
			}
		}
		return implode( ', ', $out );
	}

	/** A schema from the builder or an import, cleaned. */
	public static function sanitize( array $raw ): array {
		$s = self::blank();

		$fields = array();
		$keys   = array();
		if ( isset( $raw['fields'] ) && is_array( $raw['fields'] ) ) {
			foreach ( $raw['fields'] as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				$f = FRM_Fields::sanitize( $r );
				if ( in_array( $f['type'], array( 'select', 'radio', 'checkboxes' ), true ) && ! $f['options'] ) {
					continue; // A choice without options cannot be answered.
				}
				$base = '' !== $f['key'] ? $f['key'] : $f['type'];
				$key  = $base;
				$n    = 2;
				while ( in_array( $key, $keys, true ) || in_array( $key, array( 'website', 'lang', 'form' ), true ) ) {
					$key = substr( $base, 0, 36 ) . '-' . $n;
					++$n;
				}
				$f['key'] = $key;
				$keys[]   = $key;
				$fields[] = $f;
				if ( count( $fields ) >= 60 ) {
					break;
				}
			}
		}
		$s['fields'] = $fields;

		$m                     = isset( $raw['mail'] ) && is_array( $raw['mail'] ) ? $raw['mail'] : array();
		$s['mail']['to']       = isset( $m['to'] ) && is_string( $m['to'] ) ? self::clean_emails( $m['to'] ) : '';
		$s['mail']['subject_el'] = isset( $m['subject_el'] ) ? self::str( $m, 'subject_el', 200 ) : $s['mail']['subject_el'];
		$s['mail']['subject_en'] = self::str( $m, 'subject_en', 200 );
		if ( '' === $s['mail']['subject_el'] ) {
			$s['mail']['subject_el'] = '[{site}] {form}';
		}
		$by_type = static function ( array $types ) use ( $fields ): array {
			$out = array();
			foreach ( $fields as $f ) {
				if ( in_array( $f['type'], $types, true ) ) {
					$out[] = $f['key'];
				}
			}
			return $out;
		};
		$emails = $by_type( array( 'email' ) );
		$texts  = $by_type( array( 'text' ) );
		$ek     = self::str( $m, 'email_key', 40 );
		$nk     = self::str( $m, 'name_key', 40 );
		$s['mail']['email_key'] = in_array( $ek, $emails, true ) ? $ek : ( $emails ? $emails[0] : '' );
		if ( ! in_array( $nk, $texts, true ) ) {
			$nk = '';
			foreach ( $texts as $k ) {
				if ( preg_match( '/name|onoma/', $k ) ) {
					$nk = $k;
					break;
				}
			}
		}
		$s['mail']['name_key'] = $nk;

		$a                       = isset( $raw['auto'] ) && is_array( $raw['auto'] ) ? $raw['auto'] : array();
		$s['auto']['on']         = ! empty( $a['on'] );
		foreach ( array( 'subject_el', 'subject_en' ) as $k ) {
			if ( isset( $a[ $k ] ) ) {
				$s['auto'][ $k ] = self::str( $a, $k, 200 );
			}
		}
		foreach ( array( 'body_el', 'body_en' ) as $k ) {
			if ( isset( $a[ $k ] ) ) {
				$s['auto'][ $k ] = self::str( $a, $k, 3000, true );
			}
		}

		$t = isset( $raw['texts'] ) && is_array( $raw['texts'] ) ? $raw['texts'] : array();
		foreach ( array( 'submit_el', 'submit_en' ) as $k ) {
			$s['texts'][ $k ] = self::str( $t, $k, 60 );
		}
		foreach ( array( 'thanks_el', 'thanks_en' ) as $k ) {
			$s['texts'][ $k ] = self::str( $t, $k, 300 );
		}

		$store      = isset( $raw['store'] ) && is_string( $raw['store'] ) ? $raw['store'] : '';
		$s['store'] = in_array( $store, array( 'fail', 'all', 'off' ), true ) ? $store : 'fail';

		$sp = isset( $raw['spam'] ) && is_array( $raw['spam'] ) ? $raw['spam'] : array();
		foreach ( array( 'pow', 'nojs' ) as $k ) {
			$s['spam'][ $k ] = array_key_exists( $k, $sp ) ? ! empty( $sp[ $k ] ) : $s['spam'][ $k ];
		}
		foreach ( array( 'disallowed', 'letters', 'mx' ) as $k ) {
			$s['spam'][ $k ] = ! empty( $sp[ $k ] );
		}
		$links               = isset( $sp['links'] ) && is_numeric( $sp['links'] ) ? (int) $sp['links'] : 2;
		$s['spam']['links']  = max( -1, min( 50, $links ) );
		$s['spam']['words']  = self::str( $sp, 'words', 3000, true );

		$s['oros'] = ! empty( $raw['oros'] );

		$c                  = isset( $raw['cf7'] ) && is_array( $raw['cf7'] ) ? $raw['cf7'] : array();
		$s['cf7']['id']     = isset( $c['id'] ) ? absint( $c['id'] ) : 0;
		$s['cf7']['hash']   = isset( $c['hash'] ) && is_string( $c['hash'] ) ? substr( preg_replace( '/[^a-f0-9]/', '', strtolower( $c['hash'] ) ), 0, 40 ) : '';

		return $s;
	}

	/**
	 * A form, or null.
	 *
	 * @return array|null id, title, active, modified + the schema keys.
	 */
	public static function get( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}
		if ( array_key_exists( $id, self::$cache ) ) {
			return self::$cache[ $id ];
		}
		$p = get_post( $id );
		if ( ! $p || self::PT !== $p->post_type || ! in_array( $p->post_status, array( 'publish', 'draft' ), true ) ) {
			self::$cache[ $id ] = null;
			return null;
		}
		$raw  = json_decode( (string) get_post_meta( $id, self::META, true ), true );
		$form = self::sanitize( is_array( $raw ) ? $raw : array() );

		$form['id']       = $id;
		$form['title']    = (string) $p->post_title;
		$form['active']   = 'publish' === $p->post_status;
		$form['modified'] = (string) $p->post_modified_gmt;

		self::$cache[ $id ] = $form;
		return $form;
	}

	/** Save a form (new when $id = 0). Returns the id, or 0. */
	public static function save( int $id, string $title, array $schema, bool $active ): int {
		$title  = mb_substr( trim( sanitize_text_field( $title ) ), 0, 120 );
		$title  = '' !== $title ? $title : __( 'Φόρμα', 'forms' );
		$schema = self::sanitize( $schema );
		$json   = wp_slash( (string) wp_json_encode( $schema, JSON_UNESCAPED_UNICODE ) );
		$post   = array(
			'post_type'   => self::PT,
			'post_title'  => $title,
			'post_status' => $active ? 'publish' : 'draft',
		);
		if ( $id > 0 ) {
			$old = get_post( $id );
			if ( ! $old || self::PT !== $old->post_type ) {
				return 0;
			}
			$post['ID'] = $id;
			$res        = wp_update_post( $post, true );
		} else {
			$res = wp_insert_post( $post, true );
		}
		if ( is_wp_error( $res ) || ! $res ) {
			return 0;
		}
		$id = (int) $res;
		update_post_meta( $id, self::META, $json );
		unset( self::$cache[ $id ] );
		do_action( 'noxpress_purge_page_cache' );
		return $id;
	}

	public static function delete( int $id ): bool {
		$p = get_post( $id );
		if ( ! $p || self::PT !== $p->post_type ) {
			return false;
		}
		unset( self::$cache[ $id ] );
		return (bool) wp_delete_post( $id, true );
	}

	/**
	 * All forms, newest first.
	 *
	 * @return array[] get() rows.
	 */
	public static function all(): array {
		$ids = get_posts(
			array(
				'post_type'      => self::PT,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$f = self::get( (int) $id );
			if ( $f ) {
				$out[] = $f;
			}
		}
		return $out;
	}

	/** id => title of every form (block, selects). */
	public static function choices(): array {
		$out = array();
		foreach ( self::all() as $f ) {
			$out[ $f['id'] ] = $f['title'];
		}
		return $out;
	}

	/** A field of a form by key, or null. */
	public static function field( array $form, string $key ): ?array {
		foreach ( $form['fields'] as $f ) {
			if ( $f['key'] === $key ) {
				return $f;
			}
		}
		return null;
	}

	/** True when the form has a field of this type. */
	public static function has_type( array $form, string $type ): bool {
		foreach ( $form['fields'] as $f ) {
			if ( $type === $f['type'] ) {
				return true;
			}
		}
		return false;
	}

	/** A form text (submit, thanks) with the general text as fallback. */
	public static function text( array $form, string $key, string $lang ): string {
		$v = FRM_Settings::pick( (string) $form['texts'][ $key . '_el' ], (string) $form['texts'][ $key . '_en' ], $lang );
		if ( '' === $v ) {
			return FRM_Settings::text( $key, $lang );
		}
		return (string) apply_filters( 'frm_text', $v, 'form:' . $form['id'] . ':' . $key, $lang ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- own filter.
	}

	/* =====================================================================
	 * Templates
	 * =================================================================== */

	public static function template_labels(): array {
		return array(
			'contact' => __( 'Επικοινωνία', 'forms' ),
			'signup'  => __( 'Εγγραφή email', 'forms' ),
			'product' => __( 'Ερώτηση για προϊόν', 'forms' ),
			'empty'   => __( 'Κενή φόρμα', 'forms' ),
		);
	}

	private static function f( string $type, string $key, string $el, string $en, array $more = array() ): array {
		return array_merge(
			FRM_Fields::blank( $type ),
			array(
				'key'      => $key,
				'label_el' => $el,
				'label_en' => $en,
			),
			$more
		);
	}

	/** The schema of a template. */
	public static function template( string $t ): array {
		$s = self::blank();
		switch ( $t ) {
			case 'contact':
				$s['fields'] = array(
					self::f( 'text', 'name', 'Όνομα', 'Name', array( 'required' => true, 'width' => 'half' ) ),
					self::f( 'email', 'email', 'Email', 'Email', array( 'required' => true, 'width' => 'half' ) ),
					self::f( 'text', 'subject', 'Θέμα', 'Subject' ),
					self::f( 'textarea', 'message', 'Μήνυμα', 'Message', array( 'required' => true ) ),
					self::f( 'consent', 'consent', '', '' ),
				);
				break;
			case 'signup':
				$s['fields']              = array(
					self::f( 'email', 'email', 'Email', 'Email', array( 'required' => true ) ),
					self::f( 'consent', 'consent', 'Θέλω να λαμβάνω νέα και προσφορές με email. Μπορώ να διαγραφώ όποτε θέλω ({privacy}).', 'I want to receive news and offers by email. I can unsubscribe any time ({privacy}).' ),
				);
				$s['mail']['subject_el']  = '[{site}] {form}: {email}';
				$s['texts']['submit_el']  = 'Εγγραφή';
				$s['texts']['submit_en']  = 'Subscribe';
				$s['texts']['thanks_el']  = 'Ευχαριστούμε! Η εγγραφή σας καταχωρήθηκε.';
				$s['texts']['thanks_en']  = 'Thank you! You are signed up.';
				$s['spam']['links']       = 0;
				break;
			case 'product':
				$s['fields']             = array(
					self::f( 'hidden', 'product', 'Προϊόν', 'Product', array( 'source' => 'product' ) ),
					self::f( 'text', 'name', 'Όνομα', 'Name', array( 'required' => true, 'width' => 'half' ) ),
					self::f( 'email', 'email', 'Email', 'Email', array( 'required' => true, 'width' => 'half' ) ),
					self::f( 'textarea', 'message', 'Η ερώτησή σας', 'Your question', array( 'required' => true ) ),
					self::f( 'consent', 'consent', '', '' ),
				);
				$s['mail']['subject_el'] = '[{site}] {form}: {product}';
				break;
		}
		return self::sanitize( $s );
	}
}
