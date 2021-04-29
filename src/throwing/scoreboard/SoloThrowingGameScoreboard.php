<?php


namespace throwing\scoreboard;


use game_chef\api\GameChef;
use game_chef\models\FFAGame;
use game_chef\pmmp\scoreboard\Score;
use game_chef\pmmp\scoreboard\Scoreboard;
use game_chef\pmmp\scoreboard\ScoreboardSlot;
use game_chef\pmmp\scoreboard\ScoreSortType;
use pocketmine\Player;
use pocketmine\utils\TextFormat;

class SoloThrowingGameScoreboard extends Scoreboard
{
    static function init(): void {
        self::__setup(ScoreboardSlot::sideBar());
    }

    private static function create(Player $player, FFAGame $game): Scoreboard {
        $scores = [];

        $isRankedInTop5 = false;
        foreach (GameChef::sortFFATeamsByScore($game->getTeams()) as $index => $team) {
            if ($team->getName() === $player->getName()) {
                $isRankedInTop5 = true;
                $scores[] = new Score(TextFormat::RED . $team->getName() . TextFormat::RESET . ":" . strval($team->getScore()));
            } else {
                $scores[] = new Score($team->getName() . ":" . strval($team->getScore()));
            }

            if ($index >= 5) break;
        }

        if (!$isRankedInTop5) {
            $scores[] = new Score("----------");
            $scores[] = new Score($player->getName() . "");
        }

        return parent::__create($game->getMap()->getName(), $scores, ScoreSortType::smallToLarge());
    }

    static function send(Player $player, FFAGame $game) {
        $scoreboard = self::create($player, $game);
        parent::__send($player, $scoreboard);
    }

    static function update(Player $player, FFAGame $game) {
        $scoreboard = self::create($player, $game);
        parent::__update($player, $scoreboard);
    }
}