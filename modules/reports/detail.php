<?php

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/TeamManager.php';
require_once __DIR__ . '/../../includes/player_characters.php';

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
    redirect('modules/quiz/list.php');
}

$isTeamMode = TeamManager::isTeamMode($room);
$teamCount = TeamManager::getTeamCount($room);
$teamLeaderboard = $isTeamMode ? TeamManager::getTeamLeaderboard($roomId) : [];

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
    ORDER BY p.score DESC, p.playeradd ASC
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

<style>
/* Team Standings in Report */
.report-team-grid {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.report-team-card {
    background: #ffffff;
    border-radius: 14px;
    padding: 18px 22px;
    border: 1px solid var(--saas-slate-200);
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
    position: relative;
    overflow: hidden;
    transition: transform 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
}
.report-team-card:hover {
    transform: translateY(-2px);
    border-color: var(--team-color, #7c3aed);
    box-shadow: 0 6px 16px rgba(15, 23, 42, 0.08);
}
.report-team-strip {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 6px;
    background: var(--team-color, #7c3aed);
}
.report-team-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}
.report-team-rank-badge {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 1.15rem;
    color: #fff;
    background: #64748b;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}
.report-team-rank-badge.team-rank-1 {
    background: linear-gradient(135deg, #ffd700, #ffb300);
    color: #333;
    box-shadow: 0 2px 8px rgba(255, 193, 7, 0.45);
}
.report-team-rank-badge.team-rank-2 {
    background: linear-gradient(135deg, #e0e0e0, #bdbdbd);
    color: #333;
    box-shadow: 0 2px 8px rgba(189, 189, 189, 0.45);
}
.report-team-rank-badge.team-rank-3 {
    background: linear-gradient(135deg, #cd7f32, #b87333);
    color: #fff;
    box-shadow: 0 2px 8px rgba(205, 127, 50, 0.45);
}
.report-team-top3-chip {
    display: inline-flex;
    align-items: center;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    padding: 3px 10px 3px 6px;
    gap: 8px;
    font-size: 0.82rem;
    color: #1e293b;
}
.report-team-avatar {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    overflow: hidden;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #e2e8f0;
}
.report-team-avatar svg {
    width: 100%;
    height: 100%;
}
</style>

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
                <?php if ($isTeamMode): ?>
                    <span style="color: #cbd5e1; font-size: 11px;">/</span>
                    <span class="saas-badge saas-badge-purple font-weight-bold" style="font-size: 11px;">
                        <i class="fas fa-users mr-1"></i> Team Mode (<?= $teamCount ?> Teams)
                    </span>
                <?php endif; ?>
            </div>

            <h2 class="font-weight-bold mb-1" style="font-size: 24px; color: #0f172a; letter-spacing: -0.02em;">
                <?= htmlspecialchars($room['quiz_name']) ?>
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
                    <?= $isTeamMode ? (count($teamLeaderboard) . ' teams participated') : 'Students participated in this session' ?>
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

        <!-- KPI 3: Champion Team OR Average Score -->
        <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
            <div class="saas-kpi-card saas-kpi-purple">
                <div class="saas-kpi-header">
                    <span class="saas-kpi-label" style="color: #6d28d9;"><?= $isTeamMode ? 'Champion Team' : 'Average Score' ?></span>
                    <div class="saas-kpi-icon icon-purple">
                        <i class="fas <?= $isTeamMode ? 'fa-trophy' : 'fa-award' ?>" style="<?= $isTeamMode ? 'color: #f59e0b;' : '' ?>"></i>
                    </div>
                </div>
                <?php if ($isTeamMode && !empty($teamLeaderboard)): 
                    $champ = $teamLeaderboard[0];
                ?>
                    <div class="saas-kpi-value text-truncate" style="font-size: 20px; color: <?= htmlspecialchars($champ['color']) ?>;" title="<?= htmlspecialchars($champ['name']) ?>">
                        <i class="<?= htmlspecialchars($champ['icon']) ?> mr-1"></i> <?= htmlspecialchars($champ['name']) ?>
                    </div>
                    <div class="saas-kpi-footer">
                        <strong><?= number_format($champ['total_score']) ?></strong> pts &bull; <?= $champ['member_count'] ?> members
                    </div>
                <?php else: ?>
                    <div class="saas-kpi-value tabular-nums">
                        <?= number_format($avgScore) ?>
                        <span style="font-size: 13px; font-weight: 500; color: #64748b;">pts</span>
                    </div>
                    <div class="saas-kpi-footer">
                        Average points per student
                    </div>
                <?php endif; ?>
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
            <?php if ($isTeamMode): ?>
            <button class="saas-tab-link active" id="tabBtnTeams" type="button" onclick="switchReportTab('teams')">
                <i class="fas fa-users" style="font-size: 12px; color: #7c3aed;"></i>
                <span>Team Leaderboard</span>
                <span class="saas-tab-count"><?= count($teamLeaderboard) ?></span>
            </button>
            <?php endif; ?>
            <button class="saas-tab-link <?= $isTeamMode ? '' : 'active' ?>" id="tabBtnPlayers" type="button" onclick="switchReportTab('players')">
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

    <?php if ($isTeamMode): ?>
    <!-- 4. TAB 0: TEAM LEADERBOARD (WHEN TEAM MODE IS ACTIVE) -->
    <div id="tabContentTeams" class="tab-pane-content" style="display: block;">
        <div class="saas-table-card mb-4">
            <div class="saas-table-header d-flex justify-content-between align-items-center">
                <h5 class="saas-table-title mb-0">
                    <i class="fas fa-users" style="color: #7c3aed; font-size: 14px;"></i>
                    <span>Team Standings & Leaderboard</span>
                </h5>
                <span class="saas-badge saas-badge-purple font-weight-bold">
                    <?= count($teamLeaderboard) ?> Teams
                </span>
            </div>

            <?php if (empty($teamLeaderboard)): ?>
                <div class="text-center py-5 px-3">
                    <div class="saas-kpi-icon mx-auto mb-3" style="width: 44px; height: 44px; font-size: 18px; background: #eff6ff; color: #3b82f6; border: 1px dashed #bfdbfe;">
                        <i class="fas fa-users-slash"></i>
                    </div>
                    <h6 class="font-weight-bold" style="color: #0f172a; margin-bottom: 4px;">No team data recorded</h6>
                    <p class="text-muted mx-auto" style="max-width: 380px; font-size: 13px;">
                        No team points or answers were recorded in this game session.
                    </p>
                </div>
            <?php else: ?>
                <div class="p-3 p-md-4">
                    <div class="report-team-grid">
                        <?php foreach ($teamLeaderboard as $tRankIdx => $teamItem):
                            $tRank = $teamItem['rank'] ?? ($tRankIdx + 1);
                            $rankClass = ($tRank === 1) ? 'team-rank-1' : (($tRank === 2) ? 'team-rank-2' : (($tRank === 3) ? 'team-rank-3' : 'team-rank-other'));
                        ?>
                            <div class="report-team-card" style="--team-color: <?= htmlspecialchars($teamItem['color']) ?>;">
                                <div class="report-team-strip"></div>
                                <div class="report-team-header">
                                    <div class="d-flex align-items-center" style="gap: 12px;">
                                        <div class="report-team-rank-badge <?= $rankClass ?>">
                                            <?= $tRank ?>
                                        </div>
                                        <div>
                                            <div class="d-flex align-items-center flex-wrap" style="gap: 8px;">
                                                <h4 class="font-weight-bold mb-0" style="font-size: 1.15rem; color: #0f172a;">
                                                    <i class="<?= htmlspecialchars($teamItem['icon']) ?> mr-1" style="color: <?= htmlspecialchars($teamItem['color']) ?>;"></i>
                                                    <?= htmlspecialchars($teamItem['name']) ?>
                                                </h4>
                                                <?php if ($tRank === 1): ?>
                                                    <span class="badge badge-warning text-dark font-weight-bold px-2 py-1" style="font-size: 0.72rem;">
                                                        <i class="fas fa-trophy mr-1"></i> Champion
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="small text-muted font-weight-medium mt-1">
                                                <span class="mr-2"><i class="fas fa-user-friends mr-1 text-primary"></i> <?= $teamItem['member_count'] ?> thành viên</span>
                                                <span><i class="fas fa-check-circle mr-1 text-success"></i> <?= (int)$teamItem['total_correct'] ?> câu đúng</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center" style="gap: 14px;">
                                        <div class="text-right">
                                            <div class="font-weight-bold tabular-nums" style="font-size: 1.45rem; color: #1e293b; line-height: 1.1;">
                                                <?= number_format($teamItem['total_score']) ?>
                                            </div>
                                            <small class="text-muted font-weight-bold text-uppercase" style="font-size: 0.72rem;">pts</small>
                                        </div>
                                        <button type="button" class="saas-btn saas-btn-outline" style="font-size: 12px; padding: 6px 12px;" onclick="openTeamReportModal(<?= (int)$teamItem['id'] ?>)">
                                            <span>Chi tiết</span>
                                            <i class="fas fa-chevron-right ml-1" style="font-size: 10px;"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- TOP 3 PLAYERS IN TEAM -->
                                <div class="report-team-top3 pt-3 mt-2 border-top">
                                    <div class="small font-weight-bold text-muted mb-2 text-uppercase d-flex align-items-center" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                                        <i class="fas fa-medal text-warning mr-1"></i> Top 3 cá nhân cao điểm nhất:
                                    </div>
                                    <?php if (!empty($teamItem['top_3_players'])): ?>
                                        <div class="d-flex flex-wrap" style="gap: 8px;">
                                            <?php foreach ($teamItem['top_3_players'] as $t3Idx => $topP): 
                                                $pApp = get_player_appearance($room, (int)$topP['id_player']);
                                            ?>
                                                <div class="report-team-top3-chip shadow-sm">
                                                    <span class="badge badge-warning text-dark font-weight-bold" style="font-size: 0.68rem; border-radius: 50%; width: 18px; height: 18px; display: inline-flex; align-items: center; justify-content: center; padding: 0;">
                                                        <?= $t3Idx + 1 ?>
                                                    </span>
                                                    <span class="report-team-avatar">
                                                        <?= player_appearance_image($pApp, 'report-mini-avatar') ?>
                                                    </span>
                                                    <span class="font-weight-bold text-dark text-truncate" style="max-width: 120px;" title="<?= htmlspecialchars($topP['nickname']) ?>">
                                                        <?= htmlspecialchars($topP['nickname']) ?>
                                                    </span>
                                                    <span class="badge badge-light border text-primary font-weight-bold tabular-nums">
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
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- 5. TAB 1: PLAYERS LEADERBOARD & PAGINATION -->
    <div id="tabContentPlayers" class="tab-pane-content" style="<?= $isTeamMode ? 'display: none;' : 'display: block;' ?>">
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
                                <?php if ($isTeamMode): ?>
                                    <th class="text-left" style="width: 170px;">Team</th>
                                <?php endif; ?>
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
                                    <?php if ($isTeamMode): ?>
                                    <td class="text-left">
                                        <?= TeamManager::renderTeamBadge(intval($p['team_id'] ?? 0), $p['team_name'] ?? null) ?>
                                    </td>
                                    <?php endif; ?>
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

    <!-- 6. TAB 2: QUESTION ANALYSIS -->
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

<!-- MODAL: TEAM DETAIL ROSTER -->
<div class="modal fade" id="teamReportModal" tabindex="-1" role="dialog" aria-labelledby="teamReportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content border-0 shadow" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header text-white" id="teamReportModalHeader" style="background: linear-gradient(135deg, #1368ce, #46178f);">
                <div class="d-flex align-items-center" style="gap: 12px;">
                    <div id="teamReportModalIcon" style="font-size: 1.8rem;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div>
                        <h5 class="modal-title font-weight-bold mb-0" id="teamReportModalLabel">Team Detail</h5>
                        <small id="teamReportModalSubtitle" class="text-white-50">Team standings and member roster</small>
                    </div>
                </div>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity: 0.85;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-0">
                <div class="d-flex justify-content-around py-3 px-4 bg-light border-bottom text-center">
                    <div>
                        <div class="small text-muted font-weight-bold text-uppercase">Thành viên</div>
                        <div class="font-weight-bold h5 mb-0" id="teamReportMemberCount">0</div>
                    </div>
                    <div class="border-left"></div>
                    <div>
                        <div class="small text-muted font-weight-bold text-uppercase">Tổng điểm</div>
                        <div class="font-weight-bold h5 mb-0 text-primary tabular-nums" id="teamReportTotalScore">0 pts</div>
                    </div>
                    <div class="border-left"></div>
                    <div>
                        <div class="small text-muted font-weight-bold text-uppercase">Tổng câu đúng</div>
                        <div class="font-weight-bold h5 mb-0 text-success tabular-nums" id="teamReportTotalCorrect">0</div>
                    </div>
                </div>
                <div class="table-responsive p-3" style="max-height: 400px; overflow-y: auto;">
                    <table class="saas-table table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="text-center" style="width: 70px;">Rank</th>
                                <th class="text-left">Student</th>
                                <th class="text-right" style="width: 130px;">Score</th>
                                <th class="text-right" style="width: 140px;">Correct</th>
                                <th class="text-right" style="width: 120px;">Accuracy</th>
                            </tr>
                        </thead>
                        <tbody id="teamReportTableBody">
                            <!-- Populated dynamically -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="saas-btn saas-btn-outline" data-dismiss="modal">Đóng</button>
            </div>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>assets/js/reports-pagination.js"></script>
<script>
const REPORT_TEAMS = <?= json_encode($teamLeaderboard) ?>;
const TOTAL_QUESTIONS = <?= intval($totalQuestions) ?>;

function switchReportTab(tabName) {
    const tabs = ['teams', 'players', 'questions'];
    tabs.forEach(t => {
        const btn = document.getElementById('tabBtn' + t.charAt(0).toUpperCase() + t.slice(1));
        const pane = document.getElementById('tabContent' + t.charAt(0).toUpperCase() + t.slice(1));
        if (btn) {
            if (t === tabName) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        }
        if (pane) {
            pane.style.display = (t === tabName) ? 'block' : 'none';
        }
    });
}

function openTeamReportModal(teamId) {
    const team = REPORT_TEAMS.find(t => Number(t.id) === Number(teamId));
    if (!team) return;

    const modalHeader = document.getElementById('teamReportModalHeader');
    if (modalHeader) {
        modalHeader.style.background = team.bg_gradient || team.color || '#1368ce';
    }

    const title = document.getElementById('teamReportModalLabel');
    if (title) title.textContent = team.name;

    const icon = document.getElementById('teamReportModalIcon');
    if (icon) icon.innerHTML = '<i class="' + (team.icon || 'fas fa-users') + '"></i>';

    const sub = document.getElementById('teamReportModalSubtitle');
    if (sub) sub.textContent = 'Hạng ' + (team.rank || '-') + ' toàn trận • ' + (team.member_count || 0) + ' thành viên';

    const countEl = document.getElementById('teamReportMemberCount');
    if (countEl) countEl.textContent = team.member_count + ' thành viên';

    const scoreEl = document.getElementById('teamReportTotalScore');
    if (scoreEl) scoreEl.textContent = Number(team.total_score).toLocaleString() + ' pts';

    const corrEl = document.getElementById('teamReportTotalCorrect');
    if (corrEl) corrEl.textContent = Number(team.total_correct || 0) + ' câu';

    const tbody = document.getElementById('teamReportTableBody');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (!team.members || team.members.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted font-weight-bold">Chưa có người chơi nào gia nhập đội này.</td></tr>';
    } else {
        team.members.forEach((m, idx) => {
            const tr = document.createElement('tr');
            const rankNum = idx + 1;
            let rankBadge = '<span class="saas-rank-chip saas-rank-other">' + rankNum + '</span>';
            if (rankNum === 1) rankBadge = '<span class="saas-rank-chip saas-rank-1"><i class="fas fa-crown mr-1" style="color: #ca8a04; font-size: 10px;"></i>1</span>';
            else if (rankNum === 2) rankBadge = '<span class="saas-rank-chip saas-rank-2"><i class="fas fa-medal mr-1" style="color: #64748b; font-size: 10px;"></i>2</span>';
            else if (rankNum === 3) rankBadge = '<span class="saas-rank-chip saas-rank-3"><i class="fas fa-award mr-1" style="color: #ea580c; font-size: 10px;"></i>3</span>';

            const correct = Number(m.correct_count || m.answers_correct || 0);
            const accuracy = TOTAL_QUESTIONS > 0 ? Math.round((correct / TOTAL_QUESTIONS) * 1000) / 10 : 0;

            tr.innerHTML = `
                <td class="text-center">${rankBadge}</td>
                <td class="text-left font-weight-medium" style="color: #0f172a;">${escapeHtml(m.nickname || 'Unknown')}</td>
                <td class="text-right font-weight-bold tabular-nums" style="color: #0f172a;">${Number(m.score || 0).toLocaleString()} <span style="font-size: 11px; color: #94a3b8;">pts</span></td>
                <td class="text-right tabular-nums">${correct} / ${TOTAL_QUESTIONS}</td>
                <td class="text-right font-weight-bold tabular-nums" style="color: #334155;">${accuracy}%</td>
            `;
            tbody.appendChild(tr);
        });
    }

    if (window.jQuery && jQuery('#teamReportModal').modal) {
        jQuery('#teamReportModal').modal('show');
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
    });
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
