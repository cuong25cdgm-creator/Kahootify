<?php

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../config/database.php';

$creatorId = intval($_SESSION['id_creator']);
$roomId    = intval($_GET['id_room'] ?? $_GET['id'] ?? 0);

if ($roomId <= 0) {
    set_flash_message('danger', 'Invalid game room ID!');
    redirect('modules/reports/list.php');
}

// 1. Verify session ownership
$room = db_fetch_one("
    SELECT r.*, q.name as quiz_name, q.description as quiz_description
    FROM room r
    JOIN quiz q ON r.id_quiz = q.id_quiz
    WHERE r.id_room = :r AND r.id_creator = :c
", [':r' => $roomId, ':c' => $creatorId]);

if (!$room) {
    set_flash_message('danger', 'Game report not found or you do not have permission to view it!');
    redirect('modules/auth/dashboard.php');
}

// 2. Fetch questions played in this session (respecting randomized order if saved)
$selectedQIds = !empty($room['question_order']) ? json_decode($room['question_order'], true) : null;
if (is_array($selectedQIds) && !empty($selectedQIds)) {
    $inClause = implode(',', array_map('intval', $selectedQIds));
    $questions = db_fetch_all("
        SELECT * FROM questions 
        WHERE id_question IN ($inClause)
        ORDER BY FIELD(id_question, $inClause)
    ");
} else {
    $questions = db_fetch_all("
        SELECT * FROM questions 
        WHERE id_quiz = :q 
        ORDER BY id_question ASC
    ", [':q' => $room['id_quiz']]);
}
$totalQuestions = count($questions);

// 3. Fetch player scoreboard
$sqlPlayers = "
    SELECT p.*,
           (SELECT COUNT(*) FROM votes v 
            JOIN answers a ON v.id_answer = a.id_answer 
            WHERE v.id_player = p.id_player AND a.is_correct = 1) as correct_count,
           (SELECT COUNT(*) FROM votes v 
            WHERE v.id_player = p.id_player) as total_answered
    FROM players p
    WHERE p.id_room = :r
    ORDER BY " . (!empty($room['is_review_mode']) ? "p.answers_correct DESC, p.playeradd ASC" : "p.score DESC, p.playeradd ASC") . "
";
$rawPlayers = db_fetch_all($sqlPlayers, [':r' => $roomId]);
$players = function_exists('filter_room_players') ? filter_room_players($roomId, $rawPlayers) : $rawPlayers;
$totalPlayers = count($players);

// 4. Compute KPI summaries
$totalScore = array_sum(array_column($players, 'score'));
$avgScore   = $totalPlayers > 0 ? round($totalScore / $totalPlayers) : 0;

$totalCorrectCount = array_sum(array_column($players, 'correct_count'));
$totalPossibleAnswers = $totalPlayers * $totalQuestions;
$classAccuracy = $totalPossibleAnswers > 0 ? round(($totalCorrectCount / $totalPossibleAnswers) * 100, 1) : 0;

// 5. Compute question breakdown & identify most difficult question
$questionAnalytics = [];
$mostDifficultQuestion = null;
$maxIncorrectRate = -1;

$kahootSymbols = [
    0 => ['symbol' => '▲', 'name' => 'A', 'color' => '#e21b3c'],
    1 => ['symbol' => '◆', 'name' => 'B', 'color' => '#1368ce'],
    2 => ['symbol' => '●', 'name' => 'C', 'color' => '#d89e00'],
    3 => ['symbol' => '■', 'name' => 'D', 'color' => '#26890c']
];

foreach ($questions as $qIndex => $q) {
    $qId = intval($q['id_question']);

    // Fetch all answers for this question
    $answers = db_fetch_all("
        SELECT * FROM answers 
        WHERE id_question = :qid 
        ORDER BY id_answer ASC
    ", [':qid' => $qId]);

    // Fetch student votes for this question in this session
    $votesRows = db_fetch_all("
        SELECT v.id_answer, COUNT(*) as vote_count
        FROM votes v
        WHERE v.id_player IN (SELECT id_player FROM players WHERE id_room = :r)
          AND v.id_answer IN (SELECT id_answer FROM answers WHERE id_question = :qid)
        GROUP BY v.id_answer
    ", [':r' => $roomId, ':qid' => $qId]);

    $votesMap = [];
    foreach ($votesRows as $vr) {
        $votesMap[intval($vr['id_answer'])] = intval($vr['vote_count']);
    }

    $qTotalVotes = array_sum($votesMap);
    $qCorrectVotes = 0;

    foreach ($answers as $ansIdx => &$ans) {
        $aId = intval($ans['id_answer']);
        $voteCnt = $votesMap[$aId] ?? 0;
        $ans['vote_count'] = $voteCnt;
        
        $denominator = ($totalPlayers > 0) ? $totalPlayers : max(1, $qTotalVotes);
        $ans['vote_percent'] = round(($voteCnt / $denominator) * 100, 1);

        if ($ans['is_correct'] == 1) {
            $qCorrectVotes += $voteCnt;
        }

        $styleMeta = $kahootSymbols[$ansIdx % 4] ?? ['symbol' => '●', 'name' => chr(65 + $ansIdx), 'color' => '#64748b'];
        $ans['symbol'] = $styleMeta['symbol'];
        $ans['label'] = $styleMeta['name'];
        $ans['color'] = $styleMeta['color'];
    }
    unset($ans);

    // Accuracy and incorrect rate
    $denominatorQ = ($totalPlayers > 0) ? $totalPlayers : max(1, $qTotalVotes);
    $qAccuracy = round(($qCorrectVotes / $denominatorQ) * 100, 1);
    $qIncorrectRate = 100 - $qAccuracy;

    $itemData = [
        'question'       => $q,
        'index'          => $qIndex + 1,
        'answers'        => $answers,
        'total_voted'    => $qTotalVotes,
        'correct_votes'  => $qCorrectVotes,
        'accuracy'       => $qAccuracy,
        'incorrect_rate' => $qIncorrectRate
    ];
    $questionAnalytics[] = $itemData;

    // Detect most difficult question
    if ($totalPlayers > 0 && $qIncorrectRate > $maxIncorrectRate) {
        $maxIncorrectRate = $qIncorrectRate;
        $mostDifficultQuestion = [
            'number'         => $qIndex + 1,
            'text'           => $q['text'],
            'incorrect_rate' => $qIncorrectRate,
            'accuracy'       => $qAccuracy,
            'correct_votes'  => $qCorrectVotes,
            'total_players'  => $totalPlayers
        ];
    }
}

$pageTitle = "Report PIN: " . htmlspecialchars($room['pin']) . " | Reports";
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/reports-analytics.css">

<div class="reports-dashboard container my-4" style="max-width: 1140px;">

    <!-- 1. Header & Actions (Linear / Stripe Style) -->
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4 pb-2 border-bottom">
        <div>
            <div class="d-flex align-items-center mb-1" style="gap: 8px;">
                <a href="<?= BASE_URL ?>modules/reports/list.php" class="text-decoration-none" style="font-size: 12px; font-weight: 600; color: #7c3aed;">
                    <i class="fas fa-arrow-left mr-1"></i> Session Reports
                </a>
                <span style="color: #cbd5e1; font-size: 11px;">/</span>
                <span class="saas-badge saas-badge-amber font-mono font-weight-bold" style="font-size: 11px;">
                    PIN: <?= htmlspecialchars($room['pin']) ?>
                </span>
            </div>

            <h2 class="font-weight-bold mb-1" style="font-size: 24px; color: #0f172a; letter-spacing: -0.02em;">
                <?= htmlspecialchars($room['quiz_name']) ?>
                <?php if (!empty($room['is_review_mode'])): ?>
                    <span class="badge badge-info ml-2" style="font-size: 13px; vertical-align: middle;">
                        <i class="fas fa-book-reader mr-1"></i> Review Mode
                    </span>
                <?php endif; ?>
            </h2>

            <div class="d-flex flex-wrap align-items-center" style="gap: 14px; font-size: 13px; color: #64748b; margin-top: 4px;">
                <span>
                    <i class="far fa-calendar-alt mr-1" style="color: #7c3aed;"></i> Date: <?= date('M d, Y', strtotime($room['roomadd'])) ?>
                </span>
                <span>
                    <i class="far fa-clock mr-1" style="color: #3b82f6;"></i> <?= date('h:i A', strtotime($room['roomadd'])) ?>
                </span>
                <span>
                    <i class="far fa-question-circle mr-1" style="color: #10b981;"></i> <?= $totalQuestions ?> <?= $totalQuestions === 1 ? 'question' : 'questions' ?>
                </span>
            </div>
        </div>

        <!-- Action dropdown & Quick download -->
        <div class="mt-3 mt-md-0 d-flex align-items-center" style="gap: 8px;">
            <a href="<?= BASE_URL ?>modules/reports/export_csv.php?id_room=<?= $roomId ?>" class="saas-btn saas-btn-solid" style="background: linear-gradient(135deg, #059669, #047857); border-color: #047857; box-shadow: 0 2px 4px rgba(5, 150, 105, 0.25);">
                <i class="fas fa-file-excel mr-1" style="font-size: 12px;"></i>
                <span>Download CSV</span>
            </a>

            <div class="dropdown saas-action-dropdown">
                <button class="saas-dropdown-btn" type="button" id="detailMenuBtn" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" style="padding: 6px 12px; font-size: 13px;">
                    <i class="fas fa-ellipsis-v mr-1" style="font-size: 12px; color: #64748b;"></i>
                    <span>Options</span>
                    <i class="fas fa-chevron-down ml-1" style="font-size: 10px; color: #64748b;"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-right saas-dropdown-menu shadow" aria-labelledby="detailMenuBtn">
                    <!-- 1. Download CSV -->
                    <a class="dropdown-item saas-dropdown-item" href="<?= BASE_URL ?>modules/reports/export_csv.php?id_room=<?= $roomId ?>">
                        <span class="saas-menu-icon icon-emerald"><i class="fas fa-file-excel"></i></span>
                        <div>
                            <div class="font-weight-medium">Download CSV</div>
                            <small class="text-muted" style="font-size: 11px;">Excel-compatible grade scorecard</small>
                        </div>
                    </a>

                    <!-- 2. Print Report -->
                    <a class="dropdown-item saas-dropdown-item" href="javascript:void(0)" onclick="window.print();">
                        <span class="saas-menu-icon icon-blue"><i class="fas fa-print"></i></span>
                        <div>
                            <div class="font-weight-medium">Print Report</div>
                            <small class="text-muted" style="font-size: 11px;">Print page or save as clean PDF</small>
                        </div>
                    </a>

                    <div class="dropdown-divider my-1"></div>

                    <!-- 3. Back to Reports -->
                    <a class="dropdown-item saas-dropdown-item" href="<?= BASE_URL ?>modules/reports/list.php">
                        <span class="saas-menu-icon icon-purple"><i class="fas fa-arrow-left"></i></span>
                        <div>
                            <div class="font-weight-medium">Back to Reports</div>
                            <small class="text-muted" style="font-size: 11px;">View all game sessions</small>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. Summary KPI Cards with Vibrant Accent Lines -->
    <div class="row mb-4">
        <!-- KPI 1: Total Players -->
        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
            <div class="saas-kpi-card saas-kpi-blue">
                <div class="saas-kpi-header">
                    <span class="saas-kpi-label" style="color: #1d4ed8;">Total Players</span>
                    <div class="saas-kpi-icon icon-blue">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="saas-kpi-value tabular-nums">
                    <?= $totalPlayers ?>
                </div>
                <div class="saas-kpi-footer">
                    Students participated in this session
                </div>
            </div>
        </div>

        <!-- KPI 2: Class Accuracy Rate -->
        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
            <div class="saas-kpi-card saas-kpi-emerald">
                <div class="saas-kpi-header">
                    <span class="saas-kpi-label" style="color: #047857;">Class Accuracy</span>
                    <div class="saas-kpi-icon icon-emerald">
                        <i class="fas fa-bullseye"></i>
                    </div>
                </div>
                <div class="saas-kpi-value tabular-nums">
                    <?= $classAccuracy ?><span style="font-size: 15px; font-weight: 500; color: #64748b;">%</span>
                </div>
                <div class="saas-kpi-footer">
                    <?= $totalCorrectCount ?> / <?= max(1, $totalPossibleAnswers) ?> total correct answers
                </div>
            </div>
        </div>

        <!-- KPI 3: Average Room Score -->
        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
            <div class="saas-kpi-card saas-kpi-purple">
                <div class="saas-kpi-header">
                    <span class="saas-kpi-label" style="color: #6d28d9;">Average Score</span>
                    <div class="saas-kpi-icon icon-purple">
                        <i class="fas fa-award"></i>
                    </div>
                </div>
                <div class="saas-kpi-value tabular-nums">
                    <?= number_format($avgScore) ?>
                    <span style="font-size: 13px; font-weight: 500; color: #64748b;">pts</span>
                </div>
                <div class="saas-kpi-footer">
                    Average points per student
                </div>
            </div>
        </div>

        <!-- KPI 4: Difficult Question -->
        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
            <div class="saas-kpi-card saas-kpi-rose">
                <div class="saas-kpi-header">
                    <span class="saas-kpi-label" style="color: #be123c;">Difficult Question</span>
                    <div class="saas-kpi-icon icon-rose">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
                <div class="saas-kpi-value">
                    <?php if ($mostDifficultQuestion && $totalPlayers > 0): ?>
                        <span class="tabular-nums" style="color: #e11d48;">Question #<?= $mostDifficultQuestion['number'] ?></span>
                    <?php else: ?>
                        <span style="font-size: 17px; font-weight: 500; color: #64748b;">None</span>
                    <?php endif; ?>
                </div>
                <div class="saas-kpi-footer">
                    <?php if ($mostDifficultQuestion && $totalPlayers > 0): ?>
                        <span class="saas-badge saas-badge-rose tabular-nums" style="font-size: 11px; padding: 1px 6px;">
                            <?= $mostDifficultQuestion['incorrect_rate'] ?>% incorrect
                        </span>
                        <span class="text-truncate d-inline-block align-middle ml-1" style="max-width: 110px; font-size: 11.5px; color: #64748b;" title="<?= htmlspecialchars($mostDifficultQuestion['text']) ?>">
                            <?= htmlspecialchars($mostDifficultQuestion['text']) ?>
                        </span>
                    <?php else: ?>
                        All questions answered proficiently
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Segmented Control Tab Navigation (Linear / shadcn style) -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="saas-tabs-container" role="tablist">
            <button class="saas-tab-link active" id="tabBtnPlayers" type="button" onclick="switchReportTab('players')">
                <i class="fas fa-trophy" style="font-size: 12px; color: #f59e0b;"></i>
                <span>Players Leaderboard</span>
                <span class="saas-tab-count"><?= $totalPlayers ?></span>
            </button>
            <button class="saas-tab-link" id="tabBtnQuestions" type="button" onclick="switchReportTab('questions')">
                <i class="fas fa-chart-bar" style="font-size: 12px; color: #3b82f6;"></i>
                <span>Question Analysis</span>
                <span class="saas-tab-count"><?= $totalQuestions ?></span>
            </button>
        </div>
    </div>

    <!-- 4. TAB 1: PLAYERS LEADERBOARD & PAGINATION -->
    <div id="tabContentPlayers" class="tab-pane-content">
        <div class="saas-table-card">
            <div class="saas-table-header">
                <h5 class="saas-table-title">
                    <i class="fas fa-medal" style="color: #f59e0b; font-size: 14px;"></i>
                    <span>Student Final Standings</span>
                </h5>
                <a href="<?= BASE_URL ?>modules/reports/export_csv.php?id_room=<?= $roomId ?>" class="saas-btn saas-btn-outline" style="font-size: 12px; padding: 4px 10px;">
                    <i class="fas fa-file-excel" style="color: #10b981;"></i>
                    <span>Export CSV</span>
                </a>
            </div>

            <?php if (empty($players)): ?>
                <div class="text-center py-5 px-3">
                    <div class="saas-kpi-icon mx-auto mb-3" style="width: 44px; height: 44px; font-size: 18px; background: #eff6ff; color: #3b82f6; border: 1px dashed #bfdbfe;">
                        <i class="fas fa-user-slash"></i>
                    </div>
                    <h6 class="font-weight-bold" style="color: #0f172a; margin-bottom: 4px;">No players recorded</h6>
                    <p class="text-muted mx-auto" style="max-width: 380px; font-size: 13px;">
                        No student participants joined or answered questions in this game session.
                    </p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="saas-table table-hover" id="playersTable">
                        <thead>
                            <tr>
                                <th class="text-center" style="width: 70px;">Rank</th>
                                <th class="text-left">Student Nickname</th>
                                <th class="text-right" style="width: 150px;">Total Score</th>
                                <th class="text-right" style="width: 150px;">Correct Answers</th>
                                <th class="text-left" style="width: 260px;">Accuracy Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($players as $idx => $p): 
                                $rank = $idx + 1;
                                $correct = intval($p['correct_count']);
                                $accuracy = $totalQuestions > 0 ? round(($correct / $totalQuestions) * 100, 1) : 0;
                                
                                $rankClass = 'saas-rank-other';
                                $rankPrefix = '';
                                if ($rank === 1) {
                                    $rankClass = 'saas-rank-1';
                                    $rankPrefix = '<i class="fas fa-crown mr-1" style="color: #ca8a04; font-size: 10px;"></i>';
                                } elseif ($rank === 2) {
                                    $rankClass = 'saas-rank-2';
                                    $rankPrefix = '<i class="fas fa-medal mr-1" style="color: #64748b; font-size: 10px;"></i>';
                                } elseif ($rank === 3) {
                                    $rankClass = 'saas-rank-3';
                                    $rankPrefix = '<i class="fas fa-award mr-1" style="color: #ea580c; font-size: 10px;"></i>';
                                }

                                $progressClass = 'saas-progress-emerald';
                                if ($accuracy < 40) {
                                    $progressClass = 'saas-progress-rose';
                                } elseif ($accuracy < 70) {
                                    $progressClass = 'saas-progress-amber';
                                }
                            ?>
                                <tr>
                                    <td class="text-center">
                                        <span class="saas-rank-chip <?= $rankClass ?>">
                                            <?= $rankPrefix ?><?= $rank ?>
                                        </span>
                                    </td>
                                    <td class="text-left">
                                        <div class="font-weight-medium" style="color: #0f172a; font-size: 14px;">
                                            <?= htmlspecialchars($p['nickname']) ?>
                                        </div>
                                        <div style="font-size: 11.5px; color: #94a3b8; margin-top: 1px;">
                                            Submitted <?= intval($p['total_answered']) ?> / <?= $totalQuestions ?> questions
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        <span class="saas-numeric font-weight-bold" style="color: #0f172a; font-size: 14px;">
                                            <?= number_format($p['score']) ?>
                                        </span>
                                        <span style="font-size: 11.5px; color: #94a3b8;">pts</span>
                                    </td>
                                    <td class="text-right">
                                        <span class="saas-numeric font-weight-medium" style="color: #1e293b; font-size: 13.5px;">
                                            <?= $correct ?>
                                        </span>
                                        <span style="font-size: 11.5px; color: #64748b;">/ <?= $totalQuestions ?> correct</span>
                                    </td>
                                    <td class="text-left">
                                        <div class="d-flex align-items-center" style="gap: 12px;">
                                            <div class="saas-progress-track flex-grow-1">
                                                <div class="saas-progress-bar <?= $progressClass ?>" style="width: <?= $accuracy ?>%;"></div>
                                            </div>
                                            <span class="saas-numeric font-weight-bold" style="font-size: 12.5px; color: #334155; width: 44px; text-align: right;">
                                                <?= $accuracy ?>%
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Integrated Pagination Footer -->
                <div id="playersPagination" class="saas-pagination-bar"></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 5. TAB 2: QUESTION ANALYSIS -->
    <div id="tabContentQuestions" class="tab-pane-content" style="display: none;">
        <?php if (empty($questionAnalytics)): ?>
            <div class="saas-table-card text-center py-5 px-3">
                <div class="saas-kpi-icon mx-auto mb-3" style="width: 44px; height: 44px; font-size: 18px; background: #f5f3ff; color: #7c3aed;">
                    <i class="far fa-question-circle"></i>
                </div>
                <h6 class="font-weight-bold" style="color: #0f172a;">No questions found</h6>
                <p class="text-muted" style="font-size: 13px;">This quiz contains no questions.</p>
            </div>
        <?php else: ?>
            <?php foreach ($questionAnalytics as $qa): 
                $q = $qa['question'];
                $qAcc = $qa['accuracy'];
                
                $accBadge = 'saas-badge-emerald';
                $accProgress = 'saas-progress-emerald';
                if ($qAcc < 40) {
                    $accBadge = 'saas-badge-rose';
                    $accProgress = 'saas-progress-rose';
                } elseif ($qAcc < 70) {
                    $accBadge = 'saas-badge-amber';
                    $accProgress = 'saas-progress-amber';
                }
            ?>
                <div class="saas-question-card">
                    <div class="saas-question-header">
                        <div class="d-flex flex-wrap justify-content-between align-items-center" style="gap: 10px;">
                            <div class="d-flex align-items-center" style="gap: 10px; flex: 1 1 500px;">
                                <span class="saas-badge saas-badge-slate font-mono font-weight-bold" style="font-size: 11px;">
                                    Question #<?= $qa['index'] ?>
                                </span>
                                <h6 class="saas-question-title mb-0">
                                    <?= htmlspecialchars($q['text']) ?>
                                </h6>
                            </div>

                            <div class="d-flex align-items-center" style="gap: 6px;">
                                <span class="saas-badge saas-badge-slate" style="font-size: 11.5px;">
                                    <i class="far fa-clock mr-1" style="color: #3b82f6;"></i><?= $q['time_limit'] ?>s
                                </span>
                                <span class="saas-badge saas-badge-slate" style="font-size: 11.5px;">
                                    <i class="fas fa-award mr-1" style="color: #7c3aed;"></i><?= $q['points'] ?> pts
                                </span>
                                <span class="saas-badge <?= $accBadge ?> tabular-nums font-weight-bold" style="font-size: 12px;">
                                    Accuracy: <?= $qAcc ?>% (<?= $qa['correct_votes'] ?>/<?= max(1, $totalPlayers) ?>)
                                </span>
                            </div>
                        </div>

                        <!-- 6px Sleek Accuracy Bar -->
                        <div class="saas-progress-track mt-3">
                            <div class="saas-progress-bar <?= $accProgress ?>" style="width: <?= $qAcc ?>%;"></div>
                        </div>
                    </div>

                    <div class="p-3" style="background-color: #fafbfd;">
                        <div class="row">
                            <?php foreach ($qa['answers'] as $ans): 
                                $isCorrect = ($ans['is_correct'] == 1);
                            ?>
                                <div class="col-md-6 mb-3">
                                    <div class="saas-answer-item <?= $isCorrect ? 'correct' : '' ?>">
                                        <div class="d-flex justify-content-between align-items-start mb-2" style="gap: 8px;">
                                            <div class="d-flex align-items-center" style="gap: 8px;">
                                                <span class="saas-answer-symbol" style="background-color: <?= $ans['color'] ?>;">
                                                    <?= $ans['symbol'] ?>
                                                </span>
                                                <span class="font-weight-medium" style="color: #0f172a; font-size: 13.5px;">
                                                    <?= htmlspecialchars($ans['text']) ?>
                                                </span>
                                            </div>
                                            <?php if ($isCorrect): ?>
                                                <span class="saas-badge saas-badge-emerald" style="font-size: 11px; padding: 2px 6px;">
                                                    <i class="fas fa-check mr-1" style="font-size: 10px;"></i> Correct Answer
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top" style="border-color: #f1f5f9 !important;">
                                            <span style="font-size: 12px; color: #64748b;">
                                                <b class="tabular-nums font-weight-semibold" style="color: #1e293b;"><?= $ans['vote_count'] ?></b> <?= $ans['vote_count'] === 1 ? 'student selected' : 'students selected' ?>
                                            </span>
                                            <span class="saas-numeric font-weight-bold <?= $isCorrect ? 'text-success' : '' ?>" style="font-size: 13px; color: <?= $isCorrect ? '#047857' : '#0f172a' ?>;">
                                                <?= $ans['vote_percent'] ?>%
                                            </span>
                                        </div>

                                        <div class="saas-progress-track mt-2">
                                            <div class="saas-progress-bar" style="width: <?= $ans['vote_percent'] ?>%; background-color: <?= $ans['color'] ?>;"></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<script src="<?= BASE_URL ?>assets/js/reports-pagination.js"></script>
<script>
function switchReportTab(tabName) {
    const btnPlayers = document.getElementById('tabBtnPlayers');
    const btnQuestions = document.getElementById('tabBtnQuestions');
    const panePlayers = document.getElementById('tabContentPlayers');
    const paneQuestions = document.getElementById('tabContentQuestions');

    if (tabName === 'players') {
        btnPlayers.classList.add('active');
        btnQuestions.classList.remove('active');
        panePlayers.style.display = 'block';
        paneQuestions.style.display = 'none';
    } else {
        btnQuestions.classList.add('active');
        btnPlayers.classList.remove('active');
        paneQuestions.style.display = 'block';
        panePlayers.style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('playersTable') && document.getElementById('playersPagination')) {
        new SaasTablePagination({
            table: '#playersTable',
            container: '#playersPagination',
            itemNoun: 'players',
            pageSize: 10,
            allowedPageSizes: [10, 25, 50]
        });
    }
});
</script>

<style>
@media print {
    .navbar-kahoot,
    .saas-btn,
    .saas-tabs-container,
    .saas-pagination-bar,
    .saas-action-dropdown,
    .alert {
        display: none !important;
    }
    .tab-pane-content {
        display: block !important;
    }
    .saas-kpi-card,
    .saas-table-card,
    .saas-question-card {
        box-shadow: none !important;
        border: 1px solid #cbd5e1 !important;
        page-break-inside: avoid;
    }
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
