<?php

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../config/database.php';

$creatorId = intval($_SESSION['id_creator']);
$quizId    = intval($_GET['id_quiz'] ?? 0);

$quiz = db_fetch_one("SELECT id_quiz, game_type, is_team_mode, team_count, team_player_limit FROM quiz WHERE id_quiz = :q AND id_creator = :c", [':q' => $quizId, ':c' => $creatorId]);
if (!$quiz) {
    set_flash_message('danger', 'Quiz not found or access denied.');
    redirect('modules/quiz/list.php');
}

$questions = db_fetch_all("SELECT id_question FROM questions WHERE id_quiz = :q ORDER BY id_question ASC", [':q' => $quizId]);

if (empty($questions)) {
    set_flash_message('warning', 'This quiz has no questions! Please add questions before hosting a game.');
    redirect('modules/quiz/edit.php?id=' . $quizId);
}

$gameType = $quiz['game_type'] ?? 'classic';

do {
    $pin = sprintf("%06d", mt_rand(100000, 999999));
    $existing = db_fetch_one("SELECT id_room FROM room WHERE pin = :pin AND status != 'finished'", [':pin' => $pin]);
} while ($existing);

db_execute("UPDATE room SET status = 'finished' WHERE id_creator = :c AND status != 'finished'", [':c' => $creatorId]);

$roomId = db_insert(
    "INSERT INTO room (id_quiz, id_creator, pin, status, game_type, opened_pieces, buzzer_status, is_team_mode, team_count, team_player_limit) VALUES (:q, :c, :pin, 'waiting', :gt, '[]', 'idle', :tm, :tc, :tpl)",
    [
        ':q'   => $quizId,
        ':c'   => $creatorId,
        ':pin' => $pin,
        ':gt'  => $gameType,
        ':tm'  => intval($quiz['is_team_mode'] ?? 0),
        ':tc'  => max(2, min(10, intval($quiz['team_count'] ?? 2))),
        ':tpl' => max(0, intval($quiz['team_player_limit'] ?? 0))
    ]
);

$_SESSION['id_room'] = $roomId;
$_SESSION['pin'] = $pin;
$_SESSION['id_quiz'] = $quizId;
$_SESSION['question_index'] = 0;

redirect('modules/host/lobby.php');
