<?php
/**
 * Plugin Name:       Shakewell Media Replace
 * Plugin URI:        https://github.com/shakewellagency/shakewell-media-replace
 * Description:       Replace a media library file with a new upload and keep the attachment ID and URL.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Shakewell
 * Author URI:        https://shakewell.agency
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shakewell-media-replace
 *
 * @package Shakewell\MediaReplace
 */

namespace Shakewell\MediaReplace;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.0';
const FILE    = __FILE__;

require_once __DIR__ . '/src/Plugin.php';
require_once __DIR__ . '/src/Replacer.php';

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
