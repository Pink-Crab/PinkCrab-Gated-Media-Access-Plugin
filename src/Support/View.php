<?php
/**
 * Template rendering.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Support;

/**
 * Renders a template file from `views/` with data, so a class holds its logic and its markup lives somewhere that reads as markup.
 *
 * A template sees one variable, `$data`, the array it was handed. `extract()` is deliberately not used: WordPress coding standards forbid it, and a template naming its own keys can be read on its own.
 *
 * Anything that resolves outside the view directory, or is not there, renders nothing rather than fataling. A half-drawn admin screen is recoverable; a fatal is not.
 */
class View {

	/**
	 * The template directory, under the plugin root.
	 */
	private const DIRECTORY = 'views/';

	/**
	 * Renders a template and prints it.
	 *
	 * @param string              $template Path under `views/`, without the `.php`.
	 * @param array<string,mixed> $data     Handed to the template as `$data`.
	 */
	public static function render( string $template, array $data = array() ): void {
		echo self::get( $template, $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a template escapes its own output.
	}

	/**
	 * Renders a template and returns it.
	 *
	 * @param string              $template Path under `views/`, without the `.php`.
	 * @param array<string,mixed> $data     Handed to the template as `$data`.
	 *
	 * @throws \Throwable Whatever the template threw, once its buffer is closed.
	 */
	public static function get( string $template, array $data = array() ): string {
		$path = self::path( $template );

		if ( null === $path ) {
			return '';
		}

		$level = ob_get_level();

		try {
			ob_start();
			self::include_template( $path, $data );

			return (string) ob_get_clean();
		} catch ( \Throwable $error ) {
			// A template that threw part way through leaves its buffer open otherwise.
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}

			throw $error;
		}
	}

	/**
	 * The template directory, filterable so a site can draw from its own.
	 */
	private static function directory(): string {
		/**
		 * Filters where templates are loaded from.
		 *
		 * @param string $directory Absolute path, trailing slash.
		 */
		return (string) apply_filters( 'gatedmedia_view_directory', GATEDMEDIA_DIR_PATH . self::DIRECTORY );
	}

	/**
	 * The absolute path of a template, or null when it is missing or outside the view directory.
	 *
	 * @param string $template Path under the view directory, without the `.php`.
	 */
	private static function path( string $template ): ?string {
		$root = realpath( self::directory() );
		$file = realpath( self::directory() . ltrim( $template, '/' ) . '.php' );

		if ( false === $root || false === $file || ! str_starts_with( $file, $root . DIRECTORY_SEPARATOR ) ) {
			return null;
		}

		return $file;
	}

	/**
	 * Includes a template in a scope holding nothing but its data.
	 *
	 * Static, so a template cannot reach `$this` and start calling back into the class that drew it.
	 *
	 * @param string              $gatedmedia_view_path Absolute path of the template.
	 * @param array<string,mixed> $data                 Handed to the template as `$data`.
	 */
	private static function include_template( string $gatedmedia_view_path, array $data ): void {
		( static function () use ( $gatedmedia_view_path, $data ): void {
			include $gatedmedia_view_path;
		} )();
	}
}
