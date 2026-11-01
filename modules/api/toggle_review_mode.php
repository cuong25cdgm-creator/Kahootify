<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

function review_api_response(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    review_api_response(405, ['success' => false, 'message' => 'Method Not Allowed']);
}

$creatorId = intval($_SESSION['id_creator'] ?? 0);
if ($creatorId <= 0) {
    review_api_response(401, ['success' => false, 'message' => 'Please log in to continue.']);
}

$data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$quizId = intval($data['id_quiz'] ?? 0);
$action = trim($data['action'] ?? 'enable'); // 'enable', 'update', 'stop'

$quiz = db_fetch_one("SELECT * FROM quiz WHERE id_quiz = :q AND id_creator = :c", [
    ':q' => $quizId,
    ':c' => $creatorId
]);

if (!$quiz) {
    review_api_response(404, ['success' => false, 'message' => 'Quiz not found or permission denied.']);
}

// 1. DỪNG PHÒNG ÔN TẬP
if ($action === 'stop') {
    db_execute("UPDATE quiz SET is_review_mode = 0 WHERE id_quiz = :q", [':q' => $quizId]);
    db_execute("UPDATE room SET status = 'finished' WHERE id_quiz = :q AND is_review_mode = 1 AND status != 'finished'", [':q' => $quizId]);

    review_api_response(200, [
        'success' => true,
        'message' => 'Review session has been stopped successfully.',
        'is_review_mode' => 0
    ]);
}

// 2. BẬT HOẶC CẬP NHẬT THỜI GIAN ÔN TẬP
$startTime   = !empty($data['review_start_time']) ? date('Y-m-d H:i:s', strtotime($data['review_start_time'])) : null;
$endTime     = !empty($data['review_end_time'])   ? date('Y-m-d H:i:s', strtotime($data['review_end_time']))   : null;

if (!empty($startTime) && !empty($endTime) && strtotime($startTime) >= strtotime($endTime)) {
    review_api_response(400, [
        'success' => false,
        'message' => 'End time must be after start time.'
    ]);
}

// Cập nhật quiz
db_execute(
    "UPDATE quiz SET is_review_mode = 1, review_start_time = :st, review_end_time = :et WHERE id_quiz = :q",
    [
        ':st' => $startTime,
        ':et' => $endTime,
        ':q'  => $quizId
    ]
);

// Tìm phòng ôn tập đang mở của quiz này
$activeRoom = db_fetch_one(
    "SELECT * FROM room WHERE id_quiz = :q AND is_review_mode = 1 AND status = 'review_active' LIMIT 1",
    [':q' => $quizId]
);

if (!$activeRoom) {
    // Tạo mã PIN 6 chữ số duy nhất
    do {
        $pin = sprintf("%06d", mt_rand(100000, 999999));
        $existing = db_fetch_one("SELECT id_room FROM room WHERE pin = :pin AND status != 'finished'", [':pin' => $pin]);
    } while ($existing);

    $roomId = db_insert(
        "INSERT INTO room (id_quiz, id_creator, pin, status, is_review_mode, review_start_time, review_end_time, show_questions_on_players) 
         VALUES (:q, :c, :pin, 'review_active', 1, :st, :et, 1)",
        [
            ':q'   => $quizId,
            ':c'   => $creatorId,
            ':pin' => $pin,
            ':st'  => $startTime,
            ':et'  => $endTime
        ]
    );
    $activeRoom = db_fetch_one("SELECT * FROM room WHERE id_room = :r", [':r' => $roomId]);
} else {
    // Cập nhật lại thời hạn vào room đang mở
    db_execute(
        "UPDATE room SET review_start_time = :st, review_end_time = :et WHERE id_room = :r",
        [
            ':st' => $startTime,
            ':et' => $endTime,
            ':r'  => $activeRoom['id_room']
        ]
    );
}

review_api_response(200, [
    'success'           => true,
    'message'           => 'Review Mode enabled successfully!',
    'is_review_mode'    => 1,
    'id_room'           => intval($activeRoom['id_room']),
    'pin'               => $activeRoom['pin'],
    'review_start_time' => $startTime,
    'review_end_time'   => $endTime,
    'join_url'          => BASE_URL . 'modules/player/join.php?pin=' . $activeRoom['pin']
]);
