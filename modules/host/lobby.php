<?php

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/room_access.php';
require_once __DIR__ . '/../../includes/player_characters.php';

$roomId = intval($_SESSION['id_room'] ?? 0);
$creatorId = intval($_SESSION['id_creator']);
$room = db_fetch_one("SELECT r.*, q.name as quiz_name FROM room r JOIN quiz q ON r.id_quiz = q.id_quiz WHERE r.id_room = :r AND r.id_creator = :c", [':r' => $roomId, ':c' => $creatorId]);

if (!$room || $room['status'] === 'finished') {
    set_flash_message('danger', 'Game room does not exist or has already finished!');
    redirect('modules/auth/dashboard.php');
}

if (!empty($room['is_review_mode']) || $room['status'] === 'review_active') {
    redirect('modules/reports/detail.php?id_room=' . $roomId);
}

if ($room['status'] !== 'waiting') {
    redirect('modules/host/play.php');
}

if (empty($_SESSION['room_lock_token'])) {
    $_SESSION['room_lock_token'] = bin2hex(random_bytes(32));
}
$roomLockError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_room_lock'])) {
    $token = $_POST['room_lock_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['room_lock_token'], $token)) {
        http_response_code(403);
        $roomLockError = 'Your request could not be verified. Refresh the page and try again.';
    } elseif (($_POST['lock_room_id'] ?? '') !== (string)$roomId || !in_array($_POST['set_room_lock'], ['0', '1'], true)) {
        http_response_code(400);
        $roomLockError = 'Invalid room lock setting.';
    } else {
        set_room_locked($room, $_POST['set_room_lock'] === '1');
        redirect('modules/host/lobby.php');
    }
}
$isLocked = room_is_locked($room);
$questions = db_fetch_all("SELECT id_question FROM questions WHERE id_quiz = :q ORDER BY id_question ASC", [':q' => $room['id_quiz']]);
$totalQuizQuestions = count($questions);

if (isset($_GET['start_game'])) {
    if ($totalQuizQuestions > 0) {
        $allQIds = array_column($questions, 'id_question');

        if (isset($_GET['show_questions'])) {
            $showQ = intval($_GET['show_questions']) ? 1 : 0;
            db_execute("UPDATE room SET show_questions_on_players = :sq WHERE id_room = :r", [
                ':sq' => $showQ,
                ':r'  => $roomId
            ]);
        }

        $mode = $_GET['question_mode'] ?? 'all';
        $n = isset($_GET['question_limit']) ? intval($_GET['question_limit']) : $totalQuizQuestions;
        $selectedQIds = $allQIds;
        $savedLimit = null;

        // Nếu Host chọn bốc N câu hợp lệ (1 <= N < Tổng số câu)
        if ($mode === 'random' && $n > 0 && $n < $totalQuizQuestions) {
            shuffle($allQIds);
            $selectedQIds = array_slice($allQIds, 0, $n);
            $savedLimit = $n;
        } elseif ($mode === 'random' && $n >= $totalQuizQuestions) {
            // N >= tổng số câu: bốc tất cả các câu xáo trộn
            shuffle($allQIds);
            $selectedQIds = $allQIds;
            $savedLimit = $totalQuizQuestions;
        } else {
            // Chế độ 'all' hoặc N <= 0: Chơi toàn bộ câu hỏi (mặc định)
            $selectedQIds = $allQIds;
            $savedLimit = null;
        }

        $firstQId = $selectedQIds[0];
        db_execute(
            "UPDATE room SET status = :s, question_start_time = UNIX_TIMESTAMP(), question_limit = :ql, question_order = :qo WHERE id_room = :r",
            [
                ':s'  => (string)$firstQId,
                ':ql' => $savedLimit,
                ':qo' => json_encode(array_values($selectedQIds)),
                ':r'  => $roomId
            ]
        );
        $_SESSION['question_index'] = 0;
        redirect('modules/host/play.php');
    } else {
        set_flash_message('danger', 'Bài trắc nghiệm này chưa có câu hỏi nào!');
        redirect('modules/auth/dashboard.php');
    }
}

if (isset($_GET['cancel_game'])) {
    db_execute("UPDATE room SET status = 'finished' WHERE id_room = :r", [':r' => $roomId]);
    if (!empty($room['is_review_mode'])) {
        db_execute("UPDATE quiz SET is_review_mode = 0 WHERE id_quiz = :q", [':q' => $room['id_quiz']]);
        unset($_SESSION['id_room'], $_SESSION['pin'], $_SESSION['id_quiz']);
        set_flash_message('info', 'Review session has been stopped.');
        redirect('modules/quiz/list.php');
    }
    unset($_SESSION['id_room'], $_SESSION['pin'], $_SESSION['id_quiz']);
    set_flash_message('info', 'Game room has been cancelled!');
    redirect('modules/auth/dashboard.php');
}

$players = filter_room_players($roomId, db_fetch_all("SELECT * FROM players WHERE id_room = :r ORDER BY id_player ASC", [':r' => $roomId]));
$joinPageUrl = BASE_URL . 'modules/player/join.php';
$joinUrl = $joinPageUrl . '?pin=' . rawurlencode($room['pin']);
$joinHost = strtolower(parse_url(BASE_URL, PHP_URL_HOST) ?? '');
$isLoopbackHost = in_array($joinHost, ['localhost', '127.0.0.1', '[::1]', '::1'], true) || substr($joinHost, -10) === '.localhost';

$showOnPlayers = !empty($room['show_questions_on_players']);

$pageTitle = "Lobby - Game PIN: " . htmlspecialchars($room['pin']);
$bodyClass = "backgroundColorChanges";
require_once __DIR__ . '/../../includes/header.php';
?>

<audio id="lobbyAudio" loop preload="auto">
    <source src="<?= BASE_URL ?>assets/audio/lobby.mp3" type="audio/mpeg">
    <source src="<?= BASE_URL ?>assets/audio/lobby.wav" type="audio/wav">
    <source src="<?= BASE_URL ?>assets/audio/lobby.ogg" type="audio/ogg">
</audio>

<nav class="navbar navbar-dark bg-dark px-4 py-2 d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center">
        <img src="<?= BASE_URL ?>assets/img/logo.png" alt="Logo" style="max-height: 32px; filter: brightness(100);" class="mr-3">
        <span class="text-white font-weight-bold" style="font-size: 1.1rem;"><?= htmlspecialchars($room['quiz_name']) ?></span>
    </div>
    <div class="d-flex align-items-center" style="gap: 12px;">

        <button type="button" class="btn btn-sm btn-outline-light font-weight-bold shadow-sm rounded-pill px-3" data-toggle="modal" data-target="#gameSettingsModal" title="Cài đặt phòng chơi">
            <i class="fas fa-cog mr-1"></i> Cài đặt
        </button>

        <button type="button" id="audioToggleBtn" class="btn btn-sm btn-light font-weight-bold shadow-sm rounded-pill px-3" onclick="toggleAudio()" title="Toggle lobby music">
            <i class="fas fa-volume-up text-success mr-1" id="audioIcon"></i>
            <span id="audioLabel">Music: ON</span>
        </button>

        <a href="lobby.php?cancel_game=true" class="btn btn-sm btn-outline-danger font-weight-bold" onclick="return confirm('Are you sure you want to cancel this game room?');">
            <i class="fas fa-times mr-1"></i> Cancel Game
        </a>
    </div>
</nav>

<style>

.lobby-pin-card {
    background: #ffffff;
    border-radius: 20px;
    padding: 25px 40px;
    max-width: 900px;
    width: 90%;
    margin: 20px auto;
    box-shadow: 0 15px 35px rgba(0,0,0,0.3);
    text-align: center;
    box-sizing: border-box;
}
.lobby-join-options {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 32px;
}
.lobby-pin-details { flex: 1; min-width: 0; }
.lobby-join-address { overflow-wrap: anywhere; }
.lobby-qr-panel { border-left: 2px solid #eee; padding-left: 32px; }
.lobby-qr-button {
    display: block;
    padding: 0;
    margin: 0 auto;
    border: 0;
    border-radius: 8px;
    background: #fff;
    cursor: zoom-in;
    width: 208px;
    max-width: 100%;
}
.lobby-qr-button:focus-visible { outline: 3px solid #1368ce; outline-offset: 4px; }
.lobby-qr-code svg { display: block; width: 100%; height: auto; }
.lobby-qr-dialog {
    width: min(90vw, 560px);
    max-height: 90vh;
    overflow: auto;
    border: 0;
    border-radius: 20px;
    padding: 24px;
    text-align: center;
    color: #212529;
}
.lobby-qr-dialog::backdrop { background: rgba(0, 0, 0, 0.75); }
.lobby-qr-dialog .lobby-qr-code { max-width: 440px; margin: auto; }
@media (max-width: 900px) {
    .lobby-pin-card { padding: 24px 16px; }
    .lobby-join-options { flex-direction: column; gap: 20px; }
    .lobby-qr-panel { border-left: 0; border-top: 2px solid #eee; padding: 20px 0 0; width: 100%; }
}
.lobby-pin-number {
    font-family: 'Montserrat', sans-serif;
    font-weight: 900;
    font-size: clamp(3rem, 7.5vw, 4.8rem);
    letter-spacing: 10px;
    margin-right: -10px;
    line-height: 1.1;
    color: #212529;
    user-select: all;
    display: inline-block;
    word-break: keep-all;
    white-space: nowrap;
}
@media (max-width: 600px) {
    .lobby-pin-number {
        font-size: clamp(2rem, 11vw, 3rem);
        letter-spacing: 6px;
        margin-right: -6px;
    }
}
</style>

<div class="container-fluid text-center text-white py-4">

    <div class="lobby-pin-card mx-auto text-dark">
        <div class="lobby-join-options" id="lobbyJoin" data-join-url="<?= htmlspecialchars($joinUrl, ENT_QUOTES, 'UTF-8') ?>">
            <div class="lobby-pin-details">
                <p class="font-weight-bold mb-2">Join at</p>
                <a class="lobby-join-address" href="<?= htmlspecialchars($joinPageUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars(preg_replace('#^https?://#', '', $joinPageUrl)) ?></a>
                <p class="text-muted font-weight-bold text-uppercase mt-3 mb-2" style="letter-spacing: 3px; font-size: 0.95rem;">GAME PIN</p>
                <div class="lobby-pin-number"><?= htmlspecialchars($room['pin']) ?></div>
                <div class="mt-3">
                    <button type="button" id="copyJoinLink" class="btn btn-outline-dark font-weight-bold" hidden>
                        <i class="fas fa-link mr-1" aria-hidden="true"></i> Copy join link
                    </button>
                    <p id="joinLinkStatus" class="small text-muted mt-2 mb-0" role="status" aria-live="polite"></p>
                    <input type="text" id="joinLinkFallback" class="form-control mt-2" aria-label="Join link: select and copy" value="<?= htmlspecialchars($joinUrl, ENT_QUOTES, 'UTF-8') ?>" readonly hidden>
                </div>
            </div>
            <div class="lobby-qr-panel">
                <p class="font-weight-bold mb-1">Or scan to join</p>
                <button type="button" id="enlargeJoinQr" class="lobby-qr-button" aria-label="Enlarge the QR code to join this game" aria-haspopup="dialog" aria-controls="joinQrDialog" disabled>
                    <span id="joinQrCode" class="lobby-qr-code"><span class="small text-muted">Loading QR code...</span></span>
                </button>
                <p id="joinQrHint" class="small text-muted mb-0">Scan with your phone camera</p>
                <noscript><p class="small text-danger">Enable JavaScript to display the QR code, or join using the PIN.</p></noscript>
            </div>
        </div>
        <?php if ($isLoopbackHost): ?>
            <div class="alert alert-warning small text-left mt-3 mb-0" role="note">
                <strong>Joining from a phone?</strong> Connect to the same Wi-Fi and open this lobby using your computer's LAN IP instead of localhost (for example, http://192.168.1.10/Kahootify/). The QR code will then use that address.
            </div>
        <?php endif; ?>
        <form action="<?= BASE_URL ?>modules/host/lobby.php" method="POST" class="mt-3">
            <input type="hidden" name="room_lock_token" value="<?= htmlspecialchars($_SESSION['room_lock_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="lock_room_id" value="<?= $roomId ?>">
            <button type="submit" name="set_room_lock" value="<?= $isLocked ? '0' : '1' ?>" id="roomLockButton" class="btn <?= $isLocked ? 'btn-warning' : 'btn-outline-dark' ?> font-weight-bold" aria-describedby="roomLockState">
                <i id="roomLockIcon" class="fas <?= $isLocked ? 'fa-lock' : 'fa-lock-open' ?> mr-1" aria-hidden="true"></i>
                <span id="roomLockLabel"><?= $isLocked ? 'Unlock room' : 'Lock room' ?></span>
            </button>
        </form>
        <p id="roomLockState" class="small font-weight-bold mt-2 mb-0 <?= $isLocked ? 'text-danger' : 'text-muted' ?>" role="status" aria-live="polite"><?= $isLocked ? 'Room locked. New players cannot join.' : 'Room open. Players can join using the Game PIN or a join link.' ?></p>
        <?php if ($roomLockError !== ''): ?>
            <div class="alert alert-danger small mt-3 mb-0" role="alert"><?= htmlspecialchars($roomLockError) ?></div>
        <?php endif; ?>
    </div>

    <dialog id="joinQrDialog" class="lobby-qr-dialog" aria-labelledby="joinQrTitle">
        <form method="dialog" class="text-right"><button type="submit" class="btn btn-outline-dark btn-sm" aria-label="Close enlarged QR code">Close <span aria-hidden="true">&times;</span></button></form>
        <h2 id="joinQrTitle" class="h4 font-weight-bold mt-2">Scan to join</h2>
        <div id="joinQrCodeLarge" class="lobby-qr-code"></div>
        <p class="font-weight-bold mt-2 mb-0">Game PIN: <?= htmlspecialchars($room['pin']) ?></p>
    </dialog>

    <?php if (!empty($room['is_review_mode'])): ?>
        <div class="lobby-review-card mx-auto mb-4 p-4 text-center text-dark shadow" style="max-width: 650px; background: #ffffff; border-radius: 20px;">
            <span class="badge badge-success px-3 py-2 font-weight-bold mb-2" style="font-size: 13px; border-radius: 20px;">
                <i class="fas fa-book-reader mr-1"></i> Review Mode (Active)
            </span>
            <h4 class="font-weight-bold mt-2" style="color: #46178f;"><?= htmlspecialchars($room['quiz_name'] ?? 'Kahootify') ?></h4>
            <p class="text-muted small mb-3">
                Students can take this quiz independently at their own pace using the PIN or link without the host starting the session.
            </p>

            <?php if (!empty($room['review_start_time']) || !empty($room['review_end_time'])): ?>
                <div class="p-2 mb-3 rounded font-weight-bold small" style="background: #f1f5f9; color: #334155;">
                    <i class="far fa-clock mr-1 text-primary"></i> Duration: 
                    <span><?= !empty($room['review_start_time']) ? date('M d, Y H:i', strtotime($room['review_start_time'])) : 'Immediate' ?></span>
                    &rarr;
                    <span><?= !empty($room['review_end_time']) ? date('M d, Y H:i', strtotime($room['review_end_time'])) : 'No end time' ?></span>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-center align-items-center flex-wrap" style="gap: 12px;">
                <a href="<?= BASE_URL ?>modules/quiz/list.php" class="btn btn-outline-secondary font-weight-bold px-4 py-2" style="border-radius: 30px;">
                    <i class="fas fa-arrow-left mr-1"></i> Back to My Kahoots
                </a>
                <a href="lobby.php?cancel_game=true" class="btn btn-outline-danger font-weight-bold px-4 py-2" style="border-radius: 30px;" onclick="return confirm('Are you sure you want to stop this review session?');">
                    <i class="fas fa-stop-circle mr-1"></i> Stop Session
                </a>
            </div>
        </div>

        <div class="d-flex justify-content-center align-items-center my-4" style="gap: 20px;">
            <div class="bg-dark px-4 py-2 rounded-pill shadow">
                <i class="fas fa-users text-success mr-2"></i>
                <span class="font-weight-bold" style="font-size: 1.2rem;">
                    <span id="playerCount"><?= count($players) ?></span> participants
                </span>
            </div>
        </div>
    <?php else: ?>
        <div class="lobby-settings-bar mx-auto mb-3" style="max-width: 540px; width: 90%;">
            <div class="d-flex justify-content-end align-items-center">
                <div class="lobby-device-toggle-pill shadow d-inline-flex align-items-center" style="background: #ffffff; border-radius: 50px; padding: 10px 22px; border: 2px solid #ffffff;">
                    <label for="toggleShowQuestions" class="mb-0 mr-3 text-dark font-weight-bold" style="font-family: 'Montserrat', sans-serif; font-size: 0.98rem; font-weight: 800; color: #111111; cursor: pointer; user-select: none;">
                        Show questions &amp; answers on player devices:
                    </label>
                    <span id="settingStatusLabel" class="badge <?= $showOnPlayers ? 'badge-success' : 'badge-secondary' ?> font-weight-bold mr-3 px-2 py-1" style="font-size: 0.85rem; letter-spacing: 0.5px; cursor: pointer;" onclick="$('#toggleShowQuestions').click()" title="Click to toggle">
                        <?= $showOnPlayers ? 'ON' : 'OFF' ?>
                    </span>
                    <div class="custom-control custom-switch" style="transform: scale(1.3); transform-origin: center center;">
                        <input type="checkbox" class="custom-control-input" id="toggleShowQuestions" <?= $showOnPlayers ? 'checked' : '' ?> onchange="updateShowQuestions(this.checked)">
                        <label class="custom-control-label" for="toggleShowQuestions" style="cursor: pointer;"></label>
                    </div>
                </div>
            </div>
        </div>

        <div class="lobby-settings-bar mx-auto mb-4" style="max-width: 540px; width: 90%;">
            <div class="lobby-device-toggle-pill shadow d-flex flex-column text-dark" style="background: #ffffff; border-radius: 18px; padding: 14px 22px; border: 2px solid #ffffff;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="font-weight-bold" style="font-family: 'Montserrat', sans-serif; font-size: 0.98rem; font-weight: 800; color: #111111;">
                        <i class="fas fa-random text-primary mr-1"></i> Questions per game:
                    </span>
                    <span id="lobbyModeStatusBadge" class="badge badge-primary font-weight-bold px-2 py-1" style="font-size: 0.85rem;">
                        All (<?= $totalQuizQuestions ?> questions)
                    </span>
                </div>

                <div class="d-flex flex-column text-left" style="gap: 8px;">
                    <div class="custom-control custom-radio">
                        <input type="radio" id="lobbyModeAll" name="lobby_question_mode" class="custom-control-input" value="all" checked onchange="syncQuestionSettings('lobby')">
                        <label class="custom-control-label font-weight-bold text-dark" for="lobbyModeAll" style="cursor: pointer; font-size: 0.92rem;">
                            Play all questions (<span class="text-primary font-weight-bold"><?= $totalQuizQuestions ?> questions</span>)
                        </label>
                    </div>
                    <div class="custom-control custom-radio d-flex align-items-center flex-wrap">
                        <input type="radio" id="lobbyModeRandom" name="lobby_question_mode" class="custom-control-input" value="random" onchange="syncQuestionSettings('lobby')">
                        <label class="custom-control-label font-weight-bold text-dark mr-2 mb-0" for="lobbyModeRandom" style="cursor: pointer; font-size: 0.92rem;">
                            Random selection
                        </label>
                        <div class="d-inline-flex align-items-center" style="gap: 6px;">
                            <input type="number" id="lobbyQuestionLimit" class="form-control form-control-sm text-center font-weight-bold text-dark" 
                                   style="width: 75px; height: 32px; border-radius: 8px; border: 2px solid #ced4da;" 
                                   min="1" max="<?= $totalQuizQuestions ?>" 
                                   value="<?= min(5, $totalQuizQuestions) ?>" 
                                   onchange="onLobbyLimitInput()" oninput="onLobbyLimitInput()" onclick="$('#lobbyModeRandom').prop('checked', true); syncQuestionSettings('lobby');">
                            <span class="font-weight-bold text-muted small">/ <?= $totalQuizQuestions ?> questions in pool</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-center align-items-center my-4" style="gap: 20px;">
            <div class="bg-dark px-4 py-2 rounded-pill shadow">
                <i class="fas fa-users text-success mr-2"></i>
                <span class="font-weight-bold" style="font-size: 1.2rem;">
                    <span id="playerCount"><?= count($players) ?></span> players
                </span>
            </div>

            <a href="lobby.php?start_game=true&show_questions=<?= $showOnPlayers ? '1' : '0' ?>" id="btnStartGame" class="btn btn-kahoot btn-kahoot-green btn-lg px-5 py-3 shadow" style="<?= count($players) === 0 ? 'pointer-events: none; opacity: 0.6;' : '' ?>">
                <i class="fas fa-play mr-2"></i> START GAME
            </a>
        </div>
    <?php endif; ?>

    <div class="container mt-4" style="max-width: 900px;">
        <h5 class="font-weight-bold mb-3" style="text-shadow: 1px 1px 3px rgba(0,0,0,0.5);">
            Waiting for players to join:
        </h5>
        <?php require __DIR__ . '/../../includes/host_players.php'; ?>
    </div>
</div>

<div class="modal fade text-dark" id="gameSettingsModal" tabindex="-1" role="dialog" aria-labelledby="settingsModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header text-white" style="background-color: var(--kahoot-purple-dark);">
                <h5 class="modal-title font-weight-bold" id="settingsModalTitle">
                    <i class="fas fa-sliders-h mr-2"></i>Game Room Settings
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4 text-left">
                <div class="p-3 mb-3 rounded" style="background-color: #f8f9fa; border: 1px solid #e9ecef;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="font-weight-bold mb-0" style="font-family: 'Montserrat', sans-serif; font-weight: 800; color: #111111;">
                            <i class="fas fa-random text-primary mr-2"></i>Questions Per Game
                        </h6>
                        <span id="modalModeStatusBadge" class="badge badge-primary font-weight-bold px-2 py-1" style="font-size: 0.85rem;">
                            All (<?= $totalQuizQuestions ?> questions)
                        </span>
                    </div>
                    <p class="text-muted small mb-3">
                        Total questions available: <b><?= $totalQuizQuestions ?> questions</b>. You can play all questions or randomly select N questions for this round.
                    </p>

                    <div class="custom-control custom-radio mb-2">
                        <input type="radio" id="modalModeAll" name="modal_question_mode" class="custom-control-input" value="all" checked onchange="syncQuestionSettings('modal')">
                        <label class="custom-control-label font-weight-bold text-dark" for="modalModeAll" style="cursor: pointer;">
                            Play all questions (<span class="text-primary font-weight-bold"><?= $totalQuizQuestions ?> questions</span>)
                        </label>
                    </div>

                    <div class="custom-control custom-radio d-flex align-items-center flex-wrap">
                        <input type="radio" id="modalModeRandom" name="modal_question_mode" class="custom-control-input" value="random" onchange="syncQuestionSettings('modal')">
                        <label class="custom-control-label font-weight-bold text-dark mr-2 mb-0" for="modalModeRandom" style="cursor: pointer;">
                            Random selection
                        </label>
                        <div class="d-inline-flex align-items-center" style="gap: 6px;">
                            <input type="number" id="modalQuestionLimit" class="form-control form-control-sm text-center font-weight-bold text-dark" 
                                   style="width: 80px; height: 32px; border-radius: 8px; border: 2px solid #ced4da;" 
                                   min="1" max="<?= $totalQuizQuestions ?>" 
                                   value="<?= min(5, $totalQuizQuestions) ?>" 
                                   onchange="onModalLimitChange()" oninput="onModalLimitChange()" onclick="$('#modalModeRandom').prop('checked', true); syncQuestionSettings('modal');">
                            <span class="font-weight-bold text-muted small">/ <?= $totalQuizQuestions ?> questions</span>
                        </div>
                    </div>
                </div>

                <div class="p-3 mb-3 rounded" style="background-color: #f8f9fa; border: 1px solid #e9ecef;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="pr-3">
                            <h6 class="font-weight-bold mb-1" style="font-family: 'Montserrat', sans-serif; font-weight: 800; color: #111111;">
                                Show questions &amp; answers on player devices
                            </h6>
                            <p class="text-muted small mb-0">
                                Useful for remote learning or large screens. When enabled, player devices show full question text, media, and button labels.
                            </p>
                        </div>
                        <div class="d-flex align-items-center">
                            <span id="modalSettingStatusLabel" class="badge <?= $showOnPlayers ? 'badge-success' : 'badge-secondary' ?> font-weight-bold mr-2 px-2 py-1" style="font-size: 0.85rem; cursor: pointer;" onclick="$('#modalToggleShowQuestions').click()" title="Click to toggle">
                                <?= $showOnPlayers ? 'ON' : 'OFF' ?>
                            </span>
                            <div class="custom-control custom-switch pl-4" style="transform: scale(1.3); transform-origin: right center;">
                                <input type="checkbox" class="custom-control-input" id="modalToggleShowQuestions" <?= $showOnPlayers ? 'checked' : '' ?> onchange="updateShowQuestions(this.checked)">
                                <label class="custom-control-label" for="modalToggleShowQuestions" style="cursor: pointer;"></label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-primary font-weight-bold rounded-pill px-4" data-dismiss="modal">Done</button>
            </div>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>assets/js/qrcodegen.js"></script>
<script src="<?= BASE_URL ?>assets/js/lobby-join.js"></script>
<script src="<?= BASE_URL ?>assets/js/host-players.js"></script>
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<script>

const audio = document.getElementById('lobbyAudio');

function tryPlayAudio() {
    if (!audio) return;
    audio.play().then(function() {
        updateAudioUI(true);
    }).catch(function() {

        updateAudioUI(false);

        $(document).one('click keydown', function() {
            audio.play().then(function() {
                updateAudioUI(true);
            }).catch(function() {});
        });
    });
}

function toggleAudio() {
    if (!audio) return;
    if (audio.paused) {
        audio.play().then(function() {
            updateAudioUI(true);
        }).catch(function(e) {
            console.log('Không thể phát nhạc:', e);
        });
    } else {
        audio.pause();
        updateAudioUI(false);
    }
}

function updateAudioUI(isPlaying) {
    const icon = document.getElementById('audioIcon');
    const label = document.getElementById('audioLabel');
    const btn = document.getElementById('audioToggleBtn');
    if (!icon || !label || !btn) return;
    if (isPlaying) {
        icon.className = 'fas fa-volume-up text-success mr-1';
        label.textContent = 'Music: ON';
        btn.className = 'btn btn-sm btn-light font-weight-bold shadow-sm rounded-pill px-3';
    } else {
        icon.className = 'fas fa-volume-mute text-danger mr-1';
        label.textContent = 'Music: OFF';
        btn.className = 'btn btn-sm btn-outline-light font-weight-bold shadow-sm rounded-pill px-3';
    }
}

const roomId = <?= $roomId ?>;
let currentShowQuestions = <?= $showOnPlayers ? 1 : 0 ?>;
const totalQuizQuestions = <?= $totalQuizQuestions ?>;

function updateShowQuestions(checked) {
    currentShowQuestions = checked ? 1 : 0;

    $('#toggleShowQuestions').prop('checked', checked);
    $('#modalToggleShowQuestions').prop('checked', checked);

    updateStartGameHref();

    const label = $('#settingStatusLabel');
    if (label.length) {
        label.text(checked ? 'ON' : 'OFF')
             .removeClass('badge-success badge-secondary')
             .addClass(checked ? 'badge-success' : 'badge-secondary');
    }

    const modalLabel = $('#modalSettingStatusLabel');
    if (modalLabel.length) {
        modalLabel.text(checked ? 'ON' : 'OFF')
                  .removeClass('badge-success badge-secondary')
                  .addClass(checked ? 'badge-success' : 'badge-secondary');
    }

    $.ajax({
        url: '<?= BASE_URL ?>modules/api/game_state.php',
        type: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            action: 'update_settings',
            id_room: roomId,
            show_questions_on_players: currentShowQuestions
        }),
        error: function(err) {
            console.error('Failed to update room settings:', err);
        }
    });
}

function getSelectedQuestionConfig() {
    const isRandom = $('#lobbyModeRandom').is(':checked') || $('#modalModeRandom').is(':checked');
    let limit = parseInt($('#lobbyQuestionLimit').val()) || totalQuizQuestions;
    if (limit <= 0) limit = 1;
    if (limit > totalQuizQuestions) limit = totalQuizQuestions;
    return {
        mode: isRandom ? 'random' : 'all',
        limit: limit
    };
}

function syncQuestionSettings(source) {
    let mode, limit;
    if (source === 'modal') {
        mode = $('input[name="modal_question_mode"]:checked').val() || 'all';
        limit = parseInt($('#modalQuestionLimit').val()) || totalQuizQuestions;
        if (limit <= 0) limit = 1;
        if (limit > totalQuizQuestions) limit = totalQuizQuestions;

        $('#lobbyModeAll').prop('checked', mode === 'all');
        $('#lobbyModeRandom').prop('checked', mode === 'random');
        $('#lobbyQuestionLimit').val(limit);
    } else {
        mode = $('input[name="lobby_question_mode"]:checked').val() || 'all';
        limit = parseInt($('#lobbyQuestionLimit').val()) || totalQuizQuestions;
        if (limit <= 0) limit = 1;
        if (limit > totalQuizQuestions) limit = totalQuizQuestions;

        $('#modalModeAll').prop('checked', mode === 'all');
        $('#modalModeRandom').prop('checked', mode === 'random');
        $('#modalQuestionLimit').val(limit);
    }

    if (mode === 'random') {
        $('#lobbyModeStatusBadge').text('Bốc ' + limit + ' / ' + totalQuizQuestions + ' câu')
                                  .removeClass('badge-primary').addClass('badge-warning text-dark');
        $('#modalModeStatusBadge').text('Bốc ' + limit + ' / ' + totalQuizQuestions + ' câu')
                                  .removeClass('badge-primary').addClass('badge-warning text-dark');
    } else {
        $('#lobbyModeStatusBadge').text('Tất cả (' + totalQuizQuestions + ' câu)')
                                  .removeClass('badge-warning text-dark').addClass('badge-primary');
        $('#modalModeStatusBadge').text('Tất cả (' + totalQuizQuestions + ' câu)')
                                  .removeClass('badge-warning text-dark').addClass('badge-primary');
    }

    updateStartGameHref();
}

function onLobbyLimitInput() {
    $('#lobbyModeRandom').prop('checked', true);
    syncQuestionSettings('lobby');
}

function onModalLimitChange() {
    $('#modalModeRandom').prop('checked', true);
    syncQuestionSettings('modal');
}

function updateStartGameHref() {
    const config = getSelectedQuestionConfig();
    let url = 'lobby.php?start_game=true&show_questions=' + currentShowQuestions + '&question_mode=' + config.mode;
    if (config.mode === 'random') {
        url += '&question_limit=' + config.limit;
    }
    $('#btnStartGame').attr('href', url);
}

$(document).ready(function() {
    tryPlayAudio();
    updateStartGameHref();
    $('#btnStartGame').on('click', function() {
        updateStartGameHref();
    });
});



document.addEventListener('roomplayerschange', function (event) {
    const data = event.detail;
    updateRoomLockUI(data.is_locked);
    $('#playerCount').text(data.players.length);
    $('#btnStartGame').css(data.players.length > 0
        ? {'pointer-events': 'auto', 'opacity': '1'}
        : {'pointer-events': 'none', 'opacity': '0.6'});
});

function updateRoomLockUI(isLocked) {
    $('#roomLockButton').val(isLocked ? '0' : '1')
        .toggleClass('btn-warning', isLocked).toggleClass('btn-outline-dark', !isLocked);
    $('#roomLockIcon').toggleClass('fa-lock', isLocked).toggleClass('fa-lock-open', !isLocked);
    $('#roomLockLabel').text(isLocked ? 'Unlock room' : 'Lock room');
    $('#roomLockState').text(isLocked
        ? 'Room locked. New players cannot join.'
        : 'Room open. Players can join using the Game PIN or a join link.')
        .toggleClass('text-danger', isLocked).toggleClass('text-muted', !isLocked);
}

</script>
</body>
</html>
