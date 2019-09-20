<?php

$start_time = microtime(true);

$where = isset($date) ? 'where to_date(`timestamp`) = "'.$date.'"' : '';

$query = '
	select to_date(`date`) as `date`, `iata`, count(`iata`) as `total`
	from tweets
	GROUP BY `iata`, `date`
	order by `date` asc, `total` asc
';

$out = array();

exec("impala-shell -B --quiet --output_delimiter=, -q '".$query."'", $out);

$out = toArray($out);

//start here

$types = array(1,7,30);

foreach ($types as $days) {
	$results = total($out, $days);

	foreach ($results as $date => $iatas) {
		exec('redis-cli set tweets:routes:'.$date.':'.$days.' \''.json_encode($iatas).'\'');
	}
}

//end
$end_time = microtime(true);
$executionTime = $end_time - $start_time;
echo "\n Time taken: $executionTime seconds.\n";

//functions

function total($out, $days) {
	$toDate = array();

	foreach($out as $date => $codes){
		$toDate[$date] = array();

		foreach($codes as $iata => $total) {
			$toDate[$date][$iata] = 0;

			for($i=0;$i<$days;++$i) {
				$lastDay = date('Y-m-d', strtotime('-'.$i.' day', strtotime($date)));
				if (isset($out[$lastDay][$iata])) {
					$toDate[$date][$iata] += $out[$lastDay][$iata];
				}
			}
		}
	
		arsort($toDate[$date]);
	}

	return $toDate;
}

function toArray($out) {
	$data = array();
	$total = array();

	foreach($out as $row) {
		$cols = explode(",", $row);
		$data[$cols[0]][$cols[1]] = $cols[2];
	}

	return $data;
}
