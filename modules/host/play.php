<?php

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/room_access.php';
require_once __DIR__ . '/../../includes/player_characters.php';

$roomId = intval($_SESSION['id_room'] ?? 0);
$room = db_fetch_one("SELECT r.*, q.name as quiz_name FROM room r JOIN quiz q ON r.id_quiz = q.id_quiz WHERE r.id_room = :r AND r.id_creator = :c", [':r' => $roomId, ':c' => (int)$_SESSION['id_creator']]);

if (!$room) {
    redirect('modules/auth/dashboard.php');
}

if (!empty($room['is_review_mode']) || $room['status'] === 'review_active') {
    redirect('modules/reports/detail.php?id_room=' . $roomId);
}

$status = $room['status'];

if ($status === 'waiting') {
    redirect('modules/host/lobby.php');
}

if ($status === 'finished') {
    redirect('modules/host/leaderboard.php?final=true');
}

$selectedQIds = !empty($room['question_order']) ? json_decode($room['question_order'], true) : null;
if (!is_array($selectedQIds) || empty($selectedQIds)) {
    $fallbackQuestions = db_fetch_all("SELECT id_question FROM questions WHERE id_quiz = :q ORDER BY id_question ASC", [':q' => $room['id_quiz']]);
    $selectedQIds = array_column($fallbackQuestions, 'id_question');
}
$selectedQIds = array_values(array_filter(array_map('intval', (array)$selectedQIds)));
$totalQuestions = count($selectedQIds);

// CRITICAL: Handle actions FIRST before checking $isResultPhase!
if (isset($_GET['action']) && $_GET['action'] === 'next_question') {
    $currentQId = 0;
    if (isset($_GET['qid']) && intval($_GET['qid']) > 0) {
        $currentQId = intval($_GET['qid']);
    }
    if ($currentQId <= 0) {
        $currentQId = intval(str_replace('res_', '', $status));
    }
    $currentIdx = array_search((int)$currentQId, $selectedQIds, true);
    if ($currentIdx === false) {
        $currentIdx = array_search((int)$currentQId, $selectedQIds);
    }
    if ($currentIdx === false && isset($_SESSION['question_index'])) {
        $currentIdx = intval($_SESSION['question_index']);
    }

    if ($currentIdx !== false && isset($selectedQIds[$currentIdx + 1])) {
        $nextQId = intval($selectedQIds[$currentIdx + 1]);
        $_SESSION['question_index'] = $currentIdx + 1;
        db_execute("UPDATE room SET status = :s, question_start_time = UNIX_TIMESTAMP() WHERE id_room = :r", [
            ':s' => (string)$nextQId,
            ':r' => $roomId
        ]);
        redirect('modules/host/play.php');
    } else {
        db_execute("UPDATE room SET status = 'finished' WHERE id_room = :r", [':r' => $roomId]);
        redirect('modules/host/leaderboard.php?final=true');
    }
}

// When show_result is triggered (either all answered or time is up),
// immediately update status to res_<qid> and go straight to leaderboard
if (isset($_GET['action']) && $_GET['action'] === 'show_result') {
    if (is_numeric($status)) {
        db_execute("UPDATE room SET status = :s WHERE id_room = :r", [
            ':s' => 'res_' . $status,
            ':r' => $roomId
        ]);
        $toQId = intval($status);
    } else {
        $toQId = intval(str_replace('res_', '', $status));
    }
    redirect('modules/host/leaderboard.php?qid=' . $toQId);
}

// If room is in result phase (and no action requested), directly show leaderboard
$isResultPhase = (strpos($status, 'res_') === 0);
if ($isResultPhase) {
    $toQId = intval(str_replace('res_', '', $status));
    redirect('modules/host/leaderboard.php?qid=' . $toQId);
}

$qId = intval($status);

$currentQuestion = db_fetch_one("SELECT * FROM questions WHERE id_question = :qid", [':qid' => $qId]);
$answers = db_fetch_all("SELECT * FROM answers WHERE id_question = :qid ORDER BY id_answer ASC", [':qid' => $qId]);

$questionMediaUrl = '';
$questionMediaType = 'image';
if (!empty($currentQuestion['image'])) {
    if (preg_match('#^https?://#i', $currentQuestion['image'])) {
        $questionMediaUrl = $currentQuestion['image'];
        $ext = strtolower(pathinfo(parse_url($questionMediaUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        $questionMediaType = (in_array($ext, ['mp4', 'webm']) || ($currentQuestion['media_type'] ?? '') === 'video') ? 'video' : 'image';
    } else {
        $imgFilename = basename($currentQuestion['image']);
        $mediaDiskPath = dirname(__DIR__, 2) . '/assets/img/questions/' . $imgFilename;
        if (file_exists($mediaDiskPath)) {
            $questionMediaUrl = BASE_URL . 'assets/img/questions/' . rawurlencode($imgFilename);
            $ext = strtolower(pathinfo($imgFilename, PATHINFO_EXTENSION));
            $questionMediaType = (in_array($ext, ['mp4', 'webm']) || ($currentQuestion['media_type'] ?? '') === 'video') ? 'video' : 'image';
        }
    }
}

$counts = room_question_counts($room, $qId);
$totalPlayers = $counts['total_players'];
$votedCount = $counts['voted_count'];

$timeLimit = intval($currentQuestion['time_limit'] ?? 20);

if (!in_array($timeLimit, [10, 20, 30, 60])) {
    if ($timeLimit <= 10) $timeLimit = 10;
    elseif ($timeLimit <= 20) $timeLimit = 20;
    elseif ($timeLimit <= 30) $timeLimit = 30;
    else $timeLimit = 60;
}

$startTime = !empty($room['question_start_time']) ? intval($room['question_start_time']) : time();
$elapsed = time() - $startTime;
$remainingSeconds = max(0, $timeLimit - $elapsed);

$currentIdx = array_search((int)$qId, $selectedQIds, true);
$questionNumber = ($currentIdx !== false) ? ($currentIdx + 1) : 1;

$questionAudioFile = null;
$audioDir = __DIR__ . '/../../assets/audio';
if (is_dir($audioDir)) {
    $searchKey = $timeLimit . ' Second';
    $audioFiles = scandir($audioDir);
    $matchedAudios = [];
    foreach ($audioFiles as $af) {
        if ($af === '.' || $af === '..') continue;
        if (stripos($af, $searchKey) !== false && preg_match('/\.(mp3|wav|ogg)$/i', $af)) {
            $matchedAudios[] = $af;
        }
    }
    if (!empty($matchedAudios)) {
        $questionAudioFile = $matchedAudios[($questionNumber - 1) % count($matchedAudios)];
    }
}

$colors = [KAHOOT_COLOR_RED, KAHOOT_COLOR_BLUE, KAHOOT_COLOR_YELLOW, KAHOOT_COLOR_GREEN];
$icons  = [KAHOOT_ICON_RED, KAHOOT_ICON_BLUE, KAHOOT_ICON_YELLOW, KAHOOT_ICON_GREEN];

$pageTitle = "Question " . $questionNumber . "/" . $totalQuestions . " - Kahoot! Host";
$bodyClass = "backgroundColorChanges";
require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($questionAudioFile): ?>
<audio id="questionAudio" preload="auto">
    <source src="<?= BASE_URL ?>assets/audio/<?= rawurlencode($questionAudioFile) ?>" type="audio/mpeg">
</audio>
<?php endif; ?>

<nav class="navbar navbar-dark bg-dark px-4 py-2 d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center">
        <img src="<?= BASE_URL ?>assets/img/logo.png" alt="Logo" style="max-height: 32px; filter: brightness(100);" class="mr-3">
        <span class="badge badge-light font-weight-bold mr-2">PIN: <?= htmlspecialchars($room['pin']) ?></span>
        <span class="text-white font-weight-bold">Question <?= $questionNumber ?> / <?= $totalQuestions ?></span>
    </div>
    <div class="d-flex align-items-center">
        <?php if ($questionAudioFile): ?>
            <button type="button" id="audioToggleBtn" class="btn btn-sm btn-light font-weight-bold shadow-sm rounded-pill mr-2 px-3" onclick="toggleQuestionAudio()" title="Toggle countdown music">
                <i class="fas fa-volume-up text-success mr-1" id="audioIcon"></i>
                <span id="audioLabel">Music: ON</span>
            </button>
        <?php endif; ?>

        <a href="play.php?action=show_result" onclick="stopQuestionAudio()" class="btn btn-outline-light font-weight-bold">
            Skip to Leaderboard &raquo;
        </a>
    </div>
</nav>

<div class="container my-3" style="max-width: 1050px;">

    <details class="text-white mb-3">
        <summary class="font-weight-bold">Manage players</summary>
        <div class="text-center mt-3"><?php $reloadAfterKick = true; require __DIR__ . '/../../includes/host_players.php'; ?></div>
    </details>

    <div class="question-card-banner">
        <h2 class="font-weight-bold text-dark mb-0">
            <?= htmlspecialchars($currentQuestion['text']) ?>
        </h2>
        <?php if (!empty($questionMediaUrl)): ?>
            <div class="text-center mt-3">
                <?php if ($questionMediaType === 'video'): ?>
                    <video src="<?= htmlspecialchars($questionMediaUrl) ?>" controls autoplay muted loop class="img-fluid rounded shadow-sm" style="max-height: 280px; max-width: 100%; border-radius: 12px;"></video>
                <?php else: ?>
                    <img src="<?= htmlspecialchars($questionMediaUrl) ?>" alt="Question Media" class="img-fluid rounded shadow-sm" style="max-height: 280px; max-width: 100%; object-fit: contain; border-radius: 12px;">
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="d-flex justify-content-between align-items-center my-3 px-3">
        <div>
            <div id="timerBox" class="timer-circle <?= $remainingSeconds <= 5 ? 'warning' : '' ?>">
                <span id="timerSeconds"><?= $remainingSeconds ?></span>
            </div>
        </div>

        <div class="text-center">
            <div class="bg-white rounded-pill px-4 py-2 shadow-sm font-weight-bold text-dark" style="font-size: 1.2rem;">
                <i class="fas fa-paper-plane text-primary mr-1"></i>
                <span id="voteCount"><?= $votedCount ?></span> / <span id="totalPlayerCount"><?= $totalPlayers ?></span> Answers
            </div>
        </div>
    </div>

    <div class="row">
        <?php foreach ($answers as $idx => $a):
            $cIdx = $idx % 4;
            $ansImgUrl = '';
            if (!empty($a['image_path'])) {
                if (preg_match('#^https?://#i', $a['image_path'])) {
                    $ansImgUrl = $a['image_path'];
                } else {
                    $aImgFile = basename($a['image_path']);
                    $aImgDiskPath = dirname(__DIR__, 2) . '/assets/img/questions/' . $aImgFile;
                    if (file_exists($aImgDiskPath)) {
                        $ansImgUrl = BASE_URL . 'assets/img/questions/' . rawurlencode($aImgFile);
                    }
                }
            }
        ?>
            <div class="col-md-6 mb-3">
                <div class="answer-box shadow-sm d-flex justify-content-between align-items-center"
                     style="background-color: <?= $colors[$cIdx] ?>; cursor: default;">
                    <div class="d-flex align-items-center flex-grow-1 mr-2">
                        <span class="symbol mr-3" style="font-size: 2rem;"><?= $icons[$cIdx] ?></span>
                        <?php if ($ansImgUrl): ?>
                            <img src="<?= htmlspecialchars($ansImgUrl) ?>" alt="Answer" class="img-fluid rounded mr-3 bg-white p-1" style="max-height: 55px; max-width: 85px; object-fit: contain; box-shadow: 0 2px 4px rgba(0,0,0,0.15);">
                        <?php endif; ?>
                        <?php if (!empty($a['text'])): ?>
                            <span style="font-size: 1.3rem;"><?= htmlspecialchars($a['text']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script>
const qAudio = document.getElementById('questionAudio');
let isAudioPlaying = false;

function playQuestionAudio() {
    if (!qAudio) return;

    const elapsed = <?= $elapsed ?>;
    const timeLimit = <?= $timeLimit ?>;
    if (elapsed > 0 && elapsed < timeLimit) {
        try {
            qAudio.currentTime = elapsed;
        } catch(e) {}
    }

    qAudio.play().then(function() {
        isAudioPlaying = true;
        updateAudioUI(true);
    }).catch(function() {
        isAudioPlaying = false;
        updateAudioUI(false);

        $(document).one('click keydown', function() {
            if (qAudio && qAudio.paused) {
                try {
                    qAudio.currentTime = Math.max(0, <?= $timeLimit ?> - remaining);
                } catch(e) {}
                qAudio.play().then(function() {
                    isAudioPlaying = true;
                    updateAudioUI(true);
                }).catch(function() {});
            }
        });
    });
}

function toggleQuestionAudio() {
    if (!qAudio) return;
    if (qAudio.paused) {
        qAudio.play().then(function() {
            isAudioPlaying = true;
            updateAudioUI(true);
        }).catch(function(e) {
            console.log('Audio error:', e);
        });
    } else {
        qAudio.pause();
        isAudioPlaying = false;
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
        btn.className = 'btn btn-sm btn-light font-weight-bold shadow-sm rounded-pill mr-2 px-3';
    } else {
        icon.className = 'fas fa-volume-mute text-danger mr-1';
        label.textContent = 'Music: OFF';
        btn.className = 'btn btn-sm btn-outline-light font-weight-bold shadow-sm rounded-pill mr-2 px-3';
    }
}

function stopQuestionAudio() {
    if (qAudio) {
        try {
            qAudio.pause();
            qAudio.currentTime = 0;
        } catch(e) {}
    }
}

$(document).ready(function() {
    playQuestionAudio();
});

let remaining = <?= $remainingSeconds ?>;
const roomId = <?= $roomId ?>;
const qId = <?= $qId ?>;

// Countdown timer: when time runs out, auto-transition immediately to leaderboard
const timerInterval = setInterval(function() {
    remaining--;
    if (remaining <= 0) {
        clearInterval(timerInterval);
        clearInterval(pollInterval);
        stopQuestionAudio();
        $('#timerSeconds').text('0');

        window.location.href = 'play.php?action=show_result';
    } else {
        $('#timerSeconds').text(remaining);
        if (remaining <= 5) {
            $('#timerBox').addClass('warning');
        }
    }
}, 1000);

// Polling: when ALL participants have answered, immediately transition to leaderboard
const pollInterval = setInterval(function() {
    $.getJSON('<?= BASE_URL ?>modules/api/game_state.php?action=get_question_status&id_room=' + roomId + '&id_question=' + qId, function(data) {
        if (data && data.success) {
            $('#voteCount').text(data.voted_count);
            $('#totalPlayerCount').text(data.total_players);

            if (data.total_players > 0 && data.voted_count >= data.total_players) {
                clearInterval(pollInterval);
                clearInterval(timerInterval);
                stopQuestionAudio();
                window.location.href = 'play.php?action=show_result';
            }
        }
    });
}, 800);
</script>
</body>
</html>
