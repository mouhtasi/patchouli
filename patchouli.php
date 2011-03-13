<?php

// Prevent PHP from stopping the script automatically
set_time_limit(0);

// Connect to the IRC server 
$socket = fsockopen("irc.rizon.net", 6667) or die();

$bot_nick = 'mybot';	//The nick the bot will have on the server.
fputs($socket,"NICK " . $bot_nick . "\n");
$owner = 'owner';    //Your nick on the IRC server

// Send user information
fputs($socket,"USER " .$bot_nick. "  0 bot :PHP Bot\n");

$seen = array();
$save_time = time();
$ignore_seen = array(); // messages in these channels are not logged for the !seen function
$ignore_url = array();  // list of channels to ignore fetching urls from
$topic_toggle = 0;

/*Calculate the time difference, $diff, in human readable form.*/
function time_ago($diff){
	$stime = '';
	
	if($diff <= 60)
		return $diff . " seconds ";
		
	elseif( $diff > 60){
		$weeks = 0;
		while(($diff / 604800) >= 1){
			$diff -= 604800;
			$weeks += 1;
		}
		if($weeks != 0)
			$stime .= $weeks." weeks ";
			
		$days = 0;
		while(($diff / 86400) >= 1){
			$diff -= 86400;
			$days += 1;
		}
		if($days != 0)
			$stime .= $days." days ";
			
		$hours = 0;
		while(($diff / 3600) >= 1){
			$diff -= 3600;
			$hours += 1;
		}
		if($hours != 0)
			$stime .= $hours." hours ";
			
		$minutes = 0;
		while(($diff / 60) >= 1){
			$diff -= 60;
			$minutes +=1;
		}
		if($minutes != 0)
			$stime .= $minutes." minutes ";
			
		$seconds = 0;
		while(($diff / 1) >= 1){
			$diff -= 1;
			$seconds += 1;
		}
		if($seconds != 0)
			$stime .= $seconds." seconds ";
		
		return $stime;
	}
}

/*Parse twitter $feed.*/
function parse_feed($feed) {
	preg_match('#<updated>(.*?)</updated>#is', $feed, $updated);
	
	$when = trim($updated[1]);
	$times = explode('T', $when);
	$date = $times[0];
	$time = trim($times[1], 'Z');
	return array($date, $time);
}

$File = "seen.txt";    //Name of the file that seen data will be saved to.
$f = fopen($File, 'a+');    //Open $File for reading and writing. If the file does not exist, create it.
 
//Read seen data from file object $f and load to $seen.
while (! feof ($f)) {
	$line = fgets ($f);
	$v = explode(' ', $line);
	
	$v[1] = implode(" ", array_slice($v, 1));
	if(isset($v[1])){
	$seen[$v[0]] = $v[1];
	}
}	
fclose($f);

// keep the program executing
while(1) {

		// get the data from server
		while($data = fgets($socket, 512)) {
		// echo the data received to page
		echo $data;
		// flush old data, it isn't needed any longer.
		flush();

		// We split data by whitespace for later use
		$matches= explode(' ', $data);

		// check for ping from server - pong back
		if($matches[0] == "PING") fputs($socket, "PONG ".$matches[1]."\n");
		
		// save seen data to file every 300s
		if((time() - $save_time) > 300){
			echo('Saving seen data');
			print("\n");
			$f = fopen($File, 'w+');
			$a = ""; foreach($seen as $b  => $c){ $a.= $b . " " . $c . "\n";}
			fwrite($f, $a);
			fclose($f);
			$save_time = time();
		}

		// check that there is a command received
		if(isset($matches['1'])) {
			switch($matches['1']) {
				case "PRIVMSG":
					//print_r($matches);
					$channel = $matches['2'];
					$chat_text = trim(substr(implode(' ', array_slice($matches, 3)), 1));
					$array = explode(' ', strval($matches));
					list($user, $host) = explode('!', substr($matches[0], 1));
					
					if(!in_array(strtolower($channel), $ignore_seen)){
						$said_at = time();
						$seen[strtolower($user)] = $channel . " " . $said_at . " " . $chat_text;
					}
					
					//Make the bot talk in a channel by PMing it
					if(stristr(strtolower($chat_text), "!say") and $user == $owner){
						$say = ltrim($chat_text, '!say ');
						$msgin = explode(' ', $say);
						$chan = $msgin[0];
						$msgin[0] = '';
						$msgout = trim(implode(' ', $msgin), ' ');
						fputs($socket, "PRIVMSG " . $chan . " :" . $msgout . "\n");
					}elseif(strtolower($chat_text) == '!protocol'){
						for($ctr=0;;$ctr=$ctr+1){
							if($matches[$ctr]){
								fputs($socket, "PRIVMSG " . $channel . " :" . $matches[$ctr] . "\n");
							}
							else{
								break;
							}
						}
					//Make the bot '/me' in a channel
					}elseif(stristr(strtolower($chat_text), '!do') and $user == $owner){
						$action = ltrim($chat_text, '!do ');
						$msgin = explode(' ', $action);
						$chan = $msgin[0];
						$msgin[0] = '';
						$msgout = trim(implode(' ', $msgin), ' ');
						fputs($socket, "PRIVMSG " . $chan . " :\x01ACTION " . $msgout . "\x01\n");
					// change a variable's value (useful if there are dynamic functions)
					}elseif (stristr(strtolower($chat_text), '!set') && $user == $owner) {
						$args = explode(' ', $chat_text);
						if (strtolower($args[0]) == '!set') {
							if (count($args) < 2) {
								fputs($socket, "PRIVMSG " . $user . " :Incorrect parameters.\n");
							} else {
								$var = $args[1];
								if (count($args) == 2) {
									if (isset($$var)) {
										fputs($socket, "PRIVMSG " . $user . " :'$var' is set to '${$var}'\n");
									} else {
										fputs($socket, "PRIVMSG " . $user . " :Cannot find variable '$var'\n");
									}
								} else {
									$val = implode(' ', array_slice($args, 2));
									$$var = $val;
									fputs($socket, "PRIVMSG " . $user . " :Set '$var' to '$val'\n");
								}
							}
						}
					}elseif (stristr(strtolower($chat_text), '!raw') && $user == $owner) {
						// send raw commands to the server
						$action = ltrim($chat_text, '!raw ');
						$msgin = explode(' ', $action);
						$msgout = trim(implode(' ', $msgin), ' ');
						fputs($socket, $msgout ."\n");
						
					}elseif(stristr(strtolower($chat_text), '!seen')){
						// return seen data on the specified nick
						$msgin = explode(' ', $chat_text);
						if($msgin[0] == '!seen' && isset($msgin[1])){
							$who_normal = $msgin[1];
							$who = strtolower($msgin[1]);
							if($seen[$who]){
								$raw_seen_info = $seen[$who];
								$seen_info = explode(' ', $raw_seen_info);
								$time_diff = time() - $seen_info[1];
								$seen_time = time_ago($time_diff);
								$text = implode(' ', $seen_info);
								$text = ltrim($text, $seen_info[0]);
								$text = ltrim($text, ' ');
								$text = ltrim($text, $seen_info[1]);
								$text = trim($text);
								
								fputs($socket, "PRIVMSG " . $channel . " :" .$user.", I last saw \x02" .$who_normal. " \2in \x02" .$seen_info[0].  " " . $seen_time . "\2ago saying \x02" .$text. "\2\n");
							}else{
								fputs($socket, "PRIVMSG " . $channel . " :" . $user . ", I have never seen \x02" .$who_normal."\n");
							}
						}
						
					}elseif(strtolower($chat_text) == "!topic"){
						// get the topic of the channel
						fputs($socket, "TOPIC " . $channel . "\n");
						$topic_toggle = 1;
						
					}elseif(stristr(strtolower($chat_text), '!twitter')){
						// get the last tweet of the specified user
						$msgin = explode(' ', $chat_text);
						if(strtolower($msgin[0]) == '!twitter'){
							$username = trim($msgin[1]);
							$feed = "http://search.twitter.com/search.atom?q=from:" . $username . "&rpp=1";						

							$twitterFeed = file_get_contents($feed);
							preg_match_all('#<title>(.*?)</title>#is', $twitterFeed, $match);

							if (isset($match[1][1])){
								$tweet = trim($match[1][1]);
								$tweet = htmlspecialchars_decode($tweet,ENT_QUOTES);
								$tweet = html_entity_decode($tweet, ENT_COMPAT, 'UTF-8');
								list($date, $time) = parse_feed($twitterFeed);
								fputs($socket, "PRIVMSG " . $channel . " :".$username."'s last tweet: \x02\"" . $tweet . "\"\2 on \x02" . $date . " \2at \x02" .$time ."\n");
								
							}else{
								fputs($socket, "PRIVMSG " . $channel . " :Cannot find user.\n");
							}
						}
						
					}elseif((stristr(strtolower($chat_text), 'http://') || stristr(strtolower($chat_text), 'https://')) && (!in_array(strtolower($channel), $ignore_url))) {
						// get the title of the url
						$msgin = explode(' ', $chat_text);
						foreach ($msgin as &$value){
							if(stristr(strtolower($value), 'http://') || stristr(strtolower($chat_text), 'https://')){
								$page = $value;
								break;
							}
						}
						
						if(stristr(strtolower($page), 'http://www.youtube')){
						// use get_meta_tags for youtube
							$tags = get_meta_tags($page);
							$title = $tags['title'];
						}else{
						// non-youtube urls
							$context = stream_context_create(array('http' => array('timeout' => 10)));
							$data = file_get_contents($page, false, $context, -1, 10000);
							preg_match('#<title>(.*?)</title>#is', $data, $match);
							$title = trim($match[1]);
							$title = html_entity_decode($title, ENT_COMPAT, 'UTF-8');
						}
						
						if($title != ''){
							fputs($socket, "PRIVMSG " . $channel . " :\x02URL:\2 " . html_entity_decode($title) . "\2\n");
						}
					}
						
				break;
				
				case "332":
					// data containing the topic
					if($topic_toggle == 1){
						$topic = trim(substr(implode(' ', array_slice($matches, 4)), 1));
					}
				break;
				
				case "333":
					// data containing the time topic was changed and the nick it was changed by
					if($topic_toggle == 1){
						//$topic .= " set by ";
						$by = $matches['4'];
						$chan = $matches["3"];
						$utime = $matches['5'];
						$time = strftime("%m/%d/%Y %I:%M%P", $utime);
						fputs($socket, "PRIVMSG " . $chan . " :Topic: \"" . $topic . "\" set by \x02". $by . " \2on \x02" . $time ."\2\n");
						$topic_toggle = 0;
					}
				break;
				
				case "376":    //wait for MOTD to end
					// these are the commands that are entered when the bot connects
					fputs($socket, "MODE " .$bot_nick. " +iB\n");
					//fputs($socket, "PRIVMSG nickserv :identify PASS\n");

					//fputs($socket,"JOIN #channel\n");
				break;
			}
		}
	}
}
?>
