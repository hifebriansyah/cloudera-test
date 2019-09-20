<?php

$start_time = microtime(true);

$query = '
	select to_date(`timestamp`) as `date`, hour(`timestamp`) as `hour`, `ib_iata`,  count(`ib_iata`) as `total`
	from flight_search_histories
	GROUP BY `date`, `hour`, `ib_iata`
	order by `date` asc, `hour` asc, `total` asc
';

$out = array();

exec("impala-shell -B --quiet --output_delimiter=, -q '".$query."'", $out);

$out = toArray($out);

//start here

$out = include 'data.json';

$types = array(1,7,30);

foreach ($types as $days) {
	$results = array(array(),array());

	for ($i=0; $i < 20 ; $i++) {
		$results = ranking($out, $i, $results[0], $results[1], $days);
	}

	foreach ($results[0] as $date => $winners) {
		exec('redis-cli set populars:routes:'.$date.':'.$days.' \''.json_encode($winners).'\'');
	}
}

//end
$end_time = microtime(true);
$executionTime = $end_time - $start_time;
echo "\n Time taken: $executionTime seconds.\n";

//functions

function ranking($out, $sequence = 1, $winners, $exclude = array(), $days) {
	$toDate = array();

	foreach($out['data'] as $date => $results){
		$toDate[$date] = $results;
		$sliced = array_slice($toDate, ($days*-1), $days, true);

		$slices = array();

		foreach ($sliced as $rows) {
			foreach ($rows as $row) {
				if(!isset($exclude[$date][$row['iata']])) {
					$slices[$row['date']][$row['time']] = array(
						'iata' => $row['iata'],
						'total' => $row['total'],
						'points' => 0
					);
				}
			}
		}

		$st = setPoints($out['total'], $slices);
		$exclude[$date][$st['iata']] = $st['iata'];
		$winners[$date][] = $st;
	}

	return array($winners, $exclude);
}

function setPoints($total, $data) {

	$ranked = array();

	foreach($data as $date => $results){
		$was = null;
		foreach ($results as $result) {
			if(!isset($ranked[$result['iata']])) {
				$ranked[$result['iata']]['iata'] = $result['iata'];
				$ranked[$result['iata']]['points'] = 1;
				$ranked[$result['iata']]['total'] = 0;
			} else {
				$ranked[$result['iata']]['points'] += 1;
			}

			$now = $result['iata'];

			if($now == $was) {
				$ranked[$result['iata']]['points'] += 0.5;
			}

			$was = $now;
		}
	}

	usort ($ranked,"cmp");

	foreach($data as $date => $results){
		if(isset($total[$date][$ranked[0]['iata']])) $ranked[0]['total'] += $total[$date][$ranked[0]['iata']];
	}

	return $ranked[0];
}

function toArray($out) {
	$data = array();
	$total = array();

	foreach($out as $row) {
		$cols = explode(",", $row);

		$data[$cols[0]][] = array(
			'date' => $cols[0],
			'time' => $cols[1],
			'iata' => $cols[2],
			'total' => $cols[3],
			'points' => 0,
		);

		if(!isset($total[$cols[0]][$cols[2]])){
			$total[$cols[0]][$cols[2]] = $cols[3];
		} else {
			$total[$cols[0]][$cols[2]] += $cols[3];
		}
	}

	return array(
		"data" => $data,
		"total" => $total
	);	
}

function cmp($a, $b) {
    if ($a['points'] == $b['points']) {
		return 0;
    }

    return ($a['points'] > $b['points']) ? -1 : 1;
}
