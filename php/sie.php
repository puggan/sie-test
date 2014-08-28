<?php

	if(php_sapi_name() !== 'cli' AND count(get_included_files()) == 1) { header("Location: sie_test.php"); die(); }

	define('REGEXP_SIE_COLUMNS_PART', "[ \t]*(\"(([^\"\\\\]+|\\\\.)*)\"|\{(([^\}\\\\]+|\\\\.)*)\}|[^ \t\"]+)");
	define('REGEXP_SIE_COLUMNS', "#" . REGEXP_SIE_COLUMNS_PART . "#");
	define('REGEXP_SIE_ROW', "#^([A-Z]+)(" . REGEXP_SIE_COLUMNS_PART . ")*$#");

	class SIE
	{
		public $errors = array();

		public $settings = array();

		public $verifications = array();

		static function test($sie_data)
		{
			$sie = new SIE($sie_data);

			$sie->validate_verifications_balance();

			$sie->validate_verifications_format();

			$sie->validate_settings_required();

			$sie->validate_settings_format();

			$sie->validate_checksum();

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

			foreach($rows as $row_nr => $row)
			{
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
						$columns[] = $current_match[2];
					}
					else
					{
						$columns[] = $current_match[1];
					}
				}

				$column_count = count($columns);
				if($deepth == 0)
				{
					if(isset($current_ver))
					{
						$error = "Row {$current_ver['row_nr']}, label VER, didn't have a group of transactions";
						$this->errors[] = $error;
						trigger_error($error);
						continue;
					}

					$label = $columns[0];
					switch($label)
					{
						// one value settings
						case 'BKOD':
						case 'FLAGGA':
						case 'FNAMN':
						case 'FNR':
						case 'FORMAT':
						case 'FTYP':
						case 'KPTYP':
						case 'OMFATTN':
						case 'SIETYP':
						case 'TAXAR':
						case 'VALUTA':
						{
							if($column_count < 2)
							{
								$error = "Row {$row_nr}, label {$label}, expected 2 column, found {$column_count} columns";
								$this->errors[] = $error;
								trigger_error($error);
								continue;
							}

							if(isset($this->settings[$label]))
							{
								$error = "Row {$row_nr}, label {$label} occurrence more then once";
								$this->errors[] = $error;
								trigger_error($error);
								continue;
							}

							if($column_count > 2)
							{
								$error = "Row {$row_nr}, label {$label}, expected 2 column, found {$column_count} columns";
								$this->errors[] = $error;
								trigger_error($error);
							}

							$value = $columns[1];

							$this->settings[$label] = $value;
							break;
						}

						// multi value settings, no index
						case 'ADRESS':
						case 'GEN':
						case 'ORGNR':
						case 'PROGRAM':
						{
							if($column_count < 2)
							{
								$error = "Row {$row_nr}, label {$label}, expected at least 2 column, found {$column_count} columns";
								$this->errors[] = $error;
								trigger_error($error);
								continue;
							}

							if(isset($this->settings[$label]))
							{
								$error = "Row {$row_nr}, label {$label} occurrence more then once";
								$this->errors[] = $error;
								trigger_error($error);
								continue;
							}

							$value = array_slice($columns, 1);

							$this->settings[$label] = $value;
							break;
						}

						// multi row values, no index
						case 'PROSA': // 0 index + 1 value + autoindex
						{
							if($column_count != 2)
							{
								$error = "Row {$row_nr}, label {$label}, expected 2 column, found {$column_count} columns";
								$this->errors[] = $error;
								trigger_error($error);
								continue;
							}

							$value = array_slice($columns, 1);

							$this->settings[$label][] = $value;
							break;
						}

						// two value settings as key-value pairs, 1 index, 1 value
						case 'DIM':
						case 'ENHET':
						case 'KONTO':
						case 'KTYP':
						case 'SRU':
						{
							if($column_count < 3)
							{
								$error = "Row {$row_nr}, label {$label}, expected 3 column, found {$column_count} columns";
								$this->errors[] = $error;
								trigger_error($error);
								continue;
							}

							if(!isset($this->settings[$label]))
							{
								$this->settings[$label] = array();
							}

							$key = $columns[1];
							$value = $columns[2];

							if(isset($this->settings[$label][$key]))
							{
								$error = "Row {$row_nr}, label {$label}, key {$key} occurrence more then once";
								$this->errors[] = $error;
								trigger_error($error);
								continue;
							}

							if($column_count > 3)
							{
								$error = "Row {$row_nr}, label {$label}, expected 3 column, found {$column_count} columns";
								$this->errors[] = $error;
								trigger_error($error);
							}

							$this->settings[$label][$key] = $value;
							break;
						}

						// single indexed settings, 1 index, x values
						case 'RAR': // 1 index + 2 values
						case 'UNDERDIM': // 1 index + 2 values
						{
							if($column_count < 3)
							{
								$error = "Row {$row_nr}, label {$label}, expected at least 3 column, found {$column_count} columns";
								$this->errors[] = $error;
								trigger_error($error);
								continue;
							}

							if(!isset($this->settings[$label]))
							{
								$this->settings[$label] = array();
							}

							$key = $columns[1];
							$value = array_slice($columns, 2);

							if(isset($this->settings[$label][$key]))
							{
								$error = "Row {$row_nr}, label {$label}, key {$key} occurrence more then once";
								$this->errors[] = $error;
								trigger_error($error);
								continue;
							}

							$this->settings[$label][$key] = $value;
							break;
						}

						// dubble indexed settings, 1 index, x values
						case 'IB': // 2 index + 2 values
						case 'OBJEKT': // 2 index + 1 value
						case 'OIB': // 2 index + 3 values
						case 'OUB': // 2 index + 3 values
						case 'RES': // 2 index + 2 values
						case 'UB': // 2 index + 2 values
						{
							if($column_count < 4)
							{
								$error = "Row {$row_nr}, label {$label}, expected at least 4 column, found {$column_count} columns";
								$this->errors[] = $error;
								trigger_error($error);
								continue;
							}

							if(!isset($this->settings[$label]))
							{
								$this->settings[$label] = array();
							}

							$main_key = $columns[1];
							$sub_key = $columns[2];
							$value = array_slice($columns, 3);

							if(isset($this->settings[$label][$main_key][$sub_key]))
							{
								$error = "Row {$row_nr}, label {$label}, key {$key} {$sub_key} occurrence more then once";
								$this->errors[] = $error;
								trigger_error($error);
								continue;
							}

							$this->settings[$label][$main_key][$sub_key] = $value;
							break;
						}

						// triple indexed settings, 1 index, x values
						case 'PBUDGET': // 3 index + 3 values
						case 'PSALDO': // 3 index + 3 values
						{
							if($column_count < 5)
							{
								$error = "Row {$row_nr}, label {$label}, expected at least 5 column, found {$column_count} columns";
								$this->errors[] = $error;
								trigger_error($error);
								continue;
							}

							if(!isset($this->settings[$label]))
							{
								$this->settings[$label] = array();
							}

							$main_key = $columns[1];
							$sub_key = $columns[2];
							$deep_key = $columns[3];
							$value = array_slice($columns, 4);

							if(isset($this->settings[$label][$main_key][$sub_key][$deep_key]))
							{
								$error = "Row {$row_nr}, label {$label}, key {$key} {$sub_key} {$deep_key} occurrence more then once";
								$this->errors[] = $error;
								trigger_error($error);
								continue;
							}

							$this->settings[$label][$main_key][$sub_key][$deep_key] = $value;
							break;
						}

						// verifications
						case 'VER':
						{
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
								'balance' => 0,
								'row_nr' => $row_nr,
							);
							break;
						}

// 						{
// 							echo "Support for {$label} isn't implemented yet: {$row}\n";
// 							print_r($columns);
// 							break;
// 						}

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
								break;
							}
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
				continue;
			}
		}

		public function validate_verifications_balance()
		{
			foreach($this->verifications as $current_ver)
			{
				$balance = (float) 0;
				foreach($current_ver['trans'] as $current_trans)
				{
					$balance += $current_trans[2];
				}
				if($balance != 0)
				{
					$error = "Row {$current_ver['row_nr']}, VER is not in balance: {$balance}";
					$this->errors[] = $error;
					trigger_error($error);
					continue;
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

			$export_mode = ($sie_type < 4 OR isset($this->settings['IB']));

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

		public function validate_settings_format()
		{
			// TODO
		}

		public function validate_checksum()
		{
			// TODO
		}
	}