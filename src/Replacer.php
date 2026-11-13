<?php
/**
 * Swaps an attachment's file for a new upload.
 *
 * @package Shakewell\MediaReplace
 */

namespace Shakewell\MediaReplace;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the attachment ID and the file's path, so every URL already pointing at it keeps working.
 */
final class Replacer {

	/**
	 * Replaces the file behind an attachment.
	 *
	 * @param int    $id       Attachment ID.
	 * @param string $new_path Path of the new file (the upload's temporary file).
	 * @param string $new_name Original name of the new file, used to detect its type.
	 * @return true|WP_Error
	 */
	public static function replace( int $id, string $new_path, string $new_name ) {
		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $id ) || 'attachment' !== get_post_type( $id ) ) {
			return new WP_Error( 'shakewell_media_replace_forbidden', __( 'You are not allowed to replace this file.', 'shakewell-media-replace' ) );
		}

		$old = get_attached_file( $id );
		$dir = wp_get_upload_dir();
		if ( ! $old || ! empty( $dir['error'] ) || 0 !== strpos( $old, trailingslashit( $dir['basedir'] ) ) || false !== strpos( $old, '..' ) ) {
			return new WP_Error( 'shakewell_media_replace_path', __( 'This attachment is not stored in the uploads folder.', 'shakewell-media-replace' ) );
		}

		$checked = self::check( $id, $old, $new_path, $new_name );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		// Write beside the old file, then rename, so a failed copy never leaves a half-written original.
		if ( ! wp_mkdir_p( dirname( $old ) ) || ! copy( $new_path, $old . '.new' ) ) {
			return new WP_Error( 'shakewell_media_replace_write', __( 'The new file could not be saved.', 'shakewell-media-replace' ) );
		}

		$old_meta = wp_get_attachment_metadata( $id );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- same folder, and WP_Filesystem has no atomic rename.
		if ( ! rename( $old . '.new', $old ) ) {
			wp_delete_file( $old . '.new' );
			return new WP_Error( 'shakewell_media_replace_write', __( 'The new file could not be saved.', 'shakewell-media-replace' ) );
		}

		// Only now that the new file is in place: new sizes can reuse the old names, so delete before regenerating.
		self::delete_generated( $id, $old, is_array( $old_meta ) ? $old_meta : array() );

		if ( get_post_mime_type( $id ) !== $checked['type'] ) {
			wp_update_post(
				array(
					'ID'             => $id,
					'post_mime_type' => $checked['type'],
				)
			);
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		// Keep the path: scaling a big image or rotating it for its EXIF orientation would rename the file and so change the URL.
		add_filter( 'big_image_size_threshold', '__return_false' );
		add_filter( 'wp_image_maybe_exif_rotate', '__return_false' );
		$meta = wp_generate_attachment_metadata( $id, $old );
		remove_filter( 'big_image_size_threshold', '__return_false' );
		remove_filter( 'wp_image_maybe_exif_rotate', '__return_false' );

		// Fires wp_update_attachment_metadata, which offload plugins and CDNs listen to.
		wp_update_attachment_metadata( $id, $meta );
		clean_attachment_cache( $id );

		/**
		 * Fires after a file was replaced and its metadata rebuilt. Offload plugins re-upload here.
		 *
		 * @param int    $id   Attachment ID.
		 * @param string $path Absolute path of the new file.
		 */
		do_action( 'shakewell_media_replace_replaced', $id, $old );

		return true;
	}

	/**
	 * Applies WordPress's own upload checks, then requires the same file extension and broad type as the old file.
	 *
	 * @param int    $id       Attachment ID.
	 * @param string $old      Path of the current file.
	 * @param string $new_path Path of the new file.
	 * @param string $new_name Original name of the new file.
	 * @return array{type:string,ext:string}|WP_Error
	 */
	private static function check( int $id, string $old, string $new_path, string $new_name ) {
		if ( ! is_file( $new_path ) || filesize( $new_path ) <= 0 ) {
			return new WP_Error( 'shakewell_media_replace_empty', __( 'No file was uploaded.', 'shakewell-media-replace' ) );
		}
		if ( filesize( $new_path ) > wp_max_upload_size() ) {
			return new WP_Error( 'shakewell_media_replace_size', __( 'The file is larger than the upload limit.', 'shakewell-media-replace' ) );
		}

		$found = wp_check_filetype_and_ext( $new_path, sanitize_file_name( $new_name ) );
		if ( empty( $found['type'] ) || empty( $found['ext'] ) || ! in_array( $found['type'], get_allowed_mime_types(), true ) ) {
			return new WP_Error( 'shakewell_media_replace_type', __( 'This file type is not allowed.', 'shakewell-media-replace' ) );
		}

		$normalise = static function ( string $ext ): string {
			$ext = strtolower( $ext );
			return in_array( $ext, array( 'jpeg', 'jpe' ), true ) ? 'jpg' : $ext;
		};
		$old_mime  = (string) get_post_mime_type( $id );
		if ( strstr( $found['type'], '/', true ) !== strstr( $old_mime, '/', true ) ) {
			return new WP_Error( 'shakewell_media_replace_broad', __( 'The new file must be the same kind of file as the one it replaces.', 'shakewell-media-replace' ) );
		}
		if ( $normalise( $found['ext'] ) !== $normalise( pathinfo( $old, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'shakewell_media_replace_ext', __( 'The new file must have the same extension, so the address stays the same.', 'shakewell-media-replace' ) );
		}

		return $found;
	}

	/**
	 * Deletes every generated size, the unscaled original and edit backups (except 'full-orig'), but not the main file.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $old  Path of the main file.
	 * @param array  $meta Current attachment metadata.
	 */
	private static function delete_generated( int $id, string $old, array $meta ): void {
		$names = array();
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			$names[] = $size['file'] ?? '';
		}
		$names[] = $meta['original_image'] ?? '';
		// 'full-orig' is the file as it was before an image-editor edit; content may still link to it, so it stays.
		foreach ( (array) get_post_meta( $id, '_wp_attachment_backup_sizes', true ) as $key => $size ) {
			$names[] = is_array( $size ) && 'full-orig' !== $key ? ( $size['file'] ?? '' ) : '';
		}

		foreach ( array_unique( array_filter( $names, 'is_string' ) ) as $name ) {
			// basename() keeps a tampered metadata entry inside the attachment's folder.
			$path = dirname( $old ) . '/' . basename( $name );
			if ( '' !== $name && $path !== $old && is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		delete_post_meta( $id, '_wp_attachment_backup_sizes' );
	}
}
