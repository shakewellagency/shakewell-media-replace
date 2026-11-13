<?php
/**
 * Replacing a file keeps the attachment and its address, rebuilds sizes, and refuses unsafe files.
 *
 * @package Shakewell\MediaReplace
 */

namespace Shakewell\MediaReplace\Tests;

use Shakewell\MediaReplace\Plugin;
use Shakewell\MediaReplace\Replacer;
use WP_UnitTestCase;

final class ReplaceTest extends WP_UnitTestCase {

	/** @var int */
	private $id;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
	}

	private function copy_of( string $source, string $name ): string {
		$path = get_temp_dir() . wp_unique_filename( get_temp_dir(), $name );
		copy( $source, $path );
		return $path;
	}

	public function test_replaces_file_keeping_id_url_and_rebuilding_sizes(): void {
		$url      = wp_get_attachment_url( $this->id );
		$old_dir  = dirname( get_attached_file( $this->id ) );
		$old_meta = wp_get_attachment_metadata( $this->id );
		$this->assertNotEmpty( $old_meta['sizes'] );
		$old_size_file = $old_dir . '/' . $old_meta['sizes']['thumbnail']['file'];
		$this->assertFileExists( $old_size_file );

		$fired = 0;
		add_action(
			'shakewell_media_replace_replaced',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		$result = Replacer::replace( $this->id, $this->copy_of( DIR_TESTDATA . '/images/test-image.jpg', 'new.jpg' ), 'new.jpg' );

		$this->assertTrue( $result );
		$this->assertSame( 1, $fired );
		$this->assertSame( $url, wp_get_attachment_url( $this->id ) );
		$this->assertFileDoesNotExist( $old_size_file );
		$this->assertSame( getimagesize( DIR_TESTDATA . '/images/test-image.jpg' )[0], wp_get_attachment_metadata( $this->id )['width'] );
	}

	public function test_a_rotated_jpeg_keeps_the_file_name(): void {
		$file = get_attached_file( $this->id );
		$url  = wp_get_attachment_url( $this->id );
		$this->assertTrue( Replacer::replace( $this->id, $this->copy_of( DIR_TESTDATA . '/images/test-image-upside-down.jpg', 'up.jpg' ), 'up.jpg' ) );
		$this->assertSame( $file, get_attached_file( $this->id ) );
		$this->assertSame( $url, wp_get_attachment_url( $this->id ) );
	}

	public function test_keeps_the_pre_edit_original_that_content_may_link_to(): void {
		$dir  = dirname( get_attached_file( $this->id ) );
		$orig = $dir . '/pre-edit.jpg';
		copy( DIR_TESTDATA . '/images/test-image.jpg', $orig );
		update_post_meta( $this->id, '_wp_attachment_backup_sizes', array( 'full-orig' => array( 'file' => 'pre-edit.jpg' ) ) );
		Replacer::replace( $this->id, $this->copy_of( DIR_TESTDATA . '/images/test-image.jpg', 'new.jpg' ), 'new.jpg' );
		$this->assertFileExists( $orig );
	}

	public function test_updates_metadata_through_the_standard_filter_so_offload_plugins_notice(): void {
		$seen = 0;
		add_filter(
			'wp_update_attachment_metadata',
			static function ( $meta ) use ( &$seen ) {
				++$seen;
				return $meta;
			}
		);
		Replacer::replace( $this->id, $this->copy_of( DIR_TESTDATA . '/images/test-image.jpg', 'new.jpg' ), 'new.jpg' );
		$this->assertGreaterThan( 0, $seen );
	}

	public function test_refuses_a_different_extension(): void {
		$result = Replacer::replace( $this->id, $this->copy_of( DIR_TESTDATA . '/images/test-image.png', 'new.png' ), 'new.png' );
		$this->assertWPError( $result );
		$this->assertSame( 'shakewell_media_replace_ext', $result->get_error_code() );
	}

	public function test_refuses_a_disallowed_type(): void {
		$path = get_temp_dir() . 'evil.php';
		file_put_contents( $path, '<?php echo 1;' );
		$result = Replacer::replace( $this->id, $path, 'evil.php' );
		$this->assertWPError( $result );
		$this->assertSame( 'shakewell_media_replace_type', $result->get_error_code() );
	}

	public function test_refuses_a_file_whose_content_is_not_its_extension(): void {
		$path = get_temp_dir() . 'fake.jpg';
		file_put_contents( $path, 'not an image' );
		$this->assertWPError( Replacer::replace( $this->id, $path, 'fake.jpg' ) );
	}

	public function test_refuses_a_different_kind_of_file(): void {
		$bits   = wp_upload_bits( 'doc.pdf', null, '%PDF-1.4' );
		$pdf    = wp_insert_attachment(
			array(
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'Doc',
			),
			$bits['file']
		);
		$result = Replacer::replace( $pdf, $this->copy_of( DIR_TESTDATA . '/images/test-image.jpg', 'x.jpg' ), 'x.jpg' );
		$this->assertWPError( $result );
		$this->assertSame( 'shakewell_media_replace_broad', $result->get_error_code() );
	}

	public function test_refuses_a_file_over_the_upload_limit(): void {
		add_filter( 'upload_size_limit', static fn() => 10 );
		$result = Replacer::replace( $this->id, $this->copy_of( DIR_TESTDATA . '/images/test-image.jpg', 'big.jpg' ), 'big.jpg' );
		$this->assertSame( 'shakewell_media_replace_size', $result->get_error_code() );
	}

	public function test_refuses_a_user_who_cannot_edit_the_attachment(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$result = Replacer::replace( $this->id, $this->copy_of( DIR_TESTDATA . '/images/test-image.jpg', 'new.jpg' ), 'new.jpg' );
		$this->assertSame( 'shakewell_media_replace_forbidden', $result->get_error_code() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$result = Replacer::replace( $this->id, $this->copy_of( DIR_TESTDATA . '/images/test-image.jpg', 'new2.jpg' ), 'new2.jpg' );
		$this->assertSame( 'shakewell_media_replace_forbidden', $result->get_error_code() );
	}

	public function test_a_tampered_size_name_cannot_delete_outside_the_folder(): void {
		$victim = get_temp_dir() . 'victim.txt';
		file_put_contents( $victim, 'x' );
		$meta                                = wp_get_attachment_metadata( $this->id );
		$meta['sizes']['thumbnail']['file'] = '../../../../../../../../' . ltrim( $victim, '/' );
		wp_update_attachment_metadata( $this->id, $meta );

		Replacer::replace( $this->id, $this->copy_of( DIR_TESTDATA . '/images/test-image.jpg', 'new.jpg' ), 'new.jpg' );
		$this->assertFileExists( $victim );
	}

	public function test_controls_render_in_the_modal_and_edit_box_with_nonce_and_label(): void {
		set_current_screen( 'dashboard' );
		Plugin::boot();
		$fields = apply_filters( 'attachment_fields_to_edit', array(), get_post( $this->id ) );
		$this->assertStringContainsString( 'data-nonce=', $fields['shakewell_media_replace']['html'] );
		$this->assertStringContainsString( 'role="status"', $fields['shakewell_media_replace']['html'] );
		$this->assertStringContainsString( '<label for=', $fields['shakewell_media_replace']['html'] );

		$actions = apply_filters( 'media_row_actions', array(), get_post( $this->id ) );
		$this->assertArrayHasKey( 'shakewell_media_replace', $actions );
	}
}
