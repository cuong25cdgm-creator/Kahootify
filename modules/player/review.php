<?php

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/room_access.php';
require_once __DIR__ . '/../../includes/player_characters.php';

$playerId = intval($_GET['pid'] ?? ($_SESSION['id_player'] ?? 0));
$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : ($_SESSION['player_token'] ?? '');

if ($playerId <= 0) {
    redirect('modules/player/join.php');
}

$player = db_fetch_one("SELECT * FROM players WHERE id_player = :p", [':p' => $playerId]);
if (!$player) {
    redirect('modules/player/join.php');
}

$roomId = intval($player['id_room']);
$room = db_fetch_one("SELECT * FROM room WHERE id_room = :r", [':r' => $roomId]);
if (!$room || empty($room['is_review_mode'])) {
    redirect('modules/player/play.php?pid=' . $playerId . '&token=' . urlencode($token));
}

// Kiểm tra token bảo mật
$expectedToken = substr(md5($playerId . '_' . $roomId . '_kahoot_secret_2026'), 0, 16);
if (!hash_equals($expectedToken, $token)) {
    die('Invalid session token. Please join again.');
}

// Xử lý LÀM LẠI BÀI (Retake Quiz)
if (isset($_GET['retake'])) {
    db_execute("DELETE FROM votes WHERE id_player = :p", [':p' => $playerId]);
    db_execute("UPDATE players SET current_question_index = 0, is_finished = 0, answers_correct = 0 WHERE id_player = :p", [':p' => $playerId]);
    redirect('modules/player/review.php?pid=' . $playerId . '&token=' . urlencode($token));
}

// Kiểm tra hạn phòng ôn tập
$now = time();
$reviewEnd = !empty($room['review_end_time']) ? strtotime($room['review_end_time']) : PHP_INT_MAX;
$isExpired = ($now > $reviewEnd);

$quiz = db_fetch_one("SELECT * FROM quiz WHERE id_quiz = :q", [':q' => $room['id_quiz']]);
$questions = db_fetch_all("SELECT * FROM questions WHERE id_quiz = :q ORDER BY sort_order ASC, id_question ASC", [':q' => $room['id_quiz']]);
$totalQuestions = count($questions);

if ($totalQuestions === 0) {
    die('This quiz currently has no questions.');
}

$pIdx = intval($player['current_question_index'] ?? 0);

// Xử lý POST (Nộp câu trả lời / Chuyển câu)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'submit_answer' && $pIdx < $totalQuestions) {
        $curQ = $questions[$pIdx];
        $qId = intval($curQ['id_question']);

        // Kiểm tra xem đã nộp cho câu này chưa
        $alreadyVoted = db_fetch_one(
            "SELECT v.id_vote FROM votes v JOIN answers a ON v.id_answer = a.id_answer WHERE v.id_player = :p AND a.id_question = :q LIMIT 1",
            [':p' => $playerId, ':q' => $qId]
        );

        if (!$alreadyVoted) {
            $chosenAnswerId = 0;
            $isCorrect = 0;

            if ($curQ['question_type'] === 'text') {
                $userText = trim($_POST['text_answer'] ?? '');
                $allAnswers = db_fetch_all("SELECT * FROM answers WHERE id_question = :qid", [':qid' => $qId]);
                $matched = null;
                foreach ($allAnswers as $ans) {
                    if (mb_strtolower(trim($ans['text'])) === mb_strtolower($userText)) {
                        $matched = $ans;
                        break;
                    }
                }
                if ($matched) {
                    $chosenAnswerId = intval($matched['id_answer']);
                    $isCorrect = intval($matched['is_correct']);
                } elseif (!empty($allAnswers)) {
                    // Chọn câu trả lời đầu tiên nhưng đánh dấu là sai
                    $chosenAnswerId = intval($allAnswers[0]['id_answer']);
                    $isCorrect = 0;
                }
            } else {
                $chosenAnswerId = intval($_POST['answer_id'] ?? 0);
                $ansRow = db_fetch_one("SELECT is_correct FROM answers WHERE id_answer = :aid AND id_question = :qid", [
                    ':aid' => $chosenAnswerId,
                    ':qid' => $qId
                ]);
                $isCorrect = $ansRow ? intval($ansRow['is_correct']) : 0;
            }

            if ($chosenAnswerId > 0) {
                db_insert(
                    "INSERT INTO votes (id_player, id_answer, points_earned, streak_bonus) VALUES (:p, :a, 0, 0)",
                    [':p' => $playerId, ':a' => $chosenAnswerId]
                );
                if ($isCorrect) {
                    db_execute("UPDATE players SET answers_correct = answers_correct + 1 WHERE id_player = :p", [':p' => $playerId]);
                }
            }
        }
        redirect('modules/player/review.php?pid=' . $playerId . '&token=' . urlencode($token));
    } elseif ($action === 'next_question') {
        db_execute("UPDATE players SET current_question_index = current_question_index + 1 WHERE id_player = :p", [':p' => $playerId]);
        if ($pIdx + 1 >= $totalQuestions) {
            db_execute("UPDATE players SET is_finished = 1 WHERE id_player = :p", [':p' => $playerId]);
        }
        redirect('modules/player/review.php?pid=' . $playerId . '&token=' . urlencode($token));
    } elseif ($action === 'finish_review') {
        db_execute("UPDATE players SET is_finished = 1 WHERE id_player = :p", [':p' => $playerId]);
        redirect('modules/player/review.php?pid=' . $playerId . '&token=' . urlencode($token));
    }
}

// Nếu đã làm hết câu hỏi hoặc hết hạn: Hiển thị kết quả
if (!empty($player['is_finished']) || $pIdx >= $totalQuestions || $isExpired) {
    $resultsPlayerId = $playerId;
    $leaderboard = db_fetch_all("
        SELECT p.*,
               COALESCE(p.answers_correct, (SELECT COUNT(*) FROM votes v JOIN answers a ON v.id_answer = a.id_answer WHERE v.id_player = p.id_player AND a.is_correct = 1)) as correct_count
        FROM players p 
        WHERE p.id_room = :r 
        ORDER BY correct_count DESC, p.playeradd ASC", [':r' => $roomId]);
    require_once __DIR__ . '/../../includes/final_results.php';
    exit;
}

// Đang ở câu hỏi hiện tại
$currentQuestion = $questions[$pIdx];
$qId = intval($currentQuestion['id_question']);
$answers = db_fetch_all("SELECT * FROM answers WHERE id_question = :qid ORDER BY id_answer ASC", [':qid' => $qId]);

// Kiểm tra xem câu này đã trả lời chưa
$myVote = db_fetch_one(
    "SELECT v.*, a.is_correct, a.text as answer_text, a.image_path as answer_image 
     FROM votes v 
     JOIN answers a ON v.id_answer = a.id_answer 
     WHERE v.id_player = :p AND a.id_question = :q 
     LIMIT 1",
    [':p' => $playerId, ':q' => $qId]
);
$hasAnswered = ($myVote !== null && $myVote !== false);

$pageTitle = "Review: " . htmlspecialchars($quiz['name'] ?? 'Kahootify');
$bodyClass = "backgroundColorChanges";
require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/player-characters.css">
<style>
.review-container {
    max-width: 860px;
    margin: 20px auto;
    padding: 0 15px 40px;
}
.review-header-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 14px 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}
.review-badge {
    background: #ecfdf5;
    color: #15803d;
    border: 1px solid #86efac;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}
.review-question-card {
    background: #ffffff;
    border-radius: 18px;
    padding: 24px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.08);
    text-align: center;
    margin-bottom: 20px;
}
.review-question-title {
    font-size: 1.45rem;
    font-weight: 800;
    color: #1e1b4b;
    margin-bottom: 16px;
}
.review-media-box {
    max-height: 320px;
    margin-bottom: 20px;
    overflow: hidden;
    border-radius: 12px;
}
.review-media-box img, .review-media-box video {
    max-height: 320px;
    max-width: 100%;
    object-fit: contain;
    border-radius: 12px;
}
.review-answers-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 24px;
}
@media (max-width: 600px) {
    .review-answers-grid {
        grid-template-columns: 1fr;
    }
}
.review-ans-tile {
    padding: 16px;
    border-radius: 14px;
    border: none;
    font-size: 1.1rem;
    font-weight: 700;
    color: #ffffff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: transform 0.15s, opacity 0.15s;
    text-align: left;
    min-height: 72px;
    width: 100%;
}
.review-ans-tile:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.tile-red    { background-color: #e21b3c; }
.tile-blue   { background-color: #1368ce; }
.tile-yellow { background-color: #d89e00; }
.tile-green  { background-color: #26890c; }

.review-ans-tile.is-chosen-correct {
    border: 4px solid #ffffff !important;
    box-shadow: 0 0 0 3px #16a34a, 0 6px 15px rgba(22, 163, 74, 0.4);
}
.review-ans-tile.is-chosen-wrong {
    opacity: 0.6;
    border: 4px solid #ef4444 !important;
}
.review-ans-tile.is-correct-reveal {
    box-shadow: 0 0 0 3px #16a34a, 0 6px 15px rgba(22, 163, 74, 0.4);
}
.review-ans-tile.is-dimmed {
    opacity: 0.4;
}
.review-feedback-box {
    border-radius: 14px;
    padding: 16px 20px;
    font-size: 1.15rem;
    font-weight: 800;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    animation: fadeIn 0.3s ease;
}
.feedback-correct {
    background: #ecfdf5;
    color: #15803d;
    border: 2px solid #86efac;
}
.feedback-wrong {
    background: #fef2f2;
    color: #b91c1c;
    border: 2px solid #fca5a5;
}
</style>

<div class="review-container">
    <!-- Header -->
    <div class="review-header-card">
        <div>
            <span class="review-badge"><i class="fas fa-book-reader mr-1"></i> Review Mode</span>
            <h5 class="font-weight-bold mb-0 mt-1 text-dark"><?= htmlspecialchars($quiz['name'] ?? 'Quiz') ?></h5>
        </div>
        <div class="text-right">
            <span class="font-weight-bold text-muted" style="font-size: 0.95rem;">
                Question <span class="text-primary font-weight-bold" style="font-size: 1.2rem;"><?= ($pIdx + 1) ?></span> / <?= $totalQuestions ?>
            </span>
            <div class="small text-muted font-weight-bold"><?= htmlspecialchars($player['nickname'] ?? 'Player') ?></div>
        </div>
    </div>

    <!-- Question Card -->
    <div class="review-question-card">
        <h3 class="review-question-title"><?= htmlspecialchars($currentQuestion['text'] ?? '') ?></h3>

        <?php if (!empty($currentQuestion['image'])): ?>
            <div class="review-media-box">
                <?php
                $imgUrl = BASE_URL . htmlspecialchars($currentQuestion['image']);
                $isVid = ($currentQuestion['media_type'] ?? '') === 'video' || preg_match('/\.(mp4|webm)$/i', $currentQuestion['image']);
                ?>
                <?php if ($isVid): ?>
                    <video src="<?= $imgUrl ?>" controls></video>
                <?php else: ?>
                    <img src="<?= $imgUrl ?>" alt="Question Media">
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($hasAnswered): ?>
            <!-- Feedback Banner -->
            <?php if (!empty($myVote['is_correct'])): ?>
                <div class="review-feedback-box feedback-correct">
                    <div><i class="fas fa-check-circle mr-2"></i> Correct! You got it right.</div>
                    <i class="fas fa-smile-beam fa-lg"></i>
                </div>
            <?php else: ?>
                <div class="review-feedback-box feedback-wrong">
                    <div><i class="fas fa-times-circle mr-2"></i> Incorrect. See the correct answer below!</div>
                    <i class="fas fa-lightbulb fa-lg"></i>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Form Answers -->
        <?php if ($currentQuestion['question_type'] === 'text'): ?>
            <!-- Dạng Tự luận (Text) -->
            <?php if (!$hasAnswered): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="submit_answer">
                    <div class="form-group mb-3 text-left">
                        <label class="font-weight-bold text-muted small">Type your answer:</label>
                        <input type="text" name="text_answer" class="form-control form-control-lg text-center font-weight-bold" placeholder="Type your answer here..." required autofocus autocomplete="off">
                    </div>
                    <button type="submit" class="btn btn-kahoot btn-kahoot-purple btn-lg px-5 py-3 font-weight-bold shadow" style="border-radius: 30px;">
                        Submit Answer <i class="fas fa-paper-plane ml-1"></i>
                    </button>
                </form>
            <?php else: ?>
                <div class="p-3 my-3 rounded" style="background: #f8fafc; border: 2px dashed #cbd5e1;">
                    <div class="font-weight-bold text-muted mb-1">Correct answer:</div>
                    <div class="h4 font-weight-bold text-success">
                        <i class="fas fa-check mr-2"></i><?= htmlspecialchars($answers[0]['text'] ?? '') ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <!-- Dạng Trắc nghiệm (Multiple Choice) -->
            <?php
            $colors = ['tile-red', 'tile-blue', 'tile-yellow', 'tile-green'];
            $shapes = ['▲', '◆', '●', '■'];
            ?>
            <form method="POST" id="ansForm">
                <input type="hidden" name="action" value="submit_answer">
                <input type="hidden" name="answer_id" id="selectedAnsId" value="">

                <div class="review-answers-grid">
                    <?php foreach ($answers as $i => $ans): ?>
                        <?php
                        $colorClass = $colors[$i % 4];
                        $shape = $shapes[$i % 4];
                        $ansId = intval($ans['id_answer']);

                        $tileExtra = '';
                        if ($hasAnswered) {
                            $isMyPick = (intval($myVote['id_answer']) === $ansId);
                            $isCorrectAns = (!empty($ans['is_correct']));

                            if ($isMyPick && $isCorrectAns) {
                                $tileExtra = 'is-chosen-correct';
                            } elseif ($isMyPick && !$isCorrectAns) {
                                $tileExtra = 'is-chosen-wrong';
                            } elseif ($isCorrectAns) {
                                $tileExtra = 'is-correct-reveal';
                            } else {
                                $tileExtra = 'is-dimmed';
                            }
                        }
                        ?>
                        <button type="button" 
                                class="review-ans-tile <?= $colorClass ?> <?= $tileExtra ?>" 
                                <?= $hasAnswered ? 'disabled' : '' ?>
                                onclick="chooseAnswer(<?= $ansId ?>)">
                            <div class="d-flex align-items-center" style="gap: 10px; flex: 1; min-width: 0;">
                                <span style="font-size: 1.2rem;"><?= $shape ?></span>
                                <?php if (!empty($ans['image_path'])): ?>
                                    <img src="<?= BASE_URL . htmlspecialchars($ans['image_path']) ?>" alt="Answer" style="max-height: 48px; max-width: 60px; object-fit: cover; border-radius: 6px;">
                                <?php else: ?>
                                    <span style="white-space: normal; line-height: 1.25;"><?= htmlspecialchars($ans['text']) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($hasAnswered && !empty($ans['is_correct'])): ?>
                                <i class="fas fa-check-circle fa-lg ml-2" title="Correct answer"></i>
                            <?php elseif ($hasAnswered && intval($myVote['id_answer']) === $ansId && empty($ans['is_correct'])): ?>
                                <i class="fas fa-times-circle fa-lg ml-2" title="Your choice"></i>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($hasAnswered): ?>
            <!-- Nút chuyển câu tiếp theo hoặc hoàn thành -->
            <form method="POST" class="mt-4">
                <?php if ($pIdx + 1 < $totalQuestions): ?>
                    <input type="hidden" name="action" value="next_question">
                    <button type="submit" class="btn btn-kahoot btn-kahoot-purple btn-lg px-5 py-3 shadow font-weight-bold" style="border-radius: 30px;">
                        Next Question <i class="fas fa-arrow-right ml-1"></i>
                    </button>
                <?php else: ?>
                    <input type="hidden" name="action" value="finish_review">
                    <button type="submit" class="btn btn-success btn-lg px-5 py-3 shadow font-weight-bold" style="border-radius: 30px;">
                        <i class="fas fa-trophy mr-1"></i> View Review Results
                    </button>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
function chooseAnswer(ansId) {
    document.getElementById('selectedAnsId').value = ansId;
    document.getElementById('ansForm').submit();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
