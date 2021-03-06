<?php
// Terminate if this launches without a valid session
session_start();
if (!(isset($_SESSION['sLogin']) && $_SESSION['sLogin'] != '')) {
    header ("Location: session.php?id=0");
    exit();
}

$base = dirname(__FILE__);
include_once "$base/config.php";
include_once "$base/functions.php";

$link = mysql_connect($dbHost,$dbUser,$dbPass);
$db = mysql_select_db($dbName,$link);

$type = $_REQUEST['type'];

$types = array(
                 '0' => 'es',
                 '1' => 'eg',
                 '2' => 'ed',
                '2a' => 'ee',
                 '3' => 'pd',
                 '4' => 'si',
                 '5' => 'tb',
                 '6' => 'ec',
                 '7' => 'tx',
                 '8' => 'fi',
                 '9' => 'cat',
                '10' => 'map',
                '11' => 'comments',
                '12' => 'remove_comment',
                '13' => 'sensors',
                '14' => 'user_profile',
                '15' => 'summary',
                '16' => 'view',
                '17' => 'autocat',
);

$type = $types[$type];

if (isset($_REQUEST['ts'])) {
    // Need EC
    $tsParts = explode("|", hextostr(mysql_real_escape_string($_REQUEST['ts'])));
    $sdate  = $tsParts[0]; 
    $edate  = $tsParts[1];
    $stime  = $tsParts[2];
    $etime  = $tsParts[3];
    $offset = $tsParts[4];
    $when = "event.timestamp BETWEEN 
             CONVERT_TZ('$sdate $stime','$offset','+00:00') AND
             CONVERT_TZ('$edate $etime','$offset','+00:00')";
}

if (isset($_REQUEST['sensors'])) {
    $sensors = hextostr($_REQUEST['sensors']);
    if ($sensors == 'empty') {
        $sensors = '';
    }
}

if (isset($_REQUEST['rt'])) {
    $rt = mysql_real_escape_string($_REQUEST['rt']);
    if ($rt == 1) {
        $rt = "AND event.status = 0";
    } else {
        $rt = "";
    }
}

if (!$type) {
    exit;
}

function ec() {

    global $when, $sensors;

    $query = "SELECT COUNT(status) AS count, status
              FROM event
              LEFT JOIN sensor AS s ON event.sid = s.sid
              WHERE $when
              $sensors
              GROUP BY status";

    $result = mysql_query($query);

    $rows = array();

    while ($row = mysql_fetch_assoc($result)) {
        $rows[] = $row;
    }
    $theJSON = json_encode($rows);
    echo $theJSON;
}

function si() {

    function urlMkr($line) {
        $pattern = '/reference:url,([^;]+)/';
        $answer = preg_replace($pattern, 'reference:url,<a class=rref href="http://$1" target=rule>$1</a>',  $line);
        return $answer;
    }

    $object = $_REQUEST['sid'];
    list($sigID, $gID) = explode("-", $object);
    global $rulePath;
    $wasMatched = 0;
    $dirs = explode("||",$rulePath);

    if ( $gID > 100 ) {
        $dc = 0;
        $wasMatched = 2;
    } else { 
        $dc = (count($dirs) - 1);
    }

    for ($i = 0; $i <= $dc; $i++)
        if ($ruleDir = opendir($dirs[$i])) {
            while (false !== ($ruleFile = readdir($ruleDir))) {
                if ($ruleFile != "." && $ruleFile != "..") {
                    $ruleLines = file("$dirs[$i]/$ruleFile");
                    $lineNumber = 1;

                    foreach($ruleLines as $line) {

                        $searchCount = preg_match("/sid\:\s*$sigID\s*\;/",$line);

                        if($searchCount > 0) {
                            $tempMsg = preg_match("/\bmsg\s*:\s*\"(.+?)\"\s*;/i",$line,$ruleMsg);

                            $line = urlMkr(htmlspecialchars($line));
                            
                            $result = array("ruletxt"	=> $line,
                                            "rulefile"	=> $ruleFile,
                                            "ruleline"	=> $lineNumber,
                            );
                            $wasMatched = 1;
                            break;
                        }
                    $lineNumber++;
                    }
                }
           }

           closedir($ruleDir);
    }

    if ($wasMatched == 0) {
        $result = array("ruletxt" => "No match for signature ID $sigID",
                        "rulefile"  => "n/a",
                        "ruleline"  => "n/a",                 
        );
    }

    if ($wasMatched == 2) {
        $result = array("ruletxt" => "Generator ID > 100. This event belongs to a preprocessor or the decoder. <b>Generator ID:</b> $gID ",
                        "rulefile"  => "n/a",
                        "ruleline"  => "n/a",
        );
    }

    $theJSON = json_encode($result);
    echo $theJSON;

}

function es() {   
    global $offset, $when, $sensors, $rt;
    $object = mysql_real_escape_string($_REQUEST['object']);
    $sv = mysql_real_escape_string($_REQUEST['sv']);
    $filter = hextostr($_REQUEST['filter']);
    
    if ($filter != 'empty') {
        if (substr($filter, 0,4) == 'cmt ') {
            $comment = explode('cmt ', $filter);
            $qp2 = "LEFT JOIN history ON event.sid = history.sid AND event.cid = history.cid 
                    WHERE history.comment = '$comment[1]'";
        } else {
            $filter = str_replace('&lt;','<', $filter);
            $filter = str_replace('&gt;','>', $filter);
            $filter = "AND " . $filter;
            $qp2 = "WHERE $when
                    $sensors
                    $filter
                    $rt";
        }
    } else {
        $qp2 = "WHERE $when
                $sensors
                $rt";
    }

    $query = "SELECT COUNT(event.signature) AS f1,
              event.signature AS f2,
              event.signature_id AS f3,
              event.signature_gen AS f4,
              MAX(CONVERT_TZ(event.timestamp,'+00:00','$offset')) AS f5,
              COUNT(DISTINCT(event.src_ip)) AS f6, 
              COUNT(DISTINCT(event.dst_ip)) AS f7,
              event.ip_proto AS f8,
              GROUP_CONCAT(DISTINCT(event.status)) AS f9,
              GROUP_CONCAT(DISTINCT(event.sid)) AS f10,
              GROUP_CONCAT(event.status) AS f11,
              GROUP_CONCAT(SUBSTRING(CONVERT_TZ(event.timestamp, '+00:00', '$offset'),12,2)) AS f12,
              event.priority AS f13
              FROM event
              LEFT JOIN mappings AS msrc ON event.src_ip = msrc.ip
              LEFT JOIN mappings AS mdst ON event.dst_ip = mdst.ip
              $qp2
              GROUP BY f3
              ORDER BY f5 $sv";
    
    $result = mysql_query($query);
    $rows = array();

    while ($row = mysql_fetch_assoc($result)) {
        $rows[] = $row;
    }
    $theJSON = json_encode($rows);
    echo $theJSON;
}

function eg() {

    global $offset, $when, $sensors, $rt;
    $sid = mysql_real_escape_string($_REQUEST['object']);
    $sv = mysql_real_escape_string($_REQUEST['sv']);
    $filter = hextostr($_REQUEST['filter']);
    
    if ($filter != 'empty') {
        if (substr($filter, 0,4) == 'cmt ') {
            $comment = explode('cmt ', $filter);
            $qp2 = "LEFT JOIN history ON event.sid = history.sid AND event.cid = history.cid 
                    WHERE history.comment = '$comment[1]'
                    AND event.signature_id = '$sid'";
        } else {
            $filter = str_replace('&lt;','<', $filter);
            $filter = str_replace('&gt;','>', $filter);
            $filter = "AND " . $filter;
            $qp2 = "WHERE $when
                    $sensors
                    AND event.signature_id = '$sid'
                    $filter
                    $rt";
        }
    } else {
        $qp2 = "WHERE $when
                $sensors
                AND event.signature_id = '$sid'
                $rt";
    }

    $query = "SELECT COUNT(event.signature) AS count,
              MAX(CONVERT_TZ(event.timestamp,'+00:00','$offset')) AS maxTime, 
              INET_NTOA(event.src_ip) AS src_ip,
              msrc.c_long AS src_cc,
              INET_NTOA(event.dst_ip) AS dst_ip,
              mdst.c_long AS dst_cc,
              msrc.cc AS srcc,
              mdst.cc AS dstc,
              GROUP_CONCAT(event.sid) AS c_sid,
              GROUP_CONCAT(event.cid) AS c_cid,
              GROUP_CONCAT(event.status) AS c_status,
              GROUP_CONCAT(SUBSTR(CONVERT_TZ(event.timestamp,'+00:00','$offset'),12,5)) AS c_ts,
              GROUP_CONCAT(SUBSTRING(CONVERT_TZ(event.timestamp, '+00:00', '$offset'),12,2)) AS f12,
              event.priority AS f13
              FROM event
              LEFT JOIN mappings AS msrc ON event.src_ip = msrc.ip
              LEFT JOIN mappings AS mdst ON event.dst_ip = mdst.ip
              $qp2
              GROUP BY event.src_ip, src_cc, event.dst_ip, dst_cc
              ORDER BY maxTime $sv";

    $result = mysql_query($query);

    $rows = array();

    while ($row = mysql_fetch_assoc($result)) {
        $rows[] = $row;
    }
    $theJSON = json_encode($rows);
    echo $theJSON;
}

// 2
function ed() {

    global $offset, $when, $sensors, $rt;
    $comp = mysql_real_escape_string($_REQUEST['object']);
    $filter = hextostr($_REQUEST['filter']);
    $sv = mysql_real_escape_string($_REQUEST['sv']);
    $adqp = hextostr(mysql_real_escape_string($_REQUEST['adqp']));
    list($ln,$sid,$src_ip,$dst_ip) = explode("-", $comp);
    $src_ip = sprintf("%u", ip2long($src_ip));
    $dst_ip = sprintf("%u", ip2long($dst_ip));

    if ($filter != 'empty') {
        if (substr($filter, 0,4) == 'cmt ') {
            $comment = explode('cmt ', $filter);
            $qp2 = "LEFT JOIN history ON event.sid = history.sid AND event.cid = history.cid 
                    WHERE history.comment = '$comment[1]'
                    AND (event.signature_id = '$sid'
                    AND event.src_ip = '$src_ip'
                    AND event.dst_ip = '$dst_ip')";
        } else {
        
          $qp2 = "WHERE $when
                  $sensors
                  AND (event.signature_id = '$sid' 
                  AND event.src_ip = '$src_ip' 
                  AND event.dst_ip = '$dst_ip')";
        }
    } else {
        if ($adqp === "empty") {
            $adqp = "";
        }
        $qp2 = "WHERE $when
                $sensors
                $adqp
                AND (event.signature_id = '$sid' 
                AND event.src_ip = '$src_ip' 
                AND event.dst_ip = '$dst_ip')";
    }

    $query = "SELECT event.status AS f1, 
              CONCAT_WS(',',CONVERT_TZ(event.timestamp,'+00:00','$offset'),event.timestamp) AS f2,
              INET_NTOA(event.src_ip) AS f3,
              event.src_port AS f4,
              INET_NTOA(event.dst_ip) AS f5,
              event.dst_port AS f6, 
              event.sid AS f7,
              event.cid AS f8,
              event.ip_proto AS f9,
              event.signature AS f10,
              event.signature_id AS f11,
              event.priority AS f12
              FROM event
              $qp2
              $rt
              ORDER BY event.timestamp $sv";
    
    $result = mysql_query($query);
    $rows = array();

    while ($row = mysql_fetch_assoc($result)) {
        $rows[] = $row;
    }
    $theJSON = json_encode($rows);
    echo $theJSON;

}

// 2a
function ee() {

    global $offset, $when, $sensors, $rt;
    $sv = mysql_real_escape_string($_REQUEST['sv']);
    $filter = hextostr($_REQUEST['filter']);

    if ($filter != 'empty') {
        if (substr($filter, 0,4) == 'cmt ') {
            $comment = explode('cmt ', $filter);
            $qp2 = "LEFT JOIN history ON event.sid = history.sid AND event.cid = history.cid 
                    WHERE history.comment = '$comment[1]'";
        } else {
            $filter = str_replace('&lt;','<', $filter);
            $filter = str_replace('&gt;','>', $filter);
            $filter = "AND " . $filter;
            $qp2 = "WHERE $when
                    $sensors
                    $filter
                    $rt";
        }
    } else {
        $qp2 = "WHERE $when
                $sensors
                $rt";
    }

    $query = "SELECT event.status AS f1, 
              CONCAT_WS(',',CONVERT_TZ(event.timestamp,'+00:00','$offset'),event.timestamp) AS f2,
              INET_NTOA(event.src_ip) AS f3,
              event.src_port AS f4, 
              msrc.c_long AS f5,
              msrc.cc AS f6,          
              INET_NTOA(event.dst_ip) AS f7, 
              event.dst_port AS f8,
              mdst.c_long AS f9,
              mdst.cc AS f10,
              event.sid AS f11,
              event.cid AS f12, 
              event.ip_proto AS f13,
              event.signature AS f14,
              event.signature_id AS f15,
              event.priority AS f16,
              event.signature_gen AS f17
              FROM event
              LEFT JOIN mappings AS msrc ON event.src_ip = msrc.ip
              LEFT JOIN mappings AS mdst ON event.dst_ip = mdst.ip
              $qp2
              ORDER BY event.timestamp $sv";

    $result = mysql_query($query);
    $rows = array();
    while ($row = mysql_fetch_assoc($result)) {
        $rows[] = $row;
    }
    $theJSON = json_encode($rows);
    echo $theJSON;
}

function pd() {

    global $offset;
    $comp = mysql_real_escape_string($_REQUEST['object']);
    list($sid,$cid) = explode("-", $comp);

    $query = "SELECT INET_NTOA(event.src_ip), 
              INET_NTOA(event.dst_ip),
              event.ip_ver, event.ip_hlen, event.ip_tos,
              event.ip_len, event.ip_id, event.ip_flags,
              event.ip_off, event.ip_ttl, event.ip_csum,
              event.src_port, event.dst_port, event.ip_proto,
              event.signature, event.signature_id,
              CONVERT_TZ(event.timestamp,'+00:00','$offset'), event.sid, event.cid,
              GROUP_CONCAT(history.comment SEPARATOR ' || ') AS comment
              FROM event
              LEFT JOIN history ON event.sid = history.sid AND event.cid = history.cid
              WHERE event.sid='$sid' AND event.cid='$cid'";

    $result = mysql_query($query);

    $rows = array();

    $row = mysql_fetch_assoc($result);
    $rows[] = $row;
    $ipp = $row["ip_proto"];

    // Protocol
    switch ($ipp) {

        case 1:
            $query = "SELECT event.icmp_type AS icmp_type,
                      event.icmp_code AS icmp_code,
                      icmphdr.icmp_csum AS icmp_csum, 
                      icmphdr.icmp_id AS icmp_id,
                      icmphdr.icmp_seq AS icmp_seq
                      FROM event, icmphdr
                      WHERE event.sid=icmphdr.sid
                      AND event.cid=icmphdr.cid
                      AND event.sid='$sid'
                      AND event.cid='$cid'";
            
            $result = mysql_query($query);

            $row = mysql_fetch_assoc($result);
            $rows[] = $row;
            break;

        case 6:
            $query = "SELECT tcp_seq, tcp_ack, tcp_off, tcp_res, tcp_flags, tcp_win, tcp_urp, tcp_csum
                      FROM tcphdr 
                      WHERE sid='$sid' AND cid='$cid'";
            
            $result = mysql_query($query);

            $row = mysql_fetch_assoc($result);
            $rows[] = $row;
            break;
         
        case 17:
            $query = "SELECT udp_len, udp_csum 
                      FROM udphdr 
                      WHERE sid='$sid' AND cid='$cid'";

            $result = mysql_query($query);

            $row = mysql_fetch_assoc($result);
            $rows[] = $row;
            break;
        default:
            $result = array(0 => 0);
            $rows[] = $row;
            break;
    }

    // Data
    $query = "SELECT data_payload 
              FROM data 
              WHERE sid='$sid' AND cid='$cid'";

    $result = mysql_query($query);

    $row = mysql_fetch_assoc($result);
    $rows[] = $row;
    $theJSON = json_encode($rows);
    echo $theJSON;

}

function tb() {
    $tab = $_REQUEST['tab'];
    $_SESSION['sTab'] = $tab;
}

function tx() {

    global $offset;
    $txdata  = hextostr($_REQUEST['txdata']);
    $usr     = $_SESSION['sUser'];
    $pwd     = $_SESSION['sPass'];
    list($sid, $timestamp, $sip, $spt, $dip, $dpt) = explode("|", $txdata);

    // Lookup sensorname
    $query = "SELECT hostname FROM sensor
              WHERE sid = '$sid'";

    $qResult = mysql_query($query);
    
    $sensorName = mysql_result($qResult, 0);
 
    $cmd = "../.scripts/cliscript.tcl \"$usr\" \"$sensorName\" \"$timestamp\" $sid $sip $dip $spt $dpt";

    $descspec = array(
                 0 => array("pipe", "r"),
                 1 => array("pipe", "w"),
                 2 => array("pipe", "w")
    );

    $proc = proc_open($cmd, $descspec, $pipes);
    $debug = "Process execution failed";
    $_raw = $fmtd = "";
    if (is_resource($proc)) {
        fwrite($pipes[0], $pwd);
        fclose($pipes[0]);
        $_raw = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $debug = fgets($pipes[2]);
        fclose($pipes[2]);
    }

    $raw = explode("\n", $_raw);
    foreach ($raw as $line) {

        $line = htmlspecialchars($line);
        $type = substr($line, 0,3);

        switch ($type) {
            case "DEB": $debug .= preg_replace('/^DEBUG:.*$/', "<span class=txtext_dbg>$0</span>", $line) . "<br>"; $line = ''; break;
            case "HDR": $line = preg_replace('/(^HDR:)(.*$)/', "<span class=txtext_hdr>$2</span>", $line); break;
            case "DST": $line = preg_replace('/^DST:.*$/', "<span class=txtext_dst>$0</span>", $line); break;
            case "SRC": $line = preg_replace('/^SRC:.*$/', "<span class=txtext_src>$0</span>", $line); break;
        }

        if (strlen($line) > 0) {
            $fmtd  .= $line . "<br>";
        }
    }

    $fmtd  .= "<br>" . $debug;

    $result = array("tx"  => "$fmtd",
                    "dbg" => "$debug",
                    "cmd" => "$cmd");

    $theJSON = json_encode($result);
    echo $theJSON;
}

function fi() {   
    $user = $_SESSION['sUser'];
    $mode = mysql_real_escape_string($_REQUEST['mode']);

    switch ($mode) {
        case "query"  : 
            $query = "SELECT UNHEX(name) AS name, alias, filter, UNHEX(notes) as notes, age, global, username
                      FROM filters
                      ORDER BY global,name ASC";

            $result = mysql_query($query);

            $rows = array();

            while ($row = mysql_fetch_assoc($result)) {
                $rows[] = $row;
            }

            $theJSON = json_encode($rows); 

            break;

        case "update" :
            $data = hextostr($_REQUEST['data']);
            list($alias, $name, $notes, $filter) = explode("||", $data);
            $name = strtohex($name);
            $notes = strtohex($notes);
            $remove = array("DELETE","UPDATE","INSERT","SELECT","CONCAT","REGEXP",
                            "REVERSE","REPLACE","RLIKE","SUBSTR","SUBSTRING");
            $filter = str_ireplace($remove, "", $filter);
            $filter = strtohex($filter);
            
            $query = "INSERT INTO filters (name,alias,username,filter,notes)
                      VALUES ('$name','$alias','$user','$filter','$notes')
                      ON DUPLICATE KEY UPDATE 
                      name='$name',alias='$alias',filter='$filter',notes='$notes'";

            mysql_query($query);
            $result = mysql_error();
            $return = array("msg" => $result);
            $theJSON = json_encode($return);

            break;

        case "remove" : 
            $alias =  mysql_real_escape_string($_REQUEST['data']);  
            $query = "DELETE FROM filters WHERE username = '$user' AND (alias = '$alias' AND global = 0)";
            mysql_query($query);
            $result = mysql_error();
            $return = array("msg" => $result);
            $theJSON = json_encode($return); 

            break;

    }

    echo $theJSON;

}

function cat() {
    $catdata = $_REQUEST['catdata'];
    $usr     = $_SESSION['sUser'];
    $pwd     = $_SESSION['sPass'];
    
    list($cat, $msg, $lst) = explode("|||", $catdata);
    $msg = htmlentities($msg);
    
    $cmd = "../.scripts/clicat.tcl 0 \"$usr\" \"$cat\" \"$msg\" \"$lst\"";
    $descspec = array(
                 0 => array("pipe", "r"),
                 1 => array("pipe", "w")
    );

    $proc = proc_open($cmd, $descspec, $pipes);
    $debug = "Process execution failed";
    if (is_resource($proc)) {
        fwrite($pipes[0], $pwd);
        fclose($pipes[0]);
        $debug = fgets($pipes[1]);
        fclose($pipes[1]);
    }
    $result = array("dbg"  => htmlspecialchars($debug));

    $theJSON = json_encode($result);
    echo $theJSON;
}

function comments() {
    $query = "SELECT COUNT(comment) AS f1,
              comment AS f2,
              u.username AS f3,
              MIN(timestamp) AS f4,
              MAX(timestamp) AS f5,
              GROUP_CONCAT(DISTINCT(status)) AS f6
              FROM history 
              LEFT JOIN user_info AS u ON history.uid = u.uid 
              WHERE timestamp BETWEEN 
              UTC_DATE() - INTERVAL 365 DAY AND UTC_TIMESTAMP()
              AND comment NOT IN('NULL','Auto Update','')
              GROUP BY comment
              ORDER BY f5 DESC";

    $result = mysql_query($query);
    $rows = array();

    while ($row = mysql_fetch_assoc($result)) {
        $rows[] = $row;
    }
    $theJSON = json_encode($rows);
    echo $theJSON;
}

function remove_comment() {   
    $user = $_SESSION['sUser'];
    $comment = hextostr($_REQUEST['comment']);
    $comment = mysql_real_escape_string($comment);
    $query = "DELETE FROM sguildb.history WHERE comment = '$comment'";
    mysql_query($query);
    $result = mysql_error();
    $return = array("msg" => $result);

    $theJSON = json_encode($return); 
    echo $theJSON;
}

function map() {
    global $when;
    $filter = $_REQUEST['filter'];

    $srcq = "SELECT COUNT(src_ip) AS c, m1.cc 
             FROM event 
             LEFT JOIN mappings AS m1 ON event.src_ip = m1.ip
             LEFT JOIN mappings AS m2 ON event.dst_ip = m2.ip
             WHERE $when
             AND src_ip NOT BETWEEN 167772160 AND 184549375
             AND src_ip NOT BETWEEN 2886729728 AND 2886795263
             AND src_ip NOT BETWEEN 3232235520 AND 3232301055
             AND m1.cc IS NOT NULL
             GROUP BY m1.cc
             ORDER BY c DESC";

    $dstq = "SELECT COUNT(dst_ip) AS c, m2.cc 
             FROM event 
             LEFT JOIN mappings AS m1 ON event.src_ip = m1.ip
             LEFT JOIN mappings AS m2 ON event.dst_ip = m2.ip
             WHERE $when
             AND dst_ip NOT BETWEEN 167772160 AND 184549375
             AND dst_ip NOT BETWEEN 2886729728 AND 2886795263
             AND dst_ip NOT BETWEEN 3232235520 AND 3232301055
             AND m2.cc IS NOT NULL
             GROUP BY m2.cc
             ORDER BY c DESC";

    $srcr = mysql_query($srcq);
    $dstr = mysql_query($dstq);

    // A => src, B=> dst,  C=> cumulative
    $a1 = $a2 = $b1 = $b2 = array();
    $aHit = $bHit = $cHit = 'no';

    // Source countries and count
    while ($row = mysql_fetch_row($srcr)) {
        $a1[] = $row[0];
        $a2[] = $row[1];
        $c1[] = $row[0];
        $c2[] = $row[1];
        $aHit = 'yes';
        $cHit = 'yes';
    }

    // Destination countries and count
    // As we loop through we check to see if we hit a country
    // that we already processed so that we can derive a sum
    while ($row = mysql_fetch_row($dstr)) {
        $b1[] = $row[0];
        $b2[] = $row[1];
        if ($aHit == 'yes') {
            $key = array_search($row[1],$c2);
            if ($key === FALSE) {
                $c1[] = $row[0];
                $c2[] = $row[1];
            } else {
                $base = $c1[$key] + $row[0];
                $c1[$key] = $base;
            }
        } else {
            $c1[] = $row[0];
            $c2[] = $row[1];
        }

        $bHit = 'yes';
        $cHit = 'yes';
    }        

    $aSum = $bSum = $cSum = $aItems = $bItems = $cItems = 0;

    function makeDetail($x1,$x2) {
        $detail = ""; 
        $lc = count($x1);
        for ($i=0; $i<$lc; $i++) {
            $detail .= "\"$x2[$i]\": \"$x1[$i]\"";
            if ($i < $lc-1) {
                $detail .= ",";
            }
        }
        return $detail;
    }

    if ($aHit == 'yes') {
        $aItems = count($a1);
        $aSum = array_sum($a1);
        array_multisort($a1, SORT_DESC, $a2);
        $srcd = makeDetail($a1,$a2);
    }

    if ($bHit == 'yes') {
        $bItems = count($b1);
        $bSum = array_sum($b1);
        array_multisort($b1, SORT_DESC, $b2);
        $dstd = makeDetail($b1,$b2);
    }

    if ($cHit == 'yes') {
        $cItems = count($c1);
        $cSum = array_sum($c1);
        array_multisort($c1, SORT_DESC, $c2);
        $alld = makeDetail($c1,$c2);
    }

    $result = array("src"  => "$srcd", 
                    "dst"  => "$dstd", 
                    "all"  => "$alld",
                    "srcc" => "$aItems",
                    "srce" => "$aSum",
                    "dstc" => "$bItems",
                    "dste" => "$bSum",
                    "allc" => "$cItems",
                    "alle" => "$cSum",
    );

    $theJSON = json_encode($result);
    echo $theJSON;

}

function sensors() {
    $query = "SELECT net_name AS f1, 
                     hostname AS f2,
                     agent_type AS f3,
                     sensor.sid AS f4
                     FROM sensor
                     WHERE agent_type != 'pcap' 
                     AND active = 'Y'
                     ORDER BY net_name ASC";

    $result = mysql_query($query);
    $rows = array();

    while ($row = mysql_fetch_assoc($result)) {
        $rows[] = $row;
    }
    $theJSON = json_encode($rows);
    echo $theJSON;
}

function user_profile() {
    $user = $_SESSION['sUser'];
    $tz = hextostr($_REQUEST['tz']);
    $validtz = "/^(-12:00|-11:00|-10:00|-09:30|-09:00|-08:00|-07:00|-06:00|-05:00|-04:30|-04:00|-03:30|-03:00|-02:00|-01:00|\+00:00|\+01:00|\+02:00|\+03:00|\+03:30|\+04:00|\+04:30|\+05:00|\+05:30|\+05:45|\+06:00|\+06:30|\+07:00|\+08:00|\+08:45|\+09:00|\+09:30|\+10:00|\+10:30|\+11:00|\+11:30|\+12:00|\+12:45|\+13:00|\+14:00)$/";

    if (preg_match($validtz, $tz)) { 
        $query = "UPDATE user_info SET tzoffset = '$tz' WHERE username = '$user'";
        mysql_query($query);
        $result = mysql_error();
        // Update session offset
        $_SESSION['tzoffset'] = $tz;
    } else {
        $result = "Invalid timezone offset";
    }
    $return = array("msg" => $result);
    $theJSON = json_encode($return); 
    echo $theJSON;
}

function summary() {
    global $when, $sensors;
    $limit = $_REQUEST['limit'];
    $qargs = $_REQUEST['qargs'];
    $filter  = hextostr($_REQUEST['filter']);
    list($type,$subtype) = explode("-", $qargs); 
    $oppip = "src";
    if ($subtype == "src") { $oppip = "dst"; }

    if ($filter != 'empty') {
        if (substr($filter, 0,4) == 'cmt ') {
            $comment = explode('cmt ', $filter);
            $qp2 = "LEFT JOIN history ON event.sid = history.sid AND event.cid = history.cid
                    WHERE history.comment = '$comment[1]'";
        } else {
            $filter = str_replace('&lt;','<', $filter);
            $filter = str_replace('&gt;','>', $filter);
            $filter = "AND " . $filter;
            $qp2 = "WHERE $when
                    $sensors
                    $filter";
        }
    } else {
        $qp2 = "WHERE $when
                $sensors";
    }

    switch ($type) {
        case "ip":
            $query = "SELECT COUNT(event.{$subtype}_ip) AS f1,
                      COUNT(DISTINCT(event.signature)) AS f2,
                      COUNT(DISTINCT(event.{$oppip}_ip)) AS f3,
                      m{$subtype}.cc AS f4, 
                      m{$subtype}.c_long AS f5,
                      INET_NTOA(event.{$subtype}_ip) AS f6
                      FROM event
                      LEFT JOIN mappings AS m{$subtype} ON event.{$subtype}_ip = m{$subtype}.ip  
                      $qp2
                      GROUP BY f6
                      ORDER BY f1 DESC";
        break;
        case "pt":
            $query = "SELECT COUNT(event.{$subtype}_port) AS f1,
                      COUNT(DISTINCT(event.signature)) AS f2,
                      COUNT(DISTINCT(event.src_ip)) AS f3,
                      COUNT(DISTINCT(event.dst_ip)) AS f4,
                      event.{$subtype}_port AS f5
                      FROM event
                      $qp2
                      GROUP BY f5
                      ORDER BY f1 DESC";
        break;
        case "sig":
            $query = "SELECT COUNT(event.signature) AS f1,
                      COUNT(DISTINCT(event.src_ip)) AS f2,
                      COUNT(DISTINCT(event.dst_ip)) AS f3,
                      event.signature_id AS f4,
                      event.signature AS f5
                      FROM event
                      $qp2
                      GROUP BY f4
                      ORDER BY f1 DESC";
        break;
        case "cc":
            $query = "SELECT COUNT(event.{$subtype}_ip) AS f1,
                      COUNT(DISTINCT(event.signature)) AS f2,
                      COUNT(DISTINCT(event.{$oppip}_ip)) AS f3,
                      m.cc AS f4,
                      m.c_long AS f5,
                      COUNT(DISTINCT(event.{$subtype}_ip)) AS f6
                      FROM event LEFT JOIN mappings AS m ON event.{$subtype}_ip = m.ip
                      $qp2
                      AND event.{$subtype}_ip NOT BETWEEN 167772160 AND 184549375
                      AND event.{$subtype}_ip NOT BETWEEN 2886729728 AND 2886795263 
                      AND event.{$subtype}_ip NOT BETWEEN 3232235520 AND 3232301055
                      AND m.cc IS NOT NULL GROUP BY m.cc ORDER BY f1 DESC"; 
        break; 
    }
    $result = mysql_query($query);
    $rows = array();
    $i = 0;
    $n = 0;
    $r = mysql_num_rows($result);
    while ($row = mysql_fetch_assoc($result)) {
        $n += $row["f1"];
        $i++;
        if ($i <= $limit) $rows[] = $row; 
    }
    $rows[] = array("n" => $n, "r" => $r);
    $theJSON = json_encode($rows);
    echo $theJSON;     
}

function view() {
    global $when, $sensors;
    $qargs   = $_REQUEST['qargs'];
    $filter  = hextostr($_REQUEST['filter']);
    list($type,$subtype) = explode("-", $qargs);

    if ($filter != 'empty') {
        if (substr($filter, 0,4) == 'cmt ') {
            $comment = explode('cmt ', $filter);
            $qp2 = "LEFT JOIN history ON event.sid = history.sid AND event.cid = history.cid
                    WHERE history.comment = '$comment[1]'";
        } else {
            $filter = str_replace('&lt;','<', $filter);
            $filter = str_replace('&gt;','>', $filter);
            $filter = "AND " . $filter;
            $qp2 = "WHERE $when
                    $sensors
                    $filter";
        }
    } else {
        $qp2 = "WHERE $when
                $sensors";
    }

    switch ($type) {
        case "ip":
            $query = "SELECT CONCAT_WS('|', INET_NTOA(event.src_ip), msrc.cc, msrc.c_long) AS source,
                      CONCAT_WS('|', INET_NTOA(event.dst_ip), mdst.cc, mdst.c_long) AS target,
                      COUNT(event.src_ip) AS value
                      FROM event
                      LEFT JOIN mappings AS msrc ON event.src_ip = msrc.ip
                      LEFT JOIN mappings AS mdst ON event.dst_ip = mdst.ip 
                      $qp2 
                      AND (INET_NTOA(event.src_ip) != '0.0.0.0' AND INET_NTOA(event.dst_ip) != '0.0.0.0')
                      GROUP BY source,target";
        break;
        case "ips":
            $query = "SELECT CONCAT_WS('|', INET_NTOA(event.src_ip), msrc.cc, msrc.c_long) AS source,
                      event.signature AS sig,
                      CONCAT_WS('|', INET_NTOA(event.dst_ip), mdst.cc, mdst.c_long) AS target,
                      COUNT(event.src_ip) AS value
                      FROM event
                      LEFT JOIN mappings AS msrc ON event.src_ip = msrc.ip
                      LEFT JOIN mappings AS mdst ON event.dst_ip = mdst.ip 
                      $qp2 
                      AND (INET_NTOA(event.src_ip) != '0.0.0.0' AND INET_NTOA(event.dst_ip) != '0.0.0.0')
                      GROUP BY source,target";
        break;
        case "sc":
            $query = "SELECT CONCAT_WS('|' ,msrc.c_long, msrc.cc) AS source,
                      CONCAT_WS('|',INET_NTOA(event.dst_ip), mdst.cc) AS target,
                      COUNT(event.src_ip) AS value
                      FROM event
                      LEFT JOIN mappings AS msrc ON event.src_ip = msrc.ip
                      LEFT JOIN mappings AS mdst ON event.dst_ip = mdst.ip
                      $qp2
                      AND (INET_NTOA(event.src_ip) != '0.0.0.0' AND INET_NTOA(event.dst_ip) != '0.0.0.0')
                      AND event.src_ip NOT BETWEEN 167772160 AND 184549375
                      AND event.src_ip NOT BETWEEN 2886729728 AND 2886795263
                      AND event.src_ip NOT BETWEEN 3232235520 AND 3232301055
                      GROUP BY source,target";
        break;   
        case "dc":
            $query = "SELECT CONCAT_WS('|', INET_NTOA(event.src_ip), msrc.cc) AS source,
                      CONCAT_WS('|', mdst.c_long, mdst.cc) AS target,
                      COUNT(event.dst_ip) AS value
                      FROM event
                      LEFT JOIN mappings AS msrc ON event.src_ip = msrc.ip
                      LEFT JOIN mappings AS mdst ON event.dst_ip = mdst.ip
                      $qp2
                      AND (INET_NTOA(event.src_ip) != '0.0.0.0' AND INET_NTOA(event.dst_ip) != '0.0.0.0')
                      AND event.dst_ip NOT BETWEEN 167772160 AND 184549375
                      AND event.dst_ip NOT BETWEEN 2886729728 AND 2886795263
                      AND event.dst_ip NOT BETWEEN 3232235520 AND 3232301055
                      GROUP BY source,target";
        break;    
    }
    $result = mysql_query($query);
    $rc = mysql_num_rows($result);
    $records = 0;
    $rows = $srcs = $tgts = $vals = $skip = $names = $_names = array();

    if ($rc == 0) { 
        $theJSON = json_encode(array("nodes" => $names, "links" => $rows, "records" => $records));
        echo $theJSON;
        exit();
    }  
  
    while ($row = mysql_fetch_assoc($result)) {
        if ($type == "ips") {
            $srcs[] = $row["source"]; 
            $tgts[] = $row["sig"];
            $vals[] = $row["value"];
            $srcs[] = $row["sig"];
            $tgts[] = $row["target"];
            $vals[] = $row["value"];
        } else {
            $srcs[] = $row["source"];
            $tgts[] = $row["target"];
            $vals[] = $row["value"];
        }
        $sads[] = 0;
        $records++; 
    }
    // Value counts
    $src_c = array_count_values($srcs);
    $tgt_c = array_count_values($tgts);

    // Accomodate sources that exist as a target with the 
    // current target as a source (not allowed)
    foreach ($srcs as $index => $src) {
        // Find the target
        if (in_array($index, $skip)) { continue; }
        $tgt = $tgts[$index];
        // Find the keys for all instances of the target as a source
        $tgt_keys = array_keys($srcs,$tgt);
        // Now see if any have the source as a target
        foreach ($tgt_keys as $pos) {
            if ($tgts[$pos] == $src) {
                $sads_val = $vals[$pos];
                unset($srcs[$pos]);
                unset($tgts[$pos]);
                unset($vals[$pos]);
                unset($sads[$pos]);
                // Add offset to be skipped
                $skip[] = $pos;
                // By setting this we flag that this source is also a target
                $sads[$index] = $sads_val; 
            }
        }
        
        // If there is no filter, remove 1:1s with a count of 1
        if ($filter == 'empty') {
            if ($vals[$index] == 1 && $sads[$index] == 0 && $src_c[$src] == 1) {
                unset($srcs[$index]);
                unset($tgts[$index]);
                unset($vals[$index]);
                unset($sads[$index]);
            }
        } 
    }       

    // We have probably truncated these so realign the indexes
    $srcs = array_slice($srcs, 0);
    $tgts = array_slice($tgts, 0);
    $vals = array_slice($vals, 0);
    $sads = array_slice($sads, 0);

    // Create distinct names array
    $lc = count($srcs);
    for ($i = 0; $i < $lc; $i++) {
        if (!in_array($srcs[$i], $_names)) {
            $_names[] = $srcs[$i];
        }
        if (!in_array($tgts[$i], $_names)) {
            $_names[] = $tgts[$i]; 
        }
        
    }

    // Now go through the results and map the
    // sources and targets to the indexes in $_names
    for ($i = 0; $i < $lc; $i++) {
        // get source index
        $skey = array_search($srcs[$i], $_names);
        // get target index
        $dkey = array_search($tgts[$i], $_names);
        $val = (int)$vals[$i];
        $sad = (int)$sads[$i];
        $rows[] = array("source" => $skey, "target" => $dkey, "value" => $val, "sad" => $sad);
        //echo "$skey,$dkey,$val,$sad<br>";
    }

    // Lastly, we reformat names
    foreach ($_names as $name) {
        $names[] = array("name" => $name);
        //echo "$name<br>"; 
    }
     
    $theJSON = json_encode(array("nodes" => $names, "links" => $rows, "records" => $records));
    echo $theJSON;
}

function autocat() {
    $usr    = $_SESSION['sUser'];
    $pwd    = $_SESSION['sPass'];
    $offset = $_SESSION['tzoffset'];
    $mode   = mysql_real_escape_string($_REQUEST['mode']);

    switch ($mode) {
        case "query"  : 
            $query = "SELECT autoid, CONVERT_TZ(erase,'+00:00','$offset') AS erase, sensorname, 
                      src_ip, src_port, dst_ip, dst_port, ip_proto,
                      signature, status, active, CONVERT_TZ(timestamp,'+00:00','$offset') AS ts,
                      u.username AS user, comment
                      FROM autocat
                      LEFT JOIN user_info AS u ON autocat.uid = u.uid
                      ORDER BY ts DESC";

            $result = mysql_query($query);

            $rows = array();

            while ($row = mysql_fetch_assoc($result)) {
                $rows[] = $row;
            }

            $theJSON = json_encode($rows); 
            break;
        case "update" :
            $data = hextostr($_REQUEST['data']);
            $v = json_decode($data, true);
            // Is the timestamp freeform?
            $pattern = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/';
            $expires = $v[expires];  
            if (!preg_match($pattern, $expires)) {
                $expires = gmdate("Y-m-d H:i:s", strtotime("+ $expires"));
            }
            
            $cmd = "../.scripts/clicat.tcl 1 \"$usr\" \"$expires\" \"$v[sensor]\" \"$v[src_ip]\" \"$v[src_port]\" \"$v[dst_ip]\" \"$v[dst_port]\" \"$v[proto]\" \"$v[signature]\" \"$v[status]\" \"$v[comment]\"";
            $descspec = array(0 => array("pipe", "r"), 1 => array("pipe", "w"));
            $proc = proc_open($cmd, $descspec, $pipes);
            $debug = "Process execution failed";
            if (is_resource($proc)) {
              fwrite($pipes[0], $pwd);
              fclose($pipes[0]);
              $debug = fgets($pipes[1]);
              fclose($pipes[1]);
            }

            $result = array("dbg"  => htmlspecialchars($debug)); 
            $theJSON = json_encode($result);
            break;

        case "toggle" : 
            $obj = $_REQUEST['obj'];
            list($type, $id) = explode("-", $obj);
            $cmd = "../.scripts/clicat.tcl $type \"$usr\" $id";
            $descspec = array(0 => array("pipe", "r"), 1 => array("pipe", "w"));
            $proc = proc_open($cmd, $descspec, $pipes);
            $debug = "Process execution failed";
            if (is_resource($proc)) {
              fwrite($pipes[0], $pwd);
              fclose($pipes[0]);
              $debug = fgets($pipes[1]);
              fclose($pipes[1]);
            }

            $result = array("dbg"  => htmlspecialchars($debug));
            $theJSON = json_encode($result);            
            break;
    }

    echo $theJSON;
}

$type();
?>
