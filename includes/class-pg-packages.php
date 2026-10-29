<?php
defined( 'ABSPATH' ) || exit;

/**
 * Package builder data + programmatic order creation
 * (shared by the Package Builder and the Screenshot Order creator).
 */
class PG_Packages {

	public static function init() {
		add_action( 'wp_ajax_pg_products_search', array( __CLASS__, 'ajax_search' ) );
		add_action( 'wp_ajax_pg_product_image', array( __CLASS__, 'ajax_image' ) );
		add_action( 'wp_ajax_pg_create_order', array( __CLASS__, 'ajax_create_order' ) );
		add_action( 'wp_ajax_pg_packages_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_pg_packages_delete', array( __CLASS__, 'ajax_delete' ) );
	}

	public static function product_row( WC_Product $p ) {
		$img = $p->get_image_id() ? wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ) : '';
		return array(
			'id'      => $p->get_id(),
			'name'    => $p->get_name(),
			'price'   => (float) $p->get_price(),
			'regular' => (float) ( $p->get_regular_price() ? $p->get_regular_price() : $p->get_price() ),
			'stock'   => $p->managing_stock() ? (int) $p->get_stock_quantity() : null,
			'status'  => $p->get_status(),
			'hidden'  => 'visible' !== $p->get_catalog_visibility(),
			'thumb'   => $img ? $img : wc_placeholder_img_src( 'thumbnail' ),
			'type'    => $p->get_type(),
			'sku'     => $p->get_sku(),
			'instock' => $p->is_in_stock(),
			'cost'    => current_user_can( 'manage_options' ) ? PG_Profit::get_cost( $p->get_id() ) : null,
		);
	}

	public static function all_products( $limit = 500 ) {
		return wc_get_products(
			array(
				'status'  => array( 'publish', 'private', 'draft', 'pending' ),
				'type'    => array( 'simple', 'variation', 'bundle', 'woosb', 'composite', 'grouped', 'external' ),
				'limit'   => $limit,
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		);
	}

	/**
	 * Product browser / search used by the order screens.
	 * Params: q, page, per_page, cat (term id), sort (popular|new|name|price_asc|price_desc), cats=1 to include categories.
	 */
	public static function ajax_search() {
		PG_Helpers::guard_ajax();
		$q        = trim( PG_Helpers::post( 'q' ) );
		$page     = max( 1, PG_Helpers::post_int( 'page' ) );
		$per_page = PG_Helpers::post_int( 'per_page' );
		$per_page = $per_page ? min( 60, max( 6, $per_page ) ) : 30;
		$cat      = PG_Helpers::post_int( 'cat' );
		$sort     = sanitize_key( PG_Helpers::post( 'sort' ) );

		$args = array(
			'status'   => array( 'publish', 'private', 'draft', 'pending' ),
			'type'     => array_diff( array_keys( wc_get_product_types() ), array( 'grouped', 'external' ) ),
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
		);
		switch ( $sort ) {
			case 'popular':
				$args['meta_key'] = 'total_sales'; // phpcs:ignore
				$args['orderby']  = array( 'meta_value_num' => 'DESC', 'title' => 'ASC' );
				break;
			case 'name':
				$args['orderby'] = 'title';
				$args['order']   = 'ASC';
				break;
			case 'price_asc':
			case 'price_desc':
				$args['meta_key'] = '_price'; // phpcs:ignore
				$args['orderby']  = array( 'meta_value_num' => 'price_asc' === $sort ? 'ASC' : 'DESC', 'title' => 'ASC' );
				break;
			case 'new':
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
				break;
			default:
				$args['orderby'] = '' === $q ? 'date' : 'title';
				$args['order']   = '' === $q ? 'DESC' : 'ASC';
		}
		if ( '' !== $q ) {
			$args['s'] = $q;
		}
		if ( $cat ) {
			$term = get_term( $cat, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$args['category'] = array( $term->slug );
			}
		}
		$res  = wc_get_products( $args );
		$list = $res->products;

		// Exact ID / SKU match goes first (page 1 only).
		if ( '' !== $q && 1 === $page ) {
			$exact = ctype_digit( $q ) ? wc_get_product( (int) $q ) : null;
			if ( ! $exact ) {
				$sku_id = wc_get_product_id_by_sku( $q );
				$exact  = $sku_id ? wc_get_product( $sku_id ) : null;
			}
			if ( $exact ) {
				array_unshift( $list, $exact );
			}
		}

		$out  = array();
		$seen = array();
		foreach ( $list as $p ) {
			if ( isset( $seen[ $p->get_id() ] ) ) {
				continue;
			}
			$seen[ $p->get_id() ] = true;
			if ( $p->is_type( 'variable' ) ) {
				foreach ( $p->get_children() as $vid ) {
					$v = wc_get_product( $vid );
					if ( $v && ! isset( $seen[ $vid ] ) ) {
						$seen[ $vid ] = true;
						$out[]        = self::product_row( $v );
					}
				}
				continue;
			}
			$out[] = self::product_row( $p );
		}

		$data = array(
			'products' => $out,
			'total'    => (int) $res->total,
			'pages'    => max( 1, (int) $res->max_num_pages ),
			'page'     => $page,
		);
		if ( PG_Helpers::post( 'cats' ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => true,
					'orderby'    => 'count',
					'order'      => 'DESC',
				)
			);
			$data['cats'] = array();
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $t ) {
					$data['cats'][] = array( 'id' => $t->term_id, 'name' => html_entity_decode( $t->name, ENT_QUOTES ), 'count' => (int) $t->count );
				}
			}
		}
		wp_send_json_success( $data );
	}

	/** Product image as a data: URI so it can be drawn on canvas without CORS issues. */
	public static function ajax_image() {
		PG_Helpers::guard_ajax();
		$p = wc_get_product( PG_Helpers::post_int( 'product_id' ) );
		if ( ! $p ) {
			wp_send_json_error( array( 'message' => 'Not found' ) );
		}
		$img_id = $p->get_image_id();
		if ( ! $img_id && $p->get_parent_id() ) {
			$parent = wc_get_product( $p->get_parent_id() );
			$img_id = $parent ? $parent->get_image_id() : 0;
		}
		if ( ! $img_id ) {
			wp_send_json_success( array( 'data_uri' => '' ) );
		}
		$path = '';
		$meta = wp_get_attachment_metadata( $img_id );
		$file = get_attached_file( $img_id );
		if ( $file && ! empty( $meta['sizes'] ) ) {
			foreach ( array( 'medium_large', 'large', 'woocommerce_single', 'medium' ) as $size ) {
				if ( ! empty( $meta['sizes'][ $size ]['file'] ) ) {
					$cand = path_join( dirname( $file ), $meta['sizes'][ $size ]['file'] );
					if ( file_exists( $cand ) ) {
						$path = $cand;
						break;
					}
				}
			}
		}
		if ( ! $path && $file && file_exists( $file ) && filesize( $file ) < 4 * MB_IN_BYTES ) {
			$path = $file;
		}
		if ( ! $path ) {
			wp_send_json_success( array( 'data_uri' => '' ) );
		}
		$type = wp_check_filetype( $path );
		$mime = $type['type'] ? $type['type'] : 'image/jpeg';
		$raw  = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_send_json_success( array( 'data_uri' => 'data:' . $mime . ';base64,' . base64_encode( $raw ) ) ); // phpcs:ignore
	}

	/**
	 * Create an order. Items carry their own (promotional) unit price; an extra
	 * package discount is spread across lines in proportion to their value.
	 * Catalog prices are never changed.
	 */
	public static function create_order( array $customer, array $items, $discount = 0, $delivery = 0, $source = 'package', $label = '' ) {
		$phone = PG_Helpers::bd_phone( isset( $customer['phone'] ) ? $customer['phone'] : '' );
		if ( ! $phone ) {
			return new WP_Error( 'pg_phone', __( 'Enter a valid BD mobile number.', 'product-glide' ) );
		}
		if ( empty( $customer['name'] ) || empty( $customer['address'] ) ) {
			return new WP_Error( 'pg_cust', __( 'Name and address are required.', 'product-glide' ) );
		}
		$lines = array();
		$gross = 0;
		foreach ( $items as $it ) {
			$p   = wc_get_product( absint( isset( $it['product_id'] ) ? $it['product_id'] : 0 ) );
			$qty = max( 1, absint( isset( $it['qty'] ) ? $it['qty'] : 1 ) );
			if ( ! $p ) {
				continue;
			}
			$unit    = isset( $it['price'] ) && '' !== $it['price'] ? max( 0, (float) $it['price'] ) : (float) $p->get_price();
			$line    = round( $unit * $qty, 2 );
			$lines[] = array( 'product' => $p, 'qty' => $qty, 'line' => $line );
			$gross  += $line;
		}
		if ( ! $lines ) {
			return new WP_Error( 'pg_items', __( 'Add at least one product.', 'product-glide' ) );
		}
		$discount = min( max( 0, (float) $discount ), $gross );
		$left     = $discount;
		$count    = count( $lines );
		foreach ( $lines as $i => &$l ) {
			if ( $i === $count - 1 ) {
				$share = $left;
			} else {
				$share = $gross > 0 ? round( $discount * ( $l['line'] / $gross ), 2 ) : 0;
				$left -= $share;
			}
			$l['total'] = max( 0, round( $l['line'] - $share, 2 ) );
		}
		unset( $l );

		$order = wc_create_order( array( 'created_via' => 'product-glide' ) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		foreach ( $lines as $l ) {
			$order->add_product( $l['product'], $l['qty'], array( 'subtotal' => $l['line'], 'total' => $l['total'] ) );
		}
		$name  = sanitize_text_field( $customer['name'] );
		$parts = preg_split( '/\s+/', $name, 2 );
		$addr  = array(
			'first_name' => $parts[0],
			'last_name'  => isset( $parts[1] ) ? $parts[1] : '',
			'address_1'  => sanitize_text_field( $customer['address'] ),
			'city'       => sanitize_text_field( isset( $customer['city'] ) ? $customer['city'] : '' ),
			'country'    => 'BD',
			'phone'      => $phone,
		);
		$order->set_address( $addr, 'billing' );
		$order->set_address( $addr, 'shipping' );
		if ( ! empty( $customer['email'] ) && is_email( $customer['email'] ) ) {
			$order->set_billing_email( sanitize_email( $customer['email'] ) );
		}
		if ( (float) $delivery > 0 ) {
			$ship = new WC_Order_Item_Shipping();
			$ship->set_method_title( __( 'Delivery charge', 'product-glide' ) );
			$ship->set_method_id( 'flat_rate' );
			$ship->set_total( (float) $delivery );
			$order->add_item( $ship );
		}
		$method   = isset( $customer['payment'] ) && 'bacs' === $customer['payment'] ? 'bacs' : 'cod';
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$order->set_payment_method( $method );
		$order->set_payment_method_title( isset( $gateways[ $method ] ) ? $gateways[ $method ]->get_title() : ( 'cod' === $method ? 'Cash on delivery' : 'Bank transfer' ) );
		if ( ! empty( $customer['note'] ) ) {
			$order->set_customer_note( sanitize_textarea_field( $customer['note'] ) );
		}
		$order->update_meta_data( '_pg_source', $source );
		$order->calculate_totals( false );
		$order->save();
		if ( 'lead' === $source ) {
			$msg = sprintf( 'Created from Product Glide incomplete order #%s (customer did not finish checkout; confirmed by phone).', $label );
		} elseif ( 'package' === $source ) {
			$msg = sprintf( 'Created from Product Glide package%s. Package discount: %s', $label ? ' "' . $label . '"' : '', wc_format_decimal( $discount, 2 ) );
		} else {
			$msg = 'Created from Product Glide screenshot/manual order creator.';
		}
		PG_Team::$quiet = true;
		$order->update_status( 'processing', $msg, true );
		PG_Team::$quiet = false;
		PG_Team::log( $order->get_id(), 'created', 'lead' === $source ? 'Incomplete order #' . $label : ( 'package' === $source ? 'Package' . ( $label ? ' "' . $label . '"' : '' ) : ( 'ocr' === $source ? 'Screenshot / manual' : 'Product Glide' ) ) );
		return wc_get_order( $order->get_id() );
	}

	public static function ajax_create_order() {
		PG_Helpers::guard_ajax();
		$c     = PG_Helpers::post_json( 'customer' );
		$items = PG_Helpers::post_json( 'items' );
		$cust  = array();
		foreach ( array( 'name', 'phone', 'address', 'city', 'payment', 'email' ) as $k ) {
			$cust[ $k ] = isset( $c[ $k ] ) ? sanitize_text_field( $c[ $k ] ) : '';
		}
		$cust['note'] = isset( $c['note'] ) ? sanitize_textarea_field( $c['note'] ) : '';
		$clean        = array();
		foreach ( $items as $it ) {
			$clean[] = array(
				'product_id' => absint( isset( $it['product_id'] ) ? $it['product_id'] : 0 ),
				'qty'        => absint( isset( $it['qty'] ) ? $it['qty'] : 1 ),
				'price'      => isset( $it['price'] ) && '' !== $it['price'] ? (float) $it['price'] : '',
			);
		}
		$order = self::create_order(
			$cust,
			$clean,
			(float) PG_Helpers::post( 'discount', 0 ),
			(float) PG_Helpers::post( 'delivery', 0 ),
			sanitize_key( PG_Helpers::post( 'source', 'package' ) ),
			PG_Helpers::post( 'label', '' )
		);
		if ( is_wp_error( $order ) ) {
			wp_send_json_error( array( 'message' => $order->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				'message' => sprintf( /* translators: order no */ __( 'Order #%s created.', 'product-glide' ), $order->get_order_number() ),
				'order'   => PG_Orders::order_card_data( $order ),
			)
		);
	}

	/* Saved package presets (option). */

	public static function saved() {
		$list = get_option( 'pg_packages', array() );
		return is_array( $list ) ? $list : array();
	}

	public static function ajax_save() {
		PG_Helpers::guard_ajax();
		$pkg = PG_Helpers::post_json( 'package' );
		$id  = ! empty( $pkg['id'] ) ? sanitize_key( $pkg['id'] ) : 'p' . time();
		$items = array();
		foreach ( isset( $pkg['items'] ) ? (array) $pkg['items'] : array() as $it ) {
			$items[] = array(
				'product_id' => absint( $it['product_id'] ),
				'name'       => sanitize_text_field( $it['name'] ),
				'qty'        => max( 1, absint( $it['qty'] ) ),
				'price'      => (float) $it['price'],
				'regular'    => (float) $it['regular'],
				'thumb'      => esc_url_raw( isset( $it['thumb'] ) ? $it['thumb'] : '' ),
			);
		}
		$clean = array(
			'id'         => $id,
			'title'      => sanitize_text_field( isset( $pkg['title'] ) ? $pkg['title'] : '' ),
			'subtitle'   => sanitize_text_field( isset( $pkg['subtitle'] ) ? $pkg['subtitle'] : '' ),
			'discount'   => (float) ( isset( $pkg['discount'] ) ? $pkg['discount'] : 0 ),
			'delivery'   => (float) ( isset( $pkg['delivery'] ) ? $pkg['delivery'] : 0 ),
			'valid_from' => sanitize_text_field( isset( $pkg['valid_from'] ) ? $pkg['valid_from'] : '' ),
			'valid_to'   => sanitize_text_field( isset( $pkg['valid_to'] ) ? $pkg['valid_to'] : '' ),
			'items'      => $items,
			'saved_at'   => PG_Helpers::bd_time( time() ),
		);
		$list        = self::saved();
		$list[ $id ] = $clean;
		update_option( 'pg_packages', $list, false );
		wp_send_json_success( array( 'packages' => array_values( $list ), 'id' => $id ) );
	}

	public static function ajax_delete() {
		PG_Helpers::guard_ajax();
		$id   = sanitize_key( PG_Helpers::post( 'id' ) );
		$list = self::saved();
		unset( $list[ $id ] );
		update_option( 'pg_packages', $list, false );
		wp_send_json_success( array( 'packages' => array_values( $list ) ) );
	}
}
