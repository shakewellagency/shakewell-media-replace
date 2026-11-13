<?php
/**
 * The AJAX endpoint rejects requests without a valid nonce or upload.
 *
 * @package Shakewell\MediaReplace
 */

namespace Shakewell\MediaReplace\Tests;

use Shakewell\MediaReplace\Plugin;
use WP_Ajax_UnitTestCase;

final class AjaxTest extends WP_Ajax_UnitTestCase {

	public function test_bad_nonce_is_refused(): void {
		$this->_setRole( 'administrator' );
		set_current_screen( 'dashboard' );
		Plugin::boot();
		$id                = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$_POST['id']       = $id;
		$_POST['nonce']    = 'wrong';
		try {
			$this->_handleAjax( 'shakewell_media_replace' );
		} catch ( \WPAjaxDieStopException $e ) {
			$this->assertSame( '-1', $e->getMessage() );
			return;
		} catch ( \WPAjaxDieContinueException $e ) {
			$this->fail( 'Request was accepted.' );
		}
		$this->fail( 'Expected the request to die.' );
	}

	public function test_missing_upload_is_refused(): void {
		$this->_setRole( 'administrator' );
		set_current_screen( 'dashboard' );
		Plugin::boot();
		$id             = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$_POST['id']    = $id;
		$_POST['nonce'] = wp_create_nonce( 'shakewell_media_replace_' . $id );
		try {
			$this->_handleAjax( 'shakewell_media_replace' );
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		}
		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'] );
	}
}
