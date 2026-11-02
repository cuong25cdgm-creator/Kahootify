<?php

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/TeamManager.php';

$creatorId = intval($_SESSION['id_creator']);
$roomId = intval($_GET['id_room'] ?? $_GET['id'] ?? 0);

if ($roomId <= 0) {
    set_flash_message('danger', 'Invalid game room ID!');
    redirect('modules/reports/list.php');
}

// Verify room ownership
$room = db_fetch_one("
    SELECT r.*, q.name as quiz_name
    FROM room r
    JOIN quiz q ON r.id_quiz = q.id_quiz
    WHERE r.id_room = :r AND r.id_creator = :c
", [':r' => $roomId, ':c' => $creatorId]);

if (!$room) {
    set_flash_message('danger', 'Game report not found or you do not have permission to download it!');
    redirect('modules/reports/list.php');
}

$isTeamMode = TeamManager::isTeamMode($room);

// Get total questions played in this session
$selectedQIds = !empty($room['question_order']) ? json_decode($room['question_order'], true) : null;
if (is_array($selectedQIds) && !empty($selectedQIds)) {
    $totalQuestions = count($selectedQIds);
} else {
    $totalQuestions = db_count('questions', 'id_quiz = :q', [':q' => $room['id_quiz']]);
}

// Fetch ranked player scores
$sql = "SELECT p.*,
        (SELECT COUNT(*) FROM votes v JOIN answers a ON v.id_answer = a.id_answer WHERE v.id_player = p.id_player AND a.is_correct = 1) as correct_count
        FROM players p
        WHERE p.id_room = :id_room
        ORDER BY p.score DESC, p.playeradd ASC";
$rawPlayers = db_fetch_all($sql, [':id_room' => $roomId]);
$players = function_exists('filter_room_players') ? filter_room_players($roomId, $rawPlayers) : $rawPlayers;

// File naming: Kahoot_Report_PIN_[PIN]_[Date].csv
$dateStr = date('Y-m-d', strtotime($room['roomadd'] ?? 'now'));
$filename = "Kahoot_Report_PIN_" . preg_replace('/[^A-Za-z0-9_-]/', '', $room['pin']) . "_" . $dateStr . ".csv";

// Download headers
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Output UTF-8 BOM so Microsoft Excel renders accented/UTF-8 characters cleanly
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

if ($isTeamMode) {
    $teamLeaderboard = TeamManager::getTeamLeaderboard($roomId);

    // 1. Team Leaderboard Standings
    fputcsv($output, ['--- TEAM LEADERBOARD STANDINGS ---']);
    fputcsv($output, ['Team Rank', 'Team Name', 'Total Score', 'Correct Answers', 'Members Count']);
    foreach ($teamLeaderboard as $tRankIdx => $t) {
        $tRank = $t['rank'] ?? ($tRankIdx + 1);
        fputcsv($output, [
            $tRank,
            $t['name'],
            intval($t['total_score']),
            intval($t['total_correct']),
            intval($t['member_count'])
        ]);
    }
    fputcsv($output, []); // blank row

    // 2. Individual Student Standings
    fputcsv($output, ['--- INDIVIDUAL STUDENT STANDINGS ---']);
    fputcsv($output, ['Rank', 'Team', 'Student Nickname', 'Total Score', 'Correct Answers', 'Total Questions', 'Accuracy Rate (%)']);

    foreach ($players as $idx => $p) {
        $rank = $idx + 1;
        $correct = intval($p['correct_count'] ?? $p['answers_correct'] ?? 0);
        $accuracy = $totalQuestions > 0 ? round(($correct / $totalQuestions) * 100, 1) : 0;
        $teamInfo = TeamManager::getTeamInfo(intval($p['team_id'] ?? 0));
        $teamName = $p['team_name'] ?: ($teamInfo['name'] ?? 'No Team');

        fputcsv($output, [
            $rank,
            $teamName,
            $p['nickname'],
            intval($p['score']),
            $correct,
            $totalQuestions,
            $accuracy . '%'
        ]);
    }
} else {
    // English CSV Column Headers
    fputcsv($output, ['Rank', 'Student Nickname', 'Total Score', 'Correct Answers', 'Total Questions', 'Accuracy Rate (%)']);

    // Output student records
    foreach ($players as $idx => $p) {
        $rank = $idx + 1;
        $correct = intval($p['correct_count'] ?? $p['answers_correct'] ?? 0);
        $accuracy = $totalQuestions > 0 ? round(($correct / $totalQuestions) * 100, 1) : 0;

        fputcsv($output, [
            $rank,
            $p['nickname'],
            intval($p['score']),
            $correct,
            $totalQuestions,
            $accuracy . '%'
        ]);
    }
}

fclose($output);
exit;
