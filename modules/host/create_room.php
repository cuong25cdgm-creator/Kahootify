<?php

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../config/database.php';

$creatorId = intval($_SESSION['id_creator']);
$quizId    = intval($_GET['id_quiz'] ?? 0);

$quiz = db_fetch_one("SELECT * FROM quiz WHERE id_quiz = :q AND id_creator = :c", [':q' => $quizId, ':c' => $creatorId]);
if (!$quiz) {
    set_flash_message('danger', 'Quiz not found or access denied.');
    redirect('modules/quiz/list.php');
}

$questions = db_fetch_all("SELECT id_question FROM questions WHERE id_quiz = :q ORDER BY id_question ASC", [':q' => $quizId]);

if (empty($questions)) {
    set_flash_message('warning', 'This quiz has no questions! Please add questions before hosting a game.');
    redirect('modules/quiz/edit.php?id=' . $quizId);
}

// Nếu bộ câu hỏi đang ở chế độ ôn tập: khóa live host, chuyển thẳng sang trang báo cáo / kết quả ôn tập
if (!empty($quiz['is_review_mode'])) {
    $reviewRoom = db_fetch_one("SELECT * FROM room WHERE id_quiz = :q AND is_review_mode = 1 AND status = 'review_active' LIMIT 1", [':q' => $quizId]);
    if (!$reviewRoom) {
        $reviewRoom = db_fetch_one("SELECT * FROM room WHERE id_quiz = :q AND is_review_mode = 1 ORDER BY id_room DESC LIMIT 1", [':q' => $quizId]);
    }
    if (!$reviewRoom) {
        do {
            $pin = sprintf("%06d", mt_rand(100000, 999999));
            $existing = db_fetch_one("SELECT id_room FROM room WHERE pin = :pin AND status != 'finished'", [':pin' => $pin]);
        } while ($existing);

        $roomId = db_insert(
            "INSERT INTO room (id_quiz, id_creator, pin, status, is_review_mode, review_start_time, review_end_time, show_questions_on_players) VALUES (:q, :c, :pin, 'review_active', 1, :st, :et, 1)",
            [
                ':q'   => $quizId,
                ':c'   => $creatorId,
                ':pin' => $pin,
                ':st'  => $quiz['review_start_time'] ?? null,
                ':et'  => $quiz['review_end_time'] ?? null
            ]
        );
        $reviewRoom = db_fetch_one("SELECT * FROM room WHERE id_room = :r", [':r' => $roomId]);
    }

    redirect('modules/reports/detail.php?id_room=' . $reviewRoom['id_room']);
}

do {
    $pin = sprintf("%06d", mt_rand(100000, 999999));
    $existing = db_fetch_one("SELECT id_room FROM room WHERE pin = :pin AND status != 'finished'", [':pin' => $pin]);
} while ($existing);

// Chỉ đóng các phòng live thông thường, không đóng phòng ôn tập
db_execute("UPDATE room SET status = 'finished' WHERE id_creator = :c AND is_review_mode = 0 AND status != 'finished'", [':c' => $creatorId]);

$roomId = db_insert(
    "INSERT INTO room (id_quiz, id_creator, pin, status) VALUES (:q, :c, :pin, 'waiting')",
    [
        ':q'   => $quizId,
        ':c'   => $creatorId,
        ':pin' => $pin
    ]
);

$_SESSION['id_room'] = $roomId;
$_SESSION['pin'] = $pin;
$_SESSION['id_quiz'] = $quizId;
$_SESSION['question_index'] = 0;

redirect('modules/host/lobby.php');
