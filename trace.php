#!/usr/bin/env php
<?php
$hostLimit=20;
$ttlLimit=30;
$verbose=false;

function show_help(){
	echo "This command scans (ping and traceroute) hosts\n"
		."and generates accumulated output from results\n"
		."Options:\n"
		."\t-h\tHelp\n"
		."\t-H\tTarget host\n"
		."\t-l\tTracked host count limit (defaults to $hostLimit)\n"
		."\t-v\tVerbose output on stdrr\n"
		."\n";

}

foreach(getopt( "hH:v" ) as $option=>$val){
	switch($option){
	case 'h':
		show_help();
		exit(0);
		break;
	case 'H':
		if(is_array($val))
		{
			$targetHosts=array();
			foreach($val as $host){
				$targetHosts[]=$host;
				$targetHostsTTL[$host]=$ttlLimit;
			}
		} else{
			$targetHosts=array($val);
			$targetHostsTTL[$val]=$ttlLimit;
		}
		break;
	case 'v':
		$verbose=true;
		break;
	default:
		fputs(STDERR,"Unknown option $option\n");
		show_help();
		exit(0);
	}
}

if(empty($targetHosts)){
	fputs(STDERR,"No target host specified\n");
	exit(1);
}


// Defining functions
function run_ping($ip){
	global $verbose;
	if($verbose) fputs(STDERR,"Running ping to $ip\n");
	$fd=popen("ping -A -c 10 -n -R -W 1 ".escapeshellcmd($ip),'r');

	$routes=array();
	$route=array();
	$isRoute=false;

	$line=fgets($fd);
	while( $line !== false ){
		if($verbose) fputs(STDERR,"ping:$line");
		if(substr($line, 0, 3) == "RR:")
			$isRoute=true;

		if($isRoute){
			$exp=explode("\t",$line);
			if(count($exp)==2){
				$route[]=trim($exp[1]);
			}else{
				if(count($route) and !in_array($route,$routes))
						$routes[]=$route;
				$route=array();
				$isRoute=false;
			}
		}
		$line=fgets($fd);
	}
	pclose($fd);
	return $routes;
}

function run_traceroute($ip,$ttl){
	global $verbose;
	if($verbose) fputs(STDERR,"Running traceroute to $ip with ttl $ttl\n");
	$fd=popen("traceroute -w 2 -n ".escapeshellcmd($ip)." -m $ttl 2>/dev/null",'r');

	$hops=array();
	while( ($line=fgets($fd)) !== false ){
		if($verbose) fputs(STDERR,"traceroute:$line");
		if(preg_match('/^([ 0-9][0-9])  /',$line,$out) == false)
			continue;
		$i=(int)$out[1];
		$ret=preg_match_all('/ (?:(?P<ip>[0-9.]+)(?:  [.0-9]+ ms(:? !(:?H|N|P|S|F-[0-9]+|X|V|C|[0-9]+)|))+|\*)/',
			$line, $out);
		if($ret!==false){
			$route=array_unique($out['ip']);
			if(!empty($route))
				$hops[$i++]=$route;
		}
	}
	pclose($fd);
	// Clean empty hops on the end
	foreach(array_reverse(array_keys($hops)) as $id){
		$uniq=array_unique($hops[$id]);
		if(count($uniq)!=1 or $uniq[0]!="")
			break;
		unset($hops[$id]);
	}
	return $hops;
}

$currentIndex=0;
$result=new SimpleXMLElement('<result><version v="1"/><scans></scans></result>');
while($currentIndex<count($targetHosts)){
	$targetHost=$targetHosts[$currentIndex];
	$currentIndex++;
	# Run ping -R on host
	$routes=run_ping($targetHost);

	# Store result
	if(count($routes)){
		$ping=$result->scans->addChild('ping');
		$ping->addAttribute('target',$targetHost);
		foreach($routes as $route){
			$obj=$ping->addChild('route');
			foreach($route as $id=>$host){
				# Add new hosts to stack
				$obj->addChild('host',$host);
				if(!in_array($host,$targetHosts))
				{
					if($verbose) fputs(STDERR,"Adding host to history $host\n");
					$targetHosts[]=$host;
					$targetHostsTTL[$host]=$id*2;
				}
			}
		}
	}
	# Run traceroute on host
	$trace=run_traceroute($targetHost,$targetHostsTTL[$targetHost]);
	if(count($trace)){
		# Store result
		$traceEl=$result->scans->addChild('traceroute');
		$traceEl->addAttribute('target',$targetHost);
		foreach($trace as $distance=>$hops){
			if(count($hops)){
				$hidden="false";
				$obj=$traceEl->addChild('hops');
				$obj->addAttribute('distance',$distance);
				foreach($hops as $host){
					if(empty($host)){
						$hidden="true";
						continue;
					}
					# Add new hosts to stack
					$obj->addChild('host',$host);
					# Add new hosts to stack
					if(!in_array($host,$targetHosts))
					{
						if($verbose) fputs(STDERR,"Adding host to history $host\n");
						$targetHosts[]=$host;
						$targetHostsTTL[$host]=$distance*2;
					}
				}
				$obj->addAttribute('hiddenHops',$hidden);
			}
		}
	}

	if($currentIndex>$hostLimit)
	{
		if($verbose) fputs(STDERR,"Stopping scanning - tracket host count hit\n");
		break;
	}
}
# Gnerate result output
echo $result->asXml();
