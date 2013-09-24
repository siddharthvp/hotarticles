<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);
ini_set('max_execution_time', 2000);

/* Setup the mediawiki classes. */
require_once dirname(__FILE__) . '/../config.inc.php';
require_once dirname(__FILE__) . '/../botclasses.php';

function getEditCounts( $category, $days = 3, $limit = 5 ) {
	$pages = array();
	$result = mysql_query("select s.rev_id,s.rev_timestamp from revision as s where s.rev_timestamp> DATE_FORMAT(DATE_SUB(NOW(),INTERVAL " . $days . " DAY),'%Y%m%d%H%i%s') order by s.rev_timestamp asc limit 1;");
	while ($row = mysql_fetch_array($result)) {
		$revId = $row['rev_id'];
		$revTimestamp = $row['rev_timestamp'];
	}
	if ( $revId && $revTimestamp ) {
		$result = mysql_query("select main.page_title as title,count(main.rev_minor_edit) as ctall, sum(main.rev_minor_edit) from (select tt.page_title,rev_minor_edit,rev_user_text from revision join (select a.page_id,a.page_title from categorylinks join page as t on t.page_id=cl_from and t.page_namespace=1 join page as a on a.page_title=t.page_title and a.page_namespace=0 where cl_to='".$category."' and a.page_latest>".$revId.") as tt on rev_page=tt.page_id where rev_timestamp>".$revTimestamp.") as main group by main.page_title order by ctall desc limit ".$limit.";");
		while ($row = mysql_fetch_array($result)) {
			$title = str_replace( '_', ' ', $row['title'] );
			$pages[$title] = $row['ctall'];
		}
	}
	return $pages;
}

$wikipedia = new wikipedia();

/* Log in to wikipedia. */
$wikipedia->login($enwiki['user'],$enwiki['pass']);

$link = mysql_connect($hotarticlesdb['host'], $hotarticlesdb['user'], $hotarticlesdb['pass']);
mysql_select_db($hotarticlesdb['dbname'], $link);

if ( isset( $argv[1] ) && !is_nan( $argv[1] ) ) {
	$query = "SELECT * FROM hotarticles WHERE id = $argv[1]";
} else {
	$query = "SELECT * FROM hotarticles";
}
$result = mysql_query($query) or die(mysql_error());

$link = mysql_connect ($enwikidb['host'], $enwikidb['user'], $enwikidb['pass']);
mysql_select_db($enwikidb['dbname'], $link);

while ($row = mysql_fetch_array ($result)) {
	if ($row['method'] == 'category') {
		$category = str_replace(' ', '_', $row['source']);
		$count = $wikipedia->categorypagecount('Category:'.$category);
		if ($count < $maxArticles) {
			$editCounts = getEditCounts( $category, $row['span_days'], $row['article_number'] );
		} else {
			echo "Category ".$row['source']." is too large. Skipping.\n";
			continue;
		}
	} else {
		echo "Invalid method for ".$row['source'].". Skipping.\n";
		continue;
	}

	$output = "{|\r";
	$validUpdate = false;
	$output = "{|\r";
	$validUpdate = false;
	foreach ( $editCounts as $key => $value ) {
		if ( $value != '' && $key != '' ) {
			$validUpdate = true;
			switch ( true ) {
				case ( $value >= $row['red'] ):
					$color = '#c60d27';
					break;
				case ( $value >= $row['orange'] ):
					$color = '#f75a0d';
					break;
				case ( $value > 0 ):
					$color = '#ff9900';
					break;
			}
			$output .= <<<WIKITEXT
|-
| style="text-align:center; font-size:130%; color:white; background:$color; padding: 0 0.2em" | '''$value'''&nbsp;<span style="font-size:60%">edits</span>
| style="padding: 0.4em;" | [[$key]]
WIKITEXT;
			$output .= "\r";
		}
	}
	$output .= <<<WIKITEXT
|-
| style="padding: 0.1em;" |
|}
WIKITEXT;
	$wordarray = array('zero','one','two','three','four','five','six','seven','eight','nine','ten');
	$date = date('j F Y', time());
	$output .= "\rThese are the articles that have been edited the most within the last ".$wordarray[$row['span_days']]." days. Last updated $date.\r";

	if ( $validUpdate ) {
		$edit = $wikipedia->edit($row['target_page'],$output,'Updating for '.date('F j, Y'));
		//if ($edit) echo "Completed update for ".$row['source'].".\n";
	}
}
echo "$date: Bot run";
?>

