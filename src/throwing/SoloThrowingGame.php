<?php


namespace throwing;


use game_chef\api\FFAGameBuilder;
use game_chef\api\GameChef;
use game_chef\models\FFAGame;
use game_chef\models\GameType;
use game_chef\models\Score;
use game_chef\pmmp\bossbar\Bossbar;
use game_chef\pmmp\bossbar\BossbarType;
use pocketmine\entity\Attribute;
use pocketmine\entity\Effect;
use pocketmine\entity\EffectInstance;
use pocketmine\level\Position;
use pocketmine\Player;
use pocketmine\Server;
use throwing\scoreboard\SoloThrowingGameScoreboard;

class SoloThrowingGame
{
    static function getGameType(): GameType {
        return new GameType("SoloThrowing");
    }

    static function getBossBarType(): BossbarType {
        return new BossbarType("SoloThrowing");
    }

    /**
     * @param string $mapName
     * @throws \Exception
     */
    static function buildGame(string $mapName): void {
        $ffaGameBuilder = new FFAGameBuilder();
        $ffaGameBuilder->setGameType(self::getGameType());
        $ffaGameBuilder->setMaxPlayers(null);
        $ffaGameBuilder->setTimeLimit(600);
        $ffaGameBuilder->setVictoryScore(new Score(15));
        $ffaGameBuilder->setCanJumpIn(true);
        $ffaGameBuilder->selectMapByName($mapName);

        $ffaGame = $ffaGameBuilder->build();
        GameChef::registerGame($ffaGame);
    }

    static function sendToGame(Player $player, FFAGame $game): void {
        $levelName = $game->getMap()->getLevelName();
        $level = Server::getInstance()->getLevelByName($levelName);

        $player->teleport($level->getSpawnLocation());
        $player->teleport(Position::fromObject($player->getSpawn(), $level));
        $player->getAttributeMap()->getAttribute(Attribute::MOVEMENT_SPEED)->setValue(0.25);
        $player->addEffect(new EffectInstance(Effect::getEffect(Effect::JUMP_BOOST), 600, 4));
        $player->addEffect(new EffectInstance(Effect::getEffect(Effect::HASTE), 600, 4));

        //ボスバー
        $bossbar = new Bossbar($player, self::getBossBarType(), "", 1.0);
        $bossbar->send();
        SoloThrowingGameScoreboard::send($player, $game);

        $player->getInventory()->setContents([
        ]);
    }

    static function backToLobby(Player $player): void {
        $level = Server::getInstance()->getLevelByName("lobby");
        $player->teleport($level->getSpawnLocation());
        $player->getAttributeMap()->getAttribute(Attribute::MOVEMENT_SPEED)->setValue(0.1);
        $player->removeAllEffects();

        $player->getInventory()->setContents([
            //todo:インベントリセット
        ]);

        //ボスバー削除
        $bossbar = Bossbar::findByType($player, self::getBossBarType());
        if ($bossbar !== null) $bossbar->remove();
        SoloThrowingGameScoreboard::delete($player);
    }
}