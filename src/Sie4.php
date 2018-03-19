<?php

	namespace Puggan\Sie;

	/**
	 * Class Sie4
	 *
	 * @package Puggan\Sie
	 */
	class Sie4
	{
		/**
		 * Load a SIE file from filesystem
		 *
		 * @param string $file_name
		 *
		 * @return self
		 */
		public static function loadFile($file_name)
		{
			$sie = new self();
			// TODO
			return $sie;
		}

		/**
		 * Convert back to sie
		 *
		 * @return string
		 */
		public function toString()
		{
			// TODO implement
			return "N/A";
		}

		/**
		 * Save SIE to a file
		 *
		 * @param $file_name
		 *
		 * @return bool|int
		 */
		public function save($file_name)
		{
			return file_put_contents($file_name, $this->toString());
		}
	}
