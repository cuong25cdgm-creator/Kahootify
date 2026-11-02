<?php

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/modes/QuestionModeRegistry.php';
require_once __DIR__ . '/../../includes/TeamManager.php';

$creatorId = intval($_SESSION['id_creator']);
$quizId    = intval($_GET['id'] ?? 0);

$quiz = db_fetch_one("SELECT * FROM quiz WHERE id_quiz = :q AND id_creator = :c", [':q' => $quizId, ':c' => $creatorId]);
if (!$quiz) {
    set_flash_message('danger', 'Quiz not found or access denied.');
    redirect('modules/quiz/list.php');
}

function saveUploadedFile($fileKey) {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) return null;
    $f = $_FILES[$fileKey];
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/webm'];
    if (!in_array($f['type'], $allowed)) return null;
    $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $name = 'q_' . uniqid() . '_' . time() . '.' . $ext;
    $dir  = __DIR__ . '/../../assets/img/questions/';
    return move_uploaded_file($f['tmp_name'], $dir . $name) ? 'assets/img/questions/' . $name : null;
}

function detectMediaType($fileKey) {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) return null;
    $mime = $_FILES[$fileKey]['type'];
    if (strpos($mime, 'image/') === 0) return 'image';
    if (strpos($mime, 'video/') === 0) return 'video';
    return null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'apply_mode_to_all') {
        $targetMode = trim($_POST['target_mode'] ?? 'normal');
        $modeObj = QuestionModeRegistry::get($targetMode);
        $supportedTypes = $modeObj->getSupportedQuestionTypes();
        $inTypes = "'" . implode("','", array_map('addslashes', $supportedTypes)) . "'";
        $cfg = ($targetMode === 'wager') ? json_encode($modeObj->getDefaultConfig()) : null;

        db_execute(
            "UPDATE questions SET mode = :m, mode_config = :mc WHERE id_quiz = :qz AND question_type IN ($inTypes)",
            [':m' => $targetMode, ':mc' => $cfg, ':qz' => $quizId]
        );
        set_flash_message('success', "Applied '{$modeObj->getLabel()}' mode to all eligible questions!");
        redirect('modules/quiz/edit.php?id=' . $quizId);
    }

    if ($action === 'update_team_settings') {
        $isTeam = !empty($_POST['is_team_mode']) ? 1 : 0;
        $teamCount = max(2, min(10, intval($_POST['team_count'] ?? 2)));
        $playerLimit = max(0, intval($_POST['team_player_limit'] ?? 0));

        db_execute(
            "UPDATE quiz SET is_team_mode = :tm, team_count = :tc, team_player_limit = :tpl WHERE id_quiz = :q AND id_creator = :c",
            [
                ':tm'  => $isTeam,
                ':tc'  => $teamCount,
                ':tpl' => $playerLimit,
                ':q'   => $quizId,
                ':c'   => $creatorId
            ]
        );
        set_flash_message('success', 'Cài đặt Team Mode đã được lưu!');
        redirect('modules/quiz/edit.php?id=' . $quizId);
    }

    // Obstacle Puzzle settings update
    if ($action === 'update_obstacle_settings' && ($quiz['game_type'] ?? 'classic') === 'obstacle') {
        $newAns = trim($_POST['obstacle_answer'] ?? '');
        $newTp  = intval($_POST['total_pieces'] ?? 4);
        if (!in_array($newTp, [4, 6, 8, 9])) $newTp = 4;

        $newObsImg = null;
        if (isset($_FILES['obstacle_image']) && $_FILES['obstacle_image']['error'] === UPLOAD_ERR_OK) {
            $f = $_FILES['obstacle_image'];
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (in_array($f['type'], $allowed)) {
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                $name = 'obs_' . uniqid() . '_' . time() . '.' . $ext;
                $dir = __DIR__ . '/../../assets/img/obstacles/';
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                if (move_uploaded_file($f['tmp_name'], $dir . $name)) {
                    $newObsImg = 'assets/img/obstacles/' . $name;
                }
            }
        }

        if ($newObsImg) {
            db_execute("UPDATE quiz SET obstacle_answer = :ans, total_pieces = :tp, obstacle_image = :img WHERE id_quiz = :q", [
                ':ans' => $newAns,
                ':tp'  => $newTp,
                ':img' => $newObsImg,
                ':q'   => $quizId
            ]);
        } else {
            db_execute("UPDATE quiz SET obstacle_answer = :ans, total_pieces = :tp WHERE id_quiz = :q", [
                ':ans' => $newAns,
                ':tp'  => $newTp,
                ':q'   => $quizId
            ]);
        }
        set_flash_message('success', 'Obstacle settings updated!');
        redirect('modules/quiz/edit.php?id=' . $quizId);
    }

    if (in_array($action, ['add_question', 'update_question'])) {
        $qText = trim($_POST['question_text'] ?? '');
        $qType = ($_POST['question_type'] ?? '') === 'text' ? 'text' : 'multiple_choice';
        $tl    = intval($_POST['time_limit'] ?? 20);
        if (!in_array($tl, [10, 20, 30, 60])) $tl = 20;
        $pts   = intval($_POST['points'] ?? 1000);
        if (!in_array($pts, [500, 1000, 2000])) $pts = 1000;

        $qMode = trim($_POST['question_mode'] ?? 'normal');
        $modeObj = QuestionModeRegistry::get($qMode);
        if (!$modeObj->isQuestionTypeSupported($qType)) {
            $qMode = 'normal';
            $modeObj = QuestionModeRegistry::get('normal');
        }

        $modeConfig = null;
        if ($qMode === 'wager') {
            $wTime = intval($_POST['wager_time_sec'] ?? 10);
            $spBonus = !empty($_POST['wager_speed_bonus']);
            $modeConfig = json_encode($modeObj->validateConfig([
                'wagerTimeSec' => $wTime,
                'speedBonusEnabled' => $spBonus,
            ]));
        }

        if (empty($qText)) {
            set_flash_message('danger', 'Question text is required.');
            redirect('modules/quiz/edit.php?id=' . $quizId);
        }

        if ($qType === 'text') {
            $ta = trim($_POST['text_answer'] ?? '');
            if (empty($ta)) {
                set_flash_message('danger', 'Please enter the correct answer for the text question.');
                redirect('modules/quiz/edit.php?id=' . $quizId);
            }
        }

        $oldImg  = null;
        $oldMt   = null;
        $editQId = null;

        if ($action === 'update_question') {
            $editQId = intval($_POST['editing_question_id'] ?? 0);
            $oldQ    = db_fetch_one("SELECT * FROM questions WHERE id_question = :q AND id_quiz = :qz", [':q' => $editQId, ':qz' => $quizId]);
            if (!$oldQ) {
                set_flash_message('danger', 'Question not found.');
                redirect('modules/quiz/edit.php?id=' . $quizId);
            }
            $oldImg = $oldQ['image'];
            $oldMt  = $oldQ['media_type'] ?? null;
        }

        $newFile = saveUploadedFile('question_media');
        $newMt   = detectMediaType('question_media');

        if ($newFile !== null) {
            $imgCol = $newFile;
            $mtCol  = $newMt;
        } elseif (($_POST['question_media_remove'] ?? '0') === '1') {
            $imgCol = null;
            $mtCol  = null;
        } else {
            $imgCol = $oldImg;
            $mtCol  = $oldMt;
        }

        $pieceIndex = 0;
        if (($quiz['game_type'] ?? 'classic') === 'obstacle') {
            $pieceIndex = intval($_POST['piece_index'] ?? 0);
            if ($pieceIndex <= 0) {
                $maxPi = db_fetch_one("SELECT COALESCE(MAX(piece_index), 0) AS mo FROM questions WHERE id_quiz = :qz", [':qz' => $quizId]);
                $pieceIndex = min(intval($maxPi['mo']) + 1, intval($quiz['total_pieces'] ?? 4));
            }
        }

        if ($action === 'add_question') {
            $maxSo = db_fetch_one("SELECT COALESCE(MAX(sort_order), 0) AS mo FROM questions WHERE id_quiz = :qz", [':qz' => $quizId]);
            $so    = intval($maxSo['mo']) + 1;
            $qId   = db_insert(
                "INSERT INTO questions (id_quiz, text, points, time_limit, image, question_type, media_type, sort_order, mode, mode_config, piece_index) 
                 VALUES (:qz,:t,:p,:tl,:img,:qt,:mt,:so,:m,:mc,:pi)",
                [':qz'=>$quizId,':t'=>$qText,':p'=>$pts,':tl'=>$tl,':img'=>$imgCol,':qt'=>$qType,':mt'=>$mtCol,':so'=>$so,':m'=>$qMode,':mc'=>$modeConfig,':pi'=>$pieceIndex]
            );
        } else {
            $qId = $editQId;
            db_execute(
                "UPDATE questions SET text=:t, points=:p, time_limit=:tl, image=:img, question_type=:qt, media_type=:mt, mode=:m, mode_config=:mc, piece_index=:pi WHERE id_question=:qid AND id_quiz=:qz",
                [':t'=>$qText,':p'=>$pts,':tl'=>$tl,':img'=>$imgCol,':qt'=>$qType,':mt'=>$mtCol,':m'=>$qMode,':mc'=>$modeConfig,':pi'=>$pieceIndex,':qid'=>$qId,':qz'=>$quizId]
            );
            db_execute("DELETE FROM answers WHERE id_question = :qid", [':qid' => $qId]);
        }

        $correctIdxs = array_map('intval', $_POST['correct_answer_idx'] ?? []);
        $useImgArr   = $_POST['answer_use_image']  ?? [];
        $keepImgArr  = $_POST['answer_keep_image'] ?? [];
        $textArr     = $_POST['answer_text']       ?? [];

        if ($qType === 'text') {
            $ta = trim($_POST['text_answer'] ?? '');
            db_insert("INSERT INTO answers (id_question, is_correct, text) VALUES (:qid, 1, :t)", [':qid' => $qId, ':t' => $ta]);
        } else {
            for ($i = 0; $i < 4; $i++) {
                $useImg   = ($useImgArr[$i] ?? '0') === '1';
                $txt      = trim($textArr[$i] ?? '');
                $newAImg  = saveUploadedFile('answer_image_file_' . $i);
                $keepImg  = trim($keepImgArr[$i] ?? '');
                if ($useImg) {
                    $finalImg = $newAImg ?: ($keepImg ?: null);
                    if ($finalImg !== null) {
                        $corr = in_array($i, $correctIdxs) ? 1 : 0;
                        db_insert("INSERT INTO answers (id_question, is_correct, text, image_path) VALUES (:qid,:c,'',:img)", [':qid'=>$qId,':c'=>$corr,':img'=>$finalImg]);
                    }
                } elseif ($txt !== '') {
                    $corr = in_array($i, $correctIdxs) ? 1 : 0;
                    db_insert("INSERT INTO answers (id_question, is_correct, text, image_path) VALUES (:qid,:c,:t,NULL)", [':qid'=>$qId,':c'=>$corr,':t'=>$txt]);
                }
            }
        }

        $_SESSION['last_question_type_' . $quizId] = $qType;
        $_SESSION['last_question_mode_' . $quizId] = $qMode;
        $_SESSION['last_time_limit_' . $quizId]    = $tl;
        $_SESSION['last_points_' . $quizId]        = $pts;

        set_flash_message('success', $action === 'add_question' ? 'Question added!' : 'Question updated!');
        redirect('modules/quiz/edit.php?id=' . $quizId);
    }
}

if (isset($_GET['delete_question'])) {
    $dqId = intval($_GET['delete_question']);
    db_execute("DELETE FROM questions WHERE id_question = :q AND id_quiz = :qz", [':q' => $dqId, ':qz' => $quizId]);
    set_flash_message('info', 'Question deleted.');
    redirect('modules/quiz/edit.php?id=' . $quizId);
}

$questions = db_fetch_all("SELECT * FROM questions WHERE id_quiz = :q ORDER BY sort_order ASC, id_question ASC", [':q' => $quizId]);
foreach ($questions as &$q) {
    $q['answers'] = db_fetch_all("SELECT * FROM answers WHERE id_question = :qid ORDER BY id_answer ASC", [':qid' => $q['id_question']]);
}
unset($q);

$hasWagerInQuiz = false;
foreach ($questions as $qq) {
    if (($qq['mode'] ?? 'normal') === 'wager') {
        $hasWagerInQuiz = true;
        break;
    }
}

$quizMode = $quiz['mode'] ?? 'normal';
if (isset($_GET['initial_mode']) && QuestionModeRegistry::has($_GET['initial_mode'])) {
    $quizMode = $_GET['initial_mode'];
    if (($quiz['mode'] ?? 'normal') !== $quizMode) {
        db_execute("UPDATE quiz SET mode = :m WHERE id_quiz = :q", [':m' => $quizMode, ':q' => $quizId]);
        $quiz['mode'] = $quizMode;
    }
}

$defaultQMode = $quizMode;
$_SESSION['last_question_mode_' . $quizId] = $defaultQMode;
$defaultQType = $_SESSION['last_question_type_' . $quizId]
    ?? (!empty($questions) ? end($questions)['question_type'] : 'multiple_choice');
$defaultTimeLimit = $_SESSION['last_time_limit_' . $quizId]
    ?? (!empty($questions) ? end($questions)['time_limit'] : 20);
$defaultPoints = $_SESSION['last_points_' . $quizId]
    ?? (!empty($questions) ? end($questions)['points'] : 1000);

$pageTitle      = 'Edit Quiz: ' . htmlspecialchars($quiz['name']);
$extraCss       = BASE_URL . 'assets/css/edit-quiz.css?v=' . time();
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';

$flash = get_flash_message();
$isObstacle = ($quiz['game_type'] ?? 'classic') === 'obstacle';
$isTeamMode = !empty($quiz['is_team_mode']);
$teamCount = TeamManager::getTeamCount($quiz);
$teamLimit = TeamManager::getTeamPlayerLimit($quiz);
$availableTeams = TeamManager::getTeamsForEntity($quiz);
?>

<div class="editor-page-wrapper">

    <div class="editor-topbar">
        <div class="d-flex align-items-center flex-wrap" style="gap: 8px;">
            <a href="<?= BASE_URL ?>modules/quiz/list.php" class="btn btn-sm btn-outline-secondary font-weight-bold">
                <i class="fas fa-chevron-left mr-1"></i> My Kahoots
            </a>
            <div class="quiz-title-wrapper">
                <span id="quizTitleDisplay"><?= htmlspecialchars($quiz['name']) ?></span>
                <input type="text" id="quizTitleInput" value="<?= htmlspecialchars($quiz['name']) ?>" maxlength="100">
                <i class="fas fa-pencil-alt title-edit-icon" id="editTitleIcon" title="Edit title"></i>
            </div>
            <?php if ($defaultQMode === 'wager' || $hasWagerInQuiz): ?>
                <span class="badge badge-warning text-dark font-weight-bold py-1 px-2" style="font-size: 0.85rem;">
                    🎲 Mode: Wager
                </span>
            <?php endif; ?>
            <?php if ($isTeamMode): ?>
                <span class="badge badge-info text-white font-weight-bold py-1 px-2" style="font-size: 0.85rem;" title="Team Mode: <?= $teamCount ?> teams, <?= $teamLimit > 0 ? $teamLimit . ' players/team' : 'unlimited' ?>">
                    <i class="fas fa-users mr-1"></i> Team Mode (<?= $teamCount ?> Teams)
                </span>
            <?php endif; ?>
            <?php if ($isObstacle): ?>
                <span class="badge badge-warning text-dark font-weight-bold py-1 px-2" style="font-size: 0.85rem;">
                    <i class="fas fa-puzzle-piece mr-1"></i> Obstacle (<?= intval($quiz['total_pieces']) ?> tiles)
                </span>
                <button type="button" class="btn btn-sm btn-outline-warning font-weight-bold py-1 px-2" data-toggle="modal" data-target="#obstacleSettingsModal" title="Secret image & keyword">
                    <i class="fas fa-image mr-1"></i> Secret Image
                </button>
            <?php endif; ?>
        </div>

        <div class="d-flex align-items-center" style="gap: 12px;">
            <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-auto-dismiss alert-dismissible fade show mb-0 py-1 px-3 d-flex align-items-center shadow-sm"
                 role="alert"
                 style="font-size: 13px; font-weight: 700; border-radius: 8px; border: 1px solid #c3e6cb; background: #e8f5e9; color: #1b5e20; height: 34px;">
                <i class="fas fa-check-circle mr-1 text-success" style="font-size: 14px;"></i>
                <span><?= htmlspecialchars($flash['message']) ?></span>
                <button type="button" class="close ml-2 text-success" data-dismiss="alert" style="font-size: 16px; line-height: 1; opacity: 0.7; padding: 0;" aria-label="Close">
                    <span>&times;</span>
                </button>
            </div>
            <?php endif; ?>

            <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary font-weight-bold dropdown-toggle" type="button" id="quizOptionsDropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="Quiz Options">
                    <i class="fas fa-cog mr-1"></i> Option
                </button>
                <div class="dropdown-menu dropdown-menu-right shadow border-0" aria-labelledby="quizOptionsDropdown" style="min-width: 220px; border-radius: 12px; padding: 8px;">
                    <div class="dropdown-header text-uppercase font-weight-bold text-muted px-2 py-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Tùy chọn nâng cao</div>
                    <button class="dropdown-item d-flex align-items-center py-2 px-2 rounded" type="button" data-toggle="modal" data-target="#teamModeModal" onclick="$('#teamModeModal').modal('show')">
                        <i class="fas fa-users mr-2 text-primary"></i>
                        <span class="font-weight-bold">Team Mode</span>
                        <?php if ($isTeamMode): ?>
                            <span class="badge badge-success ml-auto font-weight-bold">ON (<?= $teamCount ?>)</span>
                        <?php else: ?>
                            <span class="badge badge-light border ml-auto text-muted font-weight-bold">OFF</span>
                        <?php endif; ?>
                    </button>
                </div>
            </div>

            <a href="<?= BASE_URL ?>modules/host/create_room.php?id_quiz=<?= $quiz['id_quiz'] ?>" class="btn btn-sm font-weight-bold" style="background:#46178f;color:#fff;">
                <i class="fas fa-play mr-1"></i> Host Game Now
            </a>
        </div>
    </div>

    <div class="editor-layout">

        <div class="q-list-panel">
            <div class="q-list-scroll">
                <ul id="questionListSortable" class="list-unstyled m-0 p-0">
                    <?php if (empty($questions)): ?>
                    <li class="q-list-empty">
                        <i class="fas fa-question-circle fa-2x d-block mb-2"></i>
                        No questions yet
                    </li>
                    <?php endif; ?>
                    <?php foreach ($questions as $idx => $q): 
                        $curMode = $q['mode'] ?? 'normal';
                    ?>
                    <li class="q-list-item" data-id="<?= $q['id_question'] ?>" data-mode="<?= htmlspecialchars($curMode) ?>" data-piece="<?= intval($q['piece_index'] ?? 0) ?>">
                        <span class="drag-handle"><i class="fas fa-grip-vertical"></i></span>
                        <div class="q-thumb-content">
                            <div class="q-thumb-num">
                                <?= $isObstacle ? ('🧩 Mảnh ' . intval($q['piece_index'] ?: ($idx + 1))) : ('Q' . ($idx + 1)) ?>
                            </div>
                            <div class="q-thumb-text" title="<?= htmlspecialchars($q['text'] ?? '') ?>">
                                <?= htmlspecialchars(mb_strimwidth($q['text'] ?? '', 0, 36, '…')) ?>
                            </div>
                            <div class="q-thumb-meta">
                                <?= intval($q['time_limit']) ?>s &bull; <?= number_format(intval($q['points'])) ?> pts
                                <?php if ($curMode === 'wager'): ?>
                                    <span class="badge badge-warning text-dark font-weight-bold ml-1" style="font-size:10px;">🎲 Cược</span>
                                <?php else: ?>
                                    <span class="badge badge-light text-secondary font-weight-normal ml-1" style="font-size:10px;">⚡ Thường</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="q-list-actions">
                            <button type="button" class="btn-icon" onclick="loadQuestionForEdit(<?= $q['id_question'] ?>)" title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button type="button" class="btn-icon" onclick="duplicateQuestion(<?= $q['id_question'] ?>)" title="Duplicate">
                                <i class="fas fa-copy"></i>
                            </button>
                            <a href="?id=<?= $quizId ?>&delete_question=<?= $q['id_question'] ?>"
                               class="btn-icon btn-delete"
                               onclick="return confirm('Delete this question?')"
                               title="Delete">
                                <i class="fas fa-trash-alt"></i>
                            </a>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="q-list-footer">
                <button type="button" class="btn-add-question" id="btnAddNewQuestion">
                    <i class="fas fa-plus"></i> New question
                </button>
                <?php if (!empty($questions)): ?>
                <button type="button" class="btn btn-sm btn-outline-light btn-block mt-2 font-weight-bold" data-toggle="modal" data-target="#bulkModeModal" style="border-radius: 8px; font-size: 11px;">
                    <i class="fas fa-layer-group mr-1"></i> Áp dụng chế độ hàng loạt
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="q-canvas">
            <div class="canvas-header">
                <span id="canvasModeLabel" class="canvas-mode-label">New Question</span>
                <span style="color:rgba(255,255,255,0.65);font-size:12px;"><?= count($questions) ?> question<?= count($questions) !== 1 ? 's' : '' ?></span>
            </div>
            <form id="questionForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action"               id="formAction"              value="add_question">
                <input type="hidden" name="editing_question_id"  id="editingQuestionId"       value="">
                <input type="hidden" name="question_type"        id="hiddenQuestionType"      value="<?= htmlspecialchars($defaultQType) ?>">
                <input type="hidden" name="question_mode"        id="hiddenQuestionMode"      value="normal">
                <input type="hidden" name="wager_time_sec"       id="hiddenWagerTimeSec"       value="10">
                <input type="hidden" name="wager_speed_bonus"    id="hiddenWagerSpeedBonus"    value="0">
                <input type="hidden" name="time_limit"           id="hiddenTimeLimit"         value="<?= htmlspecialchars($defaultTimeLimit) ?>">
                <input type="hidden" name="points"               id="hiddenPoints"            value="<?= htmlspecialchars($defaultPoints) ?>">
                <input type="hidden" name="question_media_remove" id="questionMediaRemoveFlag" value="0">
                <input type="hidden" name="piece_index"          id="hiddenPieceIndex"        value="1">

                <div class="canvas-body">

                    <textarea id="questionTextInput" name="question_text" rows="2"
                              placeholder="Start typing your question…"></textarea>

                    <div class="media-upload-area" id="questionMediaArea">
                        <input type="file" name="question_media" id="questionMediaFile" accept="image/*,video/*">
                        <div id="questionMediaPlaceholder">
                            <i class="fas fa-photo-video fa-2x d-block mb-1" style="color:#bbb;"></i>
                            <span style="font-size:13px;color:#aaa;">Click or drag to add image / video</span>
                        </div>
                        <div id="questionMediaPreview" style="display:none;"></div>
                        <button type="button" class="media-remove-btn" id="questionMediaRemoveBtn" style="display:none;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    <div id="answersMultipleChoice" style="<?= $defaultQType === 'text' ? 'display: none;' : '' ?>">
                        <div class="answers-grid">
                            <?php
                            $aColors   = ['color-red', 'color-blue', 'color-yellow', 'color-green'];
                            $aIcons    = ['🔺', '🔷', '🟡', '🟩'];
                            $aLabels   = ['Answer 1', 'Answer 2', 'Answer 3', 'Answer 4'];
                            $aOptional = [false, false, true, true];
                            for ($i = 0; $i < 4; $i++):
                            ?>
                            <div class="answer-tile" id="answerTile_<?= $i ?>">
                                <div class="answer-tile-header <?= $aColors[$i] ?>">
                                    <span><?= $aIcons[$i] ?></span>
                                    <span style="flex:1;font-size:13px;">
                                        <?= $aLabels[$i] ?>
                                        <?php if ($aOptional[$i]): ?>
                                        <span style="opacity:0.75;font-weight:400;font-size:11px;">(optional)</span>
                                        <?php endif; ?>
                                    </span>
                                    <input type="checkbox" class="answer-correct-cb"
                                           name="correct_answer_idx[]"
                                           value="<?= $i ?>"
                                           id="answerCorrect_<?= $i ?>"
                                           title="Mark as correct">
                                </div>
                                <div class="answer-tile-body">
                                    <input type="hidden" name="answer_use_image[<?= $i ?>]"  id="answerUseImage_<?= $i ?>"  value="0">
                                    <input type="hidden" name="answer_keep_image[<?= $i ?>]" id="answerKeepImage_<?= $i ?>" value="">
                                    <input type="text" class="answer-text-input"
                                           name="answer_text[<?= $i ?>]"
                                           id="answerText_<?= $i ?>"
                                           placeholder="Type answer…">
                                    <div class="answer-img-area" id="answerImgArea_<?= $i ?>">
                                        <div id="answerImgPreview_<?= $i ?>" class="answer-img-placeholder">
                                            <i class="fas fa-image" style="color:#ccc;"></i>
                                            <span>Click to upload</span>
                                        </div>
                                        <input type="file" name="answer_image_file_<?= $i ?>"
                                               id="answerImgFile_<?= $i ?>" accept="image/*">
                                    </div>
                                    <button type="button" class="answer-media-toggle" id="answerToggle_<?= $i ?>" data-index="<?= $i ?>">
                                        🖼 Use Image
                                    </button>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <div id="answersText" class="text-ans-section" style="<?= $defaultQType === 'text' ? 'display: block;' : 'display: none;' ?>">
                        <div class="prop-label" style="margin-bottom:8px;">Correct Answer</div>
                        <input type="text" class="text-ans-input" name="text_answer" id="textAnswerInput"
                               placeholder="Type the correct answer…" autocomplete="off">
                        <div class="text-ans-underscores" id="textAnswerUnderscores"></div>
                    </div>

                </div>

                <div class="canvas-footer">
                    <button type="button" class="btn-cancel" id="btnCancelEdit" style="display:none;">
                        <i class="fas fa-times mr-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn-save" id="btnSaveQuestion">
                        <i class="fas fa-plus mr-1"></i> Save &amp; Add Question
                    </button>
                </div>
            </form>
        </div>

        <div class="props-panel">
            <div id="modeSettingsPanel" class="prop-group" style="<?= $defaultQMode === 'wager' ? 'display:block;' : 'display:none;' ?> background: #fff8e1; border: 1px solid #ffe082; border-radius: 8px; padding: 10px;">
                <div class="prop-label mb-2 text-dark font-weight-bold" style="font-size: 12px;">
                    <i class="fas fa-sliders-h mr-1 text-warning"></i> Wager Settings
                </div>
                <div class="form-group mb-2">
                    <label class="small text-muted font-weight-bold mb-1 d-block" style="font-size: 11px;">Wager Duration</label>
                    <select class="prop-select" id="propWagerTimeSec" style="height: 32px; font-size: 12px;">
                        <option value="5">5s</option>
                        <option value="10" selected>10s (Default)</option>
                        <option value="15">15s</option>
                        <option value="20">20s</option>
                        <option value="30">30s</option>
                    </select>
                </div>
                <div class="custom-control custom-switch mt-2">
                    <input type="checkbox" class="custom-control-input" id="propWagerSpeedBonus">
                    <label class="custom-control-label small font-weight-bold text-dark" for="propWagerSpeedBonus" style="font-size: 11px;">
                        Enable Speed Bonus
                    </label>
                </div>
            </div>

            <?php if ($isObstacle): ?>
            <div class="prop-group">
                <div class="prop-label">
                    <i class="fas fa-puzzle-piece text-warning mr-1"></i> Puzzle Tile #
                </div>
                <select class="prop-select" id="propPieceIndex">
                    <?php for ($p = 1; $p <= intval($quiz['total_pieces'] ?? 4); $p++): ?>
                        <option value="<?= $p ?>">Tile #<?= $p ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="prop-group">
                <div class="prop-label">Question Type</div>
                <select class="prop-select" id="propQuestionType">
                    <option value="multiple_choice" <?= $defaultQType === 'multiple_choice' ? 'selected' : '' ?>>Multiple Choice</option>
                    <option value="text" <?= $defaultQType === 'text' ? 'selected' : '' ?>>Text Answer</option>
                </select>
            </div>
            <div class="prop-group">
                <div class="prop-label">Time Limit</div>
                <select class="prop-select" id="propTimeLimit">
                    <option value="10" <?= $defaultTimeLimit == 10 ? 'selected' : '' ?>>10 seconds</option>
                    <option value="20" <?= $defaultTimeLimit == 20 ? 'selected' : '' ?>>20 seconds</option>
                    <option value="30" <?= $defaultTimeLimit == 30 ? 'selected' : '' ?>>30 seconds</option>
                    <option value="60" <?= $defaultTimeLimit == 60 ? 'selected' : '' ?>>60 seconds</option>
                </select>
            </div>
            <div class="prop-group">
                <div class="prop-label">Points</div>
                <select class="prop-select" id="propPoints">
                    <option value="500" <?= $defaultPoints == 500 ? 'selected' : '' ?>>500</option>
                    <option value="1000" <?= $defaultPoints == 1000 ? 'selected' : '' ?>>1,000</option>
                    <option value="2000" <?= $defaultPoints == 2000 ? 'selected' : '' ?>>2,000</option>
                </select>
            </div>
        </div>

    </div>
</div>

<!-- Modal Bulk Apply Mode -->
<div class="modal fade" id="bulkModeModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content shadow-lg border-0" style="border-radius: 14px;">
            <form method="POST">
                <input type="hidden" name="action" value="apply_mode_to_all">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title font-weight-bold text-dark"><i class="fas fa-layer-group text-primary mr-2"></i>Apply Mode to All Questions</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body py-3">
                    <p class="text-muted small">Select the question mode to apply to <strong>all questions</strong> in this quiz:</p>
                    <div class="form-group mb-0">
                        <label class="font-weight-bold small text-dark">Target Mode:</label>
                        <select name="target_mode" class="form-control font-weight-bold">
                            <?php foreach (QuestionModeRegistry::getAll() as $m): ?>
                            <option value="<?= htmlspecialchars($m->getId()) ?>">
                                <?= $m->getIcon() ?> <?= htmlspecialchars($m->getLabel()) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-sm btn-secondary font-weight-bold px-3" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary font-weight-bold px-4">Apply Now</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($isObstacle): ?>
<!-- MODAL OBSTACLE SETTINGS -->
<div class="modal fade" id="obstacleSettingsModal" tabindex="-1" role="dialog" aria-labelledby="obstacleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow">
            <div class="modal-header text-white" style="background-color: var(--kahoot-purple);">
                <h5 class="modal-title font-weight-bold" id="obstacleModalLabel">
                    <i class="fas fa-puzzle-piece mr-2"></i>Secret Image &amp; Obstacle Settings
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form action="" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_obstacle_settings">
                <div class="modal-body p-4">
                    <div class="form-group text-center">
                        <label class="font-weight-bold d-block text-left">Current Secret Image:</label>
                        <?php if (!empty($quiz['obstacle_image'])): ?>
                            <img src="<?= BASE_URL . htmlspecialchars($quiz['obstacle_image']) ?>" class="img-fluid rounded border shadow-sm" style="max-height: 200px; object-fit: contain;" alt="Obstacle Image">
                        <?php else: ?>
                            <div class="alert alert-warning">No secret image uploaded yet</div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold">Upload New Image (optional):</label>
                        <input type="file" name="obstacle_image" class="form-control-file" accept="image/*">
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold">Secret Keyword (Exact Answer):</label>
                        <input type="text" name="obstacle_answer" class="form-control" value="<?= htmlspecialchars($quiz['obstacle_answer'] ?? '') ?>" required>
                    </div>

                    <div class="form-group mb-0">
                        <label class="font-weight-bold">Number of Puzzle Tiles:</label>
                        <select name="total_pieces" class="form-control">
                            <option value="4" <?= ($quiz['total_pieces'] ?? 4) == 4 ? 'selected' : '' ?>>4 tiles (2x2 grid)</option>
                            <option value="6" <?= ($quiz['total_pieces'] ?? 4) == 6 ? 'selected' : '' ?>>6 tiles (2x3 grid)</option>
                            <option value="8" <?= ($quiz['total_pieces'] ?? 4) == 8 ? 'selected' : '' ?>>8 tiles (2x4 grid)</option>
                            <option value="9" <?= ($quiz['total_pieces'] ?? 4) == 9 ? 'selected' : '' ?>>9 tiles (3x3 grid)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-kahoot btn-kahoot-green font-weight-bold">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>


<!-- MODAL TEAM MODE SETTINGS -->
<div class="modal fade" id="teamModeModal" tabindex="-1" role="dialog" aria-labelledby="teamModeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header text-white" style="background: linear-gradient(135deg, #1368ce, #46178f);">
                <h5 class="modal-title font-weight-bold" id="teamModeModalLabel">
                    <i class="fas fa-users-cog mr-2"></i>Tùy chỉnh Team Mode
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form action="" method="POST">
                <input type="hidden" name="action" value="update_team_settings">
                <div class="modal-body p-4">
                    <div class="d-flex align-items-center justify-content-between p-3 mb-3 rounded" style="background-color: #f3f0fb; border: 1px solid #e0d7f5;">
                        <div class="pr-3">
                            <h6 class="font-weight-bold mb-1" style="color: #46178f;">
                                <i class="fas fa-toggle-on mr-1"></i> Bật chế độ Team Mode
                            </h6>
                            <p class="text-muted small mb-0">
                                Khi bật, người tham gia sẽ chọn team trước khi đặt tên. Điểm cá nhân sẽ được cộng chung vào tổng điểm của team.
                            </p>
                        </div>
                        <div class="custom-control custom-switch pl-4" style="transform: scale(1.4); transform-origin: right center;">
                            <input type="checkbox" class="custom-control-input" id="is_team_mode" name="is_team_mode" value="1" <?= $isTeamMode ? 'checked' : '' ?> onchange="toggleTeamModeInputs(this.checked)">
                            <label class="custom-control-label" for="is_team_mode" style="cursor: pointer;"></label>
                        </div>
                    </div>

                    <div id="teamModeFields" style="<?= $isTeamMode ? '' : 'opacity: 0.45; pointer-events: none;' ?>">
                        <div class="form-group mb-3">
                            <label class="font-weight-bold text-dark d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-flag text-primary mr-1"></i> Số lượng Team:</span>
                                <span class="badge badge-primary font-weight-bold px-2 py-1" id="teamCountBadge"><?= $teamCount ?> Teams</span>
                            </label>
                            <select name="team_count" id="team_count" class="form-control font-weight-bold" onchange="onTeamCountChange(this.value)">
                                <?php for ($tc = 2; $tc <= 10; $tc++): ?>
                                    <option value="<?= $tc ?>" <?= $teamCount == $tc ? 'selected' : '' ?>><?= $tc ?> Đội (Teams)</option>
                                <?php endfor; ?>
                            </select>
                            <small class="text-muted">Chọn từ 2 đến 10 đội tham gia.</small>
                        </div>

                        <div class="form-group mb-3">
                            <label class="font-weight-bold text-dark d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-user-friends text-info mr-1"></i> Giới hạn người chơi mỗi team:</span>
                                <span class="badge badge-info font-weight-bold px-2 py-1" id="teamLimitBadge"><?= $teamLimit > 0 ? $teamLimit . ' người/team' : 'Không giới hạn' ?></span>
                            </label>
                            <input type="number" name="team_player_limit" id="team_player_limit" class="form-control font-weight-bold" min="0" max="100" value="<?= $teamLimit ?>" placeholder="0 = Không giới hạn" oninput="onTeamLimitChange(this.value)">
                            <small class="text-muted">Đặt là <strong>0</strong> để không giới hạn số người mỗi đội.</small>
                        </div>

                        <div class="form-group mb-0">
                            <label class="font-weight-bold text-dark mb-2">Xem trước danh sách Team:</label>
                            <div class="d-flex flex-wrap" id="teamPreviewContainer" style="gap: 8px;">
                                <?php foreach ($availableTeams as $t): ?>
                                    <span class="badge font-weight-bold shadow-sm" style="background: <?= $t['color'] ?>; color: <?= $t['text_color'] ?>; padding: 6px 12px; border-radius: 12px;">
                                        <i class="<?= $t['icon'] ?> mr-1"></i> <?= htmlspecialchars($t['name']) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-3" data-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-primary font-weight-bold rounded-pill px-4" style="background-color: var(--kahoot-purple); border-color: var(--kahoot-purple);">
                        <i class="fas fa-save mr-1"></i> Lưu cài đặt
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const ALL_TEAMS_CATALOG = <?= json_encode(array_values(TeamManager::getTeamsForEntity(['team_count' => 10]))) ?>;

function toggleTeamModeInputs(enabled) {
    const fields = document.getElementById('teamModeFields');
    if (!fields) return;
    if (enabled) {
        fields.style.opacity = '1';
        fields.style.pointerEvents = 'auto';
    } else {
        fields.style.opacity = '0.45';
        fields.style.pointerEvents = 'none';
    }
}

function onTeamCountChange(val) {
    const count = parseInt(val) || 2;
    const badge = document.getElementById('teamCountBadge');
    if (badge) badge.textContent = count + ' Teams';

    const container = document.getElementById('teamPreviewContainer');
    if (!container) return;
    container.innerHTML = '';
    const slice = ALL_TEAMS_CATALOG.slice(0, count);
    slice.forEach(t => {
        const span = document.createElement('span');
        span.className = 'badge font-weight-bold shadow-sm';
        span.style.background = t.color;
        span.style.color = t.text_color;
        span.style.padding = '6px 12px';
        span.style.borderRadius = '12px';
        span.innerHTML = '<i class="' + t.icon + ' mr-1"></i> ' + t.name;
        container.appendChild(span);
    });
}

function onTeamLimitChange(val) {
    const limit = parseInt(val) || 0;
    const badge = document.getElementById('teamLimitBadge');
    if (badge) {
        badge.textContent = limit > 0 ? (limit + ' người/team') : 'Không giới hạn';
    }
}
</script>

<script>
window.KAHOOT_EDIT = {
    quizId:      <?= intval($quizId) ?>,
    baseUrl:     <?= json_encode(BASE_URL) ?>,
    defaultMode: <?= json_encode($defaultQMode) ?>,
    gameType:    <?= json_encode($quiz['game_type'] ?? 'classic') ?>,
    totalPieces: <?= intval($quiz['total_pieces'] ?? 4) ?>,
    questions:   <?= json_encode(array_values($questions), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    modes:     <?= json_encode(array_values(array_map(function($m) {
        return [
            'id' => $m->getId(),
            'label' => $m->getLabel(),
            'description' => $m->getDescription(),
            'icon' => $m->getIcon(),
            'supportedQuestionTypes' => $m->getSupportedQuestionTypes(),
            'defaultConfig' => $m->getDefaultConfig(),
        ];
    }, QuestionModeRegistry::getAll())), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
};
</script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script src="<?= BASE_URL ?>assets/js/edit-quiz.js"></script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
