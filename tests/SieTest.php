<?php

	declare(strict_types=1);

	use PHPUnit\Framework\TestCase;
	use Puggan\Sie\Sie;

	final class SieTest extends TestCase
	{
		public function testCreateFromFile(): void
		{
			$sie = Sie::loadFile(__DIR__ . '/files/Sie4.se');
			$this->assertInstanceOf(Sie::class, $sie);
		}
	}
