<?php

require_once __DIR__ . '/../config/database.php';

if (!class_exists('TeamManager')) {
class TeamManager {
    /**
     * Default team definitions with Kahoot-inspired bold colors and icons.
     */
    private static $teams = [
        1 => [
            'id' => 1,
            'name' => 'Team 1',
            'short_name' => 'Team 1',
            'color' => '#e21b3c',
            'bg_gradient' => 'linear-gradient(135deg, #e21b3c, #b3122c)',
            'text_color' => '#ffffff',
            'icon' => 'fas fa-shield-alt',
            'emoji' => '🔴'
        ],
        2 => [
            'id' => 2,
            'name' => 'Team 2',
            'short_name' => 'Team 2',
            'color' => '#1368ce',
            'bg_gradient' => 'linear-gradient(135deg, #1368ce, #0d47a1)',
            'text_color' => '#ffffff',
            'icon' => 'fas fa-flag',
            'emoji' => '🔵'
        ],
        3 => [
            'id' => 3,
            'name' => 'Team 3',
            'short_name' => 'Team 3',
            'color' => '#d89e00',
            'bg_gradient' => 'linear-gradient(135deg, #ffa602, #d89e00)',
            'text_color' => '#ffffff',
            'icon' => 'fas fa-bolt',
            'emoji' => '🟡'
        ],
        4 => [
            'id' => 4,
            'name' => 'Team 4',
            'short_name' => 'Team 4',
            'color' => '#26890c',
            'bg_gradient' => 'linear-gradient(135deg, #26890c, #1b5e20)',
            'text_color' => '#ffffff',
            'icon' => 'fas fa-leaf',
            'emoji' => '🟢'
        ],
        5 => [
            'id' => 5,
            'name' => 'Team 5',
            'short_name' => 'Team 5',
            'color' => '#864cbf',
            'bg_gradient' => 'linear-gradient(135deg, #864cbf, #5e35b1)',
            'text_color' => '#ffffff',
            'icon' => 'fas fa-gem',
            'emoji' => '🟣'
        ],
        6 => [
            'id' => 6,
            'name' => 'Team 6',
            'short_name' => 'Team 6',
            'color' => '#ff6f00',
            'bg_gradient' => 'linear-gradient(135deg, #ff6f00, #e65100)',
            'text_color' => '#ffffff',
            'icon' => 'fas fa-fire',
            'emoji' => '🟠'
        ],
        7 => [
            'id' => 7,
            'name' => 'Team 7',
            'short_name' => 'Team 7',
            'color' => '#0097a7',
            'bg_gradient' => 'linear-gradient(135deg, #00acc1, #00838f)',
            'text_color' => '#ffffff',
            'icon' => 'fas fa-compass',
            'emoji' => '🌐'
        ],
        8 => [
            'id' => 8,
            'name' => 'Team 8',
            'short_name' => 'Team 8',
            'color' => '#e91e63',
            'bg_gradient' => 'linear-gradient(135deg, #ec407a, #c2185b)',
            'text_color' => '#ffffff',
            'icon' => 'fas fa-heart',
            'emoji' => '🌸'
        ],
        9 => [
            'id' => 9,
            'name' => 'Team 9',
            'short_name' => 'Team 9',
            'color' => '#3949ab',
            'bg_gradient' => 'linear-gradient(135deg, #3f51b5, #283593)',
            'text_color' => '#ffffff',
            'icon' => 'fas fa-crown',
            'emoji' => '👑'
        ],
        10 => [
            'id' => 10,
            'name' => 'Team 10',
            'short_name' => 'Team 10',
            'color' => '#546e7a',
            'bg_gradient' => 'linear-gradient(135deg, #607d8b, #37474f)',
            'text_color' => '#ffffff',
            'icon' => 'fas fa-star',
            'emoji' => '⭐'
        ],
    ];

    /**
     * Check if team mode is enabled in given room or quiz array/object.
     */
    public static function isTeamMode($entity): bool {
        if (!is_array($entity)) return false;
        return !empty($entity['is_team_mode']);
    }

    /**
     * Get number of configured teams.
     */
    public static function getTeamCount($entity): int {
        if (!is_array($entity)) return 2;
        $count = intval($entity['team_count'] ?? 2);
        return max(2, min(10, $count));
    }

    /**
     * Get player limit per team (0 = unlimited).
     */
    public static function getTeamPlayerLimit($entity): int {
        if (!is_array($entity)) return 0;
        return max(0, intval($entity['team_player_limit'] ?? 0));
    }

    /**
     * Get team definition by teamId (1-10).
     */
    public static function getTeamInfo(int $teamId): array {
        if (isset(self::$teams[$teamId])) {
            return self::$teams[$teamId];
        }
        return [
            'id' => $teamId,
            'name' => 'Team ' . $teamId,
            'short_name' => 'Team ' . $teamId,
            'color' => '#46178f',
            'bg_gradient' => 'linear-gradient(135deg, #46178f, #25076b)',
            'text_color' => '#ffffff',
            'icon' => 'fas fa-users',
            'emoji' => '👥'
        ];
    }

    /**
     * Get active team definitions for a room or quiz.
     */
    public static function getTeamsForEntity($entity): array {
        $count = self::getTeamCount($entity);
        $result = [];
        for ($i = 1; $i <= $count; $i++) {
            $result[$i] = self::getTeamInfo($i);
        }
        return $result;
    }

    /**
     * Get live status of teams in a room (member counts, capacity, etc.).
     */
    public static function getRoomTeamsStatus(int $roomId): array {
        $room = db_fetch_one("SELECT * FROM room WHERE id_room = :r", [':r' => $roomId]);
        if (!$room) return [];

        $teamCount = self::getTeamCount($room);
        $limit = self::getTeamPlayerLimit($room);

        // Fetch counts per team
        $counts = db_fetch_all(
            "SELECT team_id, COUNT(*) as cnt FROM players WHERE id_room = :r AND is_kicked = 0 AND team_id IS NOT NULL GROUP BY team_id",
            [':r' => $roomId]
        );
        $countsMap = [];
        foreach ($counts as $row) {
            $countsMap[intval($row['team_id'])] = intval($row['cnt']);
        }

        $teams = [];
        for ($i = 1; $i <= $teamCount; $i++) {
            $info = self::getTeamInfo($i);
            $currentCount = $countsMap[$i] ?? 0;
            $isFull = ($limit > 0 && $currentCount >= $limit);

            $teams[$i] = array_merge($info, [
                'member_count' => $currentCount,
                'limit' => $limit,
                'is_full' => $isFull,
            ]);
        }
        return $teams;
    }

    /**
     * Validate whether a player can join a specific team.
     */
    public static function validateTeamJoin(int $roomId, int $teamId): array {
        $room = db_fetch_one("SELECT * FROM room WHERE id_room = :r", [':r' => $roomId]);
        if (!$room) {
            return ['valid' => false, 'message' => 'Room not found.'];
        }

        if (!self::isTeamMode($room)) {
            return ['valid' => true, 'team_id' => null, 'team_name' => null];
        }

        $teamCount = self::getTeamCount($room);
        if ($teamId < 1 || $teamId > $teamCount) {
            return ['valid' => false, 'message' => 'Vui lòng chọn một đội hợp lệ (từ 1 đến ' . $teamCount . ').'];
        }

        $limit = self::getTeamPlayerLimit($room);
        if ($limit > 0) {
            $currentCount = db_count(
                'players',
                'id_room = :r AND team_id = :t AND is_kicked = 0',
                [':r' => $roomId, ':t' => $teamId]
            );
            if ($currentCount >= $limit) {
                return ['valid' => false, 'message' => 'Đội này đã đủ ' . $limit . ' thành viên! Vui lòng chọn đội khác.'];
            }
        }

        $teamInfo = self::getTeamInfo($teamId);
        return [
            'valid' => true,
            'team_id' => $teamId,
            'team_name' => $teamInfo['name']
        ];
    }

    /**
     * Build the full team leaderboard for a room.
     * Calculates total team score, correct answers, player count, and ranks players within each team.
     */
    public static function getTeamLeaderboard(int $roomId): array {
        $room = db_fetch_one("SELECT * FROM room WHERE id_room = :r", [':r' => $roomId]);
        if (!$room) return [];

        $teamCount = self::getTeamCount($room);
        $teams = [];
        for ($i = 1; $i <= $teamCount; $i++) {
            $teams[$i] = array_merge(self::getTeamInfo($i), [
                'total_score' => 0,
                'total_correct' => 0,
                'member_count' => 0,
                'members' => [],
                'top_3_players' => [],
            ]);
        }

        // Fetch all active players in room with their votes count
        $players = get_room_leaderboard($roomId);

        foreach ($players as $p) {
            $tId = intval($p['team_id'] ?? 0);
            if ($tId >= 1 && $tId <= $teamCount) {
                $pScore = intval($p['score'] ?? 0);
                $pCorrect = intval($p['correct_count'] ?? ($p['answers_correct'] ?? 0));

                $teams[$tId]['total_score'] += $pScore;
                $teams[$tId]['total_correct'] += $pCorrect;
                $teams[$tId]['member_count'] += 1;
                $teams[$tId]['members'][] = $p;
            }
        }

        // Sort members within each team by individual score DESC, correct count DESC
        foreach ($teams as &$t) {
            usort($t['members'], function ($a, $b) {
                $scoreDiff = intval($b['score'] ?? 0) - intval($a['score'] ?? 0);
                if ($scoreDiff !== 0) return $scoreDiff;
                $corrDiff = intval($b['correct_count'] ?? 0) - intval($a['correct_count'] ?? 0);
                if ($corrDiff !== 0) return $corrDiff;
                return intval($a['id_player']) - intval($b['id_player']);
            });

            // Assign individual team rank
            foreach ($t['members'] as $mIdx => &$m) {
                $m['team_rank'] = $mIdx + 1;
            }
            unset($m);

            // Top 3 players in team
            $t['top_3_players'] = array_slice($t['members'], 0, 3);
        }
        unset($t);

        // Sort teams by total_score DESC, total_correct DESC, team_id ASC
        usort($teams, function ($a, $b) {
            if ($b['total_score'] !== $a['total_score']) {
                return $b['total_score'] - $a['total_score'];
            }
            if ($b['total_correct'] !== $a['total_correct']) {
                return $b['total_correct'] - $a['total_correct'];
            }
            return $a['id'] - $b['id'];
        });

        // Assign team rank
        $currentRank = 1;
        foreach ($teams as $idx => &$t) {
            if ($idx > 0) {
                $prev = $teams[$idx - 1];
                if ($t['total_score'] === $prev['total_score'] && $t['total_correct'] === $prev['total_correct']) {
                    $t['rank'] = $prev['rank'];
                } else {
                    $t['rank'] = $idx + 1;
                }
            } else {
                $t['rank'] = 1;
            }
        }
        unset($t);

        return $teams;
    }

    /**
     * Render a neat HTML badge for a team.
     */
    public static function renderTeamBadge(int $teamId, ?string $customLabel = null): string {
        $info = self::getTeamInfo($teamId);
        $label = $customLabel ?: $info['short_name'];
        return sprintf(
            '<span class="badge team-badge shadow-sm font-weight-bold" style="background-color: %s; color: %s; padding: 4px 10px; border-radius: 12px; display: inline-flex; align-items: center; gap: 4px;">' .
            '<i class="%s"></i> %s</span>',
            htmlspecialchars($info['color']),
            htmlspecialchars($info['text_color']),
            htmlspecialchars($info['icon']),
            htmlspecialchars($label)
        );
    }
}
}
