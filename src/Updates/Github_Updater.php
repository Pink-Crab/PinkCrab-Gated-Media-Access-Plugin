<?php
/**
 * Updates from GitHub releases.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Updates;

/**
 * Offers the latest stable GitHub release to WordPress as a plugin update, and installs it without being asked.
 *
 * The plugin is not on wordpress.org, so the `Update URI` header in the bootstrap points at the repository and core hands the update check to `update_plugins_github.com` instead of api.wordpress.org.
 *
 * Only a plain `X.Y.Z` release is ever offered. Release candidates carry a suffix and are refused here whatever GitHub says about them, so an RC is installed by hand or not at all.
 */
class Github_Updater {

	/** The repository the releases are read from. */
	public const REPOSITORY = 'Pink-Crab/PinkCrab-Gated-Media-Access-Plugin';

	/** The plugin slug, used for the details screen and the update row. */
	public const SLUG = 'gated-media-access';

	/** The release asset that holds the installable plugin, built by .github/workflows/release.yml. */
	public const ASSET = 'gated-media-access.zip';

	/** Where the parsed release is kept between checks. A site transient, because `update_plugins` is one too and a network shares both. */
	private const CACHE_KEY = 'gatedmedia_latest_release';

	/** How long a failed lookup is remembered, so a broken connection costs one request every hour rather than one per admin page. */
	private const FAILURE_LIFETIME = HOUR_IN_SECONDS;

	/**
	 * Attaches the update check, the details screen and the cache reset.
	 *
	 * Called from the bootstrap outside the dependency gate. Everything else waits for restrict-media-file-access, but updates are how a broken install is repaired, so refusing to check for one until the plugin is working is backwards.
	 */
	public function attach(): void {
		add_filter( 'update_plugins_github.com', array( $this, 'offer' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'information' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'forget' ) );
	}

	/**
	 * Answers core's update check for this plugin.
	 *
	 * The offer is returned whether or not it is newer than what is installed: core compares the two itself, and a version that is not newer lands in `no_update`, which is what puts the auto-update control on the plugins screen.
	 *
	 * @param mixed                $update      Whatever an earlier callback returned, false by default.
	 * @param array<string, mixed> $plugin_data The plugin headers.
	 * @param string               $plugin_file The plugin basename, for any plugin whose `Update URI` is on github.com.
	 *
	 * @return mixed The update offer, or the value passed in for anything that is not this plugin.
	 */
	public function offer( $update, $plugin_data, $plugin_file ) {
		if ( GATEDMEDIA_BASENAME !== $plugin_file ) {
			return $update;
		}

		$release = $this->latest_release();

		if ( null === $release ) {
			return $update;
		}

		$headers = is_array( $plugin_data ) ? $plugin_data : array();

		$offer = array(
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => (string) ( $headers['RequiresWP'] ?? '' ),
			'requires_php' => (string) ( $headers['RequiresPHP'] ?? '' ),

			/**
			 * Filters whether a stable release installs itself.
			 *
			 * True by default, which is enough on its own: `WP_Automatic_Updater::should_update()` reads this flag off the offer and does not consult the site's own auto-update setting. Return false to leave the decision to the administrator.
			 *
			 * @param bool   $enabled Whether to update without being asked.
			 * @param string $version The version being offered.
			 */
			'autoupdate'   => (bool) apply_filters( 'gatedmedia_auto_update', true, $release['version'] ),
		);

		// An asset is only missing where the release build failed, and then the version is still worth announcing even though nothing can install it.
		if ( '' === $offer['package'] ) {
			unset( $offer['package'] );
		}

		/**
		 * Filters the update offer handed back to core.
		 *
		 * @param array<string, mixed> $offer       The offer, keyed as core's `update_plugins_{$hostname}` documents.
		 * @param array<string, mixed> $release     The parsed release it was built from.
		 * @param array<string, mixed> $plugin_data The plugin headers.
		 */
		return apply_filters( 'gatedmedia_update_offer', $offer, $release, $headers );
	}

	/**
	 * Fills the details screen behind the update row's "View version details" link.
	 *
	 * Core sends that link to plugin-install.php because the offer carries a slug, and without this the screen would ask wordpress.org about a plugin it has never heard of.
	 *
	 * @param mixed  $result The result an earlier callback returned, false by default.
	 * @param string $action The API action being asked for.
	 * @param mixed  $args   The arguments, an object carrying the slug.
	 *
	 * @return mixed The plugin information, or the value passed in for any other plugin.
	 */
	public function information( $result, $action, $args ) {
		$slug = is_object( $args ) && property_exists( $args, 'slug' ) ? (string) $args->slug : '';

		if ( 'plugin_information' !== $action || self::SLUG !== $slug ) {
			return $result;
		}

		$release = $this->latest_release();

		if ( null === $release ) {
			return $result;
		}

		$headers = $this->plugin_headers();

		return (object) array(
			'name'          => (string) ( $headers['Name'] ?? 'Gated Media Access' ),
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => (string) ( $headers['Author'] ?? '' ),
			'homepage'      => (string) ( $headers['PluginURI'] ?? '' ),
			'requires'      => (string) ( $headers['RequiresWP'] ?? '' ),
			'requires_php'  => (string) ( $headers['RequiresPHP'] ?? '' ),
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => wpautop( wp_kses_post( (string) ( $headers['Description'] ?? '' ) ) ),
				'changelog'   => wpautop( wp_kses_post( $release['notes'] ) ),
			),
		);
	}

	/**
	 * Drops the cached release once anything has been installed, so the plugins screen stops offering a version that is now the one running.
	 */
	public function forget(): void {
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * The latest stable release, from cache where there is one.
	 *
	 * @return array<string, mixed>|null Null where there is no usable release.
	 */
	private function latest_release(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );

		// An empty array is a remembered failure; false means nothing has been asked yet.
		if ( is_array( $cached ) ) {
			return array() === $cached ? null : $cached;
		}

		$release = $this->parse( $this->fetch() );

		/**
		 * Filters the parsed release before it becomes an update offer.
		 *
		 * Return null to refuse the update. The result is cached, so a callback here is not asked again until the cache lapses.
		 *
		 * @param array<string, mixed>|null $release Version, notes, url, package and published date.
		 */
		$release = apply_filters( 'gatedmedia_update_release', $release );

		/**
		 * Filters how long a release is cached for, in seconds.
		 *
		 * The check runs on admin page loads, so this is what stops every one of them hitting the GitHub API.
		 *
		 * @param int $seconds Twelve hours by default.
		 */
		$lifetime = (int) apply_filters( 'gatedmedia_update_cache_lifetime', 12 * HOUR_IN_SECONDS );

		if ( ! is_array( $release ) ) {
			set_site_transient( self::CACHE_KEY, array(), self::FAILURE_LIFETIME );

			return null;
		}

		set_site_transient( self::CACHE_KEY, $release, max( 1, $lifetime ) );

		return $release;
	}

	/**
	 * Asks GitHub for the newest release.
	 *
	 * The `latest` endpoint is used rather than the full list because it never answers with a draft or a prerelease, which is the first of the three things keeping release candidates out.
	 *
	 * @return array<string, mixed>|null Null on anything but a 200 carrying JSON.
	 */
	private function fetch(): ?array {
		/**
		 * Filters the repository releases are read from, as `owner/name`.
		 *
		 * The host is fixed: core only calls this plugin's check because the `Update URI` header names github.com.
		 *
		 * @param string $repository The repository.
		 */
		$repository = (string) apply_filters( 'gatedmedia_update_repository', self::REPOSITORY );

		$response = wp_remote_get(
			sprintf( 'https://api.github.com/repos/%s/releases/latest', $repository ),
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Turns GitHub's release into the handful of values an offer needs.
	 *
	 * @param array<string, mixed>|null $release The decoded release.
	 *
	 * @return array<string, mixed>|null Null where the release is not a stable one this plugin can install.
	 */
	private function parse( ?array $release ): ?array {
		if ( null === $release ) {
			return null;
		}

		$draft      = (bool) ( $release['draft'] ?? false );
		$prerelease = (bool) ( $release['prerelease'] ?? false );

		if ( $draft || $prerelease ) {
			return null;
		}

		$version = ltrim( (string) ( $release['tag_name'] ?? '' ), 'vV' );

		// The last of the three refusals, and the one that holds even where a release candidate has been marked as the latest release by hand.
		if ( 1 !== preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			return null;
		}

		return array(
			'version'   => $version,
			'notes'     => (string) ( $release['body'] ?? '' ),
			'url'       => (string) ( $release['html_url'] ?? '' ),
			'package'   => $this->asset_url( $release ),
			'published' => (string) ( $release['published_at'] ?? '' ),
		);
	}

	/**
	 * The download URL of the release's plugin zip.
	 *
	 * GitHub's own source archives are ignored: they hold the repository rather than a built plugin, and their top level directory is named after the tag.
	 *
	 * @param array<string, mixed> $release The decoded release.
	 *
	 * @return string Empty where the release has no such asset.
	 */
	private function asset_url( array $release ): string {
		$assets = is_array( $release['assets'] ?? null ) ? $release['assets'] : array();

		foreach ( $assets as $asset ) {
			if ( is_array( $asset ) && self::ASSET === ( $asset['name'] ?? '' ) ) {
				return (string) ( $asset['browser_download_url'] ?? '' );
			}
		}

		return '';
	}

	/**
	 * This plugin's own headers, for the details screen.
	 *
	 * @return array<string, mixed> Empty outside the admin, where `get_plugin_data()` is not loaded.
	 */
	private function plugin_headers(): array {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			return array();
		}

		return get_plugin_data( GATEDMEDIA_DIR_PATH . 'gated-media-access.php', false, false );
	}
}
