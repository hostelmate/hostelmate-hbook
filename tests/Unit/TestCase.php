<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

/** Resets the WordPress stand-ins before every test. */
abstract class TestCase extends \PHPUnit\Framework\TestCase {
	protected function setUp(): void {
		parent::setUp();
		\WpStub::reset();
	}
}
