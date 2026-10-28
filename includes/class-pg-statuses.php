<?php
defined( 'ABSPATH' ) || exit;

/**
 * Custom WooCommerce order statuses. If another plugin already registers one
 * of these slugs, that registration is kept and ours is skipped.
 */
class PG_Statuses {

	/**
	 * Built-in statuses: slug => array( label, colour, text colour ).
	 * Entries saved in the `pg_custom_statuses` option (e.g. imported from the old
	 * "Custom Order Status Manager" plugin) add to or override these.
	 */
	public static function list() {
		static $list = null;
		if ( null !== $list ) {
			return $list;
		}
		$list = array(
			'wc-courier_sent'      => array( __( 'Courier Sent', 'product-glide' ), '#2563eb', '' ),
			'wc-courier_pending'   => array( __( 'Courier Pending', 'product-glide' ), '#d97706', '' ),
			'wc-partial_delivered' => array( __( 'Partial Delivered', 'product-glide' ), '#7c3aed', '' ),
			'wc-payment_pending'   => array( __( 'Payment Pending', 'product-glide' ), '#db2777', '' ),
		);
		foreach ( (array) get_option( 'pg_custom_statuses', array() ) as $slug => $row ) {
			$slug = 'wc-' . preg_replace( '/^wc-/', '', sanitize_key( $slug ) );
			if ( empty( $row['label'] ) || strlen( $slug ) > 20 ) {
				continue;
			}
			$list[ $slug ] = array(
				$row['label'],
				! empty( $row['color'] ) ? $row['color'] : ( isset( $list[ $slug ] ) ? $list[ $slug ][1] : '#64748b' ),
				! empty( $row['text'] ) ? $row['text'] : '',
			);
		}
		return $list;
	}

	/**
	 * Copy the statuses defined in "Custom Order Status Manager for WooCommerce"
	 * (posts with a status_slug meta) so they survive when that plugin is removed.
	 */
	public static function import_legacy() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_title, m.meta_value AS slug FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'status_slug'
			 WHERE p.post_status = 'publish' AND m.meta_value <> ''"
		);
		$saved = (array) get_option( 'pg_custom_statuses', array() );
		foreach ( $rows as $r ) {
			$slug = sanitize_key( preg_replace( '/^wc-/', '', $r->slug ) );
			if ( ! $slug ) {
				continue;
			}
			$bg   = get_post_meta( $r->ID, 'background_color', true );
			$text = get_post_meta( $r->ID, 'text_color', true );
			// A white badge was the old plugin's default: let ours pick a tinted colour instead.
			$plain          = ! $bg || in_array( strtolower( $bg ), array( '#ffffff', '#fff' ), true );
			$saved[ $slug ] = array(
				'label' => $r->post_title,
				'color' => $plain ? '' : $bg,
				'text'  => $plain ? '' : $text,
			);
		}
		update_option( 'pg_custom_statuses', $saved, false );
		return $saved;
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 20 );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_to_list' ), 20 );
		// Count these as "paid-ish" so they show in reports & analytics.
		add_filter( 'woocommerce_reports_order_statuses', array( __CLASS__, 'reports' ), 20 );
		add_filter( 'woocommerce_order_is_paid_statuses', array( __CLASS__, 'paid' ), 20 );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( __CLASS__, 'payment_complete' ), 20 );
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'bulk_actions' ), 20 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'bulk_actions' ), 20 );
		add_action( 'admin_head', array( __CLASS__, 'status_css' ) );
	}

	public static function register() {
		foreach ( self::list() as $slug => $info ) {
			if ( get_post_status_object( $slug ) ) {
				continue;
			}
			register_post_status(
				$slug,
				array(
					'label'                     => $info[0],
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: count */
					'label_count'               => _n_noop( $info[0] . ' <span class="count">(%s)</span>', $info[0] . ' <span class="count">(%s)</span>', 'product-glide' ),
				)
			);
		}
	}

	public static function add_to_list( $statuses ) {
		$out = array();
		foreach ( $statuses as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'wc-processing' === $k ) {
				foreach ( self::list() as $slug => $info ) {
					if ( ! isset( $statuses[ $slug ] ) ) {
						$out[ $slug ] = $info[0];
					}
				}
			}
		}
		foreach ( self::list() as $slug => $info ) {
			if ( ! isset( $out[ $slug ] ) ) {
				$out[ $slug ] = $info[0];
			}
		}
		return $out;
	}

	public static function reports( $statuses ) {
		if ( is_array( $statuses ) ) {
			$statuses = array_unique( array_merge( $statuses, array( 'courier_sent', 'courier_pending', 'partial_delivered' ) ) );
		}
		return $statuses;
	}

	public static function paid( $statuses ) {
		return array_unique( array_merge( (array) $statuses, array( 'partial_delivered' ) ) );
	}

	public static function payment_complete( $statuses ) {
		return array_unique( array_merge( (array) $statuses, array( 'courier_sent', 'courier_pending', 'payment_pending' ) ) );
	}

	public static function bulk_actions( $actions ) {
		foreach ( self::list() as $slug => $info ) {
			$actions[ 'mark_' . substr( $slug, 3 ) ] = sprintf( /* translators: status */ __( 'Change status to %s', 'product-glide' ), strtolower( $info[0] ) );
		}
		return $actions;
	}

	public static function status_css() {
		echo '<style>';
		foreach ( self::list() as $slug => $info ) {
			$s = substr( $slug, 3 );
			if ( ! empty( $info[2] ) ) {
				printf( '.order-status.status-%1$s{background:%2$s;color:%3$s}', esc_attr( $s ), esc_attr( $info[1] ), esc_attr( $info[2] ) );
			} else {
				printf( '.order-status.status-%1$s{background:%2$s1f;color:%2$s}', esc_attr( $s ), esc_attr( $info[1] ) );
			}
		}
		echo '</style>';
	}
}
