<?php
namespace NewfoldLabs\WP\SiteMigrator\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SmokeTest extends TestCase {
	public function test_bootstrap_loads() {
		$dir = \Fixture::reset();
		$this->assertDirectoryExists( $dir );
		$this->assertTrue( function_exists( 'nfd_sm_storage_path' ) );
		\Fixture::rmdir( $dir );
	}
}
