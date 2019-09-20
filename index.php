<?php
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 'On');
	// iata
	$airports = include 'iata.php';

	// day label
	$dayLabel = ($_GET['days'] == 30) ? "monthly" : $_GET['days'];
	$dayLabel = ($_GET['days'] == 7) ? "weekly" : "daily";

	// dates
	$beforeYesterday = date('Y-m-d', strtotime('-2 day', strtotime($_GET['date'])));	
	$yesterday = date('Y-m-d', strtotime('-1 day', strtotime($_GET['date'])));	
	$tomorrow = date('Y-m-d', strtotime('+1 day', strtotime($_GET['date'])));

	// before yesterday data
	$get = exec('redis-cli get populars:routes:'.$beforeYesterday.':'.$_GET['days']);
	$get = json_decode($get, true);
	
	if($get){
		$beforeYesterdayData = array();

		foreach ($get as $key => $value) {
			$beforeYesterdayData[$value['iata']]['rank'] = $key+1;
			$beforeYesterdayData[$value['iata']]['total'] = $value['total'];
			$beforeYesterdayData[$value['iata']]['iata'] = $value['iata'];
		}
	}

	// yesterday data
	$get = exec('redis-cli get populars:routes:'.$yesterday.':'.$_GET['days']);
	$get = json_decode($get, true);
	
	if($get){
		$yesterdayData = array();

		foreach ($get as $key => $value) {
			$yesterdayData[$value['iata']]['rank'] = $key+1;
			$yesterdayData[$value['iata']]['total'] = $value['total'];
			$yesterdayData[$value['iata']]['iata'] = $value['iata'];
		}
	}

	// tomorrow data
	$get = exec('redis-cli get populars:routes:'.$tomorrow.':'.$_GET['days']);
	$get = json_decode($get, true);
	
	if($get){
		$tomorrowData = array();
	}

	//today data
	$get = exec('redis-cli get populars:routes:'.$_GET['date'].':'.$_GET['days']);
	$get = json_decode($get, true);
	$data = array();

	foreach ($get as $key => $value) {
		$data[$value['iata']] = $value;
		$data[$value['iata']]['rank'] = $key+1;
		$data[$value['iata']]['diff'] = (isset($yesterdayData[$value['iata']]))
			? $value['total'] - $yesterdayData[$value['iata']]['total']
			: 0;
		$data[$value['iata']]['growth'] = (isset($yesterdayData[$value['iata']]))
			? round($data[$value['iata']]['diff'] / $yesterdayData[$value['iata']]['total'] * 100, 2)
			: 0;
	}

	//today twitter data
	$get = exec('redis-cli get tweets:routes:'.$_GET['date'].':'.$_GET['days']);
	$tweets = json_decode($get, true);;

	// insight
	$insights = array();

	if(isset($yesterdayData)) {

		// replacing 1st place
		if(array_values($data)[0]['iata'] != array_values($yesterdayData)[0]['iata']) {
			$insights[array_values($data)[0]['iata']][] = 'replaced '.array_values($yesterdayData)[0]['iata'].' at the 1st place.';
		}

		if(isset($beforeYesterdayData)) {
			// keep ranked up
			foreach ($data as $key => $value) {
				if(isset($yesterdayData[$value['iata']]) && isset($beforeYesterdayData[$value['iata']])) {
					if(
						$yesterdayData[$value['iata']]['rank'] < $beforeYesterdayData[$value['iata']]['rank']
						&& $value['rank'] < $yesterdayData[$value['iata']]['rank']
					){
						$insights[$value['iata']][] = 'keep ranked up for at least these 2 days, this might be can be a trend.';
					}
				}
			}

			// keep ranked down
			foreach ($data as $key => $value) {
				if(isset($yesterdayData[$value['iata']]) && isset($beforeYesterdayData[$value['iata']])) {
					if(
						$yesterdayData[$value['iata']]['rank'] > $beforeYesterdayData[$value['iata']]['rank']
						&& $value['rank'] > $yesterdayData[$value['iata']]['rank']
					){
						$insights[$value['iata']][] = 'keep ranked down for at least these 2 days, it might be loss it trend.';
					}
				}
			}

		}


		foreach ($data as $key => $value) {
			// newcomer
			if(!isset($yesterdayData[$value['iata']])) {
				$insights[$value['iata']][] = 'newcomer at '. ordinal($value['rank']).' place.';
			}

			// point dominate
			$minDominate = ((((0.5 * 24) + 24) * $_GET['days']) - 0.5) * 0.6;

			if($value['points'] > $minDominate) {
				$insights[$value['iata']][] = 'dominate the '.ordinal($value['rank']).' elimination.';
			}

			// growth
			if($value['growth'] > 5) {
				$insights[$value['iata']][] = 'grow more than 5% total search.';
			}

			// loss
			if($value['growth'] < -5) {
				$insights[$value['iata']][] = 'loss more than 5% total search.';
			}
		}

		// out
		foreach ($yesterdayData as $key => $value) {
			if(!isset($data[$value['iata']])) {
				$insights[$value['iata']][] = 'out from list.';
			}
		}

		if(!count($insights)) {
			$insights[':('][] = "Hmmm.. We can't find anything to be noted today.";
		}
	} else {
		$insights[':('][] = "Upps.. We don't have enough data to give You insight today.";
	}

	// highchart data
	$categories = array();
	
	$pointSeries = array(
		array('showInLegend' => false,'name' => 'points')
	);

	$totals = 0;	

	foreach ($data as $value) {
		$categories[] = $value['iata'];
		$pointSeries[0]['data'][] = $value['points'];
		$totals += $value['points'];
	}

	$categories = json_encode($categories);
	$pointSeries = json_encode($pointSeries);

	$totalData = array();

	foreach ($data as $value) {
		$totalData[] = array(
			'name' => $value['iata'],
			'y' => ($value['total'] / $totals)
		); 
	}

	$totalData = json_encode($totalData);

	function cmp($a, $b) {
	    if ($a['points'] == $b['points']) {
			return 0;
	    }

	    return ($a['points'] > $b['points']) ? -1 : 1;
	}

	function ordinal($number) {
	    $ends = array('th','st','nd','rd','th','th','th','th','th','th');

        return ((($number % 100) >= 11) && (($number%100) <= 13)) 
    		? $number. 'th'
    		: $number. $ends[$number % 10];
	}
?>

<!DOCtype html>
<html>
<head>
	<link rel="stylesheet" type="text/css" href="index.css">
	<script src="highcharts.js"></script>
	<script type="text/javascript">
		document.addEventListener('DOMContentLoaded', function () {
			Highcharts.chart('container-points', {	
				title:{
			    	text: null
			    },
			    chart: {
			        type: 'column',
					title: false
			    },
			    xAxis: {
			        crosshair: true,
			        categories: <?= $categories ?>
			    },
			    yAxis: {
					title: false,
			        min: 0
			    },
			    tooltip: {
			        headerFormat: '<table>',
			        pointFormat: '<tr><td style="padding:0"><b>{point.y:f}</b></td></tr>',
			        footerFormat: '</table>',
			        shared: true,
			        useHTML: true
			    },
			    plotOptions: {
			        column: {
			            pointPadding: 0.2,
			            borderWidth: 0
			        },
			        series: { animation: false }
			    },
			    series: <?= $pointSeries ?>,
			    credits: {
					enabled: false
				}
			});

			Highcharts.chart('container-total', {
			    chart: {
			        plotBackgroundColor: null,
			        plotBorderWidth: null,
			        plotShadow: false,
			        type: 'pie'
			    },
			    title: {
			        text: null
			    },
			    tooltip: {
			        pointFormat: '{series.name}: <b>{point.percentage:.1f}%</b>'
			    },
			    plotOptions: {
			        pie: {
			            allowPointSelect: true,
			            cursor: 'pointer',
			            dataLabels: {
			                enabled: true,
			                format: '<b>{point.name}</b>: {point.percentage:.1f} %'
			            }
			        },
					series: { animation: false }
			    },
			    series: [{
			        colorByPoint: true,
			        data: <?= $totalData ?>
			    }],
			    credits: {
					enabled: false
				}
			});
		})

		function reloadData() {
			var xhttp = new XMLHttpRequest();
			xhttp.onreadystatechange = function() {
			if (this.readyState == 4 && this.status == 200) {
				location.reload(); 
			}
		};

		xhttp.open("GET", "reload-histories.php", true);
			xhttp.send();
		}
	</script>
</head>
<body>
	<div class="head">
		<img src="logo.png">
	</div>
	<div class="content">
		<div class="float-container">
			<div class="paper">Data : <?= $_GET['date'] ?></div>
			<div class="paper">
				Mode : 
				<a href="daily-<?= $_GET['date'] ?>.html">Daily</a>
				<a href="weekly-<?= $_GET['date'] ?>.html">Weekly</a>
				<a href="monthly-<?= $_GET['date'] ?>.html">Monthly</a>
			</div>
			<?php if(isset($yesterdayData)) { ?>
				<div class="paper">
					<a href="<?= $dayLabel ?>-<?= $yesterday; ?>.html"><<</a>
				</div>
			<?php } ?>
			<?php if(isset($tomorrowData)) { ?>
				<div class="paper">
					<a href="<?= $dayLabel ?>-<?= $tomorrow; ?>.html">>></a>
				</div>
			<?php } ?>
			<div class="paper">
				<a href="#reloading" onclick="reloadData()">Reload</a>
			</div>
		</div>
		<div class="float-container">
			<div>
				<h3>Top <?= count($data) ?> Populars Search</h3>
				<div class="paper">
					<table class="table-data">
						<thead>
							<th>Rank</th>
							<th>IATA</th>
							<th>Points</th>
							<th>Total</th>
							<th>Growth</th>
						</thead>
						<tbody>
							<?php foreach ($data as $key => $value) { ?>
								<tr>
									<td><?= $value['rank'] ?></td>
									<td><?= $value['iata'] ?></td>
									<td align="right"><?= $value['points'] ?></td>
									<td align="right"><?= $value['total'] ?></td>
									<td align="right"><?= ($value['growth']) ? $value['growth'].' %' : '-' ?></td>
								</tr>
							<?php } ?>
						</tbody>
					</table>
				</div>
			</div>

			<div>
				<h3>Twitter Crawler</h3>
				<div class="paper">
					<table class="table-data">
						<tbody>
							<?php foreach ($tweets as $iata => $total) { ?>
								<tr>
									<td><a href="#" title="<?= implode(", ", $airports[$iata]['keys']) ?>"><?= $iata ?></a></td>
									<td width="1" align="right" style="white-space: nowrap;"><?= $total ?> Tweets</td>
								</tr>
							<?php } ?>
						</tbody>
					</table>
				</div>
			</div>

			<div class="max">
				<h3>Passing Points</h3>
				<div class="paper margin-bottom">
					<div id="container-points" style="height: 250px; margin: 0 auto"></div>
				</div>

				<h3>Search Distributions</h3>
				<div class="paper">
					<div id="container-total" style="height: 250px; margin: 0 auto"></div>
				</div>
			</div>

			<div class="max">
				<h3>Insights</h3>
				<div class="paper  margin-bottom">
					<table class="table-data">
						<tbody>
							<?php 
								foreach ($insights as $key => $values) {
									$style = '';
									foreach ($values as $value) {
							?>
										<tr>
											<td valign="top" width="1" style="<?= $style ?>"><?= $key ?></td>
											<td><?= $value ?></td>
										</tr>
							<?php
										$key = '';
										$style = 'border-top:1px solid white;';
									}
								}
							?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</body>
</html>
