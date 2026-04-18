<?php
define('AJAX_SCRIPT', true);
require('../../config.php');

require_login();
header('Content-Type: application/json');

$courseid = required_param('courseid', PARAM_INT);
$datefrom = required_param('datefrom', PARAM_INT);
$dateto   = required_param('dateto', PARAM_INT);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);
require_capability('block/timestat:viewreport', $context);

// 1. Fetch Clicks AND Time Spent from the database
// We join with block_timestat to get the 'timespent' column
$sql = "SELECT l.userid, COUNT(l.id) as clicks, SUM(bt.timespent) as totaltime
        FROM {logstore_standard_log} l
        JOIN {block_timestat} bt ON bt.log_id = l.id
        WHERE l.courseid = :courseid
        AND l.timecreated >= :datefrom
        AND l.timecreated <= :dateto
        AND l.userid > 0
        GROUP BY l.userid";

$stats = $DB->get_records_sql($sql, [
    'courseid' => $courseid,
    'datefrom' => $datefrom,
    'dateto'   => $dateto,
]);

if (empty($stats)) {
    echo json_encode(['clusters' => []]);
    exit;
}

$userids_list = [];
$features_list = [];
$student_raw_data = [];

foreach ($stats as $stat) {
    $userid = $stat->userid;
    $clicks = (int)$stat->clicks;
    $minutes = round($stat->totaltime / 60, 2); // Convert seconds to minutes

    $userids_list[] = $userid;
    // Features for AI: [Clicks, Minutes Spent]
    $features_list[] = [$clicks, $minutes];

    $student_raw_data[$userid] = [
        'clicks' => $clicks,
        'minutes' => $minutes
    ];
}
foreach ($clusters as $uid => $ai_data) {
    $user = $DB->get_record('user', ['id' => $uid], 'id, firstname, lastname');
    $final_output[$uid] = [
        'userid'  => $uid,
        'name'    => fullname($user),
        'cluster' => $ai_data['cluster'],
        'color'   => $ai_data['color'],
        'stats'   => [
            'total_actions' => $student_raw_data[$uid]['clicks'], // Ensure these match
            'minutes_spent' => $student_raw_data[$uid]['minutes']
        ]
    ];
}

// 2. Call Django AI Server
$url = 'http://127.0.0.1:8000/api/cluster/';
$content = json_encode(['userids' => $userids_list, 'features' => $features_list]);

$curl = curl_init($url);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_POST, true);
curl_setopt($curl, CURLOPT_POSTFIELDS, $content);
curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);

$response = curl_exec($curl);
$httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($httpcode == 200) {
    $ai_results = json_decode($response, true);
    $clusters = $ai_results['clusters'];
    
    $final_output = [];
    foreach ($clusters as $uid => $ai_data) {
        $user = $DB->get_record('user', ['id' => $uid], 'id, firstname, lastname');
        $final_output[$uid] = [
            'userid'  => $uid,
            'name'    => fullname($user),
            'cluster' => $ai_data['cluster'],
            'color'   => $ai_data['color'],
            'stats'   => [
                'total_actions' => $student_raw_data[$uid]['clicks'],
                'minutes_spent' => $student_raw_data[$uid]['minutes']
            ]
        ];
    }
    echo json_encode(['clusters' => $final_output]);
} else {
    echo json_encode(['error' => 'AI Server Offline']);
}