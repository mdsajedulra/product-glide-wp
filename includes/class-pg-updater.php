<?php
defined( 'ABSPATH' ) || exit;

/**
 * Updates from the public GitHub repo's Releases.
 *
 * Each release is tagged vX.Y.Z and carries the plugin zip (product-glide.zip,
 * containing the product-glide/ folder) as an asset. The repo is public, so no
 * token is needed: every site sees "Update available" on the
 * Plugins page and installs it with one click.
 */
class PG_Updater {

	const SLUG      = 'product-glide';
	const CACHE     = 'pg_update_release';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_update' ), 10, 2 );
		add_action( 'admin_post_pg_check_updates', array( __CLASS__, 'check_now' ) );
	}

	private static function repo() {
		$r = trim( (string) PG_Settings::get( 'gh_repo' ) );
		return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $r ) ? $r : '';
	}

	private static function headers( $accept = 'application/vnd.github+json' ) {
		$h = array(
			'Accept'               => $accept,
			'User-Agent'           => 'product-glide-updater',
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		return $h;
	}

	/**
	 * Latest release: array( version, tag, notes, published, asset_url, html_url ) or WP_Error.
	 * Cached for a few hours so the Plugins page stays fast.
	 */
	public static function latest( $force = false ) {
		if ( ! self::repo() ) {
			return new WP_Error( 'pg_upd_repo', __( 'Set the GitHub repository in Settings → Updates.', 'product-glide' ) );
		}
		if ( ! $force ) {
			$c = get_site_transient( self::CACHE );
			if ( is_array( $c ) ) {
				return $c;
			}
			$e = get_site_transient( self::CACHE . '_err' );
			if ( $e ) {
				return new WP_Error( 'pg_upd_cached', (string) $e );
			}
		}
		delete_site_transient( self::CACHE . '_err' );
		$res = wp_remote_get( 'https://api.github.com/repos/' . self::repo() . '/releases/latest', array( 'timeout' => 15, 'headers' => self::headers() ) );
		if ( is_wp_error( $res ) ) {
			set_site_transient( self::CACHE . '_err', $res->get_error_message(), HOUR_IN_SECONDS );
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			$msg = 404 === $code ? __( 'Repository or release not found — check the repository name and that the repository is public.', 'product-glide' )
				: ( 403 === $code ? __( 'GitHub is limiting requests right now — try again in an hour.', 'product-glide' ) : sprintf( 'GitHub HTTP %d', $code ) );
			set_site_transient( self::CACHE . '_err', $msg, HOUR_IN_SECONDS );
			return new WP_Error( 'pg_upd_http', $msg );
		}
		$asset = '';
		foreach ( (array) ( isset( $body['assets'] ) ? $body['assets'] : array() ) as $a ) {
			if ( isset( $a['name'], $a['url'] ) && preg_match( '/\.zip$/i', $a['name'] ) ) {
				$asset = $a['url']; // API url; fetched in download().
				if ( 'product-glide.zip' === $a['name'] ) {
					break;
				}
			}
		}
		$tag  = isset( $body['tag_name'] ) ? (string) $body['tag_name'] : '';
		$data = array(
			'version'   => ltrim( $tag, 'vV' ),
			'tag'       => $tag,
			'notes'     => isset( $body['body'] ) ? (string) $body['body'] : '',
			'published' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
			'asset_url' => $asset,
			'html_url'  => isset( $body['html_url'] ) ? (string) $body['html_url'] : '',
		);
		set_site_transient( self::CACHE, $data, self::CACHE_TTL );
		return $data;
	}

	/** Tell WordPress about a newer release. */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$rel = self::latest();
		if ( is_wp_error( $rel ) || empty( $rel['asset_url'] ) || ! $rel['version'] ) {
			return $transient;
		}
		$file = plugin_basename( PG_FILE );
		$item = (object) array(
			'id'           => 'github.com/' . self::repo(),
			'slug'         => self::SLUG,
			'plugin'       => $file,
			'new_version'  => $rel['version'],
			'url'          => $rel['html_url'],
			'package'      => $rel['asset_url'],
			'requires_php' => '7.4',
			'icons'        => array(),
			'banners'      => array(),
		);
		if ( version_compare( $rel['version'], PG_VERSION, '>' ) ) {
			$transient->response[ $file ] = $item;
			unset( $transient->no_update[ $file ] );
		} else {
			$transient->no_update[ $file ] = $item;
		}
		return $transient;
	}

	/** "View details" popup on the Plugins page. */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$rel = self::latest();
		if ( is_wp_error( $rel ) ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Product Glide',
			'slug'          => self::SLUG,
			'version'       => $rel['version'],
			'author'        => 'Md Sajedul Islam',
			'homepage'      => $rel['html_url'],
			'last_updated'  => $rel['published'],
			'requires_php'  => '7.4',
			'download_link' => $rel['asset_url'],
			'sections'      => array(
				'changelog' => $rel['notes'] ? wpautop( esc_html( $rel['notes'] ) ) : esc_html__( 'No release notes.', 'product-glide' ),
			),
		);
	}

	/**
	 * Release assets are served from the API URL with a redirect to a signed
	 * download URL — follow it ourselves so the zip arrives intact.
	 */
	public static function download( $reply, $package, $upgrader ) {
		if ( false !== $reply || ! is_string( $package ) || ! self::repo() || 0 !== strpos( $package, 'https://api.github.com/repos/' . self::repo() . '/releases/assets/' ) ) {
			return $reply;
		}
		$res = wp_remote_get( $package, array( 'timeout' => 30, 'redirection' => 0, 'headers' => self::headers( 'application/octet-stream' ) ) );
		if ( is_wp_error( $res ) ) {
			set_site_transient( self::CACHE . '_err', $res->get_error_message(), HOUR_IN_SECONDS );
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			$loc = wp_remote_retrieve_header( $res, 'location' );
			if ( ! $loc ) {
				return new WP_Error( 'pg_upd_dl', 'GitHub did not return a download location.' );
			}
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			return download_url( $loc, 300 );
		}
		if ( 200 === $code ) {
			$tmp = wp_tempnam( 'product-glide.zip' );
			file_put_contents( $tmp, wp_remote_retrieve_body( $res ) ); // phpcs:ignore
			return $tmp;
		}
		return new WP_Error( 'pg_upd_dl', sprintf( 'Download failed (GitHub HTTP %d).', $code ) );
	}

	/** Make sure the unpacked folder is named product-glide, whatever the zip calls it. */
	public static function fix_folder( $source, $remote_source, $upgrader, $extra = array() ) {
		global $wp_filesystem;
		if ( empty( $extra['plugin'] ) || plugin_basename( PG_FILE ) !== $extra['plugin'] ) {
			return $source;
		}
		$want = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( trailingslashit( $source ) === $want || ! $wp_filesystem ) {
			return $source;
		}
		return $wp_filesystem->move( $source, $want, true ) ? $want : $source;
	}

	public static function after_update( $upgrader, $opts ) {
		if ( isset( $opts['type'] ) && 'plugin' === $opts['type'] ) {
			delete_site_transient( self::CACHE );
			delete_site_transient( self::CACHE . '_err' );
		}
	}

	/** Settings → Updates → "Check now". */
	public static function check_now() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Not allowed', 'product-glide' ) );
		}
		check_admin_referer( 'pg_check_updates' );
		delete_site_transient( self::CACHE );
		delete_site_transient( self::CACHE . '_err' );
		$rel = self::latest( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$msg = is_wp_error( $rel ) ? 'error' : ( version_compare( $rel['version'], PG_VERSION, '>' ) ? 'available' : 'latest' );
		wp_safe_redirect( add_query_arg( array( 'page' => 'pg-settings', 'tab' => 'updates', 'checked' => $msg ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Status block for the settings tab. */
	public static function render_status() {
		$rel = self::repo() ? self::latest() : null;
		echo '<div class="pg-field"><label>' . esc_html__( 'Status', 'product-glide' ) . '</label><div class="pg-upd-status">';
		echo '<div>' . esc_html__( 'Installed version:', 'product-glide' ) . ' <b>' . esc_html( PG_VERSION ) . '</b></div>';
		if ( null === $rel ) {
			echo '<div class="pg-muted">' . esc_html__( 'Add the repository below, save, then press "Check now".', 'product-glide' ) . '</div>';
		} elseif ( is_wp_error( $rel ) ) {
			echo '<div class="pg-alert pg-alert-bad" style="margin:6px 0 0">' . esc_html( $rel->get_error_message() ) . '</div>';
		} else {
			echo '<div>' . esc_html__( 'Latest release on GitHub:', 'product-glide' ) . ' <b>' . esc_html( $rel['version'] ) . '</b>' . ( $rel['published'] ? ' <span class="pg-muted">(' . esc_html( PG_Helpers::bd_time( strtotime( $rel['published'] ), 'd M Y' ) ) . ')</span>' : '' ) . '</div>';
			if ( version_compare( $rel['version'], PG_VERSION, '>' ) ) {
				echo '<div class="pg-alert pg-alert-ok" style="margin:6px 0 0">' . esc_html__( 'A new version is available.', 'product-glide' ) . ' <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Go to Plugins → Update now', 'product-glide' ) . '</a></div>';
			} else {
				echo '<div class="pg-muted">' . esc_html__( 'You are on the latest version.', 'product-glide' ) . '</div>';
			}
			if ( empty( $rel['asset_url'] ) ) {
				echo '<div class="pg-alert pg-alert-warn" style="margin:6px 0 0">' . esc_html__( 'The latest release has no .zip file attached.', 'product-glide' ) . '</div>';
			}
		}
		echo '</div></div>';
		echo '<div class="pg-field"><a class="pg-btn" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pg_check_updates' ), 'pg_check_updates' ) ) . '">' . esc_html__( 'Check now', 'product-glide' ) . '</a></div>';
	}
}
