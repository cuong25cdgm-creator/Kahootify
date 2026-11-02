<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/room_access.php';
require_once __DIR__ . '/../../includes/player_characters.php';
require_once __DIR__ . '/../../includes/modes/GameStateManager.php';
require_once __DIR__ . '/../../includes/TeamManager.php';

$rawInput = file_get_contents('php://input');
$jsonData = json_decode($rawInput, true);

$action = $_GET['action'] ?? ($_POST['action'] ?? ($jsonData['action'] ?? ''));
$roomId = intval($_GET['id_room'] ?? ($_POST['id_room'] ?? ($jsonData['id_room'] ?? 0)));

if ($roomId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid room ID']);
    exit;
}

$playerId = 0;
if (isset($_GET['pid']) || isset($jsonData['pid'])) {
    $playerId = (int)($_GET['pid'] ?? $jsonData['pid']);
    $token = $_GET['token'] ?? ($jsonData['token'] ?? '');
    if ($playerId <= 0 || !player_request_is_valid($playerId, $roomId, $token)) {
        echo json_encode(['success' => false, 'message' => 'Invalid player session']);
        exit;
    }
    $playerRoom = db_fetch_one('SELECT id_room, pin FROM room WHERE id_room = :r', [':r' => $roomId]);
    if ($playerRoom && player_is_kicked($playerRoom, $playerId)) {
        echo json_encode(['success' => false, 'kicked' => true]);
        exit;
    }

    // Touch last_seen_at for connection tracking
    db_execute("UPDATE players SET last_seen_at = UNIX_TIMESTAMP() WHERE id_player = :p", [':p' => $playerId]);
}

if ($action === 'update_settings') {
    $showVal = 0;
    if (isset($_POST['show_questions_on_players'])) {
        $showVal = intval($_POST['show_questions_on_players']) ? 1 : 0;
    } elseif (isset($_GET['show_questions_on_players'])) {
        $showVal = intval($_GET['show_questions_on_players']) ? 1 : 0;
    } elseif (isset($jsonData['show_questions_on_players'])) {
        $showVal = intval($jsonData['show_questions_on_players']) ? 1 : 0;
    }

    db_execute("UPDATE room SET show_questions_on_players = :val WHERE id_room = :r", [
        ':val' => $showVal,
        ':r'   => $roomId
    ]);

    if (isset($jsonData['question_limit']) || isset($_POST['question_limit'])) {
        $qLimit = isset($jsonData['question_limit']) ? intval($jsonData['question_limit']) : intval($_POST['question_limit'] ?? 0);
        $qMode = $jsonData['question_mode'] ?? ($_POST['question_mode'] ?? 'all');
        $saveLimit = ($qMode === 'random' && $qLimit > 0) ? $qLimit : null;
        db_execute("UPDATE room SET question_limit = :ql WHERE id_room = :r", [
            ':ql' => $saveLimit,
            ':r'  => $roomId
        ]);
    }

    echo json_encode([
        'success' => true,
        'show_questions_on_players' => $showVal,
        'message' => 'Settings updated successfully'
    ]);
    exit;
}

if ($action === 'get_room_status') {
    $room = db_fetch_one("SELECT * FROM room WHERE id_room = :r", [':r' => $roomId]);
    if ($room) {
        $parsed = GameStateManager::parseRoomStatus($room['status'] ?? '');
        $response = [
            'success'                   => true,
            'status'                    => $room['status'],
            'game_type'                 => $room['game_type'] ?? 'classic',
            'buzzer_status'             => $room['buzzer_status'] ?? 'idle',
            'current_phase'             => $parsed['phase'],
            'current_qid'               => $parsed['qid'],
            'is_locked'                 => room_is_locked($room),
            'start_time'                => $room['question_start_time'],
            'show_questions_on_players' => intval($room['show_questions_on_players'] ?? 0),
            'is_team_mode'              => intval($room['is_team_mode'] ?? 0),
            'team_count'                => intval($room['team_count'] ?? 2),
        ];

        if ($playerId > 0) {
            $p = db_fetch_one("SELECT id_player, nickname, score, status, eliminated_at_question, elimination_order, team_id, team_name FROM players WHERE id_player = :p", [':p' => $playerId]);
            if ($p) {
                $response['player_status'] = $p['status'] ?? 'ACTIVE';
                $response['player_score']  = intval($p['score'] ?? 0);
                $response['team_id']       = $p['team_id'] ? intval($p['team_id']) : null;
                $response['team_name']     = $p['team_name'] ?? null;

                if ($parsed['qid'] > 0) {
                    $log = db_fetch_one(
                        "SELECT * FROM player_question_logs WHERE id_room = :r AND id_question = :q AND id_player = :p",
                        [':r' => $roomId, ':q' => $parsed['qid'], ':p' => $playerId]
                    );
                    $response['has_wagered']     = ($log && !empty($log['wager_tier']));
                    $response['wager_tier']      = $log['wager_tier'] ?? null;
                    $response['wager_amount']    = intval($log['wager_amount'] ?? 0);
                    $response['is_sitting_out']  = !empty($log['is_sitting_out']);
                }
            }
        }

        echo json_encode($response);
    } else {
        echo json_encode(['success' => false, 'message' => 'Room does not exist']);
    }
    exit;
}

if ($action === 'get_players') {
    $room = db_fetch_one('SELECT id_room, pin FROM room WHERE id_room = :r', [':r' => $roomId]);
    if (!$room) {
        echo json_encode(['success' => false, 'message' => 'Room does not exist']);
        exit;
    }
    $players = filter_room_players($roomId, db_fetch_all("SELECT id_player, nickname, score, status, team_id, team_name FROM players WHERE id_room = :r ORDER BY (team_id IS NOT NULL AND team_id > 0) DESC, team_id ASC, id_player ASC", [':r' => $roomId]));
    foreach ($players as &$lobbyPlayer) {
        $appearance = get_player_appearance($room, (int) $lobbyPlayer['id_player']);
        $lobbyPlayer['character_svg'] = player_appearance_image($appearance, 'lobby-character');
        if (!empty($lobbyPlayer['team_id'])) {
            $tInfo = TeamManager::getTeamInfo((int)$lobbyPlayer['team_id']);
            $lobbyPlayer['team_color'] = $tInfo['color'];
            $lobbyPlayer['team_icon'] = $tInfo['icon'];
            $lobbyPlayer['team_short_name'] = $tInfo['short_name'];
        }
    }
    unset($lobbyPlayer);
    echo json_encode([
        'success'   => true,
        'players'   => $players,
        'is_locked' => room_is_locked($room),
        'count'     => count($players)
    ]);
    exit;
}

if ($action === 'get_question_status') {
    $qId = intval($_GET['id_question'] ?? 0);
    $room = db_fetch_one('SELECT id_room, pin, game_type, status FROM room WHERE id_room = :r', [':r' => $roomId]);
    if (!$room) {
        echo json_encode(['success' => false, 'message' => 'Room does not exist']);
        exit;
    }
    $counts = room_question_counts($room, $qId);

    echo json_encode([
        'success'       => true,
        'status'        => $room['status'],
        'total_players' => $counts['total_players'],
        'voted_count'   => $counts['voted_count']
    ]);
    exit;
}

if ($action === 'get_wager_status') {
    $qId = intval($_GET['id_question'] ?? 0);
    $status = GameStateManager::getWagerPhaseStatus($roomId, $qId);
    echo json_encode($status);
    exit;
}

if ($action === 'lock_wager') {
    $qId = intval($_GET['id_question'] ?? ($_POST['id_question'] ?? ($jsonData['id_question'] ?? 0)));
    $result = GameStateManager::lockWagerPhaseAndAdvance($roomId, $qId);
    echo json_encode($result);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
