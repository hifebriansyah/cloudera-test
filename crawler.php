<?php

set_time_limit (-1);

$iata = include "iata.php";

$_GET['date'] = '2019-08-01';
$_GET['days'] = 30;

foreach ($iata as $code => $val) {
	if (isset($val['keys'])){
		$until = date('Y-m-d', strtotime('+'.$_GET['days'].' day', strtotime($_GET['date'])));

		foreach ($val['keys'] as $key) {
			echo "\nkey : ".$key."\n";
			echo "scrapping..\n";
			$tweets = $tweet = [];
			exec('sudo twint -s "'.$key.'" --since '.$_GET['date'].' --until '.$until.' --format {id}^#^{tweet}^#^{photos}^#^{date}^##^', $tweet);
			//exec('echo "mf" | sudo -S -u mf /home/mf/.local/bin/twint -s "'.$key.'" --since '.$_GET['date'].' --until '.$until.' --format {id}^#^{tweet}^#^{photos}', $tweet);
			$tweet = explode("^##^", implode(' ', $tweet));

			foreach ($tweet as $value) {
				$values = explode('^#^', $value);

				if(isset($values[1])){
					$tweets[] = [
						"id" => $values[0],
						"iata" => "'".$code."'",
						"tweet" => "'".addslashes(str_replace("`", '',str_replace(",", ' ',(preg_replace( '/[^[:print:]]/', '',$values[1])))))."'",
						"photos" => "'".(str_replace(",", ' ', $values[2]))."'",
						"date" => "'".$values[3]."'",
					];
				}
			}

			$sql = toSql($tweets);
			
			if($sql) {
				echo "saving..\n";
				exec('impala-shell --quiet -q "'.$sql.'" 2> /dev/null');
				echo "done!\n";
			} else {
				echo "no result!\n";
			}
		}
	}
}

function toSql($tweets) {
   $values = [];

   foreach($tweets as $val) {
      $values[] = '('.(implode(",", $val)).')';
   }

   return (isset($values[0]))
	? "INSERT INTO \`tweets\` (\`id\`, \`iata\`, \`tweet\`, \`photos\`, \`date\`) values ".implode(',', $values)
	: 0;
}
