<?php

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../config/database.php';

$creatorId = intval($_SESSION['id_creator']);

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete') {
    $roomId = intval($_GET['id_room'] ?? $_GET['id'] ?? 0);
    if ($roomId > 0) {
        $check = db_fetch_one("SELECT id_room FROM room WHERE id_room = :r AND id_creator = :c", [
            ':r' => $roomId,
            ':c' => $creatorId
        ]);
        if ($check) {
            db_execute("DELETE FROM room WHERE id_room = :r", [':r' => $roomId]);
            set_flash_message('success', 'Game session report deleted successfully!');
        } else {
            set_flash_message('danger', 'Report not found or you do not have permission to delete it!');
        }
    }
    redirect('modules/reports/list.php');
}

// Fetch all finished rooms and active review rooms hosted by current creator
$sql = "
    SELECT 
        r.id_room,
        r.pin,
        r.status as room_status,
        r.is_review_mode,
        r.review_start_time,
        r.review_end_time,
        r.roomadd,
        r.roomupdate,
        q.id_quiz,
        q.name as quiz_name,
        COUNT(p.id_player) as total_players,
        COALESCE(MAX(p.score), 0) as top_score,
        (
            SELECT nickname 
            FROM players 
            WHERE id_room = r.id_room 
            ORDER BY score DESC, playeradd ASC 
            LIMIT 1
        ) as winner_nickname
    FROM room r
    JOIN quiz q ON r.id_quiz = q.id_quiz
    LEFT JOIN players p ON r.id_room = p.id_room
    WHERE r.id_creator = :c AND (r.status = 'finished' OR r.is_review_mode = 1)
    GROUP BY r.id_room
    ORDER BY r.id_room DESC
";

$reports = db_fetch_all($sql, [':c' => $creatorId]);

// Compute KPI summaries
$totalCompletedGames = count($reports);
$totalClassParticipants = array_sum(array_column($reports, 'total_players'));
$allTimeTopScore = !empty($reports) ? max(array_column($reports, 'top_score')) : 0;

$pageTitle = "Reports & Analytics | Kahoot!";
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/reports-analytics.css">

<div class="reports-dashboard container my-4" style="max-width: 1140px;">

    <!-- 1. Header & Actions (Linear / Stripe Style) -->
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4 pb-2 border-bottom">
        <div>
            <div class="text-uppercase mb-1" style="font-size: 11px; font-weight: 700; letter-spacing: 0.06em; color: #7c3aed;">
                <i class="fas fa-chart-pie mr-1"></i> Reports & Analytics
            </div>
            <h2 class="font-weight-bold mb-1" style="font-size: 24px; color: #0f172a; letter-spacing: -0.02em;">
                Game Session History
            </h2>
            <p class="text-muted mb-0" style="font-size: 13.5px;">
                Review student performance, analyze question accuracy, and download detailed grade reports.
            </p>
        </div>
        <div class="mt-3 mt-md-0 d-flex align-items-center" style="gap: 8px;">
            <a href="<?= BASE_URL ?>modules/quiz/list.php" class="saas-btn saas-btn-solid" style="background: linear-gradient(135deg, #7c3aed, #6d28d9); border-color: #6d28d9; box-shadow: 0 2px 4px rgba(109, 40, 217, 0.25);">
                <i class="fas fa-play" style="font-size: 11px;"></i>
                <span>Host New Game</span>
            </a>
        </div>
    </div>

    <!-- 2. Summary KPI Cards -->
    <div class="row mb-4">
        <!-- KPI 1: Completed Sessions -->
        <div class="col-md-4 mb-3 mb-md-0">
            <div class="saas-kpi-card saas-kpi-purple">
                <div class="saas-kpi-header">
                    <span class="saas-kpi-label" style="color: #6d28d9;">Completed Sessions</span>
                    <div class="saas-kpi-icon icon-purple">
                        <i class="fas fa-flag-checkered"></i>
                    </div>
                </div>
                <div class="saas-kpi-value tabular-nums">
                    <?= number_format($totalCompletedGames) ?>
                </div>
                <div class="saas-kpi-footer">
                    Total finished game sessions
                </div>
            </div>
        </div>

        <!-- KPI 2: Total Participants -->
        <div class="col-md-4 mb-3 mb-md-0">
            <div class="saas-kpi-card saas-kpi-blue">
                <div class="saas-kpi-header">
                    <span class="saas-kpi-label" style="color: #1d4ed8;">Total Participants</span>
                    <div class="saas-kpi-icon icon-blue">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="saas-kpi-value tabular-nums">
                    <?= number_format($totalClassParticipants) ?>
                </div>
                <div class="saas-kpi-footer">
                    Total student entries recorded
                </div>
            </div>
        </div>

        <!-- KPI 3: All-Time High Score -->
        <div class="col-md-4 mb-3 mb-md-0">
            <div class="saas-kpi-card saas-kpi-amber">
                <div class="saas-kpi-header">
                    <span class="saas-kpi-label" style="color: #b45309;">All-Time High Score</span>
                    <div class="saas-kpi-icon icon-amber">
                        <i class="fas fa-trophy"></i>
                    </div>
                </div>
                <div class="saas-kpi-value tabular-nums">
                    <?= number_format($allTimeTopScore) ?>
                    <span style="font-size: 14px; font-weight: 500; color: #64748b;">pts</span>
                </div>
                <div class="saas-kpi-footer">
                    Highest score recorded across all games
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Sessions Data Table -->
    <div class="saas-table-card">
        <div class="saas-table-header">
            <h5 class="saas-table-title">
                <i class="fas fa-history" style="color: #7c3aed; font-size: 14px;"></i>
                <span>All Game Sessions</span>
            </h5>
            <span class="saas-badge saas-badge-purple">
                <i class="fas fa-list-ul mr-1" style="font-size: 10px;"></i> <?= count($reports) ?> <?= count($reports) === 1 ? 'session' : 'sessions' ?>
            </span>
        </div>

        <?php if (empty($reports)): ?>
            <div class="text-center py-5 px-3">
                <div class="saas-kpi-icon mx-auto mb-3" style="width: 48px; height: 48px; font-size: 20px; background: #f5f3ff; color: #7c3aed; border: 1px dashed #ddd6fe;">
                    <i class="far fa-folder-open"></i>
                </div>
                <h6 class="font-weight-bold" style="color: #0f172a; margin-bottom: 4px;">No session reports yet</h6>
                <p class="text-muted mx-auto" style="max-width: 420px; font-size: 13px;">
                    Once you host and finish a live quiz game, performance reports and analytics will automatically appear here.
                </p>
                <a href="<?= BASE_URL ?>modules/quiz/list.php" class="saas-btn saas-btn-solid mt-2" style="background: #7c3aed; border-color: #7c3aed;">
                    <i class="fas fa-play" style="font-size: 11px;"></i>
                    <span>Host Your First Game</span>
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive" style="min-height: 250px;">
                <table class="saas-table table-hover" id="reportsTable">
                    <thead>
                        <tr>
                            <th class="text-center" style="width: 50px;">#</th>
                            <th class="text-left">Quiz Name</th>
                            <th class="text-center" style="width: 120px;">PIN</th>
                            <th class="text-left" style="width: 180px;">Hosted Date</th>
                            <th class="text-right" style="width: 130px;">Players</th>
                            <th class="text-left" style="width: 230px;">Winner / High Score</th>
                            <th class="text-right" style="width: 130px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $index => $rep): ?>
                            <tr>
                                <td class="text-center saas-numeric" style="color: #94a3b8; font-size: 12px;">
                                    <?= $index + 1 ?>
                                </td>
                                <td class="text-left">
                                    <a href="<?= BASE_URL ?>modules/reports/detail.php?id_room=<?= $rep['id_room'] ?>" class="font-weight-bold text-decoration-none" style="color: #0f172a; font-size: 14px;">
                                        <?= htmlspecialchars($rep['quiz_name']) ?>
                                    </a>
                                    <?php if (!empty($rep['is_review_mode'])): ?>
                                        <span class="badge badge-info ml-1" style="font-size: 10px;">
                                            <i class="fas fa-book-reader mr-1"></i>Review
                                        </span>
                                        <?php if (($rep['room_status'] ?? '') === 'review_active'): ?>
                                            <span class="badge badge-success ml-1" style="font-size: 10px;">Active</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary ml-1" style="font-size: 10px;">Closed</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <div style="font-size: 11.5px; color: #94a3b8; margin-top: 2px;">
                                        Session ID: #<?= $rep['id_room'] ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span class="saas-badge saas-badge-amber font-mono font-weight-bold" style="letter-spacing: 0.04em;">
                                        <?= htmlspecialchars($rep['pin']) ?>
                                    </span>
                                </td>
                                <td class="text-left" style="font-size: 13px;">
                                    <?php if (!empty($rep['is_review_mode'])): ?>
                                        <?php
                                        $startStr = !empty($rep['review_start_time']) ? date('M d, Y H:i', strtotime($rep['review_start_time'])) : date('M d, Y H:i', strtotime($rep['roomadd'] ?? 'now'));
                                        $endStr = !empty($rep['review_end_time']) ? date('M d, Y H:i', strtotime($rep['review_end_time'])) : 'No end date';
                                        ?>
                                        <div class="font-weight-medium" style="color: #334155; font-size: 12px;">
                                            <?= htmlspecialchars($startStr) ?>
                                        </div>
                                        <div style="font-size: 11.5px; color: #94a3b8;">
                                            &rarr; <?= htmlspecialchars($endStr) ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="font-weight-medium" style="color: #334155;">
                                            <?= date('M d, Y', strtotime($rep['roomadd'] ?? 'now')) ?>
                                        </div>
                                        <div style="font-size: 11.5px; color: #94a3b8;">
                                            <?= date('h:i A', strtotime($rep['roomadd'] ?? 'now')) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right">
                                    <span class="saas-numeric font-weight-bold" style="color: #0f172a; font-size: 13.5px;">
                                        <?= intval($rep['total_players']) ?>
                                    </span>
                                    <span style="font-size: 11.5px; color: #64748b;"><?= intval($rep['total_players']) === 1 ? 'player' : 'players' ?></span>
                                </td>
                                <td class="text-left">
                                    <?php if (!empty($rep['is_review_mode'])): ?>
                                        <span style="color: #94a3b8; font-size: 13px; font-style: italic;">—</span>
                                    <?php elseif ($rep['total_players'] > 0 && !empty($rep['winner_nickname'])): ?>
                                        <div class="d-flex align-items-center" style="gap: 6px;">
                                            <i class="fas fa-crown" style="color: #f59e0b; font-size: 13px;" title="Game Winner"></i>
                                            <span class="font-weight-medium text-truncate" style="max-width: 110px; color: #0f172a;" title="<?= htmlspecialchars($rep['winner_nickname']) ?>">
                                                <?= htmlspecialchars($rep['winner_nickname']) ?>
                                            </span>
                                            <span class="saas-badge saas-badge-emerald tabular-nums">
                                                <?= number_format($rep['top_score']) ?> pts
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 12px; font-style: italic;">
                                            No players
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right">
                                    <!-- 3-IN-1 CONSOLIDATED ACTION DROPDOWN MENU -->
                                    <div class="dropdown saas-action-dropdown">
                                        <button class="saas-dropdown-btn" type="button" id="actionMenu_<?= $rep['id_room'] ?>" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                            <span>Actions</span>
                                            <i class="fas fa-chevron-down" style="font-size: 10px; color: #64748b;"></i>
                                        </button>
                                        <div class="dropdown-menu dropdown-menu-right saas-dropdown-menu shadow" aria-labelledby="actionMenu_<?= $rep['id_room'] ?>">
                                            <!-- Action 1: View Details -->
                                            <a class="dropdown-item saas-dropdown-item" href="<?= BASE_URL ?>modules/reports/detail.php?id_room=<?= $rep['id_room'] ?>">
                                                <span class="saas-menu-icon icon-purple">
                                                    <i class="fas fa-chart-line"></i>
                                                </span>
                                                <div>
                                                    <div class="font-weight-medium">View Details</div>
                                                    <small class="text-muted" style="font-size: 11px;">Leaderboard & question analytics</small>
                                                </div>
                                            </a>

                                            <!-- Action 2: Download CSV -->
                                            <a class="dropdown-item saas-dropdown-item" href="<?= BASE_URL ?>modules/reports/export_csv.php?id_room=<?= $rep['id_room'] ?>">
                                                <span class="saas-menu-icon icon-emerald">
                                                    <i class="fas fa-file-excel"></i>
                                                </span>
                                                <div>
                                                    <div class="font-weight-medium">Download CSV</div>
                                                    <small class="text-muted" style="font-size: 11px;">Excel-compatible grade report</small>
                                                </div>
                                            </a>

                                            <div class="dropdown-divider my-1"></div>

                                            <!-- Action 3: Delete Session -->
                                            <a class="dropdown-item saas-dropdown-item text-danger" href="<?= BASE_URL ?>modules/reports/list.php?action=delete&id_room=<?= $rep['id_room'] ?>" onclick="return confirm('Are you sure you want to delete session PIN: <?= htmlspecialchars($rep['pin']) ?> (Quiz: <?= htmlspecialchars(addslashes($rep['quiz_name'])) ?>)? All associated player scores will be permanently deleted.');">
                                                <span class="saas-menu-icon icon-rose">
                                                    <i class="far fa-trash-alt"></i>
                                                </span>
                                                <div>
                                                    <div class="font-weight-medium">Delete Session</div>
                                                    <small style="color: #be123c; font-size: 11px;">Permanently remove records</small>
                                                </div>
                                            </a>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Integrated Pagination Footer -->
            <div id="reportsPagination" class="saas-pagination-bar"></div>
        <?php endif; ?>
    </div>

</div>

<script src="<?= BASE_URL ?>assets/js/reports-pagination.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('reportsTable') && document.getElementById('reportsPagination')) {
        new SaasTablePagination({
            table: '#reportsTable',
            container: '#reportsPagination',
            itemNoun: 'sessions',
            pageSize: 10,
            allowedPageSizes: [10, 25, 50]
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
