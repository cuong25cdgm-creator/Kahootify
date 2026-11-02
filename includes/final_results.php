<?php
// Shared by the host and player when the room has finished. All queries are read-only.
require_once __DIR__ . '/player_characters.php';
require_once __DIR__ . '/modes/WagerCalculator.php';
require_once __DIR__ . '/TeamManager.php';

$leaderboard = WagerCalculator::calculateFinalRanking($leaderboard);
$resultsPlayerId = $resultsPlayerId ?? 0;
$isHostResults = $resultsPlayerId === 0;
$quiz = db_fetch_one('SELECT name FROM quiz WHERE id_quiz = :q', [':q' => $room['id_quiz']]);
$questionCount = db_count('questions', 'id_quiz = :q', [':q' => $room['id_quiz']]);
$participantCount = count($leaderboard);

$isTeamMode = TeamManager::isTeamMode($room);
$teamLeaderboard = $isTeamMode ? TeamManager::getTeamLeaderboard((int)$room['id_room']) : [];
$teamCount = count($teamLeaderboard);

$myResult = null;
$myRank = null;
$myTeam = null;
$myTeamRank = null;

$resultCharacters = [];
foreach ($leaderboard as $entry) {
    $resultCharacters[$entry['id_player']] = get_player_appearance($room, (int) $entry['id_player']);
}
foreach ($leaderboard as $index => $entry) {
    if ((int) $entry['id_player'] === $resultsPlayerId) {
        $myResult = $entry;
        $myRank = $entry['rank'] ?? null;
        break;
    }
}

if ($isTeamMode && $myResult !== null && !empty($myResult['team_id'])) {
    foreach ($teamLeaderboard as $t) {
        if ($t['id'] === (int)$myResult['team_id']) {
            $myTeam = $t;
            $myTeamRank = $t['rank'] ?? null;
            break;
        }
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
$rankSuffix = static function (?int $rank): string {
    if (!$rank) return '';
    if ($rank % 100 >= 11 && $rank % 100 <= 13) return 'th';
    return [1 => 'st', 2 => 'nd', 3 => 'rd'][$rank % 10] ?? 'th';
};
$renderMedal = static function (?int $rank): string {
    if (!$rank) return '';
    return '<svg class="results-medal medal-rank-' . min($rank, 4) . '" viewBox="0 0 100 110" aria-hidden="true" focusable="false">'
        . '<path class="medal-edge" d="M23 4h54l20 64-47 38L3 68z"/>'
        . '<path class="medal-face" d="M26 9h48l17 57-41 33L9 66z"/>'
        . '<path class="medal-light" d="M26 9l9 17-11 36L9 66zM26 9h48L65 26H35z"/>'
        . '<path class="medal-shade" d="M74 9l17 57-41 33V80l26-20-11-34z"/>'
        . '<text x="50" y="72" text-anchor="middle" style="font-size:' . ($rank < 10 ? 62 : ($rank < 100 ? 48 : 36)) . 'px">' . $rank . '</text></svg>';
};
require __DIR__ . '/header.php';
?>

<style>
.team-standings-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
    margin-top: 20px;
    text-align: left;
}
.team-standing-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 16px 20px;
    box-shadow: 0 4px 14px rgba(0,0,0,0.06);
    border: 2px solid #eef0f3;
    cursor: pointer;
    transition: all 0.22s cubic-bezier(0.2, 0.8, 0.4, 1);
    position: relative;
    overflow: hidden;
}
.team-standing-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 26px rgba(0,0,0,0.12);
    border-color: var(--team-color, #1368ce);
}
.team-color-strip {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 8px;
    background: var(--team-color, #1368ce);
}
.team-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 12px;
}
.team-rank-badge {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 900;
    font-size: 1.15rem;
    color: #fff;
    background: #757575;
}
.team-rank-1 { background: linear-gradient(135deg, #ffd700, #ffb300); color: #333; box-shadow: 0 2px 8px rgba(255, 193, 7, 0.5); }
.team-rank-2 { background: linear-gradient(135deg, #e0e0e0, #bdbdbd); color: #333; box-shadow: 0 2px 8px rgba(189, 189, 189, 0.5); }
.team-rank-3 { background: linear-gradient(135deg, #cd7f32, #b87333); color: #fff; box-shadow: 0 2px 8px rgba(205, 127, 50, 0.5); }
.team-top3-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}
.team-top3-chip {
    display: inline-flex;
    align-items: center;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 20px;
    padding: 4px 12px 4px 6px;
    gap: 8px;
    font-size: 0.85rem;
    color: #333;
}
.team-top3-avatar {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    overflow: hidden;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #eee;
}
.team-top3-avatar svg {
    width: 100%;
    height: 100%;
}
.team-detail-table {
    width: 100%;
    border-collapse: collapse;
}
.team-detail-table th, .team-detail-table td {
    padding: 10px 14px;
    text-align: left;
    border-bottom: 1px solid #eee;
}
</style>

<div class="results-shell <?= $isHostResults ? 'results-host' : 'results-player' ?>" id="finalResults" data-host="<?= $isHostResults ? 'true' : 'false' ?>">
    <canvas class="results-confetti" id="resultsConfetti" aria-hidden="true"></canvas>
    <main class="results-main">
        <?php if ($myResult !== null): ?>
            <section class="results-personal" aria-label="Your final result">
                <div class="results-personal-main">
                    <?php if ($isTeamMode && $myTeam !== null): ?>
                        <div class="mb-2">
                            <span class="badge font-weight-bold px-3 py-1 shadow-sm" style="font-size: 0.95rem; background: <?= $myTeam['color'] ?>; color: #fff; border-radius: 20px;">
                                <i class="<?= $myTeam['icon'] ?> mr-1"></i> <?= htmlspecialchars($myTeam['name']) ?>
                            </span>
                        </div>
                        <?php if ($myTeamRank !== null): ?>
                            <?= $renderMedal($myTeamRank) ?>
                            <h1 class="results-personal-rank"><?= $myTeamRank ?><sup><?= $rankSuffix($myTeamRank) ?></sup> place</h1>
                            <p class="results-personal-message">
                                <?= $myTeamRank === 1 ? 'Your team won the championship!' : ($myTeamRank <= 3 ? 'Your team made the podium!' : 'Great effort by your team!') ?>
                            </p>
                        <?php endif; ?>
                    <?php elseif ($myRank !== null): ?>
                        <?= $renderMedal($myRank) ?>
                        <h1 class="results-personal-rank"><?= $myRank ?><sup><?= $rankSuffix($myRank) ?></sup> place</h1>
                        <p class="results-personal-message"><?= $myRank === 1 ? 'You are the champion!' : ($myRank <= 3 ? 'You made the podium!' : 'Thanks for playing!') ?></p>
                    <?php else: ?>
                        <div style="font-size: 3rem;" class="mb-2">👀</div>
                        <h1 class="results-personal-rank">Spectator</h1>
                        <p class="results-personal-message">You watched this game as a spectator.</p>
                    <?php endif; ?>
                    <?= player_appearance_image($resultCharacters[$myResult['id_player']], 'results-personal-character') ?>
                    <p class="results-personal-correct"><?= (int) $myResult['correct_count'] ?> out of <?= $questionCount ?> correct</p>
                    <?php if ($isTeamMode && $myTeam !== null): ?>
                        <p class="results-personal-total">Đội đạt <strong><?= number_format($myTeam['total_score']) ?></strong> điểm &bull; <?= $myTeam['member_count'] ?> thành viên</p>
                    <?php else: ?>
                        <p class="results-personal-total">Out of <?= $participantCount ?> <?= $participantCount === 1 ? 'player' : 'players' ?></p>
                    <?php endif; ?>
                    <div class="results-personal-actions">
                        <a href="#ceremonyStage" class="results-button results-button-light">View podium</a>
                        <a href="<?= BASE_URL ?>modules/player/join.php" class="results-button">Join another game</a>
                    </div>
                </div>
                <div class="results-personal-footer">
                    <strong>
                        <?= htmlspecialchars($myResult['nickname']) ?>
                        <?php if ($isTeamMode && $myTeam !== null): ?>
                            <small style="opacity:0.85; margin-left: 6px;">(<?= htmlspecialchars($myTeam['short_name']) ?>)</small>
                        <?php endif; ?>
                    </strong>
                    <span><?= number_format($myResult['score']) ?> <small><?= $isTeamMode ? 'personal pts' : 'points' ?></small></span>
                </div>
            </section>
        <?php endif; ?>

        <section class="ceremony-stage" id="ceremonyStage" aria-label="Final podium">
            <div class="results-heading">
                <h1><?= htmlspecialchars($quiz['name'] ?? 'Game results') ?></h1>
                <p><?= $isTeamMode ? 'Team Podium' : 'Podium' ?></p>
            </div>
            <div class="results-toolbar">
                <?php if ($isHostResults && ($isTeamMode ? $teamCount > 0 : $participantCount > 0)): ?>
                    <button class="results-icon-button" id="resultsFullscreen" type="button" aria-label="Enter fullscreen" title="Fullscreen" hidden>&#x26F6;</button>
                    <button class="results-button" id="resultsMusic" type="button" aria-pressed="true" hidden>Music: On</button>
                    <button class="results-button results-button-light" id="resultsSkip" type="button" hidden>Skip</button>
                <?php endif; ?>
            </div>
            <div class="results-spotlight" aria-hidden="true"><span></span></div>
        <?php if ($isTeamMode ? $teamCount > 0 : $participantCount > 0): ?>
            <p class="results-sr-only" id="resultsAnnouncement" role="status" aria-live="polite">Congratulations to our champions!</p>
            
            <?php if ($isTeamMode): ?>
            <!-- TEAM PODIUM -->
            <section class="results-podium results-podium-team" aria-label="Top three teams">
                <?php foreach ([2, 1, 3] as $place):
                    $winningTeam = $teamLeaderboard[$place - 1] ?? null;
                    if ($winningTeam && empty($winningTeam['rank'])) {
                        $winningTeam = null;
                    }
                    $teamName = $winningTeam['name'] ?? '';
                    $mvp = (!empty($winningTeam['top_3_players'][0])) ? $winningTeam['top_3_players'][0] : null;
                ?>
                    <article class="results-winner results-place-<?= $place ?> <?= $winningTeam ? '' : 'results-vacant' ?>" data-place="<?= $place ?>" data-nickname="<?= htmlspecialchars($teamName, ENT_QUOTES, 'UTF-8') ?>" aria-label="Place <?= $place ?><?= $winningTeam ? '' : ': no team' ?>">
                        <?php if ($winningTeam): ?>
                        <div class="results-winner-identity">
                            <span class="badge team-podium-tag mb-1 font-weight-bold" style="background: <?= $winningTeam['color'] ?>; color: #fff; font-size: 0.82rem; padding: 4px 10px; border-radius: 12px;">
                                <i class="<?= $winningTeam['icon'] ?> mr-1"></i><?= htmlspecialchars($winningTeam['short_name']) ?>
                            </span>
                            <h2 style="font-size: 1.25rem; margin-bottom: 2px;"><?= htmlspecialchars($winningTeam['name']) ?></h2>
                            <?php if ($mvp): ?>
                                <div class="team-mvp-pill small font-weight-bold" style="color: #ffd700; text-shadow: 0 1px 3px rgba(0,0,0,0.5); margin-bottom: 4px;">
                                    <i class="fas fa-crown mr-1"></i> MVP: <?= htmlspecialchars($mvp['nickname']) ?>
                                </div>
                                <?= player_appearance_image($resultCharacters[$mvp['id_player']] ?? get_player_appearance($room, (int)$mvp['id_player']), 'results-podium-character') ?>
                            <?php else: ?>
                                <div class="team-emblem-large my-3" style="font-size: 3.5rem; color: <?= $winningTeam['color'] ?>;">
                                    <i class="<?= $winningTeam['icon'] ?>"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="results-plinth" style="border-top: 4px solid <?= $winningTeam['color'] ?? '#ffd700' ?>;">
                            <?= $renderMedal($place) ?>
                            <?php if ($winningTeam): ?>
                            <p class="results-winner-score"><strong><?= number_format($winningTeam['total_score']) ?></strong> points</p>
                            <p class="results-winner-correct"><?= $winningTeam['member_count'] ?> <?= $winningTeam['member_count'] === 1 ? 'member' : 'members' ?> &bull; <?= (int)$winningTeam['total_correct'] ?> correct</p>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
            <?php else: ?>
            <!-- STANDARD PLAYER PODIUM -->
            <section class="results-podium" aria-label="Top three players">
                <?php foreach ([2, 1, 3] as $place):
                    $winner = $leaderboard[$place - 1] ?? null;
                    if ($winner && empty($winner['rank'])) {
                        $winner = null;
                    }
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
            <?php endif; ?>

        <?php else: ?>
            <section class="results-empty"><span aria-hidden="true">&#9734;</span><h2>No participants this time</h2><p>Host another quiz and invite players to join the fun.</p></section>
        <?php endif; ?>
            <div class="results-stage-footer"><span class="results-brand">Kahootify!</span><span>Game PIN: <strong><?= htmlspecialchars($room['pin']) ?></strong></span></div>
        </section>

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

            <?php if ($isTeamMode && $teamCount > 3): ?>
                <div class="results-runners" id="resultsRunners">
                    <span>A big hand for our runner-up teams</span>
                    <?php foreach (array_slice($teamLeaderboard, 3, 2) as $index => $runnerTeam): ?>
                        <span class="results-runner">
                            <strong><?= $index + 4 ?>th</strong>
                            <span class="badge mr-1 shadow-sm font-weight-bold" style="background: <?= $runnerTeam['color'] ?>; color: #fff; padding: 2px 8px; border-radius: 8px;">
                                <i class="<?= $runnerTeam['icon'] ?> mr-1"></i><?= htmlspecialchars($runnerTeam['short_name']) ?>
                            </span>
                            <?= htmlspecialchars($runnerTeam['name']) ?>
                            <small><?= number_format($runnerTeam['total_score']) ?> pts</small>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php elseif (!$isTeamMode && $participantCount > 3): ?>
                <div class="results-runners" id="resultsRunners">
                    <span>A big hand for our runners-up</span>
                    <?php foreach (array_slice($leaderboard, 3, 2) as $index => $runner): ?>
                        <span class="results-runner"><strong><?= $index + 4 ?>th</strong> <?= player_appearance_image($resultCharacters[$runner['id_player']], 'results-runner-character') ?> <?= htmlspecialchars($runner['nickname']) ?> <small><?= number_format($runner['score']) ?> pts</small></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="results-controls">
                <?php if ($isHostResults): ?>
                    <button class="results-button" id="resultsReplay" type="button" hidden>Replay podium</button>
                <?php endif; ?>
                <button class="results-button results-button-light" id="resultsStandingsToggle" type="button" aria-expanded="true" aria-controls="resultsStandings" hidden>Hide full standings</button>
            </div>
        <?php endif; ?>

        <?php if ($participantCount > 0): ?>
            <section class="results-standings" id="resultsStandings" aria-labelledby="resultsStandingsTitle">
                <?php if ($isTeamMode): ?>
                    <!-- TEAM LEADERBOARD STANDINGS -->
                    <div class="results-standings-heading">
                        <h2 id="resultsStandingsTitle"><i class="fas fa-users mr-2 text-primary"></i>Bảng xếp hạng các Đội</h2>
                        <span><?= $teamCount ?> Đội &bull; <?= $participantCount ?> Người chơi</span>
                    </div>
                    <p class="text-muted small mb-3">
                        <i class="fas fa-info-circle mr-1"></i> Nhấn vào một Đội để xem chi tiết bảng xếp hạng tất cả thành viên trong đội đó.
                    </p>

                    <div class="team-standings-list">
                        <?php foreach ($teamLeaderboard as $tRankIdx => $teamItem):
                            $tRank = $teamItem['rank'] ?? ($tRankIdx + 1);
                            $rankBadgeClass = ($tRank === 1) ? 'team-rank-1' : (($tRank === 2) ? 'team-rank-2' : (($tRank === 3) ? 'team-rank-3' : ''));
                        ?>
                            <div class="team-standing-card" style="--team-color: <?= $teamItem['color'] ?>;" onclick="openTeamModal(<?= (int)$teamItem['id'] ?>)" role="button" tabindex="0">
                                <div class="team-color-strip"></div>
                                <div class="team-card-header">
                                    <div class="d-flex align-items-center" style="gap: 12px;">
                                        <div class="team-rank-badge <?= $rankBadgeClass ?>">
                                            <?= $tRank ?>
                                        </div>
                                        <div>
                                            <div class="d-flex align-items-center" style="gap: 8px;">
                                                <h3 class="font-weight-bold mb-0" style="font-size: 1.25rem; color: #212529;">
                                                    <i class="<?= $teamItem['icon'] ?> mr-1" style="color: <?= $teamItem['color'] ?>;"></i>
                                                    <?= htmlspecialchars($teamItem['name']) ?>
                                                </h3>
                                                <span class="badge badge-light border text-muted font-weight-bold" style="font-size: 0.78rem;">
                                                    <?= $teamItem['member_count'] ?> thành viên
                                                </span>
                                            </div>
                                            <div class="small text-muted font-weight-bold mt-1">
                                                Tổng đúng: <strong><?= (int)$teamItem['total_correct'] ?></strong> câu
                                            </div>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center" style="gap: 14px;">
                                        <div class="text-right">
                                            <div class="font-weight-bold" style="font-size: 1.45rem; color: #1368ce; line-height: 1;">
                                                <?= number_format($teamItem['total_score']) ?>
                                            </div>
                                            <small class="text-muted font-weight-bold text-uppercase" style="font-size: 0.75rem;">points</small>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-primary font-weight-bold rounded-pill px-3" style="font-size: 0.8rem;" onclick="event.stopPropagation(); openTeamModal(<?= (int)$teamItem['id'] ?>)">
                                            Chi tiết <i class="fas fa-chevron-right ml-1"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- TOP 3 PLAYERS PREVIEW -->
                                <div class="border-top pt-2 mt-2">
                                    <div class="small font-weight-bold text-muted mb-2 text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                                        <i class="fas fa-medal text-warning mr-1"></i> Top 3 cá nhân cao điểm nhất:
                                    </div>
                                    <?php if (!empty($teamItem['top_3_players'])): ?>
                                        <div class="team-top3-grid">
                                            <?php foreach ($teamItem['top_3_players'] as $t3Idx => $topP): 
                                                $pApp = $resultCharacters[$topP['id_player']] ?? get_player_appearance($room, (int)$topP['id_player']);
                                                $isMe = (int)$topP['id_player'] === $resultsPlayerId;
                                            ?>
                                                <div class="team-top3-chip shadow-sm <?= $isMe ? 'border-primary' : '' ?>">
                                                    <span class="badge badge-warning text-dark font-weight-bold" style="font-size: 0.7rem; border-radius: 50%; width: 18px; height: 18px; display: inline-flex; align-items: center; justify-content: center; padding: 0;">
                                                        <?= $t3Idx + 1 ?>
                                                    </span>
                                                    <span class="team-top3-avatar">
                                                        <?= player_appearance_image($pApp, 'team-top3-svg') ?>
                                                    </span>
                                                    <span class="font-weight-bold text-dark text-truncate" style="max-width: 110px;">
                                                        <?= htmlspecialchars($topP['nickname']) ?><?= $isMe ? ' (YOU)' : '' ?>
                                                    </span>
                                                    <span class="badge badge-light border text-primary font-weight-bold">
                                                        <?= number_format($topP['score']) ?> pts
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-muted small font-italic">Chưa có người chơi nào trong đội này.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                <?php else: ?>
                    <!-- STANDARD PLAYER STANDINGS TABLE -->
                    <div class="results-standings-heading"><h2 id="resultsStandingsTitle">Final standings</h2><span><?= $participantCount ?> <?= $participantCount === 1 ? 'player' : 'players' ?></span></div>
                    <div class="results-table-scroll">
                        <table class="results-table">
                            <caption class="results-sr-only">Players ranked by total points. Equal scores are ordered by joining time.</caption>
                            <thead><tr><th scope="col">Rank</th><th scope="col">Player</th><th scope="col">Correct</th><th scope="col">Points</th></tr></thead>
                            <tbody>
                                <?php foreach ($leaderboard as $index => $entry):
                                    $isMe = (int) $entry['id_player'] === $resultsPlayerId;
                                    $pRank = $entry['rank'] ?? null;
                                    $st = strtoupper((string)($entry['status'] ?? 'ACTIVE'));
                                ?>
                                    <tr class="<?= $isMe ? 'results-my-row' : '' ?>">
                                        <td>
                                            <span class="results-table-rank results-table-rank-<?= $pRank ? min((int)$pRank, 4) : 4 ?>">
                                                <?= $pRank !== null ? $pRank : '-' ?>
                                            </span>
                                        </td>
                                        <th scope="row">
                                            <div class="results-player-name">
                                                <?= player_appearance_image($resultCharacters[$entry['id_player']], 'results-table-character') ?>
                                                <span>
                                                    <?= htmlspecialchars($entry['nickname']) ?>
                                                    <?php if ($isMe): ?> <span class="results-you">YOU</span><?php endif; ?>
                                                    <?php if ($st === 'ELIMINATED'): ?>
                                                        <span class="badge badge-dark ml-1 text-white" style="font-size: 0.75rem; font-weight: normal;">Bị loại</span>
                                                    <?php elseif ($st === 'SPECTATOR'): ?>
                                                        <span class="badge badge-secondary ml-1 text-white" style="font-size: 0.75rem; font-weight: normal;">Khán giả</span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        </th>
                                        <td><?= (int) $entry['correct_count'] ?><span class="results-question-total"> / <?= $questionCount ?></span></td>
                                        <td><?= number_format($entry['score']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="results-tie-note">
                        <?php
                        $hasWagerQuestions = db_count('questions', "id_quiz = :q AND mode = 'wager'", [':q' => $room['id_quiz']]) > 0;
                        if ($hasWagerQuestions) {
                            echo 'Người chơi còn sống xếp hạng theo điểm. Người bị loại xếp sau theo vòng bị loại.';
                        } else {
                            echo 'Equal scores are ordered by joining time.';
                        }
                        ?>
                    </p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <footer class="results-footer">
            <a class="results-button" href="<?= BASE_URL ?><?= $isHostResults ? 'modules/quiz/list.php' : 'modules/player/join.php' ?>">
                <?= $isHostResults ? 'Back to My Kahoots' : 'Join another game' ?>
            </a>
            <p>Every answer is a step forward. Thanks for playing!</p>
        </footer>
        </div>
    </main>

    <?php if ($isHostResults && $participantCount > 0): ?>
        <audio id="resultsAudio" preload="auto" loop src="<?= BASE_URL ?>assets/audio/podium-soundtrack.mp3"></audio>
    <?php endif; ?>
</div>

<?php if ($isTeamMode): ?>
<!-- MODAL: TEAM MEMBERS LEADERBOARD DETAILS -->
<div class="modal fade" id="teamDetailModal" tabindex="-1" role="dialog" aria-labelledby="teamDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content border-0 shadow" style="border-radius: 18px; overflow: hidden;">
            <div class="modal-header text-white" id="teamModalHeader" style="background: linear-gradient(135deg, #1368ce, #46178f);">
                <div class="d-flex align-items-center" style="gap: 12px;">
                    <div id="teamModalIcon" style="font-size: 1.8rem;"></div>
                    <div>
                        <h4 class="modal-title font-weight-bold mb-0 text-white" id="teamDetailModalLabel">Chi tiết Đội</h4>
                        <small class="text-white-50 font-weight-bold" id="teamModalSubtitle">Danh sách xếp hạng thành viên</small>
                    </div>
                </div>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity: 0.9;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-0">
                <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                    <span class="font-weight-bold text-dark" id="teamModalMemberCount">0 thành viên</span>
                    <span class="font-weight-bold text-primary" id="teamModalTotalScore" style="font-size: 1.2rem;">0 pts</span>
                </div>
                <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
                    <table class="team-detail-table">
                        <thead class="bg-light text-muted small font-weight-bold text-uppercase">
                            <tr>
                                <th style="width: 70px;">Hạng</th>
                                <th>Người chơi</th>
                                <th class="text-center">Số câu đúng</th>
                                <th class="text-right">Điểm cá nhân</th>
                            </tr>
                        </thead>
                        <tbody id="teamModalTableBody">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Đóng</button>
            </div>
        </div>
    </div>
</div>

<script>
const TEAM_LEADERBOARD_DATA = <?= json_encode($teamLeaderboard, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const CURRENT_PLAYER_ID = <?= (int)$resultsPlayerId ?>;

function openTeamModal(teamId) {
    const team = TEAM_LEADERBOARD_DATA.find(t => Number(t.id) === Number(teamId));
    if (!team) return;

    const modalHeader = document.getElementById('teamModalHeader');
    if (modalHeader) {
        modalHeader.style.background = team.bg_gradient || team.color || '#1368ce';
    }

    const title = document.getElementById('teamDetailModalLabel');
    if (title) title.textContent = team.name;

    const icon = document.getElementById('teamModalIcon');
    if (icon) icon.innerHTML = '<i class="' + (team.icon || 'fas fa-users') + '"></i>';

    const sub = document.getElementById('teamModalSubtitle');
    if (sub) sub.textContent = 'Hạng ' + (team.rank || '-') + ' toàn trận • Tổng ' + Number(team.total_score).toLocaleString() + ' điểm';

    const countEl = document.getElementById('teamModalMemberCount');
    if (countEl) countEl.textContent = team.member_count + ' thành viên';

    const scoreEl = document.getElementById('teamModalTotalScore');
    if (scoreEl) scoreEl.textContent = Number(team.total_score).toLocaleString() + ' points';

    const tbody = document.getElementById('teamModalTableBody');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (!team.members || team.members.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted font-weight-bold">Chưa có người chơi nào gia nhập đội này.</td></tr>';
    } else {
        team.members.forEach((m, idx) => {
            const tr = document.createElement('tr');
            const isMe = Number(m.id_player) === CURRENT_PLAYER_ID;
            if (isMe) tr.style.backgroundColor = '#e8f0fe';

            const rankNum = idx + 1;
            let rankBadge = '<span class="badge badge-light border font-weight-bold px-2 py-1" style="font-size:0.85rem;">' + rankNum + '</span>';
            if (rankNum === 1) rankBadge = '<span class="badge badge-warning text-dark font-weight-bold px-2 py-1"><i class="fas fa-crown mr-1"></i>1</span>';
            else if (rankNum === 2) rankBadge = '<span class="badge badge-secondary text-white font-weight-bold px-2 py-1">2</span>';
            else if (rankNum === 3) rankBadge = '<span class="badge badge-dark text-white font-weight-bold px-2 py-1" style="background:#cd7f32;">3</span>';

            const nickHtml = '<strong>' + escapeHtml(m.nickname) + '</strong>' + (isMe ? ' <span class="badge badge-primary ml-1" style="font-size: 0.7rem;">YOU</span>' : '');

            tr.innerHTML = `
                <td>${rankBadge}</td>
                <td>${nickHtml}</td>
                <td class="text-center font-weight-bold text-muted">${Number(m.correct_count || m.answers_correct || 0)} / <?= $questionCount ?></td>
                <td class="text-right font-weight-bold" style="font-size: 1.05rem; color: #1368ce;">${Number(m.score || 0).toLocaleString()}</td>
            `;
            tbody.appendChild(tr);
        });
    }

    const modalEl = document.getElementById('teamDetailModal');
    if (window.jQuery && typeof jQuery.fn.modal === 'function') {
        jQuery('#teamDetailModal').modal('show');
    } else if (modalEl) {
        modalEl.classList.add('show');
        modalEl.style.display = 'block';
        modalEl.removeAttribute('aria-hidden');
        modalEl.setAttribute('aria-modal', 'true');
        document.body.classList.add('modal-open');
        let backdrop = document.getElementById('teamModalBackdrop');
        if (!backdrop) {
            backdrop = document.createElement('div');
            backdrop.id = 'teamModalBackdrop';
            backdrop.className = 'modal-backdrop fade show';
            document.body.appendChild(backdrop);
            backdrop.addEventListener('click', closeTeamModal);
        }
    }
}

function closeTeamModal() {
    const modalEl = document.getElementById('teamDetailModal');
    if (window.jQuery && typeof jQuery.fn.modal === 'function') {
        jQuery('#teamDetailModal').modal('hide');
    }
    if (modalEl) {
        modalEl.classList.remove('show');
        modalEl.style.display = 'none';
        modalEl.setAttribute('aria-hidden', 'true');
        modalEl.removeAttribute('aria-modal');
    }
    document.body.classList.remove('modal-open');
    const backdrop = document.getElementById('teamModalBackdrop');
    if (backdrop) backdrop.remove();
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('#teamDetailModal [data-dismiss="modal"]').forEach(btn => {
        btn.addEventListener('click', closeTeamModal);
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeTeamModal();
    });
});

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
    });
}
</script>
<?php endif; ?>

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
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<script src="<?= BASE_URL ?>assets/js/final-results.js" defer></script>
</body>
</html>
