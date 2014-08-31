<?php

	// prevent loading this file directly at webserver, relocate user to test page.
	if(php_sapi_name() !== 'cli' AND count(get_included_files()) == 1) { header("Location: sie_test.php"); die(); }

	define('REGEXP_SIE_COLUMNS_PART', "[ \t]*(\"(([^\"\\\\]+|\\\\.)*)\"|\{(([^\}\\\\]+|\\\\.)*)\}|[^ \t\"]+)");
	define('REGEXP_SIE_COLUMNS', "#" . REGEXP_SIE_COLUMNS_PART . "#");
	define('REGEXP_SIE_ROW', "#^([A-Z]+)(" . REGEXP_SIE_COLUMNS_PART . ")*$#");
	define('REGEXP_SNI', "#^[0-9]{5}$#");
	define('REGEXP_VALUTA', "#^[A-Z]{3}$#");
	define('REGEXP_ORGNR', "#^[0-9]{6}-[0-9]{4}$#");
	define('REGEXP_INT', "#^[0-9]+$#");
	define('REGEXP_KONTO', REGEXP_INT);
	define('REGEXP_DIM', REGEXP_INT);
	define('REGEXP_KTYP', "#^[TSKI]$#");
	define('REGEXP_RAR', "#^(0|-[1-9][0-9]*)$#");
	define('REGEXP_SALDO', "#^(0(\.[0-9]+)?|-0\.[0-9]*[1-9][0-9]*|-?[1-9][0-9]*(\.[0-9]+)?)$#");

	define('SIE_KSUMMA_CRC', 0xEDB88320);
	define('SIE_KSUMMA_MAX', 0xFFFFFFFF);

	class SIE
	{
		public $errors = array();

		public $settings = array();

		public $verifications = array();

		public $ksumma = NULL;

		static function test($sie_data)
		{
			$sie = new SIE($sie_data);

			$sie->validate_verifications_balance();

			$sie->validate_settings_required();

			return !($sie->errors);
		}

		public function __construct($sie_data, $encoding = 'PC8')
		{
			if($encoding == 'PC8')
			{
				$sie_data_utf8 = iconv('CP437', 'UTF-8', $sie_data);
			}
			else if($encoding = 'UTF-8')
			{
				$sie_data_utf8 = $sie_data;
			}
			else
			{
				$sie_data_utf8 = mb_convert_encoding($sie_data, 'UTF-8', $encoding);
			}

			if(!mb_detect_encoding($sie_data_utf8, array('ASCII', 'UTF-8'), TRUE))
			{
				$error = 'Failed to convert encoding to UTF-8';
				$this->errors[] = $error;
				trigger_error($error);
				return FALSE;
			}

			$rows = explode("\n", str_replace("\r\n", "\n", $sie_data_utf8));

			$label = "";
			$deepth = 0;
			$deepth_labels = array();
			$current_ver = NULL;

			foreach($rows as $row_index => $row)
			{
				$row_nr = $row_index + 1;
				$orginal_lengt = strlen($row);
				$row = trim($row);

				if(strlen($row) < $orginal_lengt)
				{
// 					$error = "Row {$row_nr} contains extra whitespace";
// 					$this->errors[] = $error;
// 					trigger_error($error);
				}

				if(!$row)
				{
					// Empty rows are allowed, and should be ignored, ref: 5.6
					continue;
				}

				// new subgroup?
				if($row == '{')
				{
					$deepth_labels[$deepth] = $label;
					$deepth++;
					continue;
				}

				// Close subgroup?
				if($row == '}')
				{
					if($deepth == 1 AND $deepth_labels[0] == 'VER')
					{
						$this->verifications[] = $current_ver;
						$current_ver = NULL;
					}

					if($deepth < 1)
					{
						$error = "Row {$row_nr}, found } with no matching {";
						$this->errors[] = $error;
						trigger_error($error);
					}

					$deepth--;
					continue;
				}

				if($row[0] != '#')
				{
					$error = "Row {$row_nr} don't start with a label";
					$this->errors[] = $error;
					trigger_error($error);
					continue;
				}

				$row = substr($row, 1);

				$regexp_result = preg_match(REGEXP_SIE_ROW, $row);

				if(!$regexp_result)
				{
					$error = "Row {$row_nr} have syntax error, may be misplaced qoutes";
					$this->errors[] = $error;
					trigger_error($error);
					continue;
				}

				$regexp_result = preg_match_all(REGEXP_SIE_COLUMNS, $row, $column_matches, PREG_SET_ORDER);

				if(!$regexp_result)
				{
					$error = "Row {$row_nr} have syntax error, may be misplaced qoutes 2";
					$this->errors[] = $error;
					trigger_error($error);
					continue;
				}

				$columns = array();
				foreach($column_matches as $current_match)
				{
					if($current_match[1][0] == '"')
					{
						$columns[] = stripslashes($current_match[2]);
					}
					else
					{
						$columns[] = $current_match[1];
					}
				}

				$column_count = count($columns);
				$label = $columns[0];

				if(isset($this->ksumma) AND $label != 'KSUMMA')
				{
					$crc_text = "#";
					foreach($columns as $current_column)
					{
						if($current_column AND $current_column[0] == "{")
						{
							$current_column = substr($current_column, 1, -1);
							// spaces between objects?
// 							if($current_column) echo "DEBUG: '{$current_column}'\n";
						}
						$crc_text .= $current_column;
					}
					$this->ksumma_crc_add($crc_text);
				}

				if($deepth == 0)
				{
					if(isset($current_ver))
					{
						$error = "Row {$current_ver['row_nr']}, label VER, didn't have a group of transactions";
						$this->errors[] = $error;
						trigger_error($error);
						continue;
					}

					switch($label)
					{
						case 'KSUMMA':
						{
							if(count($columns) == 1)
							{
								if(isset($this->ksumma))
								{
									$error = "Row {$row_nr}, label {$label}, more then one start marker found";
									$this->errors[] = $error;
									trigger_error($error);
								}

								$this->ksumma = 0;
							}
							else
							{
								if($this->load_settings_row($row_nr, $columns))
								{
									if($this->ksumma != $columns[1])
									{
										// FIXME: get ksumma calculation working, and matching test files
// 										$error = "Row {$row_nr}, label {$label}, KSumma calculated to {$this->ksumma}, expected {$columns[1]}";
// 										$this->errors[] = $error;
// 										trigger_error($error);
									}
								}

								$this->ksumma = NULL;
							}

							break;
						}

						case 'ADRESS':
						{
							if($this->load_settings_row($row_nr, $columns, 0, 1, 4))
							{
								$keys = array('kontakt', 'utdelningsadr', 'postadr', 'tel');
								$this->settings[$label] = array_combine($keys + range(0, count($this->settings[$label]) -1), $this->settings[$label] + array_fill(0, count($keys), ''));
							}
							break;
						}

						case 'BKOD':
						{
							if($this->load_settings_row($row_nr, $columns))
							{
								if(!preg_match(REGEXP_SNI, $this->settings[$label]))
								{
									$error = "Row {$row_nr}, label {$label}, non default value '{$this->settings[$label]}' found";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}
							}
							break;
						}

						case 'DIM':
						{
							if($this->load_settings_row($row_nr, $columns, 1))
							{
								if(!preg_match(REGEXP_DIM, $columns[1]))
								{
									$error = "Row {$row_nr}, label {$label}, bad dimensionsnr '{$columns[1]}'";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}
							}
							break;
						}

						case 'ENHET':
						{
							if($this->load_settings_row($row_nr, $columns, 1))
							{
								if(!preg_match(REGEXP_INT, $columns[1]))
								{
									$error = "Row {$row_nr}, label {$label}, bad kontonr '{$columns[1]}'";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}

								if(!isset($this->settings['KONTO'][$columns[1]]))
								{
									$error = "Row {$row_nr}, label {$label}, kontonr '{$columns[1]}' have no matching KONTO";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}
							}
							break;
						}

						// SIE flag, 0 = New, 1 = Old
						case 'FLAGGA':
						{
							if($this->load_settings_row($row_nr, $columns))
							{
								if($this->settings[$label] != 0 AND $this->settings[$label] != 1)
								{
									$error = "Row {$row_nr}, label {$label}, non default value '{$this->settings[$label]}' found";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}
							}
							break;
						}

						case 'FNAMN':
						{
							if($this->load_settings_row($row_nr, $columns))
							{
								if(!trim($this->settings[$label]))
								{
									$error = "Row {$row_nr}, label {$label}, empty value";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}
							}
							break;
						}

						case 'FNR':
						{
							$this->load_settings_row($row_nr, $columns);
							break;
						}

						case 'FORMAT':
						{
							if($this->load_settings_row($row_nr, $columns))
							{
								if($this->settings[$label] != 'PC8')
								{
									$error = "Row {$row_nr}, label {$label}, non default value '{$this->settings[$label]}' found";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}
							}
							break;
						}

						case 'FTYP':
						{
							if($this->load_settings_row($row_nr, $columns))
							{
								$types = array('AB', 'E', 'HB', 'KB', 'EK', 'KHF', 'BRF', 'BF', 'SF', 'I', 'S', 'FL', 'BAB', 'MB', 'SB', 'BFL', 'FAB', 'OFB', 'SE', 'SCE', 'TSF', 'X');

								if(!in_array($this->settings[$label], $types))
								{
									$error = "Row {$row_nr}, label {$label}, non default value '{$this->settings[$label]}' found";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}
							}
							break;
						}

						case 'GEN':
						{
							if($this->load_settings_row($row_nr, $columns, 0, 1, 2))
							{
								$keys = array('datum', 'sign');
								$this->settings[$label] = array_combine($keys + range(0, count($this->settings[$label]) -1), $this->settings[$label] + array_fill(0, count($keys), ''));

								if($this->settings[$label]['datum'] != date("Ymd", strtotime($this->settings[$label]['datum'])))
								{
									$error = "Row {$row_nr}, label {$label}, bad date '{$this->settings[$label]['datum']}'";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}
							}
							break;
						}

						case 'KONTO':
						{
							if($this->load_settings_row($row_nr, $columns, 1))
							{
								if(!preg_match(REGEXP_KONTO, $columns[1]))
								{
									$error = "Row {$row_nr}, label {$label}, bad kontonr '{$columns[1]}'";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}
							}
							break;
						}

						case 'KPTYP':
						{
							if($this->load_settings_row($row_nr, $columns))
							{
								$types = array('BAS95','BAS96','EUBAS97','NE2007');

								if(!in_array($this->settings[$label], $types))
								{
print_r($types);
									$error = "Row {$row_nr}, label {$label}, non default value '{$this->settings[$label]}' found";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}
							}
							break;
						}

						case 'KTYP':
						{
							if($this->load_settings_row($row_nr, $columns, 1))
							{
								if(!preg_match(REGEXP_KONTO, $columns[1]))
								{
									$error = "Row {$row_nr}, label {$label}, bad kontonr '{$columns[1]}'";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}

								if(!preg_match(REGEXP_KTYP, $columns[2]))
								{
									$error = "Row {$row_nr}, label {$label}, konto {$columns[1]}, bad type '{$columns[2]}'";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}

								if(!isset($this->settings['KONTO'][$columns[1]]))
								{
									$error = "Row {$row_nr}, label {$label}, kontonr '{$columns[1]}' have no matching KONTO";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}
							}
							break;
						}

						case 'OBJEKT':
						{
							if($this->load_settings_row($row_nr, $columns, 2))
							{
								if(!preg_match(REGEXP_DIM, $columns[1]))
								{
									$error = "Row {$row_nr}, label {$label}, bad dimensionsnr '{$columns[1]}'";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}
							}
							break;
						}

						case 'OMFATTN':
						{
							if($this->load_settings_row($row_nr, $columns))
							{
								if($this->settings[$label] != date("Ymd", strtotime($this->settings[$label])))
								{
									$error = "Row {$row_nr}, label {$label}, bad date '{$this->settings[$label]}'";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}
							}
							break;
						}

						case 'ORGNR':
						{
							if($this->load_settings_row($row_nr, $columns, 0, 1, 3))
							{
								$keys = array('orgnr', 'förvnr', 'verknr');
								$this->settings[$label] = array_combine($keys + range(0, count($this->settings[$label]) -1), $this->settings[$label] + array_fill(0, count($keys), ''));

								if(!preg_match(REGEXP_ORGNR, $this->settings[$label]['orgnr']))
								{
									$error = "Row {$row_nr}, label {$label}, bad orgnr '{$this->settings[$label]['orgnr']}', should be XXXXXX-XXXX";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}
								else
								{
									if($this->settings[$label]['orgnr'][2] < 2)
									{
										$date = "19" . substr($this->settings[$label]['orgnr'], 0, 6);
										if($date != date("Ymd", strtotime($date)))
										{
											$error = "Row {$row_nr}, label {$label}, bad date '" . substr($date, 2). "' in orgnr";
											$this->errors[] = $error;
											trigger_error($error);
											continue;
										}
									}

									$checksum =
										$this->settings[$label]['orgnr'][0] +
										$this->settings[$label]['orgnr'][1] * 2 + ($this->settings[$label]['orgnr'][1] > 4 ? -9 : 0) +
										$this->settings[$label]['orgnr'][2] +
										$this->settings[$label]['orgnr'][3] * 2 + ($this->settings[$label]['orgnr'][3] > 4 ? -9 : 0) +
										$this->settings[$label]['orgnr'][4] +
										$this->settings[$label]['orgnr'][5] * 2 + ($this->settings[$label]['orgnr'][5] > 4 ? -9 : 0) +
										$this->settings[$label]['orgnr'][7] +
										$this->settings[$label]['orgnr'][8] * 2 + ($this->settings[$label]['orgnr'][8] > 4 ? -9 : 0) +
										$this->settings[$label]['orgnr'][9] +
										$this->settings[$label]['orgnr'][10] * 2 + ($this->settings[$label]['orgnr'][10] > 4 ? -9 : 0);

									if($checksum % 10)
									{
										$error = "Row {$row_nr}, label {$label}, bad checksum '{$checksum}' in orgnr {$this->settings[$label]['orgnr']}";
										$this->errors[] = $error;
										trigger_error($error);
										continue;
									}
								}
							}
							break;
						}

						case 'PROGRAM':
						{
							if($this->load_settings_row($row_nr, $columns, 0, 2, 2))
							{
								$keys = array('programnamn', 'version');
								$this->settings[$label] = array_combine($keys + range(0, count($this->settings[$label]) -1), $this->settings[$label] + array_fill(0, count($keys), ''));

								foreach($this->settings[$label] as $index => $value)
								{
									if(!trim($value))
									{
										$error = "Row {$row_nr}, label {$label}, empty value for {$index}";
										$this->errors[] = $error;
										trigger_error($error);
										continue;
									}
								}
							}
							break;
						}

						case 'PROSA':
						{
							$this->load_settings_row($row_nr, $columns, 0, 1, 1, TRUE);
							break;
						}

						case 'RAR':
						{
							if($this->load_settings_row($row_nr, $columns, 1, 2, 2))
							{
								$date = $columns[2];
								if($date != date("Ymd", strtotime($date)))
								{
									$error = "Row {$row_nr}, label {$label}, bad date '{$date}' in start";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}

								$date = $columns[3];
								if($date != date("Ymd", strtotime($date)))
								{
									$error = "Row {$row_nr}, label {$label}, bad date '{$date}' in slut";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}
							}
							break;
						}

						case 'SIETYP':
						{
							$this->load_settings_row($row_nr, $columns);
							break;
						}

						case 'SRU':
						{
							if($this->load_settings_row($row_nr, $columns, 1, 1, 1, TRUE))
							{
								if(!preg_match(REGEXP_KONTO, $columns[1]))
								{
									$error = "Row {$row_nr}, label {$label}, bad kontonr '{$columns[1]}'";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}

// 								if(!isset($this->settings['KONTO'][$columns[1]]))
// 								{
// 									$error = "Row {$row_nr}, label {$label}, kontonr '{$columns[1]}' have no matching KONTO";
// 									$this->errors[] = $error;
// 									trigger_error($error);
// 									continue;
// 								}
							}
							break;
						}

						case 'TAXAR':
						{
							if($this->load_settings_row($row_nr, $columns))
							{
								if($this->settings[$label] != date("Y", strtotime("{$this->settings[$label]}-01-01")))
								{
									$error = "Row {$row_nr}, label {$label}, bad year";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}
							}
							break;
						}

						case 'UNDERDIM':
						{
							if($this->load_settings_row($row_nr, $columns, 1, 2, 2))
							{
								if(!preg_match(REGEXP_DIM, $columns[1]))
								{
									$error = "Row {$row_nr}, label {$label}, bad dimensionsnr '{$columns[1]}'";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}

								if(!preg_match(REGEXP_DIM, $columns[3]))
								{
									$error = "Row {$row_nr}, label {$label}, bad superdimension '{$columns[3]}'";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}
							}
							break;
						}

						case 'VALUTA':
						{
							if($this->load_settings_row($row_nr, $columns))
							{
								if(!preg_match(REGEXP_VALUTA, $this->settings[$label]))
								{
									$error = "Row {$row_nr}, label {$label}, bad value '{$this->settings[$label]}' found";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}
							}
							break;
						}

						case 'IB':
						case 'UB':
						case 'RES':
						{
							if($this->load_settings_row($row_nr, $columns, 2, 1, 2))
							{
								if(!preg_match(REGEXP_RAR, $columns[1]))
								{
									$error = "Row {$row_nr}, label {$label}, bad årsnr '{$columns[1]}'";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}

								if(!preg_match(REGEXP_KONTO, $columns[2]))
								{
									$error = "Row {$row_nr}, label {$label}, bad kontonr '{$columns[2]}'";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}

								if(!preg_match(REGEXP_SALDO, $columns[3]))
								{
									$error = "Row {$row_nr}, label {$label}, bad saldo '{$columns[3]}'";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}
							}
							break;
						}

						case 'OIB':
						case 'OUB':
						{
							if($this->load_settings_row($row_nr, $columns, 3, 1, 2))
							{
								if(!preg_match(REGEXP_RAR, $columns[1]))
								{
									$error = "Row {$row_nr}, label {$label}, bad årsnr '{$columns[1]}'";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}

								if(!preg_match(REGEXP_KONTO, $columns[2]))
								{
									$error = "Row {$row_nr}, label {$label}, bad kontonr '{$columns[2]}'";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}

								if(!preg_match(REGEXP_SALDO, $columns[4]))
								{
									$error = "Row {$row_nr}, label {$label}, bad saldo '{$columns[4]}'";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}
							}
							break;
						}

						case 'PBUDGET':
						case 'PSALDO':
						{
							if($this->load_settings_row($row_nr, $columns, 4, 1, 2))
							{
								if(!preg_match(REGEXP_RAR, $columns[1]))
								{
									$error = "Row {$row_nr}, label {$label}, bad årsnr '{$columns[1]}'";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}

								$date = $columns[2] . "01";
								if($date != date("Ymd", strtotime($date)))
								{
									$error = "Row {$row_nr}, label {$label}, bad period '{$columns[2]}'";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}

								if(!preg_match(REGEXP_KONTO, $columns[3]))
								{
									$error = "Row {$row_nr}, label {$label}, bad kontonr '{$columns[3]}'";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}

								if(!preg_match(REGEXP_SALDO, $columns[5]))
								{
									$error = "Row {$row_nr}, label {$label}, bad saldo '{$columns[5]}'";
									$this->errors[] = $error;
									trigger_error($error);
									continue;
								}
							}
							break;
						}

						// verifications
						case 'VER':
						{
							$min_count = 4;
							if($column_count < $min_count)
							{
								$error = "Row {$row_nr}, label {$label}, expected {$min_count} column, found {$column_count} columns";
								$this->errors[] = $error;
								trigger_error($error);
								continue;
							}

							$current_ver = array(
								'serie' => $columns[1],
								'vernr' => $columns[2],
								'verdatum' => $columns[3],
								'vertext' => @$columns[4],
								'regdatum' => @$columns[5],
								'sign' => @$columns[6],
								'trans' => array(),
								'new_trans' => array(),
								'removed_trans' => array(),
								'row_nr' => $row_nr,
							);

							if($current_ver['verdatum'] != date("Ymd", strtotime($current_ver['verdatum'])))
							{
								$error = "Row {$row_nr}, label {$label}, bad date '{$current_ver['regdatum']}' as verdatum";
								$this->errors[] = $error;
								trigger_error($error);
								continue;
							}

							if($current_ver['regdatum'] AND $current_ver['regdatum'] != date("Ymd", strtotime($current_ver['regdatum'])))
							{
								$error = "Row {$row_nr}, label {$label}, bad date '{$current_ver['regdatum']}' as regdatum";
								$this->errors[] = $error;
								trigger_error($error);
								continue;
							}

							break;
						}

						case 'TRANS':
						case 'RTRANS':
						case 'BTRANS':
						{
							$error = "Row {$row_nr}, label {$label} should be inside a VER";
							$this->errors[] = $error;
							trigger_error($error);
							continue;
						}

						default:
						{
							echo "Unknown label {$label} in: {$row}\n";
// 							print_r($columns);
							break;
						}
					}
				}
				else
				{
					if($deepth == 1 AND $deepth_labels[0] == 'VER')
					{
						$label = $columns[0];
						switch($label)
						{
							case 'TRANS':
							{
								$current_trans = array_slice($columns, 1);
								$current_ver['trans'][] = $current_trans;
								break;
							}

							case 'RTRANS':
							{
								$current_trans = array_slice($columns, 1);
								$current_ver['new_trans'][] = $current_trans;
								break;
							}

							case 'BTRANS':
							{
								$current_trans = array_slice($columns, 1);
								$current_ver['removed_trans'][] = $current_trans;
								break;
							}

							default:
							{
								echo "Unknown label {$label} inside VER in: {$row}\n";
// 								print_r($columns);
								continue;
							}
						}

						if(!preg_match(REGEXP_KONTO, $columns[1]))
						{
							$error = "Row {$row_nr}, label {$label}, bad kontonr '{$columns[1]}'";
							$this->errors[] = $error;
							trigger_error($error);
						}

						if(!preg_match(REGEXP_SALDO, $columns[3]))
						{
							$error = "Row {$row_nr}, label {$label}, bad belopp '{$columns[3]}'";
							$this->errors[] = $error;
							trigger_error($error);
						}

						if(isset($columns[4]) AND $columns[4] AND $columns[4] != date("Ymd", strtotime($columns[4])))
						{
							$error = "Row {$row_nr}, label {$label}, bad date '$columns[4]' as transdat";
							$this->errors[] = $error;
							trigger_error($error);
							continue;
						}
					}
					else
					{
						$error = "Row {$row_nr}, deepth {$deepth}, main label {$deepth_labels[0]}, unsuported deepth for current label";
						$this->errors[] = $error;
						trigger_error($error);
						continue;
					}
				}
			}

			if($deepth != 0)
			{
				if($deepth > 0)
				{
					$error = "File have more { then }";
				}
				else
				{
					$error = "File have less { then }";
				}
				$this->errors[] = $error;
				trigger_error($error);
				return;
			}

			if(isset($this->ksumma))
			{
				$error = "File have a ksumma start marker, but no end marker.";
				$this->errors[] = $error;
				trigger_error($error);
				return;
			}
		}

		public function load_settings_row($row_nr, $columns, $index_count = 0, $min_values_count = TRUE, $max_values_count = NULL, $non_unique_index = FALSE)
		{
			$column_count = count($columns);
			$label = $columns[0];

			if($min_values_count === TRUE)
			{
				$min_values_count = 1;
				$max_values_count = 1;
			}

			if($min_values_count == $max_values_count)
			{
				$min_count = 1 + $index_count + $min_values_count;

				if($column_count != $min_count)
				{
					$error = "Row {$row_nr}, label {$label}, expected {$min_count} column, found {$column_count} columns";
					$this->errors[] = $error;
					trigger_error($error);
					return FALSE;
				}
			}
			else
			{
				$min_count = 1 + $index_count + $min_values_count;

				if($column_count < $min_count)
				{
					$error = "Row {$row_nr}, label {$label}, expected at least {$min_count} column, found {$column_count} columns";
					$this->errors[] = $error;
					trigger_error($error);
					return FALSE;
				}

				if($max_values_count !== NULL)
				{
					$max_count = 1 + $index_count + $max_values_count;

					if($column_count > $max_count)
					{
						$error = "Row {$row_nr}, label {$label}, expected at most {$max_count} column, found {$column_count} columns";
						$this->errors[] = $error;
						trigger_error($error);
						return FALSE;
					}
				}
			}

			$setting_pointer = &$this->settings;
			$indexes = array_slice($columns, 0, $index_count + 1);
			$values = array_slice($columns, $index_count + 1);

			foreach($indexes as $index_nr => $index)
			{
				if(isset($setting_pointer[$index]))
				{
					if($index_count == $index_nr AND !$non_unique_index)
					{
						$error = "Row {$row_nr}, #" . implode(" ", $indexes) . " occurrence more then once";
						$this->errors[] = $error;
						trigger_error($error);
						return FALSE;
					}
				}
				else
				{
					$setting_pointer[$index] = array();
				}
				$setting_pointer = &$setting_pointer[$index];
			}

// 			print_r(array('$indexes' => $indexes, '$values' => $values, '$row_nr' => $row_nr, '$index_count' => $index_count, '$min_values_count' => $min_values_count, '$max_values_count' => $max_values_count, '$non_unique_index' => $non_unique_index));

			if(isset($max_values_count))
			{
				if($max_values_count == 1)
				{
					$values = $values[0];
				}
				else if($max_values_count == 0)
				{
					$values = $index;
				}
			}

			if($non_unique_index)
			{
				$setting_pointer[] = $values;
			}
			else
			{
				$setting_pointer = $values;
			}

			return TRUE;
		}

		public function validate_verifications_balance()
		{
			foreach($this->verifications as $current_ver)
			{
				$add = 0;
				$sub = 0;
				foreach($current_ver['trans'] as $current_trans)
				{
					if($current_trans[2] > 0)
					{
						$add += $current_trans[2];
					}
					else
					{
						$sub -= $current_trans[2];
					}
				}
				if($add != $sub)
				{
					$balance = $add - $sub;
					if($balance > 0.000001 OR $balance < -0.000001)
					{
						$error = "Row {$current_ver['row_nr']}, VER is not in balance: {$add} - {$sub} = {$balance}";
						$this->errors[] = $error;
						trigger_error($error);
						continue;
					}
				}
			}
		}

		public function validate_verifications_format()
		{
			// TODO
		}

		public function validate_settings_required()
		{
			$sie_type = 1;
			if(isset($this->settings['SIETYP']))
			{
				$sie_type = (int) $this->settings['SIETYP'];
				if($sie_type < 1 OR $sie_type > 4 OR $this->settings['SIETYP'] != (string) $sie_type)
				{
					$error = "Unknonw SIE type, #SIETYP {$this->settings['SIETYP']}";
					$this->errors[] = $error;
					trigger_error($error);
					return FALSE;
				}
			}

			if($this->verifications AND $sie_type < 4)
			{
				if(isset($this->settings['SIETYP']))
				{
					$error = "Verifications not allowed in current SIE type, #SIETYP {$this->settings['SIETYP']}, validating setting as type 4";
				}
				$this->errors[] = $error;
				trigger_error($error);

				$sie_type = 4;
			}

			$export_mode = ($sie_type < 4 OR isset($this->settings['IB']) OR isset($this->settings['UB']));

			$forbidden = array();
			$forbidden['BKOD'] = !$export_mode;
			$forbidden['DIM'] = ($sie_type < 3);
			$forbidden['IB'] = !$export_mode;
			$forbidden['OBJEKT'] = ($sie_type < 3);
			$forbidden['OMFATTN'] = ($sie_type == 1 OR !$export_mode);
			$forbidden['OIB'] = ($sie_type < 3 OR !$export_mode);
			$forbidden['OUB'] = ($sie_type < 3 OR !$export_mode);
			$forbidden['PBUDGET'] = ($sie_type == 1 OR !$export_mode);
			$forbidden['PSALDO'] = ($sie_type == 1 OR !$export_mode);
			$forbidden['RES'] = !$export_mode;
			$forbidden['UB'] = !$export_mode;
			$forbidden['UNDERDIM'] = ($sie_type < 3);
			$forbidden['VER'] = ($sie_type < 4);

			$required = array();
			$required['ADRESS'] = FALSE;
			$required['BKOD'] = FALSE;
			$required['DIM'] = FALSE;
			$required['ENHET'] = FALSE;
			$required['FLAGGA'] = TRUE;
			$required['FNAMN'] = ($sie_type > 1);
			$required['FNR'] = FALSE;
			$required['FORMAT'] = TRUE;
			$required['FTYP'] = FALSE;
			$required['GEN'] = TRUE;
			$required['IB'] = $export_mode;
			$required['KONTO'] = $export_mode;
			$required['KPTYP'] = FALSE;
			$required['KTYP'] = FALSE;
			$required['OBJEKT'] = FALSE;
			$required['OIB'] = ($sie_type == 3);
			$required['OMFATTN'] = ($sie_type > 1 AND $sie_type < 4);
			$required['ORGNR'] = FALSE;
			$required['OUB'] = ($sie_type == 3);
			$required['PBUDGET'] = ($sie_type > 1 AND $sie_type < 4);
			$required['PROGRAM'] = TRUE;
			$required['PROSA'] = FALSE;
			$required['PSALDO'] = ($sie_type > 1 AND $sie_type < 4);
			$required['RAR'] = $export_mode;
			$required['RES'] = $export_mode;
			$required['SIETYP'] = ($sie_type > 1); // can only be required if value exists...
			$required['SRU'] = ($sie_type < 3);
			$required['TAXAR'] = FALSE;
			$required['TRANS'] = FALSE;
			$required['RTRANS'] = FALSE;
			$required['BTRANS'] = FALSE;
			$required['UB'] = $export_mode;
			$required['UNDERDIM'] = FALSE;
			$required['VALUTA'] = FALSE;
			$required['VER'] = FALSE;

			foreach(array_keys(array_filter($forbidden)) as $key)
			{
				if(isset($this->settings[$key]))
				{
					$error = "Label {$key} isn't allowed in SIE {$sie_type} " . ($export_mode ? 'export' : 'import');
					$this->errors[] = $error;
					trigger_error($error);
				}
			}

			foreach(array_keys(array_filter($required)) as $key)
			{
				if(!isset($this->settings[$key]))
				{
					$error = "Label {$key} is required in SIE {$sie_type} " . ($export_mode ? 'export' : 'import');
					$this->errors[] = $error;
					trigger_error($error);
				}
			}
		}

		public function ksumma_crc_add($text, $UTF8 = TRUE)
		{
			static $crc_table = NULL;

			if(!isset($crc_table))
			{
				$crc_table = array();
				foreach(range(32, 255) + array('tab' => 9) as $index)
				{
					$value = $index;

					foreach(range(1, 8) as $bit_nr)
					{
						if($value & 1)
						{
							$value = ($value >> 1) ^ SIE_KSUMMA_CRC;
						}
						else
						{
							$value = ($value >> 1);
						}
					}

					$crc_table[chr($index)] = $value;
				}
			}

			$current = SIE_KSUMMA_MAX ^ $this->ksumma;

			if($UTF8)
			{
				$text =  iconv('UTF-8', 'CP437', $text);
			}

			foreach(str_split($text) as $char)
			{
if(isset($crc_table[$char]))
				$current = (($current >> 8) & 0x00FFFFFF) ^ $crc_table[$char];
else trigger_error("Bad char " . ord($char));
			}

			$this->ksumma = SIE_KSUMMA_MAX ^ $current;
		}
	}