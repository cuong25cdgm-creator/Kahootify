<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/room_access.php';
require_once __DIR__ . '/player_characters.php';
require_once __DIR__ . '/TeamManager.php';

$roomId = (int)($roomId ?? ($room['id_room'] ?? ($_SESSION['id_room'] ?? 0)));
$roomData = is_array($room ?? null) ? $room : ['id_room' => $roomId];

if (empty($_SESSION['kick_player_token'])) {
    $_SESSION['kick_player_token'] = bin2hex(random_bytes(32));
}
$hostPlayers = filter_room_players($roomId, db_fetch_all('SELECT id_player, nickname, team_id, team_name FROM players WHERE id_room = :r ORDER BY (team_id IS NOT NULL AND team_id > 0) DESC, team_id ASC, id_player ASC', [':r' => $roomId]));
?>
<div id="hostPlayers" data-room-id="<?= $roomId ?>" data-api-url="<?= htmlspecialchars(BASE_URL . 'modules/api/', ENT_QUOTES, 'UTF-8') ?>" data-csrf-token="<?= htmlspecialchars($_SESSION['kick_player_token'], ENT_QUOTES, 'UTF-8') ?>" data-reload-after-kick="<?= !empty($reloadAfterKick) ? '1' : '0' ?>">
    <p id="kickPlayerStatus" class="small font-weight-bold" role="status" aria-live="polite"></p>
    <div id="playersContainer" class="host-players-list">
        <?php foreach ($hostPlayers as $p): 
            $appearance = get_player_appearance($roomData, (int) $p['id_player']); 
            $tInfo = !empty($p['team_id']) ? TeamManager::getTeamInfo((int)$p['team_id']) : null;
        ?>
            <div class="host-player-card" style="<?= $tInfo ? 'border-left: 4px solid ' . htmlspecialchars($tInfo['color']) . ';' : '' ?>">
                <span class="host-player-avatar"><?= player_appearance_image($appearance, 'lobby-player-avatar') ?></span>
                <span class="host-player-name">
                    <?= htmlspecialchars($p['nickname'], ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($tInfo): ?>
                        <span class="badge ml-1 shadow-sm font-weight-bold" style="background: <?= $tInfo['color'] ?>; color: #fff; font-size: 0.72rem; border-radius: 8px; padding: 2px 6px;">
                            <i class="<?= $tInfo['icon'] ?> mr-1"></i><?= htmlspecialchars($tInfo['short_name']) ?>
                        </span>
                    <?php endif; ?>
                </span>
                <button type="button" class="kick-player" data-player-id="<?= (int)$p['id_player'] ?>" data-player-name="<?= htmlspecialchars($p['nickname'], ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars('Kick ' . $p['nickname'], ENT_QUOTES, 'UTF-8') ?>">Kick</button>
            </div>
        <?php endforeach; ?>
    </div>
    <p id="waitingMsg" class="font-weight-bold mt-3" <?= $hostPlayers ? 'hidden' : '' ?>>Waiting for players to join...</p>
</div>
<script src="<?= BASE_URL ?>assets/js/host-players.js" defer></script>
