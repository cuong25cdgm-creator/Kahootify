<?php

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/room_access.php';
require_once __DIR__ . '/../../includes/player_characters.php';

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
$characters = player_character_catalog();
$selectedCharacter = is_string($_POST['character_id'] ?? null) ? $_POST['character_id'] : default_player_character();
$selectedAccessory = is_string($_POST['accessory_id'] ?? null) ? $_POST['accessory_id'] : '';
$nickname = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canJoin) {
    $submittedNickname = $_POST['nickname'] ?? '';
    $nickname = is_string($submittedNickname) ? trim($submittedNickname) : '';

    if (!isset($characters[$selectedCharacter])) {
        $error = 'Please choose an available character.';
        $selectedCharacter = default_player_character();
    } elseif ($selectedAccessory !== '' && !isset(player_accessory_catalog()[$selectedAccessory])) {
        $error = 'Please choose an available accessory.';
        $selectedAccessory = '';
    } elseif (!empty($nickname)) {

        $result = join_room_player($roomId, $pin, $nickname, $selectedCharacter, $selectedAccessory);
        if ($result['success']) {
            $playerId = $result['id_player'];
            $nickname = $result['nickname'];

            $playerToken = substr(md5($playerId . '_' . $roomId . '_kahoot_secret_2026'), 0, 16);

            $_SESSION['id_player'] = $playerId;
            $_SESSION['player_nickname'] = $nickname;
            $_SESSION['player_room_id'] = $roomId;

            if (!empty($room['is_review_mode'])) {
                redirect('modules/player/review.php?pid=' . $playerId . '&token=' . $playerToken);
            } else {
                redirect('modules/player/waiting.php?pid=' . $playerId . '&token=' . $playerToken);
            }
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
