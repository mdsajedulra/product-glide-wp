<?php
defined( 'ABSPATH' ) || exit;

/**
 * Stand-alone front-end dashboard at /{slug} (default /order-hub) with its own
 * login screen, outside the normal wp-admin. Only Administrators and the
 * "Order Manager" role can use it; Order Managers are kept out of wp-admin.
 */
class PG_App {

	const ROLE = 'pg_order_manager';
	const CAP  = 'pg_manage';

	/** Views this user may open. Profit & Stock and Steadfast tools are for Administrators only. */
	public static function views() {
		$views = array(
			'orders'   => array( 'product-glide', __( 'Orders', 'product-glide' ) ),
			'incomplete' => array( 'pg-leads', __( 'Incomplete', 'product-glide' ) ),
			'new'      => array( 'pg-ocr', __( 'New Order', 'product-glide' ) ),
			'packages' => array( 'pg-packages', __( 'Packages', 'product-glide' ) ),
		);
		if ( current_user_can( 'manage_options' ) ) {
			$views['profit']    = array( 'pg-profit', __( 'Profit & Stock', 'product-glide' ) );
			$views['steadfast'] = array( 'pg-steadfast', __( 'Steadfast', 'product-glide' ) );
			$views['team']      = array( 'pg-team', __( 'Team', 'product-glide' ) );
		}
		return $views;
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrite' ), 5 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'route' ), 0 );
		add_filter( 'user_has_cap', array( __CLASS__, 'admin_cap' ), 10, 1 );
		add_action( 'admin_init', array( __CLASS__, 'keep_out_of_admin' ), 1 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ), 20 );
	}

	/* ------------------------------------------------------------------ */
	/* Role, capability, routing                                           */
	/* ------------------------------------------------------------------ */

	public static function add_role() {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, __( 'Order Manager', 'product-glide' ), array( 'read' => true, self::CAP => true ) );
		} else {
			get_role( self::ROLE )->add_cap( self::CAP );
		}
		update_option( 'pg_flush_rewrite', 1, false );
	}

	/** Administrators always have the dashboard capability. */
	public static function admin_cap( $allcaps ) {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ self::CAP ] = true;
		}
		return $allcaps;
	}

	public static function can() {
		return current_user_can( self::CAP ) || current_user_can( 'manage_options' );
	}

	public static function is_manager_only( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		return $user && $user->exists() && in_array( self::ROLE, (array) $user->roles, true ) && ! user_can( $user, 'manage_options' );
	}

	public static function slug() {
		$s = sanitize_title( PG_Settings::get( 'app_slug', 'order-hub' ) );
		return $s ? $s : 'order-hub';
	}

	public static function url( $view = '' ) {
		return home_url( '/' . self::slug() . '/' . ( $view && 'orders' !== $view ? $view . '/' : '' ) );
	}

	public static function rewrite() {
		add_rewrite_rule( '^' . preg_quote( self::slug(), '/' ) . '(?:/([a-z-]+))?/?$', 'index.php?pg_app=1&pg_view=$matches[1]', 'top' );
		if ( get_option( 'pg_flush_rewrite' ) ) {
			delete_option( 'pg_flush_rewrite' );
			flush_rewrite_rules( false );
		}
	}

	public static function query_vars( $v ) {
		$v[] = 'pg_app';
		$v[] = 'pg_view';
		return $v;
	}

	public static function keep_out_of_admin() {
		if ( wp_doing_ajax() || ! self::is_manager_only() ) {
			return;
		}
		$script = isset( $_SERVER['SCRIPT_FILENAME'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) ) ) : '';
		if ( in_array( $script, array( 'admin-post.php', 'admin-ajax.php', 'async-upload.php' ), true ) ) {
			return;
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function login_redirect( $to, $requested, $user ) {
		if ( $user instanceof WP_User && self::is_manager_only( $user ) ) {
			return self::url();
		}
		return $to;
	}

	public static function admin_bar( $show ) {
		if ( get_query_var( 'pg_app' ) || self::is_manager_only() ) {
			return false;
		}
		return $show;
	}

	private static function no_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'product glide app' );
		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'X-Robots-Tag: noindex, nofollow' );
	}

	/* ------------------------------------------------------------------ */
	/* Request handling                                                    */
	/* ------------------------------------------------------------------ */

	public static function route() {
		if ( ! get_query_var( 'pg_app' ) ) {
			return;
		}
		self::no_cache();
		$error = '';

		// Log out.
		if ( isset( $_GET['logout'] ) && is_user_logged_in() ) {
			check_admin_referer( 'pg_logout' );
			wp_logout();
			wp_safe_redirect( self::url() );
			exit;
		}

		// Log in.
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['pg_login'] ) ) { // phpcs:ignore
			if ( ! wp_verify_nonce( isset( $_POST['_pgnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_pgnonce'] ) ) : '', 'pg_login' ) ) {
				$error = __( 'The form expired. Please try again.', 'product-glide' );
			} else {
				$user = wp_signon(
					array(
						'user_login'    => isset( $_POST['log'] ) ? sanitize_user( wp_unslash( $_POST['log'] ) ) : '',
						'user_password' => isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '', // phpcs:ignore -- passwords are not sanitized.
						'remember'      => ! empty( $_POST['rememberme'] ),
					),
					is_ssl()
				);
				if ( is_wp_error( $user ) ) {
					$error = __( 'Wrong username/email or password.', 'product-glide' );
				} elseif ( ! user_can( $user, self::CAP ) && ! user_can( $user, 'manage_options' ) ) {
					wp_logout();
					$error = __( 'This account does not have access to the order dashboard.', 'product-glide' );
				} else {
					wp_safe_redirect( self::url() );
					exit;
				}
			}
		}

		if ( ! is_user_logged_in() ) {
			self::render_login( $error );
			exit;
		}
		if ( ! self::can() ) {
			self::render_login( __( 'You are signed in, but this account does not have access to the order dashboard.', 'product-glide' ), true );
			exit;
		}

		$view  = sanitize_key( (string) get_query_var( 'pg_view' ) );
		$views = self::views();
		if ( '' === $view || ! isset( $views[ $view ] ) ) {
			$view = 'orders';
		}
		self::render_app( $view );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Output                                                              */
	/* ------------------------------------------------------------------ */

	private static function brand() {
		$s    = PG_Settings::all();
		$logo = $s['logo_url'];
		if ( ! $logo && has_custom_logo() ) {
			$logo = wp_get_attachment_image_url( get_theme_mod( 'custom_logo' ), 'medium' );
		}
		return array( $s['brand_name'] ? $s['brand_name'] : get_bloginfo( 'name' ), $logo );
	}

	private static function head( $title ) {
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#1e3a8a">
<title><?php echo esc_html( $title ); ?></title>
		<?php
	}

	public static function render_login( $error = '', $signed_in = false ) {
		list( $name, $logo ) = self::brand();
		PG_Admin::enqueue_styles_only();
		self::head( __( 'Sign in', 'product-glide' ) . ' · ' . $name );
		wp_print_styles( array( 'pg-fonts', 'pg-admin' ) );
		?>
</head>
<body class="pg-app pg-front pg-login-page">
<main class="pg-login">
	<div class="pg-login-card">
		<div class="pg-login-brand">
			<?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt=""><?php endif; ?>
			<h1><?php echo esc_html( $name ); ?></h1>
			<p class="pg-muted"><?php esc_html_e( 'Order dashboard', 'product-glide' ); ?></p>
		</div>
		<?php if ( $error ) : ?>
			<div class="pg-alert pg-alert-bad" role="alert"><?php echo esc_html( $error ); ?></div>
		<?php endif; ?>
		<?php if ( $signed_in ) : ?>
			<a class="pg-btn pg-btn-primary pg-btn-block" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'logout', 1, self::url() ), 'pg_logout' ) ); ?>"><?php esc_html_e( 'Sign out and use another account', 'product-glide' ); ?></a>
		<?php else : ?>
		<form method="post" action="<?php echo esc_url( self::url() ); ?>" class="pg-login-form">
			<input type="hidden" name="pg_login" value="1">
			<?php wp_nonce_field( 'pg_login', '_pgnonce' ); ?>
			<div class="pg-field">
				<label for="pg-log"><?php esc_html_e( 'Username or email', 'product-glide' ); ?></label>
				<input type="text" id="pg-log" name="log" autocomplete="username" required autofocus value="<?php echo isset( $_POST['log'] ) ? esc_attr( sanitize_user( wp_unslash( $_POST['log'] ) ) ) : ''; // phpcs:ignore ?>">
			</div>
			<div class="pg-field">
				<label for="pg-pwd"><?php esc_html_e( 'Password', 'product-glide' ); ?></label>
				<input type="password" id="pg-pwd" name="pwd" autocomplete="current-password" required>
			</div>
			<label class="pg-check"><input type="checkbox" name="rememberme" value="1" checked> <?php esc_html_e( 'Keep me signed in', 'product-glide' ); ?></label>
			<button type="submit" class="pg-btn pg-btn-primary pg-btn-block"><?php esc_html_e( 'Sign in', 'product-glide' ); ?></button>
			<a class="pg-login-forgot" href="<?php echo esc_url( wp_lostpassword_url( self::url() ) ); ?>"><?php esc_html_e( 'Forgot password?', 'product-glide' ); ?></a>
		</form>
		<?php endif; ?>
	</div>
</main>
</body>
</html>
		<?php
	}

	public static function render_app( $view ) {
		list( $name, $logo ) = self::brand();
		$views   = self::views();
		$page    = $views[ $view ][0];
		$handles = PG_Admin::enqueue( $page, 'front' );
		$user    = wp_get_current_user();
		self::head( $views[ $view ][1] . ' · ' . $name );
		wp_print_styles( array( 'pg-fonts', 'pg-admin' ) );
		?>
</head>
<body class="pg-app pg-front">
<header class="pg-topbar">
	<a class="pg-topbar-brand" href="<?php echo esc_url( self::url() ); ?>">
		<?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt=""><?php endif; ?>
		<span><?php echo esc_html( $name ); ?></span>
	</a>
	<nav class="pg-topnav" aria-label="<?php esc_attr_e( 'Dashboard', 'product-glide' ); ?>">
		<?php foreach ( $views as $key => $v ) : ?>
			<a href="<?php echo esc_url( self::url( $key ) ); ?>" class="<?php echo $key === $view ? 'is-active' : ''; ?>"<?php echo $key === $view ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $v[1] ); ?></a>
		<?php endforeach; ?>
	</nav>
	<div class="pg-topbar-user">
		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<a class="pg-topbar-link" href="<?php echo esc_url( admin_url( 'admin.php?page=pg-settings' ) ); ?>"><?php esc_html_e( 'Settings', 'product-glide' ); ?></a>
		<?php endif; ?>
		<span class="pg-topbar-name"><?php echo esc_html( $user->display_name ); ?></span>
		<a class="pg-btn pg-btn-sm" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'logout', 1, self::url() ), 'pg_logout' ) ); ?>"><?php esc_html_e( 'Sign out', 'product-glide' ); ?></a>
	</div>
</header>
<main class="pg-front-main">
		<?php
		switch ( $page ) {
			case 'pg-ocr':
				PG_Admin::page_ocr();
				break;
			case 'pg-leads':
				PG_Admin::page_leads();
				break;
			case 'pg-packages':
				PG_Admin::page_packages();
				break;
			case 'pg-profit':
				PG_Admin::page_profit();
				break;
			case 'pg-steadfast':
				PG_Admin::page_steadfast();
				break;
			case 'pg-team':
				PG_Admin::page_team();
				break;
			default:
				PG_Admin::page_orders();
		}
		?>
</main>
		<?php wp_print_scripts( $handles ); ?>
</body>
</html>
		<?php
	}
}
