<?php

	use PHPUnit\Framework\TestCase;
	use Puggan\Sie\Sie4;

	final class Sie4Test extends TestCase
	{
		public $base_file = __DIR__ . '/files/Sie4.se';

		public function testCreateFromFile()
		{
			$sie = Sie4::loadFile($this->base_file);
			$this->assertInstanceOf(Sie4::class, $sie);
		}

		public function testSave()
		{
			$tmp_file = tempnam(sys_get_temp_dir(), 'sie_');
			$sie = Sie4::loadFile($this->base_file);
			$sie->save($tmp_file);
			$this->assertFileEquals($this->base_file, $tmp_file);
		}
	}
