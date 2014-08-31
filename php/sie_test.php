<?php

	require_once("sie.php");

	if(php_sapi_name() === 'cli')
	{
		if($argc > 1)
		{
			$files = array_slice($argv, 1);
		}
		else
		{
			$files = array('-');
		}

		foreach($files as $file)
		{
			echo "Testing '{$file}':\n";
			if($file == '-')
			{
				$content = file_get_contents("php://stdin");
			}
			else if(!file_exists($file))
			{
				trigger_error("Can't find file: '{$file}'");
				continue;
			}
			else if(!is_readable($file))
			{
				trigger_error("Can't read file: '{$file}'");
				continue;
			}
			else if(!is_file($file))
			{
				trigger_error("Not a file: '{$file}'");
				continue;
			}
			else
			{
				$content = file_get_contents($file);
			}

			if(!$content)
			{
				trigger_error("Empty file: '{$file}'");
				continue;
			}

			$result = SIE::test($content);

			if($result)
			{
				echo "SIE-parsing OK in file: '{$file}'\n";
				continue;
			}
			else
			{
				trigger_error("SIE-parsing Errors in file: '{$file}'");
				continue;
			}
		}
	}
	else
	{
		echo <<<HTML_BLOCK
<html>
	<head>
		<title>SIE test</title>
	</head>
	<body>
		<h1>SIE test</h1>

HTML_BLOCK;
		if(isset($_POST['sie']))
		{
			$result = SIE::test($_POST['sie']);

			if($result)
			{
				echo "<p>Filen är OK</p><hr/>";
			}
			else
			{
				echo "<p>Filen innehöll fel</p><hr/>";
			}
		}
		else
		{
			$_POST['sie'] = '';
		}

		echo <<<HTML_BLOCK
			<form action='?' method='post'>
				<input type='submit' value='Validate SIE data' /><br />
				<textarea name='sie' style='min-width: 1000px; min-height: 400px;'>{$_POST['sie']}</textarea>
			</form>
	</body>
</html>

HTML_BLOCK;
	}
