<?php
// Shared by the host and player when the room has finished. All queries are read-only.
require_once __DIR__ . '/player_characters.php';
$resultsPlayerId = $resultsPlayerId ?? 0;
$isHostResults = $resultsPlayerId === 0;
$isReviewMode = !empty($room['is_review_mode']);
$quiz = db_fetch_one('SELECT name FROM quiz WHERE id_quiz = :q', [':q' => $room['id_quiz']]);
$questionCount = db_count('questions', 'id_quiz = :q', [':q' => $room['id_quiz']]);
$participantCount = count($leaderboard);
$myResult = null;
$myRank = null;
$resultCharacters = [];
foreach ($leaderboard as $entry) {
    $resultCharacters[$entry['id_player']] = get_player_appearance($room, (int) $entry['id_player']);
}
foreach ($leaderboard as $index => $entry) {
    if ((int) $entry['id_player'] === $resultsPlayerId) {
        $myResult = $entry;
        $myRank = $index + 1;
        break;
    }
}
$playerQuestionReview = [];
if (!$isHostResults && $myResult !== null) {
    $reviewQuestionIds = !empty($room['question_order']) ? json_decode($room['question_order'], true) : null;
    if (!is_array($reviewQuestionIds) || empty($reviewQuestionIds)) {
        $fallbackReviewQuestions = db_fetch_all(
            'SELECT id_question FROM questions WHERE id_quiz = :q ORDER BY id_question ASC',
            [':q' => $room['id_quiz']]
        );
        $reviewQuestionIds = array_column($fallbackReviewQuestions, 'id_question');
    }

    $reviewQuestionIds = array_values(array_unique(array_filter(array_map('intval', $reviewQuestionIds))));
    if (!empty($reviewQuestionIds)) {
        $reviewIdsSql = implode(',', $reviewQuestionIds);
        $isUserLoggedIn = !empty($_SESSION['id_creator']);
        $creatorId = (int)($_SESSION['id_creator'] ?? 0);
        if ($isUserLoggedIn) {
            $savedReviewRows = db_fetch_all(
                'SELECT id_question FROM saved_review_questions WHERE id_creator = :cid',
                [':cid' => $creatorId]
            );
        } else {
            $savedReviewRows = [];
        }
        $savedReviewIds = array_flip(array_map('intval', array_column($savedReviewRows, 'id_question')));
        $reviewQuestions = db_fetch_all(
            "SELECT * FROM questions WHERE id_quiz = :quiz AND id_question IN ($reviewIdsSql)",
            [':quiz' => $room['id_quiz']]
        );
        $reviewQuestionsById = [];
        foreach ($reviewQuestions as $reviewQuestion) {
            $reviewQuestionsById[(int) $reviewQuestion['id_question']] = $reviewQuestion;
        }

        $reviewVotes = db_fetch_all(
            "SELECT a.id_question, v.id_answer, a.is_correct FROM votes v JOIN answers a ON a.id_answer = v.id_answer WHERE v.id_player = :pid AND a.id_question IN ($reviewIdsSql)",
            [':pid' => $resultsPlayerId]
        );
        $reviewVotesByQuestion = [];
        foreach ($reviewVotes as $reviewVote) {
            $reviewVotesByQuestion[(int) $reviewVote['id_question']] = $reviewVote;
        }

        $reviewAnswers = db_fetch_all(
            "SELECT * FROM answers WHERE id_question IN ($reviewIdsSql) ORDER BY id_question ASC, id_answer ASC"
        );
        $reviewAnswersByQuestion = [];
        foreach ($reviewAnswers as $reviewAnswer) {
            $reviewAnswersByQuestion[(int) $reviewAnswer['id_question']][] = $reviewAnswer;
        }

        foreach ($reviewQuestionIds as $reviewIndex => $reviewQuestionId) {
            if (!isset($reviewQuestionsById[$reviewQuestionId], $reviewVotesByQuestion[$reviewQuestionId])) continue;

            $reviewQuestion = $reviewQuestionsById[$reviewQuestionId];
            $reviewVote = $reviewVotesByQuestion[$reviewQuestionId];
            $questionAnswers = $reviewAnswersByQuestion[$reviewQuestionId] ?? [];
            foreach ($questionAnswers as &$reviewAnswer) {
                $reviewAnswer['is_player_choice'] = (int) $reviewAnswer['id_answer'] === (int) $reviewVote['id_answer'];
            }
            unset($reviewAnswer);

            $reviewQuestion['number'] = $reviewIndex + 1;
            $reviewQuestion['player_was_correct'] = (int) $reviewVote['is_correct'] === 1;
            $reviewQuestion['is_saved'] = isset($savedReviewIds[$reviewQuestionId]);
            $reviewQuestion['answers'] = $questionAnswers;
            $playerQuestionReview[] = $reviewQuestion;
        }
    }
}
$reviewMediaUrl = static function (?string $path): string {
    if (!$path) return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    $filename = basename($path);
    $diskPath = dirname(__DIR__) . '/assets/img/questions/' . $filename;
    return is_file($diskPath) ? BASE_URL . 'assets/img/questions/' . rawurlencode($filename) : '';
};
$pageTitle = 'Final Results - ' . ($quiz['name'] ?? 'Kahootify');
$bodyClass = 'final-results-page';
$pageStyles = ['assets/css/final-results.css'];
$rankSuffix = static function (int $rank): string {
    if ($rank % 100 >= 11 && $rank % 100 <= 13) return 'th';
    return [1 => 'st', 2 => 'nd', 3 => 'rd'][$rank % 10] ?? 'th';
};
$renderMedal = static function (int $rank): string {
    return '<svg class="results-medal medal-rank-' . min($rank, 4) . '" viewBox="0 0 100 110" aria-hidden="true" focusable="false">'
        . '<path class="medal-edge" d="M23 4h54l20 64-47 38L3 68z"/>'
        . '<path class="medal-face" d="M26 9h48l17 57-41 33L9 66z"/>'
        . '<path class="medal-light" d="M26 9l9 17-11 36L9 66zM26 9h48L65 26H35z"/>'
        . '<path class="medal-shade" d="M74 9l17 57-41 33V80l26-20-11-34z"/>'
        . '<text x="50" y="72" text-anchor="middle" style="font-size:' . ($rank < 10 ? 62 : ($rank < 100 ? 48 : 36)) . 'px">' . $rank . '</text></svg>';
};
require __DIR__ . '/header.php';
?>
<div class="results-shell <?= $isHostResults ? 'results-host' : 'results-player' ?> <?= $isReviewMode ? 'is-review-mode' : '' ?>" id="finalResults" data-host="<?= $isHostResults ? 'true' : 'false' ?>" data-review="<?= $isReviewMode ? 'true' : 'false' ?>">
    <canvas class="results-confetti" id="resultsConfetti" aria-hidden="true"></canvas>
    <main class="results-main">
        <?php if ($myResult !== null): ?>
            <section class="results-personal" aria-label="Your final result">
                <div class="results-personal-main">
                    <?php if ($isReviewMode): ?>
                        <?php 
                        $reviewCorrect = (int) ($myResult['correct_count'] ?? $myResult['answers_correct'] ?? 0);
                        $reviewPct = round(($reviewCorrect / max(1, $questionCount)) * 100);
                        ?>
                        <div class="mb-2"><i class="fas fa-book-reader fa-3x text-success"></i></div>
                        <h1 class="results-personal-rank">Review Results</h1>
                        <p class="results-personal-message">You have completed all questions!</p>
                        <?= player_appearance_image($resultCharacters[$myResult['id_player']], 'results-personal-character') ?>
                        <p class="results-personal-correct font-weight-bold" style="font-size: 1.25rem; color:#15803d;">
                            <?= $reviewCorrect ?> / <?= $questionCount ?> correct (<?= $reviewPct ?>%)
                        </p>
                        <div class="results-personal-actions">
                            <a href="<?= BASE_URL ?>modules/player/review.php?pid=<?= $resultsPlayerId ?>&token=<?= urlencode($token ?? '') ?>&retake=1" class="results-button results-button-light">
                                <i class="fas fa-redo mr-1"></i> Retake quiz
                            </a>
                        </div>
                    <?php else: ?>
                        <?= $renderMedal($myRank) ?>
                        <h1 class="results-personal-rank"><?= $myRank ?><sup><?= $rankSuffix($myRank) ?></sup> place</h1>
                        <p class="results-personal-message"><?= $myRank === 1 ? 'You are the champion!' : ($myRank <= 3 ? 'You made the podium!' : 'Thanks for playing!') ?></p>
                        <?= player_appearance_image($resultCharacters[$myResult['id_player']], 'results-personal-character') ?>
                        <p class="results-personal-correct"><?= (int) $myResult['correct_count'] ?> out of <?= $questionCount ?> correct</p>
                        <p class="results-personal-total">Out of <?= $participantCount ?> <?= $participantCount === 1 ? 'player' : 'players' ?></p>
                        <div class="results-personal-actions">
                            <a href="#ceremonyStage" class="results-button results-button-light">View podium</a>
                            <a href="<?= BASE_URL ?>modules/player/join.php" class="results-button">Join another game</a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="results-personal-footer">
                    <strong><?= htmlspecialchars($myResult['nickname']) ?></strong>
                    <?php if ($isReviewMode): ?>
                        <span class="text-success font-weight-bold"><?= round(((int) ($myResult['correct_count'] ?? $myResult['answers_correct'] ?? 0) / max(1, $questionCount)) * 100) ?>% <small>accuracy</small></span>
                    <?php else: ?>
                        <span><?= number_format($myResult['score']) ?> <small>points</small></span>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!$isReviewMode): ?>
        <section class="ceremony-stage" id="ceremonyStage" aria-label="Final podium">
            <div class="results-heading">
                <h1><?= htmlspecialchars($quiz['name'] ?? 'Game results') ?></h1>
                <p>Podium</p>
            </div>
            <div class="results-toolbar">
                <?php if ($isHostResults && $participantCount > 0): ?>
                    <button class="results-icon-button" id="resultsFullscreen" type="button" aria-label="Enter fullscreen" title="Fullscreen" hidden>&#x26F6;</button>
                    <button class="results-button" id="resultsMusic" type="button" aria-pressed="true" hidden>Music: On</button>
                    <button class="results-button results-button-light" id="resultsSkip" type="button" hidden>Skip</button>
                <?php endif; ?>
            </div>
            <div class="results-spotlight" aria-hidden="true"><span></span></div>
        <?php if ($participantCount > 0): ?>
            <p class="results-sr-only" id="resultsAnnouncement" role="status" aria-live="polite">Congratulations to our champions!</p>
            <section class="results-podium" aria-label="Top three players">
                <?php foreach ([2, 1, 3] as $place):
                    $winner = $leaderboard[$place - 1] ?? null;
                ?>
                    <article class="results-winner results-place-<?= $place ?> <?= $winner ? '' : 'results-vacant' ?>" data-place="<?= $place ?>" data-nickname="<?= htmlspecialchars($winner['nickname'] ?? '', ENT_QUOTES, 'UTF-8') ?>" aria-label="Place <?= $place ?><?= $winner ? '' : ': no player' ?>">
                        <?php if ($winner): ?>
                        <div class="results-winner-identity">
                            <h2><?= htmlspecialchars($winner['nickname']) ?></h2>
                            <?= player_appearance_image($resultCharacters[$winner['id_player']], 'results-podium-character') ?>
                        </div>
                        <?php endif; ?>
                        <div class="results-plinth">
                            <?= $renderMedal($place) ?>
                            <?php if ($winner): ?>
                            <p class="results-winner-score"><strong><?= number_format($winner['score']) ?></strong> points</p>
                            <p class="results-winner-correct"><?= (int) $winner['correct_count'] ?> out of <?= $questionCount ?></p>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php else: ?>
            <section class="results-empty"><span aria-hidden="true">&#9734;</span><h2>No players this time</h2><p>Host another quiz and invite players to join the fun.</p></section>
        <?php endif; ?>
            <div class="results-stage-footer"><span class="results-brand">Kahootify!</span><span>Game PIN: <strong><?= htmlspecialchars($room['pin']) ?></strong></span></div>
        </section>
        <?php endif; ?>

        <div class="results-afterparty" id="resultsAfterparty">
        <?php if ($participantCount > 0): ?>
            <?php if (!$isHostResults && $myResult !== null): ?>
                <section class="results-review" aria-labelledby="resultsReviewTitle">
                    <div class="results-review-heading">
                        <div>
                            <h2 id="resultsReviewTitle">Review your answers</h2>
                            <p>See your choice and the correct answer for each question you answered.</p>
                        </div>
                        <span><?= count($playerQuestionReview) ?> answered</span>
                    </div>
                    <?php if (!empty($isUserLoggedIn)): ?>
                    <?php if (empty($isUserLoggedIn)): ?>
                    <div class="mb-4 p-3 rounded text-center" style="background: #faf7fe; border: 1px dashed #d0bfe3; color: #46178f; font-size: 13px;">
                        <i class="fas fa-info-circle mr-1"></i> <a href="#" data-toggle="modal" data-target="#loginModal" class="font-weight-bold" style="color: #46178f; text-decoration: underline;">Log in</a> to save questions to your dashboard.
                    </div>
                    <?php endif; ?>
                    <section class="results-saved-review" aria-labelledby="resultsSavedReviewTitle">
                        <div class="results-saved-review-heading">
                            <h3 id="resultsSavedReviewTitle"><i class="far fa-bookmark" aria-hidden="true"></i> Saved questions</h3>
                            <span id="resultsSavedReviewCount"><?= count(array_filter($playerQuestionReview, static function ($question) { return !empty($question['is_saved']); })) ?></span>
                        </div>
                        <p class="results-saved-review-empty" id="resultsSavedReviewEmpty" <?= array_filter($playerQuestionReview, static function ($question) { return !empty($question['is_saved']); }) ? 'hidden' : '' ?>>Save questions here to revisit them later.</p>
                        <ul class="results-saved-review-list" id="resultsSavedReviewList">
                            <?php foreach ($playerQuestionReview as $savedQuestion): if (empty($savedQuestion['is_saved'])) continue; ?>
                                <li data-saved-question="<?= (int) $savedQuestion['id_question'] ?>">
                                    <a href="#review-question-<?= (int) $savedQuestion['id_question'] ?>">
                                        <strong>Question <?= (int) $savedQuestion['number'] ?>:</strong>
                                        <?= htmlspecialchars($savedQuestion['text'] ?? '') ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                    <?php endif; ?>
                    <?php if (empty($playerQuestionReview)): ?>
                        <p class="results-review-empty">No submitted answers were recorded for this game.</p>
                    <?php else: ?>
                        <div class="results-review-list">
                            <?php foreach ($playerQuestionReview as $reviewQuestion):
                                $reviewImageUrl = $reviewMediaUrl($reviewQuestion['image'] ?? null);
                                $reviewImageExt = strtolower(pathinfo(parse_url($reviewImageUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                                $reviewIsVideo = in_array($reviewImageExt, ['mp4', 'webm'], true) || ($reviewQuestion['media_type'] ?? '') === 'video';
                                $reviewSymbols = ['▲', '◆', '●', '■'];
                            ?>
                                <article class="results-review-question" id="review-question-<?= (int) $reviewQuestion['id_question'] ?>">
                                    <div class="results-review-question-heading">
                                        <span class="results-review-number">Question <?= (int) $reviewQuestion['number'] ?></span>
                                        <div class="results-review-question-actions">
                                            <span class="results-review-outcome <?= $reviewQuestion['player_was_correct'] ? 'is-correct' : 'is-incorrect' ?>">
                                                <?= $reviewQuestion['player_was_correct'] ? 'Correct' : 'Incorrect' ?>
                                            </span>
                                            <?php if (!empty($isUserLoggedIn)): ?>
                                            <button class="results-review-save <?= $reviewQuestion['is_saved'] ? 'is-saved' : '' ?>" type="button"
                                                data-question-id="<?= (int) $reviewQuestion['id_question'] ?>"
                                                data-question-number="<?= (int) $reviewQuestion['number'] ?>"
                                                aria-pressed="<?= $reviewQuestion['is_saved'] ? 'true' : 'false' ?>">
                                                <i class="<?= $reviewQuestion['is_saved'] ? 'fas' : 'far' ?> fa-bookmark" aria-hidden="true"></i>
                                                <span><?= $reviewQuestion['is_saved'] ? 'Saved' : 'Save for review' ?></span>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <h3><?= htmlspecialchars($reviewQuestion['text'] ?? '') ?></h3>
                                    <?php if ($reviewImageUrl): ?>
                                        <div class="results-review-media">
                                            <?php if ($reviewIsVideo): ?>
                                                <video src="<?= htmlspecialchars($reviewImageUrl, ENT_QUOTES, 'UTF-8') ?>" controls preload="metadata"></video>
                                            <?php else: ?>
                                                <img src="<?= htmlspecialchars($reviewImageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Question illustration" loading="lazy">
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="results-review-answers">
                                        <?php foreach ($reviewQuestion['answers'] as $answerIndex => $reviewAnswer):
                                            $answerImageUrl = $reviewMediaUrl($reviewAnswer['image_path'] ?? null);
                                            $isCorrectAnswer = (int) $reviewAnswer['is_correct'] === 1;
                                            $isPlayerChoice = !empty($reviewAnswer['is_player_choice']);
                                        ?>
                                            <div class="results-review-answer <?= $isCorrectAnswer ? 'is-correct-answer' : '' ?> <?= $isPlayerChoice ? 'is-player-choice' : '' ?>">
                                                <span class="results-review-answer-symbol"><?= htmlspecialchars($reviewSymbols[$answerIndex % 4]) ?></span>
                                                <div class="results-review-answer-content">
                                                    <?php if (!empty($reviewAnswer['text'])): ?>
                                                        <span><?= htmlspecialchars($reviewAnswer['text']) ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($answerImageUrl): ?>
                                                        <img src="<?= htmlspecialchars($answerImageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Answer illustration" loading="lazy">
                                                    <?php endif; ?>
                                                </div>
                                                <div class="results-review-answer-labels">
                                                    <?php if ($isPlayerChoice): ?><span class="results-review-choice-label">Your answer</span><?php endif; ?>
                                                    <?php if ($isCorrectAnswer): ?><span class="results-review-correct-label">Correct answer</span><?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if (!$isReviewMode && $participantCount > 3): ?>
                <div class="results-runners" id="resultsRunners">
                    <span>A big hand for our runners-up</span>
                    <?php foreach (array_slice($leaderboard, 3, 2) as $index => $runner): ?>
                        <span class="results-runner"><strong><?= $index + 4 ?>th</strong> <?= player_appearance_image($resultCharacters[$runner['id_player']], 'results-runner-character') ?> <?= htmlspecialchars($runner['nickname']) ?> <small><?= number_format($runner['score']) ?> pts</small></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!$isReviewMode): ?>
            <div class="results-controls">
                <?php if ($isHostResults): ?>
                    <button class="results-button" id="resultsReplay" type="button" hidden>Replay podium</button>
                <?php endif; ?>
                <button class="results-button results-button-light" id="resultsStandingsToggle" type="button" aria-expanded="true" aria-controls="resultsStandings" hidden>Hide full standings</button>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!$isReviewMode && $participantCount > 0): ?>
            <section class="results-standings" id="resultsStandings" aria-labelledby="resultsStandingsTitle">
                <div class="results-standings-heading"><h2 id="resultsStandingsTitle">Final standings</h2><span><?= $participantCount ?> <?= $participantCount === 1 ? 'player' : 'players' ?></span></div>
                <div class="results-table-scroll">
                    <table class="results-table">
                        <caption class="results-sr-only">Players ranked by total points. Equal scores are ordered by joining time.</caption>
                        <thead><tr><th scope="col">Rank</th><th scope="col">Player</th><th scope="col">Correct</th><th scope="col">Points</th></tr></thead>
                        <tbody>
                            <?php foreach ($leaderboard as $index => $entry): $isMe = (int) $entry['id_player'] === $resultsPlayerId; ?>
                                <tr class="<?= $isMe ? 'results-my-row' : '' ?>">
                                    <td><span class="results-table-rank results-table-rank-<?= min($index + 1, 4) ?>"><?= $index + 1 ?></span></td>
                                    <th scope="row"><div class="results-player-name"><?= player_appearance_image($resultCharacters[$entry['id_player']], 'results-table-character') ?><span><?= htmlspecialchars($entry['nickname']) ?><?php if ($isMe): ?> <span class="results-you">YOU</span><?php endif; ?></span></div></th>
                                    <td><?= (int) $entry['correct_count'] ?><span class="results-question-total"> / <?= $questionCount ?></span></td>
                                    <td><?= number_format($entry['score']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="results-tie-note">Equal scores are ordered by joining time.</p>
            </section>
        <?php endif; ?>
        <footer class="results-footer"><a class="results-button" href="<?= BASE_URL ?><?= $isHostResults ? 'modules/auth/dashboard.php' : 'modules/player/join.php' ?>"><?= $isHostResults ? 'Back to dashboard' : 'Join another game' ?></a><p>Every answer is a step forward. Thanks for playing!</p></footer>
        </div>
    </main>
    <?php if ($isHostResults && $participantCount > 0): ?>
        <audio id="resultsAudio" preload="auto" loop src="<?= BASE_URL ?>assets/audio/podium-soundtrack.mp3"></audio>
    <?php endif; ?>
</div>
<?php if (!$isHostResults && $myResult !== null && !empty($isUserLoggedIn)): ?>
<script>
const savedReviewConfig = <?= json_encode([
    'url' => BASE_URL . 'modules/api/toggle_saved_review_question.php',
    'playerId' => $resultsPlayerId,
    'roomId' => (int) $room['id_room'],
    'token' => (string) (!empty($token) ? $token : substr(md5($resultsPlayerId . '_' . (int)$room['id_room'] . '_kahoot_secret_2026'), 0, 16))
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

document.querySelectorAll('.results-review-save').forEach(function(button) {
    button.addEventListener('click', async function() {
        const questionId = Number(button.dataset.questionId);
        const shouldSave = button.getAttribute('aria-pressed') !== 'true';
        button.disabled = true;

        try {
            const response = await fetch(savedReviewConfig.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id_player: savedReviewConfig.playerId,
                    id_room: savedReviewConfig.roomId,
                    id_question: questionId,
                    token: savedReviewConfig.token,
                    saved: shouldSave
                })
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Unable to save this question.');

            button.setAttribute('aria-pressed', result.saved ? 'true' : 'false');
            button.classList.toggle('is-saved', result.saved);
            button.querySelector('i').className = (result.saved ? 'fas' : 'far') + ' fa-bookmark';
            button.querySelector('span').textContent = result.saved ? 'Saved' : 'Save for review';

            const savedList = document.getElementById('resultsSavedReviewList');
            const savedItem = savedList.querySelector('[data-saved-question="' + questionId + '"]');
            if (result.saved && !savedItem) {
                const questionCard = document.getElementById('review-question-' + questionId);
                const item = document.createElement('li');
                item.dataset.savedQuestion = String(questionId);
                const link = document.createElement('a');
                link.href = '#review-question-' + questionId;
                const label = document.createElement('strong');
                label.textContent = 'Question ' + button.dataset.questionNumber + ': ';
                link.append(label, document.createTextNode(questionCard.querySelector('h3').textContent));
                item.appendChild(link);
                savedList.appendChild(item);
            } else if (!result.saved && savedItem) {
                savedItem.remove();
            }

            const savedCount = savedList.children.length;
            document.getElementById('resultsSavedReviewCount').textContent = String(savedCount);
            document.getElementById('resultsSavedReviewEmpty').hidden = savedCount > 0;
        } catch (error) {
            window.alert('Could not update your saved questions. Please try again.');
        } finally {
            button.disabled = false;
        }
    });
});
</script>
<?php endif; ?>
<script src="<?= BASE_URL ?>assets/js/final-results.js" defer></script>
</body>
</html>
