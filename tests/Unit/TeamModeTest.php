<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/TeamManager.php';
require_once __DIR__ . '/../../includes/room_access.php';

class TeamModeTest {
    public function testTeamModeHelperDefinitions() {
        assert(TeamManager::isTeamMode(['is_team_mode' => 1]) === true);
        assert(TeamManager::isTeamMode(['is_team_mode' => 0]) === false);
        assert(TeamManager::isTeamMode([]) === false);

        assert(TeamManager::getTeamCount(['team_count' => 4]) === 4);
        assert(TeamManager::getTeamCount(['team_count' => 1]) === 2, 'Minimum team count is 2');
        assert(TeamManager::getTeamCount(['team_count' => 20]) === 10, 'Maximum team count is 10');

        assert(TeamManager::getTeamPlayerLimit(['team_player_limit' => 5]) === 5);
        assert(TeamManager::getTeamPlayerLimit(['team_player_limit' => 0]) === 0);
        assert(TeamManager::getTeamPlayerLimit([]) === 0);

        $team1 = TeamManager::getTeamInfo(1);
        assert(!empty($team1['color']));
        assert(!empty($team1['icon']));
        assert(!empty($team1['name']));
        assert($team1['id'] === 1);
    }

    public function testTeamEntityCatalog() {
        $teams = TeamManager::getTeamsForEntity(['team_count' => 3]);
        assert(count($teams) === 3);
        assert(isset($teams[1], $teams[2], $teams[3]));
        assert(!isset($teams[4]));

        $badge = TeamManager::renderTeamBadge(1);
        assert(strpos($badge, 'Team 1') !== false);
        assert(strpos($badge, 'badge') !== false);
    }

    public function testValidateTeamSelection() {
        // Create a temporary mock room in DB
        $creator = db_fetch_one("SELECT id_creator FROM creators LIMIT 1");
        $creatorId = intval($creator['id_creator'] ?? 1);
        $quizId = db_insert("INSERT INTO quiz (id_creator, name, is_team_mode, team_count, team_player_limit) VALUES (:c, 'Team Mode Test', 1, 3, 2)", [':c' => $creatorId]);
        $pin = sprintf("%06d", mt_rand(100000, 999999));
        $roomId = db_insert("INSERT INTO room (id_quiz, id_creator, pin, status, is_team_mode, team_count, team_player_limit) VALUES (:q, :c, :p, 'waiting', 1, 3, 2)", [
            ':q' => $quizId,
            ':c' => $creatorId,
            ':p' => $pin
        ]);

        try {
            // Valid team join
            $validRes = TeamManager::validateTeamJoin($roomId, 1);
            assert($validRes['valid'] === true);
            assert($validRes['team_id'] === 1);

            // Invalid team (out of range: team 4 when count is 3)
            $invalidRes = TeamManager::validateTeamJoin($roomId, 4);
            assert($invalidRes['valid'] === false);

            // Invalid team (team 0)
            $invalidResZero = TeamManager::validateTeamJoin($roomId, 0);
            assert($invalidResZero['valid'] === false);

            // Fill team 1 up to limit (limit = 2)
            db_insert("INSERT INTO players (id_room, nickname, score, team_id, team_name, is_kicked) VALUES (:r, 'P1', 100, 1, 'Team 1', 0)", [':r' => $roomId]);
            db_insert("INSERT INTO players (id_room, nickname, score, team_id, team_name, is_kicked) VALUES (:r, 'P2', 200, 1, 'Team 1', 0)", [':r' => $roomId]);

            // Now team 1 should be full
            $fullRes = TeamManager::validateTeamJoin($roomId, 1);
            assert($fullRes['valid'] === false, 'Team 1 should be rejected as full when reaching player limit');

            // Team 2 should still be open
            $team2Res = TeamManager::validateTeamJoin($roomId, 2);
            assert($team2Res['valid'] === true, 'Team 2 should still be open');

        } finally {
            // Clean up test data
            db_execute("DELETE FROM players WHERE id_room = :r", [':r' => $roomId]);
            db_execute("DELETE FROM room WHERE id_room = :r", [':r' => $roomId]);
            db_execute("DELETE FROM quiz WHERE id_quiz = :q", [':q' => $quizId]);
        }
    }

    public function testTeamLeaderboardCalculationAndTop3Members() {
        // Create test room with 2 teams
        $creator = db_fetch_one("SELECT id_creator FROM creators LIMIT 1");
        $creatorId = intval($creator['id_creator'] ?? 1);
        $quizId = db_insert("INSERT INTO quiz (id_creator, name, is_team_mode, team_count) VALUES (:c, 'Leaderboard Test', 1, 2)", [':c' => $creatorId]);
        $pin = sprintf("%06d", mt_rand(100000, 999999));
        $roomId = db_insert("INSERT INTO room (id_quiz, id_creator, pin, status, is_team_mode, team_count) VALUES (:q, :c, :p, 'finished', 1, 2)", [
            ':q' => $quizId,
            ':c' => $creatorId,
            ':p' => $pin
        ]);

        try {
            // Team 1 players: 4 players
            // Total score: 1000 + 800 + 500 + 200 = 2500
            db_insert("INSERT INTO players (id_room, nickname, score, answers_correct, team_id, team_name, is_kicked) VALUES (:r, 'T1_A', 1000, 5, 1, 'Team 1', 0)", [':r' => $roomId]);
            db_insert("INSERT INTO players (id_room, nickname, score, answers_correct, team_id, team_name, is_kicked) VALUES (:r, 'T1_B', 800, 4, 1, 'Team 1', 0)", [':r' => $roomId]);
            db_insert("INSERT INTO players (id_room, nickname, score, answers_correct, team_id, team_name, is_kicked) VALUES (:r, 'T1_C', 500, 3, 1, 'Team 1', 0)", [':r' => $roomId]);
            db_insert("INSERT INTO players (id_room, nickname, score, answers_correct, team_id, team_name, is_kicked) VALUES (:r, 'T1_D', 200, 1, 1, 'Team 1', 0)", [':r' => $roomId]);

            // Team 2 players: 2 players
            // Total score: 1500 + 1200 = 2700 (Team 2 should win rank 1!)
            db_insert("INSERT INTO players (id_room, nickname, score, answers_correct, team_id, team_name, is_kicked) VALUES (:r, 'T2_A', 1500, 6, 2, 'Team 2', 0)", [':r' => $roomId]);
            db_insert("INSERT INTO players (id_room, nickname, score, answers_correct, team_id, team_name, is_kicked) VALUES (:r, 'T2_B', 1200, 5, 2, 'Team 2', 0)", [':r' => $roomId]);

            $lb = TeamManager::getTeamLeaderboard($roomId);
            assert(count($lb) === 2);

            // First place should be Team 2 (2700 pts)
            assert($lb[0]['id'] === 2, 'Team 2 should be ranked 1st');
            assert($lb[0]['rank'] === 1);
            assert($lb[0]['total_score'] === 2700);
            assert($lb[0]['member_count'] === 2);

            // Second place should be Team 1 (2500 pts)
            assert($lb[1]['id'] === 1, 'Team 1 should be ranked 2nd');
            assert($lb[1]['rank'] === 2);
            assert($lb[1]['total_score'] === 2500);
            assert($lb[1]['member_count'] === 4);

            // Check top 3 players in Team 1
            assert(count($lb[1]['top_3_players']) === 3, 'Top 3 players array must contain exactly 3 players when team has >= 3');
            assert($lb[1]['top_3_players'][0]['nickname'] === 'T1_A');
            assert($lb[1]['top_3_players'][1]['nickname'] === 'T1_B');
            assert($lb[1]['top_3_players'][2]['nickname'] === 'T1_C');

        } finally {
            db_execute("DELETE FROM players WHERE id_room = :r", [':r' => $roomId]);
            db_execute("DELETE FROM room WHERE id_room = :r", [':r' => $roomId]);
            db_execute("DELETE FROM quiz WHERE id_quiz = :q", [':q' => $quizId]);
        }
    }

    public function testTeamReportIntegration() {
        $creator = db_fetch_one("SELECT id_creator FROM creators LIMIT 1");
        $creatorId = intval($creator['id_creator'] ?? 1);
        $quizId = db_insert("INSERT INTO quiz (id_creator, name, is_team_mode, team_count) VALUES (:c, 'Report Test Quiz', 1, 2)", [':c' => $creatorId]);
        $pin = sprintf("%06d", mt_rand(100000, 999999));
        $roomId = db_insert("INSERT INTO room (id_quiz, id_creator, pin, status, is_team_mode, team_count) VALUES (:q, :c, :p, 'finished', 1, 2)", [
            ':q' => $quizId,
            ':c' => $creatorId,
            ':p' => $pin
        ]);

        try {
            $room = db_fetch_one("SELECT * FROM room WHERE id_room = :r", [':r' => $roomId]);
            assert(TeamManager::isTeamMode($room) === true);

            db_insert("INSERT INTO players (id_room, nickname, score, team_id, team_name, is_kicked) VALUES (:r, 'Alpha', 500, 1, 'Team 1', 0)", [':r' => $roomId]);
            db_insert("INSERT INTO players (id_room, nickname, score, team_id, team_name, is_kicked) VALUES (:r, 'Beta', 800, 2, 'Team 2', 0)", [':r' => $roomId]);

            $lb = TeamManager::getTeamLeaderboard($roomId);
            assert(count($lb) === 2);
            assert($lb[0]['id'] === 2);
            assert($lb[0]['total_score'] === 800);
            assert($lb[1]['id'] === 1);
            assert($lb[1]['total_score'] === 500);

            // Badge check
            $badge = TeamManager::renderTeamBadge(1, 'Team 1');
            assert(strpos($badge, 'Team 1') !== false);
        } finally {
            db_execute("DELETE FROM players WHERE id_room = :r", [':r' => $roomId]);
            db_execute("DELETE FROM room WHERE id_room = :r", [':r' => $roomId]);
            db_execute("DELETE FROM quiz WHERE id_quiz = :q", [':q' => $quizId]);
        }
    }
}
