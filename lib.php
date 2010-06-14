<?php

function get_load () {
	$load = file_get_contents('/proc/loadavg');

	$load = explode(' ', trim($load));

	return array(
		'one'=>$load[0],
		'five'=>$load[1],
		'ten'=>$load[2],
		'running'=>$load[3],
		'last-proc'=>$load[4]
	);
}

function get_memory () {
	$memory = file_get_contents('/proc/meminfo');
	$mem = array();

	foreach (explode("\n", $memory) as $line) {
		$line = trim($line);
		if (!$line) {
			continue;
		}

		$pos = strpos($line, ':');

		if ($pos === false) {
			continue;
		}

		$k = substr($line, 0, $pos);
		$v = trim(substr($line, $pos + 1));

		if (strpos($v, 'kB') !== false) {
			$v = (double)preg_replace('#([^0-9]+)#', '', $v);

			if ($v > 0) {
				$v = $v * 1024;
			}
		}

		$mem[$k] = $v;
	}

	$mem['MemUse'] =  100 * (1 - ($mem['MemFree'] / $mem['MemTotal']));
	$mem['SwapUse'] = ($mem['SwapFree'] / $mem['SwapTotal']);

	return $mem;
}

function get_uptime ()
{
	$uptime = trim(file_get_contents('/proc/uptime'));
	$uptime = explode(' ', $uptime);

	return array(
		'running'=>$uptime[0],
		'idle'=>$uptime[1],
		'utilization'=>100 * ($uptime[0] / $uptime[1])
	);
}

function get_disk ()
{
	$f = array();

	$data = shell_exec('/bin/df');
	foreach (explode("\n", $data) as $line) {
		$line = trim($line);
		if (strpos($line, '/dev/') !== 0) {
			continue;
		}

		while (strpos($line, '  ') !== false) {
			$line = str_replace('  ', ' ', $line);
		}

		$line = explode(' ', $line);

		$f[] = array(
			'name'=>$line[0],
			'size'=>(int)$line[1],
			'available'=> $line[3],
			'used'=>(int)$line[2],
			'percentage'=>(double)str_replace('%', '', $line[4]),
			'mount'=>$line[5]
		);
	}

	$s = array();
	foreach ($f as $k) {
		foreach ($k as $i=>$o) {
			if (!in_array($i, array('size', 'used', 'available'))) {
				continue;
			}

			if (!isset($s[$i])) {
				$s[$i] = 0;
			}

			$s[$i] += $o;
		}
	}

	$f['machine'] = array(
		'name'=>'machine',
		'size'=>$s['size'],
		'available'=>$s['available'],
		'used'=>$s['used'],
		'percentage'=>100 * ($s['used'] / $s['size']),
		'mount'=>false
	);
	return $f;
}

function get_apache ($status = 'http://localhost/server-status?auto') {
	$content = file_get_contents($status);

	if (!$content) {
		return array('running'=>false);
	}

	$data = array();
	$data['running'] = true;

	$content = explode("\n", $content);
	foreach ($content as $v) {
		$pos = strpos($v, ':');
		if ($pos === false) { 
			continue;
		}

		$k = substr($v, 0, $pos);
		$v = trim(substr($v, $pos + 1));

		$data[$k] = $v;
	}

	return $data;
}

function get_ps () {
	$res = shell_exec("ps aux");
	$res = explode("\n", $res);

	$headers = array();
	$ps = array();

	foreach ($res as $line) {
		$line = trim($line);
		while (strpos($line, '  ') !== false) {
			$line = str_replace('  ', ' ', $line);
		}

		if (!$line) {
			continue;
		}

		if (!$headers) {
			$headers = array_map('strtolower', explode(' ', $line));
			continue;
		}

		$data = array();

		$pos = 0;
		for ($a = 0; $a < 10; $a++) {
			$data[$headers[$a]] = substr($line, $pos, abs($pos - strpos($line, ' ', $pos + 1)));
			$pos = strpos($line, ' ', $pos + 1);
		}

		$data[$headers[10]] = substr($line, $pos + 1);

		$ps[] = $data;
	}

	return $ps;
}

function get_mysql ($host, $user, $pass)
{
	$mysql = mysql_connect($host, $user, $pass);

	if (!$mysql) {
		echo "failed to connect to mysql\n";
		return;
	}

	$stats = array();

	$plQuery = mysql_query('show global status');
	$map = array(
		'Uptime'=>'uptime',
		'Threads_connected'=>'connections',
		'Threads_running'=>'running_connections',
		'Queries'=>'total_queries',
		'Connections'=>'total_connections',
		'Bytes_received'=>'bytes_in',
		'Bytes_sent'=>'bytes_out'
	);
	while (($row = mysql_fetch_assoc($plQuery)) !== false) {
		if (!isset($map[$row['Variable_name']])) {
			continue;
		}
		
		$stats[$map[$row['Variable_name']]] = $row['Value'];
	}
	
	$stats['bps_in'] = $stats['bytes_in'] / $stats['uptime'];
	$stats['bps_out'] = $stats['bytes_out'] / $stats['uptime'];
	$stats['bps'] = ($stats['bytes_in'] + $stats['bytes_out']) / $stats['uptime'];
	mysql_close();
	
	return $stats;
}