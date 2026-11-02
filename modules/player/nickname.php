<?php

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/room_access.php';
require_once __DIR__ . '/../../includes/player_characters.php';
require_once __DIR__ . '/../../includes/TeamManager.php';

$roomId = intval($_GET['room_id'] ?? ($_POST['room_id'] ?? ($_SESSION['player_room_id'] ?? 0)));
$submittedPin = $_GET['pin'] ?? ($_POST['pin'] ?? ($_SESSION['player_pin'] ?? ''));
$pin = is_string($submittedPin) ? trim($submittedPin) : '';

if ($roomId <= 0 && !empty($pin)) {
    $room = db_fetch_one("SELECT * FROM room WHERE pin = :pin AND status != 'finished' LIMIT 1", [':pin' => $pin]);
    if ($room) {
        $roomId = intval($room['id_room']);
    }
}

if ($roomId <= 0) {
    redirect('modules/player/join.php');
}

$room = db_fetch_one('SELECT * FROM room WHERE id_room = :r AND pin = :pin', [':r' => $roomId, ':pin' => $pin]);
$error = room_join_error($room);
$canJoin = $error === '';
$isTeamMode = TeamManager::isTeamMode($room);
$roomTeams = $isTeamMode ? TeamManager::getRoomTeamsStatus($roomId) : [];
$characters = player_character_catalog();
$selectedCharacter = is_string($_POST['character_id'] ?? null) ? $_POST['character_id'] : default_player_character();
$selectedAccessory = is_string($_POST['accessory_id'] ?? null) ? $_POST['accessory_id'] : '';
$selectedTeam = intval($_POST['team_id'] ?? 0);
$nickname = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canJoin) {
    $submittedNickname = $_POST['nickname'] ?? '';
    $nickname = is_string($submittedNickname) ? trim($submittedNickname) : '';

    if ($isTeamMode && $selectedTeam <= 0) {
        $error = 'Vui lòng chọn một đội tham gia!';
    } elseif (!isset($characters[$selectedCharacter])) {
        $error = 'Please choose an available character.';
        $selectedCharacter = default_player_character();
    } elseif ($selectedAccessory !== '' && !isset(player_accessory_catalog()[$selectedAccessory])) {
        $error = 'Please choose an available accessory.';
        $selectedAccessory = '';
    } elseif (!empty($nickname)) {

        $result = join_room_player($roomId, $pin, $nickname, $selectedCharacter, $selectedAccessory, $selectedTeam);
        if ($result['success']) {
            $playerId = $result['id_player'];
            $nickname = $result['nickname'];

            $playerToken = substr(md5($playerId . '_' . $roomId . '_kahoot_secret_2026'), 0, 16);

            $_SESSION['id_player'] = $playerId;
            $_SESSION['player_nickname'] = $nickname;
            $_SESSION['player_room_id'] = $roomId;
            if (!empty($result['team_id'])) {
                $_SESSION['player_team_id'] = $result['team_id'];
                $_SESSION['player_team_name'] = $result['team_name'] ?? '';
            }

            redirect('modules/player/waiting.php?pid=' . $playerId . '&token=' . $playerToken);
        } else {
            $error = $result['message'];
            $canJoin = false;
        }

    } else {
        $error = 'Please enter your nickname!';
    }
}

$pageTitle = "Kahoot! | Enter Nickname";
$bodyClass = "backgroundColorChanges";
$pageStyles = ['assets/css/player-characters.css'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-center" style="min-height: 100vh; padding: 20px;">
    <div class="pin-card-box join-card">
        <p id="joinStepLabel" class="join-step-label" hidden></p>
        <h3 id="joinHeading" class="font-weight-bold text-dark mb-3">Player Nickname</h3>
        <p id="joinDescription" class="text-muted small">Pick a nickname and customize your character.</p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger font-weight-bold small py-2">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($canJoin): ?>
        <form action="" method="POST" id="playerJoinForm" data-has-error="<?= $error !== '' ? 'true' : 'false' ?>">
            <input type="hidden" name="room_id" value="<?= $roomId ?>">
            <input type="hidden" name="pin" value="<?= htmlspecialchars($pin) ?>">
            <?php if ($isTeamMode): ?>
                <div id="teamStep" class="mb-3">
                    <p class="font-weight-bold text-dark mb-2" style="font-size: 0.95rem;">
                        <i class="fas fa-users text-primary mr-1"></i> Chọn đội của bạn:
                    </p>
                    <input type="hidden" name="team_id" id="selectedTeamId" value="<?= $selectedTeam > 0 ? $selectedTeam : '' ?>" required>
                    <div class="team-selection-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px; max-height: 280px; overflow-y: auto; padding: 4px;">
                        <?php foreach ($roomTeams as $t): 
                            $isFull = !empty($t['is_full']);
                            $isSelected = ($selectedTeam === $t['id']);
                        ?>
                            <div class="team-option-card <?= $isFull ? 'is-full' : '' ?> <?= $isSelected ? 'is-selected' : '' ?>"
                                 data-team-id="<?= $t['id'] ?>"
                                 data-team-name="<?= htmlspecialchars($t['name']) ?>"
                                 data-is-full="<?= $isFull ? '1' : '0' ?>"
                                 style="--team-color: <?= $t['color'] ?>; background: #fff; border: 2px solid <?= $isSelected ? $t['color'] : '#e0e0e0' ?>; border-radius: 12px; padding: 12px 8px; text-align: center; cursor: <?= $isFull ? 'not-allowed' : 'pointer' ?>; opacity: <?= $isFull ? '0.55' : '1' ?>; transition: all 0.2s ease;">
                                <div class="team-card-icon mb-1" style="font-size: 1.6rem; color: <?= $t['color'] ?>;">
                                    <i class="<?= $t['icon'] ?>"></i>
                                </div>
                                <div class="font-weight-bold" style="font-size: 0.88rem; color: #222;">
                                    <?= htmlspecialchars($t['short_name']) ?>
                                </div>
                                <div class="small text-muted mt-1" style="font-size: 0.75rem;">
                                    <?php if ($isFull): ?>
                                        <span class="badge badge-danger">ĐÃ ĐẦY</span>
                                    <?php else: ?>
                                        <span class="font-weight-bold" style="color: <?= $t['color'] ?>;"><?= $t['member_count'] ?><?= $t['limit'] > 0 ? '/' . $t['limit'] : '' ?> người</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="form-group mb-3" id="nicknameStep">
                <input type="text" name="nickname" class="form-control pin-input text-center" maxlength="20" placeholder="Nickname" value="<?= htmlspecialchars($nickname) ?>" required autofocus autocomplete="off">
            </div>
            <div id="appearanceStep">
                <?php require __DIR__ . '/../../includes/character_picker.php'; ?>
            </div>
            <div class="join-actions">
                <button type="button" class="join-back" id="joinBack" hidden>Back</button>
                <button type="button" class="btn btn-kahoot join-primary" id="joinContinue" hidden>Continue</button>
                <button type="submit" class="btn btn-kahoot join-primary" id="joinSubmit">OK, go!</button>
            </div>
        </form>
        <?php else: ?>
            <a href="<?= BASE_URL ?>modules/player/nickname.php?room_id=<?= $roomId ?>&amp;pin=<?= rawurlencode($pin) ?>" class="btn btn-kahoot btn-block btn-dark text-white py-3">Try again</a>
            <a href="<?= BASE_URL ?>modules/player/join.php" class="d-block mt-3 text-dark font-weight-bold">Enter another Game PIN</a>
        <?php endif; ?>
    </div>
</div>

<script src="<?= BASE_URL ?>assets/js/player-characters.js" defer></script>
</body>
</html>
