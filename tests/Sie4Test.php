<?php

	declare(strict_types=1);

	use PHPUnit\Framework\TestCase;
	use Puggan\Sie\Sie4;

	final class Sie4Test extends TestCase
	{
		public function testCreateFromFile(): void
		{
			$sie = Sie4::loadFile(__DIR__ . '/files/Sie4.se');
			$this->assertInstanceOf(Sie4::class, $sie);
		}
	}
