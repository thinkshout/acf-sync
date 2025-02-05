<?php
/**
 * Plugin to manage import/export of ACF Configurations
 *
 * Plugin Name:     ACF Sync
 * Description:     ACF Sync functionality.
 * Domain Path:     /languages
 * Version:         2.0.0
 *
 * @package ACF Sync
 */

/**
 * Requirements. (If this comment is absent, linting fails.)
 */
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

/**
 * Add the ability to update the theme via WP CLI.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	$child = get_stylesheet_directory();
	include_once $child . '/updates/class-update-theme.php';
}


/**
 * Customize locations for ACF configurations to be discovered.
 *
 * Look for acf configuration within Single Directory Components like Posts and
 * Flexible Components, and in the active theme, in addition to the default
 * directory.
 *
 * @param array $paths The current list of paths.
 *
 * @return array
 */
function acf_sync_load_json( $paths ) {
	// Pull in fields from the parent theme.
	$paths[] = get_template_directory() . '/acf-json';

	// Pull in fields from the child theme. These will override the parent
	// theme if they have matching IDs.
	$paths[] = get_stylesheet_directory() . '/acf-json';

	$candidates = array(
		get_template_directory() . '/content-types/',
		get_template_directory() . '/views/organisms/blocks/',
	);
	foreach ( $candidates as $candidate ) {
		// Note that we can't use get_post_types here, as it only returns the
		// stock post types in this function, so we rely on glob to look at the
		// directory structure instead. This has the added benefit of working
		// for other Single Directory Components more easily.
		foreach ( glob( "$candidate/*", GLOB_ONLYDIR ) as $directory ) {
			$paths[] = "$directory/controller";
		}
	}
	return $paths;
}
add_filter( 'acf/settings/load_json', 'acf_sync_load_json' );

/**
 * Give our ACF export files clean names of the format Title_IDstring.
 *
 * @param string $filename Default filename.
 * @param array  $post The ACF configuration.
 *
 * @return string
 */
function acf_sync_json_save_file_name( $filename, $post ) {
	if ( 0 === strpos( $filename, 'group_' ) ) {
		$filename = substr( $filename, strlen( 'group_' ) );
	}

	$friendly_name = str_replace(
		array(
			' ',
			'_',
		),
		array(
			'-',
			'-',
		),
		$post['title']
	);

	$friendly_name = strtolower( $friendly_name );

	return $friendly_name . '_' . $filename;
}
add_filter( 'acf/json/save_file_name', 'acf_sync_json_save_file_name', 10, 3 );

/**
 * Provides the default path for ACF json exports.
 *
 * Note that this is heavily overridden in following functions.
 *
 * @param string $path Default path.
 *
 * @return string
 */
function acf_sync_save_json( $path ) {
	// Send updates to the current theme by default.
	$path = get_template_directory() . '/acf-json';

	return $path;
}
add_filter( 'acf/settings/save_json', 'acf_sync_save_json' );

/**
 * Using context clues, save ACF configuration that are specific to a component
 * into the component's SDC directory structure.
 *
 * @param array $paths Current path candidates.
 * @param array $acf   The ACF configuration.
 *
 * @return string[]
 */
function acf_sync_smart_save_paths( $paths, $acf ) {
	// By default, include the active theme's acf-json directory:
	$paths[] = get_stylesheet_directory() . '/acf-json';
	$file_name = acf_sync_json_save_file_name( $acf['key'] . '.json', $acf, null );
	// ACF location configuration for specific post types.
	if ( isset( $acf['location'][0][0] ) && $acf['location'][0][0]['operator'] === '==' ) {
		switch ( $acf['location'][0][0]['param'] ) {
			case 'block':
				// The value is of the form: "acf/BLOCKNAME--controller".
				preg_match( '/acf\/(.*)--controller/', $acf['location'][0][0]['value'], $matches );
				if ( isset( $matches[1] ) ) {
					$paths = _thinkshout_acf_generate_controller_paths( '/views/organisms/blocks/' . $matches[1], $file_name );
				}
				break;

			case 'page':
			case 'page_type':
				$post_type_id = 'page';
				$paths        = _thinkshout_acf_generate_controller_paths( "/content-types/$post_type_id", $file_name );
				break;

			case 'options_page':
				$post_type_id = null;
				$location_value = $acf['location'][0][0]['value'];
				$archive_start = strpos( $location_value, '-archive' );
				if ( $archive_start ) {
					$post_type_id = substr( $location_value, 0, $archive_start );
				}
				if ( $acf['location'][0][0]['param'] === 'options_page' ) {
					$options_pages = acf_get_options_pages();
					if ( isset( $options_pages[ $location_value ] ) ) {
						if ( strpos( $options_pages[ $location_value ]['parent_slug'], 'edit.php?post_type=' ) === 0 ) {
							$offset = strlen( 'edit.php?post_type=' );
							$post_type_id = substr( $options_pages[ $location_value ]['parent_slug'], $offset );
						}
					}
				}
				if ( $post_type_id ) {
					$paths = _thinkshout_acf_generate_controller_paths( "/content-types/$post_type_id", $file_name );
				}
				break;

			case 'post_type':
				$post_type_id = $acf['location'][0][0]['value'];
				$paths        = _thinkshout_acf_generate_controller_paths( "/content-types/$post_type_id", $file_name );
				break;

			case 'page_template':
				$paths = _thinkshout_acf_generate_controller_paths( '/content-types/page', $file_name );
				break;

		}
	}

	return $paths;
}
add_filter( 'acf/json/save_paths', 'acf_sync_smart_save_paths', 10, 2 );

/**
 * A helper function to look for controller-specific paths.
 *
 * It looks in the active theme and this theme for valid paths. If it finds one
 * that also contains a copy of the file, it returns that path as the only
 * candidate. If it does not, it returns candidate paths, and ensures the top
 * candidate exists so it can be used for saving.
 *
 * If $check_base_path is set to true, it will first confirm that the base path
 * exists in at least one of the active themes, and return 'false' in not.
 *
 * @param string $base_path       The directory that would have the component.
 * @param string $file_name       The name of our configuration file.
 * @param bool   $check_base_path Whether to check that a matching directory
 *   exists and return false if not, before generating our directory list.
 *
 * @return false|string[]
 */
function _thinkshout_acf_generate_controller_paths( $base_path, $file_name, $check_base_path = false ) {
	$template_directory   = get_template_directory();
	$stylesheet_directory = get_stylesheet_directory();
	$paths = array( $template_directory . $base_path );
	// We also check the subtheme, if different.
	if ( $stylesheet_directory !== $template_directory ) {
		$paths[] = $stylesheet_directory . $base_path;
	}
	if ( $check_base_path ) {
		$valid = false;
		foreach ( $paths as $path_candidate ) {
			if ( is_dir( $path_candidate ) ) {
				$valid = true;
			}
		}
		if ( ! $valid ) {
			return false;
		}
	}
	foreach ( $paths as $option => &$path_candidate ) {
		$path_candidate .= '/controller';
		if ( is_dir( "$path_candidate" ) ) {
			if ( is_file( "$path_candidate/$file_name" ) ) {
				// Existing file found, this is the path we want.
				return array( "$path_candidate" );
			}
		} elseif ( $option === 0 ) {
			// Ensure the directory exists for our default choice.
			$filesystem = ( new WP_Filesystem_Direct( false ) );
			$filesystem->mkdir( "$path_candidate", 0755, true );
		}
	}

	return $paths;
}
