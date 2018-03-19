<?php

	namespace Puggan\Sie;

	/**
	 * Class Sie4
	 *
	 * @package Puggan\Sie
	 */
	class Sie4
	{
		private $data = [];
		public $row_separator = "\r\n";

		/**
		 * Load a SIE file from filesystem
		 *
		 * @param string $file_name
		 *
		 * @return self
		 * @throws FileException
		 */
		public static function loadFile($file_name)
		{
			if(!is_file($file_name))
			{
				throw new FileException('File not found');
			}

			$file_reader = function ($file_name) {
				if(!$r = fopen($file_name, 'rb'))
				{
					throw new FileException("Can't read file");
				}

				while(FALSE !== ($l = fgets($r)))
				{
					if(substr($l, -1) !== "\n")
					{
						yield $l;
					}
					else if(substr($l, -2) !== "\r\n")
					{
						yield substr($l, 0, -1);
					}
					else
					{
						yield substr($l, 0, -2);
					}
				}

				fclose($r);
			};

			return new self($file_reader($file_name));
		}

		/**
		 * Load a SIE from rows
		 *
		 * @param string[]
		 */
		public function __construct($rows)
		{
			foreach($rows as $row)
			{
				$this->data[] = $row;
			}
		}

		/**
		 * Convert back to sie
		 *
		 * @return string
		 */
		public function toString()
		{
			return implode($this->row_separator, $this->data);
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
