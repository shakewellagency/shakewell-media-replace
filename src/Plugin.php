<?php
/**
 * Plugin bootstrap.
 *
 * @package Shakewell\MediaReplace
 */

namespace Shakewell\MediaReplace;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the "Replace file" control to the media screens and handles its request.
 */
final class Plugin {

	/**
	 * Registers hooks. Called on plugins_loaded.
	 */
	public static function boot(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'add_meta_boxes_attachment', array( self::class, 'meta_box' ) );
		add_filter( 'attachment_fields_to_edit', array( self::class, 'modal_field' ), 10, 2 );
		add_filter( 'media_row_actions', array( self::class, 'row_action' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'wp_enqueue_media', array( self::class, 'enqueue' ) );
		add_action( 'wp_ajax_shakewell_media_replace', array( self::class, 'ajax' ) );
	}

	/**
	 * Adds the box to the attachment edit screen.
	 */
	public static function meta_box(): void {
		add_meta_box( 'shakewell-media-replace', __( 'Replace file', 'shakewell-media-replace' ), array( self::class, 'render_box' ), 'attachment', 'side' );
	}

	/**
	 * Meta box callback.
	 *
	 * @param \WP_Post $post The attachment.
	 */
	public static function render_box( $post ): void {
		self::render( $post->ID );
	}

	/**
	 * Adds the control to the media modal. The edit screen has its own box, so it is skipped there.
	 *
	 * @param array    $fields Attachment fields.
	 * @param \WP_Post $post   The attachment.
	 * @return array
	 */
	public static function modal_field( $fields, $post ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ( $screen && 'attachment' === $screen->id ) || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $fields;
		}
		ob_start();
		self::render( $post->ID );
		$fields['shakewell_media_replace'] = array(
			'label' => __( 'Replace file', 'shakewell-media-replace' ),
			'input' => 'html',
			'html'  => (string) ob_get_clean(),
		);
		return $fields;
	}

	/**
	 * Adds a "Replace file" link to the media list row. It opens the edit screen at the box.
	 *
	 * @param array    $actions Row actions.
	 * @param \WP_Post $post    The attachment.
	 * @return array
	 */
	public static function row_action( $actions, $post ) {
		if ( current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $post->ID ) ) {
			$actions['shakewell_media_replace'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( get_edit_post_link( $post->ID ) . '#shakewell-media-replace' ),
				esc_html__( 'Replace file', 'shakewell-media-replace' )
			);
		}
		return $actions;
	}

	/**
	 * Loads the script on the media library, the edit screen and wherever the media modal opens.
	 *
	 * @param string $hook Admin page, empty when called for the media modal.
	 */
	public static function enqueue( $hook = '' ): void {
		// admin_enqueue_scripts passes the page; wp_enqueue_media (the modal) passes nothing.
		if ( '' !== $hook && ! in_array( $hook, array( 'post.php', 'upload.php' ), true ) ) {
			return;
		}
		wp_enqueue_script( 'shakewell-media-replace', plugins_url( 'assets/replace.js', FILE ), array(), VERSION, true );
	}

	/**
	 * Prints the file field, button and status line.
	 *
	 * @param int $id Attachment ID.
	 */
	private static function render( int $id ): void {
		$field = 'shakewell-media-replace-file-' . $id;
		?>
		<div class="shakewell-media-replace" data-id="<?php echo esc_attr( (string) $id ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'shakewell_media_replace_' . $id ) ); ?>" data-busy="<?php esc_attr_e( 'Replacing…', 'shakewell-media-replace' ); ?>" data-failed="<?php esc_attr_e( 'The file could not be replaced.', 'shakewell-media-replace' ); ?>">
			<label for="<?php echo esc_attr( $field ); ?>"><?php esc_html_e( 'Choose a new file', 'shakewell-media-replace' ); ?></label>
			<input type="file" id="<?php echo esc_attr( $field ); ?>" class="shakewell-media-replace__file">
			<button type="button" class="button shakewell-media-replace__button"><?php esc_html_e( 'Replace file', 'shakewell-media-replace' ); ?></button>
			<p class="description"><?php esc_html_e( 'Keeps the address and the attachment. Sizes are rebuilt and the old ones removed.', 'shakewell-media-replace' ); ?></p>
			<p class="shakewell-media-replace__status" role="status" aria-live="polite"></p>
		</div>
		<?php
	}

	/**
	 * AJAX handler: validates the request and hands the upload to the Replacer.
	 */
	public static function ajax(): void {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_ajax_referer( 'shakewell_media_replace_' . $id, 'nonce' );

		$file = isset( $_FILES['file'] ) ? $_FILES['file'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is checked with is_uploaded_file and the name is sanitised in the Replacer.
		if ( ! $id || ! is_array( $file ) || ! empty( $file['error'] ) || ! is_string( $file['tmp_name'] ?? null ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file was uploaded.', 'shakewell-media-replace' ) ), 400 );
		}

		// Lets multisite and other upload limits veto the file, as for any other upload.
		$file = apply_filters( 'wp_handle_upload_prefilter', $file ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, run so upload limits apply.
		if ( ! empty( $file['error'] ) ) {
			wp_send_json_error( array( 'message' => (string) $file['error'] ), 400 );
		}

		$result = Replacer::replace( $id, $file['tmp_name'], (string) $file['name'] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 'shakewell_media_replace_forbidden' === $result->get_error_code() ? 403 : 400 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'File replaced.', 'shakewell-media-replace' ),
				'url'     => add_query_arg( 'ver', time(), wp_get_attachment_url( $id ) ),
			)
		);
	}
}
