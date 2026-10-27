<?php
/**
 * BRC_Src_Queue — module "queue": posts prepared somewhere else.
 *
 * For a routine (e.g. Claude's daily Instagram post, through Site Bridge's
 * ability_run) that prepares a post on its own schedule. Every post it
 * adds waits in the queue (reason "publish") and is posted only when the
 * owner presses «Δημοσιεύτηκε» in the queue screen (or runs
 * broadcast/queue-send), never on its own.
 *
 * Abilities (WordPress 6.9+, category "noxpress", capability
 * Broadcast::cap()):
 *  broadcast/queue-add   text (required), url, image_url or attachment_id,
 *                        ref (dedupe key; the same ref is never added twice)
 *  broadcast/queue-list  items waiting, newest first (read)
 *  broadcast/queue-send  posts one waiting item now, optionally with an
 *                        edited text (= «Δημοσιεύτηκε»)
 *  broadcast/status      mode, modules, the Viber connection, counts (read)
 */

defined( 'ABSPATH' ) || exit;

final class BRC_Src_Queue {

	const CAT = 'noxpress';

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	/** The shared "noxpress" category, once for every Noxpress plugin. */
	public static function category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CAT ) ) {
			return;
		}
		wp_register_ability_category(
			self::CAT,
			array(
				'label'       => 'Noxpress',
				'description' => 'Operations of the Noxpress plugins.',
			)
		);
	}

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$none = array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
			'default'              => array(),
		);
		$defs = array(
			'queue-add'  => array(
				'label'       => 'Broadcast: add a post to the queue',
				'description' => 'Adds a post that waits in the queue until the owner presses «Δημοσιεύτηκε»; it is never posted on its own. "text" is the post (up to 2000 characters), "url" a link, "image_url" (https) or "attachment_id" an image of the media library, "ref" a key that makes the call safe to repeat (the same ref is never added twice).',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'text'          => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 2000,
						),
						'url'           => array( 'type' => 'string' ),
						'image_url'     => array( 'type' => 'string' ),
						'attachment_id' => array( 'type' => 'integer' ),
						'ref'           => array(
							'type'      => 'string',
							'maxLength' => 90,
						),
					),
					'required'             => array( 'text' ),
					'additionalProperties' => false,
				),
				'ann'         => array( false, false, true ),
				'run'         => array( __CLASS__, 'add' ),
			),
			'queue-list' => array(
				'label'       => 'Broadcast: items waiting',
				'description' => 'Items waiting in the queue (any source), newest first: id, source, title, text, url, image, why it waits (approval, brake, publish, unsure) and when it was added. Changes nothing.',
				'input'       => $none,
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'pending' ),
			),
			'queue-send' => array(
				'label'       => 'Broadcast: post a waiting item now',
				'description' => 'Posts one waiting item now (the «Δημοσιεύτηκε» / approve button), optionally with an edited "text". In test mode nothing leaves the site.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'   => array( 'type' => 'integer' ),
						'text' => array(
							'type'      => 'string',
							'maxLength' => 7000,
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'ann'         => array( false, false, true ),
				'run'         => array( __CLASS__, 'send' ),
			),
			'status'     => array(
				'label'       => 'Broadcast: status',
				'description' => 'Mode (test, live, off), modules on, the Viber channel (name, subscribers, connected), items per status, the last newsletter hook, YouTube check and brake. Changes nothing; no secrets.',
				'input'       => $none,
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'status' ),
			),
		);
		foreach ( $defs as $name => $d ) {
			wp_register_ability(
				'broadcast/' . $name,
				array(
					'label'               => $d['label'],
					'description'         => $d['description'],
					'category'            => self::CAT,
					'input_schema'        => $d['input'],
					'execute_callback'    => $d['run'],
					'permission_callback' => array( __CLASS__, 'allowed' ),
					'meta'                => array(
						'annotations'  => array(
							'readonly'    => $d['ann'][0],
							'destructive' => $d['ann'][1],
							'idempotent'  => $d['ann'][2],
						),
						'show_in_rest' => false,
					),
				)
			);
		}
	}

	public static function allowed(): bool {
		return current_user_can( Broadcast::cap() );
	}

	/** A queue item for the abilities (no internal fields). */
	private static function out( array $r ): array {
		return array(
			'id'      => $r['id'],
			'source'  => $r['source'],
			'dest'    => $r['dest'],
			'status'  => $r['status'],
			'reason'  => $r['reason'],
			'title'   => $r['title'],
			'text'    => $r['msg']['text'],
			'url'     => $r['msg']['url'],
			'image'   => $r['msg']['image'],
			'created' => gmdate( 'c', $r['created'] ),
		);
	}

	/**
	 * Adds a prepared post (queue-add, and the queue screen's form).
	 *
	 * @param array|null $in text, url, image_url, attachment_id, ref.
	 * @return array|WP_Error
	 */
	public static function add( $in = null ) {
		$in   = is_array( $in ) ? $in : array();
		$text = isset( $in['text'] ) && is_string( $in['text'] ) ? BRC_Settings::plain( $in['text'] ) : '';
		if ( '' === $text ) {
			return new WP_Error( 'brc_text', 'The post needs a text.' );
		}
		$text  = mb_substr( $text, 0, 2000 );
		$url   = isset( $in['url'] ) ? BRC_Settings::clean_url( $in['url'] ) : '';
		$image = '';
		if ( ! empty( $in['attachment_id'] ) ) {
			$id = (int) $in['attachment_id'];
			if ( ! wp_attachment_is_image( $id ) ) {
				return new WP_Error( 'brc_image', 'attachment_id is not an image of the media library.' );
			}
			$image = (string) wp_get_attachment_image_url( $id, 'large' );
		} elseif ( ! empty( $in['image_url'] ) ) {
			$image = BRC_Settings::clean_url( $in['image_url'] );
			if ( '' === $image ) {
				return new WP_Error( 'brc_image', 'image_url must be an https address.' );
			}
		}
		$ref = isset( $in['ref'] ) && is_string( $in['ref'] ) && '' !== trim( $in['ref'] )
			? preg_replace( '/[^A-Za-z0-9._:-]/', '', trim( $in['ref'] ) )
			: sha1( $text . '|' . $url );
		$skey = 'q:' . mb_substr( (string) $ref, 0, 90 );
		if ( BRC_Queue::known( 'queue', $skey ) ) {
			return new WP_Error( 'brc_known', 'This post (ref) is already in the queue or was posted.' );
		}
		if ( ! BRC_Settings::destinations() ) {
			return new WP_Error( 'brc_dest', 'No destination is switched on (Broadcast → Modules → Viber Channel).' );
		}
		$ids = BRC_Queue::add(
			'queue',
			$skey,
			array(
				'title' => wp_trim_words( $text, 8, '…' ),
				'text'  => BRC_Template::render(
					BRC_Settings::get()['tpl_queue'],
					array(
						'text' => $text,
						'url'  => $url,
					)
				),
				'url'   => $url,
				'image' => $image,
				'kind'  => '' !== $image ? 'picture' : 'text',
			),
			// The owner prepared it on purpose: a link already posted
			// (e.g. the product) is no reason to drop it.
			array(
				'reason'   => 'publish',
				'same_url' => false,
			)
		);
		if ( ! $ids ) {
			return new WP_Error( 'brc_none', 'Nothing was added (mode off, BRC_DISABLE, or the same link went out within 7 days).' );
		}
		return array(
			'added' => $ids,
			'items' => array_map(
				static function ( $id ) {
					$r = BRC_Queue::get( $id );
					return $r ? self::out( $r ) : array( 'id' => $id );
				},
				$ids
			),
		);
	}

	public static function pending( $in = null ): array {
		return array( 'items' => array_map( array( __CLASS__, 'out' ), BRC_Queue::list( array( 'status' => 'pending' ), 100 ) ) );
	}

	/** @return array|WP_Error */
	public static function send( $in = null ) {
		$in   = is_array( $in ) ? $in : array();
		$id   = isset( $in['id'] ) ? (int) $in['id'] : 0;
		$text = isset( $in['text'] ) && is_string( $in['text'] ) ? $in['text'] : null;
		if ( ! BRC_Queue::approve( $id, $text ) ) {
			return new WP_Error( 'brc_state', 'No waiting item with this id.' );
		}
		$r = BRC_Queue::get( $id );
		return array(
			'item' => $r ? self::out( $r ) : array( 'id' => $id ),
			'note' => 'test' === BRC_Settings::get()['mode'] ? 'Test mode: it will be marked "test" and nothing leaves the site.' : 'Queued; posted within a minute.',
		);
	}

	public static function status( $in = null ): array {
		$s      = BRC_Settings::get();
		$counts = array();
		foreach ( array( 'pending', 'queued', 'sent', 'test', 'failed', 'skipped', 'rejected' ) as $st ) {
			$counts[ $st ] = BRC_Queue::count( array( 'status' => $st ) );
		}
		$acc   = get_option( 'brc_viber', array() );
		$acc   = is_array( $acc ) ? $acc : array();
		$notes = BRC_Queue::notes();
		$t     = static function ( $k ) use ( $notes ) {
			return ! empty( $notes[ $k ] ) && is_numeric( $notes[ $k ] ) ? gmdate( 'c', (int) $notes[ $k ] ) : null;
		};
		return array(
			'version'    => BRC_VERSION,
			'mode'       => $s['mode'],
			'disabled'   => Broadcast::killed(),
			'modules'    => array_keys( array_filter( $s['mods'] ) ),
			'approval'   => array_keys( array_filter( $s['approve'] ) ),
			'viber'      => array(
				'connected'   => ! empty( $acc['from'] ) && 'none' !== BRC_Crypt::state( 'viber' ),
				'channel'     => isset( $acc['channel'] ) ? $acc['channel'] : '',
				'subscribers' => isset( $acc['subs'] ) ? (int) $acc['subs'] : 0,
				'last_callback' => $t( 'viber_cb' ),
			),
			'counts'     => $counts,
			'newsletter' => $t( 'nl_hook' ),
			'youtube'    => $t( 'yt_poll' ),
			'brake'      => $t( 'brake' ),
		);
	}
}
