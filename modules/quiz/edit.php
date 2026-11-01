<?php

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../config/database.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['add_question', 'update_question'])) {
        $qText = trim($_POST['question_text'] ?? '');
        $qType = ($_POST['question_type'] ?? '') === 'text' ? 'text' : 'multiple_choice';
        $tl    = intval($_POST['time_limit'] ?? 20);
        if (!in_array($tl, [10, 20, 30, 60])) $tl = 20;
        $pts   = intval($_POST['points'] ?? 1000);
        if (!in_array($pts, [500, 1000, 2000])) $pts = 1000;

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

        if ($action === 'add_question') {
            $maxSo = db_fetch_one("SELECT COALESCE(MAX(sort_order), 0) AS mo FROM questions WHERE id_quiz = :qz", [':qz' => $quizId]);
            $so    = intval($maxSo['mo']) + 1;
            $qId   = db_insert(
                "INSERT INTO questions (id_quiz, text, points, time_limit, image, question_type, media_type, sort_order) VALUES (:qz,:t,:p,:tl,:img,:qt,:mt,:so)",
                [':qz'=>$quizId,':t'=>$qText,':p'=>$pts,':tl'=>$tl,':img'=>$imgCol,':qt'=>$qType,':mt'=>$mtCol,':so'=>$so]
            );
        } else {
            $qId = $editQId;
            db_execute(
                "UPDATE questions SET text=:t, points=:p, time_limit=:tl, image=:img, question_type=:qt, media_type=:mt WHERE id_question=:qid AND id_quiz=:qz",
                [':t'=>$qText,':p'=>$pts,':tl'=>$tl,':img'=>$imgCol,':qt'=>$qType,':mt'=>$mtCol,':qid'=>$qId,':qz'=>$quizId]
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

$defaultQType = $_SESSION['last_question_type_' . $quizId]
    ?? (!empty($questions) ? end($questions)['question_type'] : 'multiple_choice');
$defaultTimeLimit = $_SESSION['last_time_limit_' . $quizId]
    ?? (!empty($questions) ? end($questions)['time_limit'] : 20);
$defaultPoints = $_SESSION['last_points_' . $quizId]
    ?? (!empty($questions) ? end($questions)['points'] : 1000);

$pageTitle      = 'Edit Quiz: ' . htmlspecialchars($quiz['name']);
$extraCss       = BASE_URL . 'assets/css/edit-quiz.css?v=' . (file_exists(__DIR__ . '/../../assets/css/edit-quiz.css') ? filemtime(__DIR__ . '/../../assets/css/edit-quiz.css') : time());
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
?>

<style>
/* Kahoot 2x2 Mini Grid Answers */
.q-thumb-answers {
    margin-top: 6px;
    width: 100%;
}
.q-answers-grid-mini {
    display: grid !important;
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap: 4px !important;
    width: 100% !important;
}
.q-mini-ans {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 3px !important;
    padding: 3px 6px !important;
    border-radius: 5px !important;
    font-size: 11px !important;
    line-height: 1.2 !important;
    min-width: 0 !important;
    box-sizing: border-box !important;
    overflow: hidden !important;
    border: 1px solid transparent !important;
}
.q-mini-shape {
    font-size: 8px !important;
    opacity: 0.85 !important;
    flex-shrink: 0 !important;
    line-height: 1 !important;
}
/* Đáp án sai: tô màu xám */
.q-mini-ans.is-wrong {
    background: #f1f5f9 !important;
    color: #64748b !important;
    border-color: #cbd5e1 !important;
}
.q-mini-ans.is-wrong .q-mini-ans-text,
.q-mini-ans.is-wrong .q-mini-icon,
.q-mini-ans.is-wrong .q-mini-shape {
    color: #64748b !important;
}
/* Đáp án đúng: TÔ MÀU XANH LÁ ĐẬM NỔI BẬT CHUẨN KAHOOT */
.q-mini-ans.is-correct {
    background: #16a34a !important;
    color: #ffffff !important;
    border-color: #15803d !important;
    font-weight: 700 !important;
    box-shadow: 0 1px 2px rgba(22, 163, 74, 0.25) !important;
}
.q-mini-ans.is-correct .q-mini-ans-text,
.q-mini-ans.is-correct .q-mini-icon,
.q-mini-ans.is-correct .q-mini-shape,
.q-mini-ans.is-correct .q-mini-check {
    color: #ffffff !important;
}
.q-mini-ans-text {
    flex: 1 !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}
.q-mini-check {
    font-size: 10px !important;
    flex-shrink: 0 !important;
    margin-left: 2px !important;
}
.q-mini-icon {
    font-size: 10px !important;
    flex-shrink: 0 !important;
}
/* Text question mode: 1 hàng full width màu xanh lá */
.q-thumb-answers.text-mode .q-mini-ans {
    width: 100% !important;
    padding: 4px 8px !important;
}
</style>

<?php
$flash = get_flash_message();
?>

<?php if ($flash): ?>
<div class="editor-flash">
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-auto-dismiss alert-dismissible fade show mb-0" role="alert">
        <?= htmlspecialchars($flash['message']) ?>
        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
    </div>
</div>
<?php endif; ?>

<div class="editor-page-wrapper">

    <div class="editor-topbar">
        <a href="<?= BASE_URL ?>modules/quiz/list.php" class="btn btn-sm btn-outline-secondary font-weight-bold">
            <i class="fas fa-chevron-left mr-1"></i> My Kahoots
        </a>
        <div class="quiz-title-wrapper">
            <span id="quizTitleDisplay"><?= htmlspecialchars($quiz['name']) ?></span>
            <input type="text" id="quizTitleInput" value="<?= htmlspecialchars($quiz['name']) ?>" maxlength="100">
            <i class="fas fa-pencil-alt title-edit-icon" id="editTitleIcon" title="Edit title"></i>
        </div>
        <a href="<?= BASE_URL ?>modules/host/create_room.php?id_quiz=<?= $quiz['id_quiz'] ?>" class="btn btn-sm font-weight-bold" style="background:#46178f;color:#fff;">
            <i class="fas fa-play mr-1"></i> Host Game Now
        </a>
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
                    <?php foreach ($questions as $idx => $q): ?>
                    <li class="q-list-item" data-id="<?= $q['id_question'] ?>">
                        <span class="drag-handle"><i class="fas fa-grip-vertical"></i></span>
                        <div class="q-thumb-content">
                            <div class="q-thumb-num">Q<?= $idx + 1 ?></div>
                            <div class="q-thumb-text" title="<?= htmlspecialchars($q['text'] ?? '') ?>">
                                <?= htmlspecialchars(mb_strimwidth($q['text'] ?? '', 0, 36, '…')) ?>
                            </div>
                            <div class="q-thumb-meta"><?= intval($q['time_limit']) ?>s &bull; <?= number_format(intval($q['points'])) ?> pts</div>

                            <?php
                            $qType = $q['question_type'] ?? 'multiple_choice';
                            $answers = $q['answers'] ?? [];
                            ?>
                            <?php if ($qType === 'text'): ?>
                                <?php
                                $correctText = '';
                                foreach ($answers as $a) {
                                    if (!empty($a['is_correct'])) {
                                        $correctText = $a['text'];
                                        break;
                                    }
                                }
                                if ($correctText === '' && !empty($answers)) {
                                    $correctText = $answers[0]['text'];
                                }
                                ?>
                                <?php if ($correctText !== ''): ?>
                                <div class="q-thumb-answers text-mode">
                                    <div class="q-mini-ans is-correct" title="<?= htmlspecialchars($correctText) ?>">
                                        <i class="fas fa-font q-mini-icon"></i>
                                        <span class="q-mini-ans-text"><?= htmlspecialchars(mb_strimwidth($correctText, 0, 24, '…')) ?></span>
                                        <i class="fas fa-check q-mini-check"></i>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php
                                $validAnswers = array_filter($answers, function($a) {
                                    return !empty($a['image_path']) || (isset($a['text']) && trim($a['text']) !== '');
                                });
                                $miniShapes = ['▲', '◆', '●', '■'];
                                $shapeIdx = 0;
                                ?>
                                <?php if (!empty($validAnswers)): ?>
                                <div class="q-thumb-answers">
                                    <div class="q-answers-grid-mini">
                                        <?php foreach ($validAnswers as $a): ?>
                                            <?php
                                            $shape = $miniShapes[$shapeIdx % 4];
                                            $shapeIdx++;
                                            $isCorrect = !empty($a['is_correct']);
                                            $hasImg = !empty($a['image_path']);
                                            $ansText = trim($a['text'] ?? '');
                                            $statusClass = $isCorrect ? 'is-correct' : 'is-wrong';
                                            $titleText = $hasImg ? ('[Ảnh]' . ($ansText !== '' ? ': ' . $ansText : '')) : $ansText;
                                            ?>
                                            <div class="q-mini-ans <?= $statusClass ?>" title="<?= htmlspecialchars($titleText) ?>">
                                                <span class="q-mini-shape"><?= $shape ?></span>
                                                <?php if ($hasImg): ?>
                                                    <i class="fas fa-image q-mini-icon q-mini-icon-img"></i>
                                                    <span class="q-mini-ans-text"><?= htmlspecialchars($ansText !== '' ? $ansText : 'Ảnh') ?></span>
                                                <?php else: ?>
                                                    <span class="q-mini-ans-text"><?= htmlspecialchars(mb_strimwidth($ansText, 0, 11, '…')) ?></span>
                                                <?php endif; ?>
                                                <?php if ($isCorrect): ?>
                                                    <i class="fas fa-check q-mini-check"></i>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
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
                <input type="hidden" name="time_limit"           id="hiddenTimeLimit"         value="<?= htmlspecialchars($defaultTimeLimit) ?>">
                <input type="hidden" name="points"               id="hiddenPoints"            value="<?= htmlspecialchars($defaultPoints) ?>">
                <input type="hidden" name="question_media_remove" id="questionMediaRemoveFlag" value="0">

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

<script>
window.KAHOOT_EDIT = {
    quizId:    <?= intval($quizId) ?>,
    baseUrl:   <?= json_encode(BASE_URL) ?>,
    questions: <?= json_encode(array_values($questions), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
};
</script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script src="<?= BASE_URL ?>assets/js/edit-quiz.js?v=<?= file_exists(__DIR__ . '/../../assets/js/edit-quiz.js') ? filemtime(__DIR__ . '/../../assets/js/edit-quiz.js') : time() ?>"></script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
