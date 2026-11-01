<?php

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../config/database.php';

$creatorId = intval($_SESSION['id_creator']);
$quizzes = db_fetch_all(
    "SELECT q.*, 
            (SELECT COUNT(*) FROM questions WHERE id_quiz = q.id_quiz) as question_count,
            (SELECT r.pin FROM room r WHERE r.id_quiz = q.id_quiz AND r.is_review_mode = 1 ORDER BY r.id_room DESC LIMIT 1) as review_pin,
            (SELECT r.id_room FROM room r WHERE r.id_quiz = q.id_quiz AND r.is_review_mode = 1 ORDER BY r.id_room DESC LIMIT 1) as review_room_id,
            (SELECT COUNT(p.id_player) FROM room r JOIN players p ON r.id_room = p.id_room WHERE r.id_quiz = q.id_quiz AND r.is_review_mode = 1) as review_player_count
     FROM quiz q
     WHERE q.id_creator = :c
     ORDER BY q.id_quiz DESC",
    [':c' => $creatorId]
);

$pageTitle = "My Kahoots | Quiz Management";
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';

$flash = get_flash_message();
?>

<div class="container my-5">
    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show mb-4" role="alert">
            <?= htmlspecialchars($flash['message']) ?>
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="font-weight-bold" style="color: var(--kahoot-purple);">
                <i class="fas fa-th-large mr-2"></i>My Kahoots
            </h2>
            <p class="text-muted">View, edit questions, or host a live game session.</p>
        </div>
        <a href="<?= BASE_URL ?>modules/quiz/create.php" class="btn btn-kahoot btn-kahoot-green">
            <i class="fas fa-plus mr-1"></i> Create New Quiz
        </a>
    </div>

    <div class="row">
        <?php if (empty($quizzes)): ?>
            <div class="col-12 text-center py-5">
                <div class="card border-0 shadow-sm p-5" style="border-radius: 12px;">
                    <i class="fas fa-folder-open fa-4x text-muted mb-3"></i>
                    <h4>You have no quizzes yet!</h4>
                    <p class="text-muted">Get started by creating your very first trivia quiz.</p>
                    <div>
                        <a href="<?= BASE_URL ?>modules/quiz/create.php" class="btn btn-kahoot btn-kahoot-purple">
                            <i class="fas fa-plus-circle mr-1"></i> Create Kahoot Now
                        </a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($quizzes as $q): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card h-100 border-0 shadow-sm" style="border-radius: 12px;">
                        <div style="height: 160px; background: linear-gradient(135deg, var(--kahoot-purple), var(--kahoot-purple-light)); display: flex; align-items: center; justify-content: center; position: relative; border-top-left-radius: 12px; border-top-right-radius: 12px; overflow: hidden;">
                            <img src="<?= BASE_URL ?>assets/img/cover.png" alt="Cover" style="max-height: 100%; object-fit: cover; opacity: 0.35; width: 100%;">
                            <div class="position-absolute text-center px-3">
                                <h5 class="text-white font-weight-bold mb-1"><?= htmlspecialchars($q['name']) ?></h5>
                                <div class="d-flex justify-content-center align-items-center flex-wrap" style="gap: 5px;">
                                    <span class="badge badge-warning text-dark font-weight-bold">
                                        <i class="fas fa-question-circle mr-1"></i> <?= $q['question_count'] ?> questions
                                    </span>
                                    <?php if (!empty($q['is_review_mode'])): ?>
                                        <span class="badge badge-success font-weight-bold shadow-sm">
                                            <i class="fas fa-book-reader mr-1"></i> Review: Active
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="card-body d-flex flex-column justify-content-between p-3">
                            <p class="text-muted small mb-3">
                                <?= !empty($q['description']) ? htmlspecialchars(mb_strimwidth($q['description'], 0, 100, '...')) : '<i>No description</i>' ?>
                            </p>
                            <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                                <small class="text-muted"><?= date('M d, Y', strtotime($q['quizadd'] ?? $q['created_at'] ?? 'now')) ?></small>
                                <div class="d-flex align-items-center">
                                    <?php if (!empty($q['is_review_mode'])): ?>
                                        <a href="<?= !empty($q['review_room_id']) ? BASE_URL . 'modules/reports/detail.php?id_room=' . $q['review_room_id'] : BASE_URL . 'modules/reports/list.php' ?>" class="btn btn-sm btn-info font-weight-bold mr-2 shadow-sm" title="View Review Results">
                                            <i class="fas fa-eye mr-1"></i> View
                                        </a>
                                    <?php else: ?>
                                        <a href="<?= BASE_URL ?>modules/host/create_room.php?id_quiz=<?= $q['id_quiz'] ?>" class="btn btn-sm btn-primary font-weight-bold mr-2" title="Play">
                                            <i class="fas fa-play mr-1"></i> Play
                                        </a>
                                    <?php endif; ?>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary font-weight-bold dropdown-toggle" type="button" id="quizActions_<?= $q['id_quiz'] ?>" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="Actions">
                                            <i class="fas fa-ellipsis-v mr-1"></i> Actions
                                        </button>
                                        <div class="dropdown-menu dropdown-menu-right shadow-sm" aria-labelledby="quizActions_<?= $q['id_quiz'] ?>">
                                            <a class="dropdown-item" href="#" data-toggle="modal" data-target="#reviewModal_<?= $q['id_quiz'] ?>">
                                                <i class="fas fa-book-reader text-primary mr-2"></i> Review mode
                                            </a>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item" href="<?= BASE_URL ?>modules/quiz/edit.php?id=<?= $q['id_quiz'] ?>">
                                                <i class="fas fa-edit text-success mr-2"></i> Edit
                                            </a>
                                            <a class="dropdown-item" href="<?= BASE_URL ?>modules/quiz/duplicate.php?id=<?= $q['id_quiz'] ?>">
                                                <i class="fas fa-copy text-info mr-2"></i> Duplicate
                                            </a>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-danger" href="<?= BASE_URL ?>modules/quiz/delete.php?id=<?= $q['id_quiz'] ?>" onclick="return confirm('Are you sure you want to delete this quiz?');">
                                                <i class="fas fa-trash-alt mr-2"></i> Delete
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Review Mode Settings for this Quiz -->
                <div class="modal fade text-dark" id="reviewModal_<?= $q['id_quiz'] ?>" tabindex="-1" role="dialog" aria-labelledby="reviewModalTitle_<?= $q['id_quiz'] ?>" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered" role="document">
                        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
                            <div class="modal-header text-white" style="background: linear-gradient(135deg, #46178f, #6d28d9);">
                                <h5 class="modal-title font-weight-bold" id="reviewModalTitle_<?= $q['id_quiz'] ?>">
                                    <i class="fas fa-book-reader mr-2"></i> Review Mode Settings
                                </h5>
                                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                            <div class="modal-body p-4 text-left">
                                <h6 class="font-weight-bold text-dark mb-1"><?= htmlspecialchars($q['name']) ?></h6>
                                <p class="text-muted small mb-3">
                                    When Review Mode is enabled, students can take this quiz independently at their own pace without the host starting the session. No timer, no points, and no leaderboard.
                                </p>

                                <?php if (!empty($q['is_review_mode'])): ?>
                                    <div class="alert alert-success border-0 shadow-sm p-3 mb-3" style="border-radius: 10px;">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <span class="font-weight-bold text-success"><i class="fas fa-check-circle mr-1"></i> Review Mode is Active</span>
                                            <span class="badge badge-light text-dark font-weight-bold"><?= intval($q['review_player_count'] ?? 0) ?> participants</span>
                                        </div>
                                        <div class="bg-white p-2 rounded border text-center my-2">
                                            <span class="text-muted small d-block">Review Game PIN:</span>
                                            <span class="h3 font-weight-bold text-dark letter-spacing-1"><?= htmlspecialchars($q['review_pin'] ?? '—') ?></span>
                                        </div>
                                        <div class="input-group input-group-sm mt-2">
                                            <input type="text" class="form-control" id="shareLink_<?= $q['id_quiz'] ?>" value="<?= BASE_URL ?>modules/player/join.php?pin=<?= htmlspecialchars($q['review_pin'] ?? '') ?>" readonly>
                                            <div class="input-group-append">
                                                <button class="btn btn-outline-success font-weight-bold" type="button" onclick="copyShareLink(<?= $q['id_quiz'] ?>)">
                                                    <i class="fas fa-copy mr-1"></i> Copy Link
                                                </button>
                                                <button class="btn btn-outline-dark font-weight-bold" type="button" onclick="toggleReviewQr(<?= $q['id_quiz'] ?>, '<?= BASE_URL ?>modules/player/join.php?pin=<?= htmlspecialchars($q['review_pin'] ?? '') ?>')">
                                                    <i class="fas fa-qrcode mr-1"></i> QR Code
                                                </button>
                                            </div>
                                        </div>
                                        <div id="reviewQrBox_<?= $q['id_quiz'] ?>" class="text-center mt-3 p-3 bg-white rounded border shadow-sm" style="display: none;">
                                            <div id="reviewQrSvg_<?= $q['id_quiz'] ?>" class="d-flex justify-content-center"></div>
                                            <div class="small text-muted mt-2 font-weight-bold">
                                                <i class="fas fa-camera mr-1"></i> Scan with your phone to take this quiz
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <form id="formReview_<?= $q['id_quiz'] ?>" onsubmit="submitReviewForm(event, <?= $q['id_quiz'] ?>)">
                                    <div class="form-group mb-3">
                                        <label class="font-weight-bold text-dark small">
                                            <i class="far fa-calendar-alt text-primary mr-1"></i> Start time (From date &amp; time):
                                        </label>
                                        <input type="datetime-local" class="form-control form-control-sm" name="review_start_time" value="<?= !empty($q['review_start_time']) ? date('Y-m-d\TH:i', strtotime($q['review_start_time'])) : '' ?>">
                                        <small class="text-muted">Leave blank to open immediately.</small>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label class="font-weight-bold text-dark small">
                                            <i class="far fa-calendar-check text-danger mr-1"></i> End time (To date &amp; time):
                                        </label>
                                        <input type="datetime-local" class="form-control form-control-sm" name="review_end_time" value="<?= !empty($q['review_end_time']) ? date('Y-m-d\TH:i', strtotime($q['review_end_time'])) : '' ?>">
                                        <small class="text-muted">Leave blank for unlimited duration.</small>
                                    </div>

                                    <div class="d-flex justify-content-between align-items-center pt-3 border-top mt-4">
                                        <?php if (!empty($q['is_review_mode'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger font-weight-bold" onclick="stopReviewMode(<?= $q['id_quiz'] ?>)">
                                                <i class="fas fa-stop-circle mr-1"></i> Stop review early
                                            </button>
                                            <button type="submit" class="btn btn-sm btn-primary font-weight-bold px-3" style="background:#46178f; border-color:#46178f;">
                                                <i class="fas fa-save mr-1"></i> Update schedule
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-secondary font-weight-bold" data-dismiss="modal">Close</button>
                                            <button type="submit" class="btn btn-sm btn-success font-weight-bold px-4" style="background:#26890c; border-color:#26890c;">
                                                <i class="fas fa-power-off mr-1"></i> Enable Review Mode
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script src="<?= BASE_URL ?>assets/js/qrcodegen.js"></script>
<script>
async function submitReviewForm(e, quizId) {
    e.preventDefault();
    const form = document.getElementById('formReview_' + quizId);
    const formData = new FormData(form);
    const payload = {
        id_quiz: quizId,
        action: 'enable',
        review_start_time: formData.get('review_start_time'),
        review_end_time: formData.get('review_end_time')
    };

    try {
        const res = await fetch('<?= BASE_URL ?>modules/api/toggle_review_mode.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'An error occurred while saving settings.');
        }
    } catch (err) {
        alert('Server connection error.');
    }
}

async function stopReviewMode(quizId) {
    if (!confirm('Are you sure you want to stop this review session early? Students will no longer be able to access it.')) {
        return;
    }
    try {
        const res = await fetch('<?= BASE_URL ?>modules/api/toggle_review_mode.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_quiz: quizId, action: 'stop' })
        });
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'Unable to stop review mode.');
        }
    } catch (err) {
        alert('Server connection error.');
    }
}

function copyShareLink(quizId) {
    const input = document.getElementById('shareLink_' + quizId);
    if (!input) return;
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value).then(() => {
        alert('Review link copied to clipboard!');
    }).catch(() => {
        document.execCommand('copy');
        alert('Review link copied!');
    });
}

function toggleReviewQr(quizId, joinUrl) {
    const box = document.getElementById('reviewQrBox_' + quizId);
    const container = document.getElementById('reviewQrSvg_' + quizId);
    if (!box || !container) return;

    if (box.style.display !== 'none') {
        box.style.display = 'none';
        return;
    }

    box.style.display = 'block';
    if (container.children.length > 0) return;

    if (typeof qrcodegen !== 'undefined') {
        try {
            const qr = qrcodegen.QrCode.encodeText(joinUrl, qrcodegen.QrCode.Ecc.MEDIUM);
            const border = 2;
            const size = qr.size + border * 2;
            const namespace = 'http://www.w3.org/2000/svg';
            const svg = document.createElementNS(namespace, 'svg');
            svg.setAttribute('viewBox', '0 0 ' + size + ' ' + size);
            svg.setAttribute('shape-rendering', 'crispEdges');
            svg.setAttribute('style', 'max-width: 170px; width: 100%; height: auto; border-radius: 8px;');

            const background = document.createElementNS(namespace, 'rect');
            background.setAttribute('width', '100%');
            background.setAttribute('height', '100%');
            background.setAttribute('fill', '#ffffff');
            svg.appendChild(background);

            const modules = [];
            for (let y = 0; y < qr.size; y++) {
                for (let x = 0; x < qr.size; x++) {
                    if (qr.getModule(x, y)) {
                        modules.push('M' + (x + border) + ',' + (y + border) + 'h1v1h-1z');
                    }
                }
            }
            const path = document.createElementNS(namespace, 'path');
            path.setAttribute('d', modules.join(''));
            path.setAttribute('fill', '#1e1b4b');
            svg.appendChild(path);

            container.replaceChildren(svg);
        } catch (e) {
            container.innerHTML = '<span class="text-danger small">Failed to generate QR code.</span>';
        }
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
