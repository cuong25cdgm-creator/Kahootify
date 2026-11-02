-- Migration: Add Team Mode columns to quiz, room, and players tables
-- Date: 2026-11-02

USE `kahoot_btn`;

-- Add team mode configuration to quiz table
ALTER TABLE `quiz`
  ADD COLUMN IF NOT EXISTS `is_team_mode` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `team_count` INT(11) NOT NULL DEFAULT 2,
  ADD COLUMN IF NOT EXISTS `team_player_limit` INT(11) NOT NULL DEFAULT 0;

-- Add team mode configuration to room table
ALTER TABLE `room`
  ADD COLUMN IF NOT EXISTS `is_team_mode` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `team_count` INT(11) NOT NULL DEFAULT 2,
  ADD COLUMN IF NOT EXISTS `team_player_limit` INT(11) NOT NULL DEFAULT 0;

-- Add team assignment to players table
ALTER TABLE `players`
  ADD COLUMN IF NOT EXISTS `team_id` INT(11) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `team_name` VARCHAR(50) NULL DEFAULT NULL;
