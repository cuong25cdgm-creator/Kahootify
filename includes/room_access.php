<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
// Room locks and removed players are stored directly in MySQL (room.is_locked, players.is_kicked)
// without generating runtime files on disk.

function with_room_access_lock($room, $callback, $exclusive = true) {
    // Kept for backward compatibility. Runs the callback directly without file locking.
    return $callback(null);
}

function read_room_access_state($handle = null) {
    return ['locked' => false, 'kicked' => []];
}

function write_room_access_state($handle, $state) {
    // No-op for file storage compatibility
}

function read_room_lock($handle = null) {
    return false;
}

function write_room_lock($handle, $isLocked) {
    // No-op for file storage compatibility
}

function get_room_access_state($room) {
    $roomId = is_array($room) ? (int)($room['id_room'] ?? 0) : (int)$room;
    $isLocked = room_is_locked($room);
    $kicked = [];
    if ($roomId > 0) {
        $rows = db_fetch_all('SELECT id_player FROM players WHERE id_room = :r AND is_kicked = 1', [':r' => $roomId]);
        foreach ($rows as $row) {
            $kicked[$row['id_player']] = true;
        }
    }
    return ['locked' => $isLocked, 'kicked' => $kicked];
}

function player_is_kicked($room, $playerId) {
    $roomId = is_array($room) ? (int)($room['id_room'] ?? 0) : (int)$room;
    if ($roomId <= 0 || (int)$playerId <= 0) return false;
    $p = db_fetch_one('SELECT is_kicked FROM players WHERE id_room = :r AND id_player = :p LIMIT 1', [
        ':r' => $roomId,
        ':p' => (int)$playerId
    ]);
    return !empty($p['is_kicked']);
}

function filter_room_players($roomId, $players, $state = null) {
    if (empty($players)) return [];
    $kickedIds = array_column(
        db_fetch_all('SELECT id_player FROM players WHERE id_room = :r AND is_kicked = 1', [':r' => (int)$roomId]),
        'id_player'
    );
    if (empty($kickedIds)) return array_values($players);
    $kickedMap = array_flip($kickedIds);
    return array_values(array_filter($players, function ($player) use ($kickedMap) {
        return !isset($kickedMap[$player['id_player']]);
    }));
}

function room_question_counts($room, $questionId, $state = null) {
    $roomId = is_array($room) ? (int)($room['id_room'] ?? 0) : (int)$room;
    $players = filter_room_players($roomId, db_fetch_all(
        'SELECT id_player FROM players WHERE id_room = :r', [':r' => $roomId]
    ), $state);
    $answered = db_fetch_all(
        'SELECT DISTINCT v.id_player FROM votes v JOIN answers a ON a.id_answer = v.id_answer JOIN players p ON p.id_player = v.id_player WHERE p.id_room = :r AND a.id_question = :q AND p.is_kicked = 0',
        [':r' => $roomId, ':q' => $questionId]
    );
    return [
        'total_players' => count($players),
        'voted_count' => count(array_intersect(array_column($players, 'id_player'), array_column($answered, 'id_player')))
    ];
}

function kick_room_player($room, $creatorId, $playerId) {
    $roomId = is_array($room) ? (int)($room['id_room'] ?? 0) : (int)$room;
    $currentRoom = db_fetch_one("SELECT id_room FROM room WHERE id_room = :r AND id_creator = :c AND status != 'finished'", [
        ':r' => $roomId,
        ':c' => (int)$creatorId
    ]);
    $player = db_fetch_one('SELECT id_player FROM players WHERE id_room = :r AND id_player = :p', [
        ':r' => $roomId,
        ':p' => (int)$playerId
    ]);
    if (!$currentRoom || !$player) return false;
    return db_execute(
        'UPDATE players SET is_kicked = 1 WHERE id_room = :r AND id_player = :p',
        [':r' => $roomId, ':p' => (int)$playerId]
    ) > 0;
}

function player_request_is_valid($playerId, $roomId, $token) {
    if ((int)($_SESSION['id_player'] ?? 0) === (int)$playerId && (int)($_SESSION['player_room_id'] ?? 0) === (int)$roomId) return true;
    $expected = substr(md5($playerId . '_' . $roomId . '_kahoot_secret_2026'), 0, 16);
    return is_string($token) && hash_equals($expected, $token);
}

function redirect_removed_player($playerId) {
    if ((int)($_SESSION['id_player'] ?? 0) === (int)$playerId) {
        unset($_SESSION['id_player'], $_SESSION['player_room_id'], $_SESSION['player_pin'], $_SESSION['player_nickname']);
    }
    redirect('modules/player/join.php?removed=1');
}

function room_is_locked($room) {
    $roomId = is_array($room) ? (int)($room['id_room'] ?? 0) : (int)$room;
    if ($roomId <= 0) return false;
    $r = db_fetch_one('SELECT is_locked FROM room WHERE id_room = :r LIMIT 1', [':r' => $roomId]);
    return !empty($r['is_locked']);
}

function set_room_locked($room, $isLocked) {
    $roomId = is_array($room) ? (int)($room['id_room'] ?? 0) : (int)$room;
    $pin = is_array($room) ? ($room['pin'] ?? '') : '';
    $creatorId = is_array($room) ? (int)($room['id_creator'] ?? 0) : 0;

    $currentRoom = db_fetch_one(
        "SELECT id_room FROM room WHERE id_room = :r AND pin = :pin AND id_creator = :c AND status = 'waiting'",
        [':r' => $roomId, ':pin' => $pin, ':c' => $creatorId]
    );
    if (!$currentRoom) {
        return false;
    }

    db_execute(
        'UPDATE room SET is_locked = :l WHERE id_room = :r',
        [':l' => $isLocked ? 1 : 0, ':r' => $roomId]
    );
    return true;
}

function room_join_error($room, $isLocked = null) {
    if (!$room || $room['status'] === 'finished') {
        return 'Invalid Game PIN or room has closed!';
    }
    if (!empty($room['is_review_mode'])) {
        $now = time();
        $start = !empty($room['review_start_time']) ? strtotime($room['review_start_time']) : 0;
        $end = !empty($room['review_end_time']) ? strtotime($room['review_end_time']) : PHP_INT_MAX;
        if ($start > 0 && $now < $start) {
            return 'Review session has not opened yet. Starts at: ' . date('M d, Y H:i', $start);
        }
        if ($end > 0 && $now > $end) {
            return 'Review session ended at: ' . date('M d, Y H:i', $end);
        }
    }
    if ($isLocked ?? room_is_locked($room)) {
        return 'This game is locked. Please wait for the host to unlock it.';
    }
    return '';
}

function join_room_player($roomId, $pin, $nickname, $characterId = null, $accessoryId = null) {
    $room = db_fetch_one('SELECT * FROM room WHERE id_room = :r AND pin = :pin', [':r' => $roomId, ':pin' => $pin]);
    if (!$room) {
        return ['success' => false, 'message' => room_join_error($room)];
    }

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $currentRoom = db_fetch_one(
            'SELECT * FROM room WHERE id_room = :r AND pin = :pin FOR UPDATE',
            [':r' => $roomId, ':pin' => $pin]
        );
        $error = room_join_error($currentRoom);
        if ($error !== '') {
            $pdo->rollBack();
            return ['success' => false, 'message' => $error];
        }

        // Trong chế độ multiplayer thường: không cho trùng tên (thêm hậu tố ngẫu nhiên)
        if (empty($currentRoom['is_review_mode'])) {
            $existing = db_fetch_one(
                'SELECT id_player FROM players WHERE id_room = :r AND nickname = :nick LIMIT 1',
                [':r' => $roomId, ':nick' => $nickname]
            );
            if ($existing) {
                $nickname .= '_' . mt_rand(10, 99);
            }
        }
        $playerId = db_insert(
            'INSERT INTO players (id_room, nickname, score, answers_correct, is_kicked, character_id, accessory_id) VALUES (:r, :nick, 0, 0, 0, :char, :acc)',
            [':r' => $roomId, ':nick' => $nickname, ':char' => ($characterId ?: '1500'), ':acc' => ($accessoryId ?: '')]
        );
        $pdo->commit();
        return ['success' => true, 'id_player' => $playerId, 'nickname' => $nickname];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
