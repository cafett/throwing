<?php


namespace throwing;


use arch\pmmp\entities\ArrowProjectile;
use game_chef\api\GameChef;
use game_chef\models\GameStatus;
use game_chef\models\Score;
use game_chef\pmmp\bossbar\Bossbar;
use game_chef\pmmp\events\AddScoreEvent;
use game_chef\pmmp\events\FinishedGameEvent;
use game_chef\pmmp\events\PlayerJoinGameEvent;
use game_chef\pmmp\events\PlayerKilledPlayerEvent;
use game_chef\pmmp\events\PlayerQuitGameEvent;
use game_chef\pmmp\events\StartedGameEvent;
use game_chef\pmmp\events\UpdatedGameTimerEvent;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityIds;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\projectile\Snowball;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\item\ItemIds;
use pocketmine\network\mcpe\protocol\GameRulesChangedPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;
use throwing\item\ProjectileItem;
use throwing\scoreboard\SoloThrowingGameScoreboard;

class Main extends PluginBase implements Listener
{
    private array $gameTypes = [];

    public function onEnable() {
        $this->gameTypes = [
            SoloThrowingGame::getGameType()
        ];
        SoloThrowingGameScoreboard::init();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onJoin(PlayerJoinEvent $event) {
        $pk = new GameRulesChangedPacket();
        $pk->gameRules["doImmediateRespawn"] = [1, true];
        $event->getPlayer()->sendDataPacket($pk);
    }

    public function onJoinGame(PlayerJoinGameEvent $event) {
        $player = $event->getPlayer();
        $gameId = $event->getGameId();
        $gameType = $event->getGameType();

        if ($gameType->equals(SoloThrowingGame::getGameType())) {
            $game = GameChef::findFFAGameById($gameId);
            if ($game->getStatus()->equals(GameStatus::Started())) {
                SoloThrowingGame::sendToGame($player, $game);
            } else {
                $player->sendMessage("試合に参加しました");
            }
        }
    }

    public function onQuitGame(PlayerQuitGameEvent $event) {
        $player = $event->getPlayer();
        $gameId = $event->getGameId();
        $gameType = $event->getGameType();

        if ($gameType->equals(SoloThrowingGame::getGameType())) {
            foreach (GameChef::getPlayerDataList($gameId) as $playerData) {
                $gamePlayer = $this->getServer()->getPlayer($playerData->getName());
                $gamePlayer->sendMessage($player->getName() . "が試合から去りました");
            }
            SoloThrowingGame::backToLobby($player);
        }
    }

    public function onStartGame(StartedGameEvent $event) {
        $gameId = $event->getGameId();
        $gameType = $event->getGameType();

        if ($gameType->equals(SoloThrowingGame::getGameType())) {
            $game = GameChef::findFFAGameById($gameId);
            GameChef::setFFAPlayersSpawnPoint($gameId);

            foreach (GameChef::getPlayerDataList($gameId) as $playerData) {
                $player = Server::getInstance()->getPlayer($playerData->getName());
                SoloThrowingGame::sendToGame($player, $game);
            }
        }
    }

    public function onFinishedGame(FinishedGameEvent $event) {
        $gameId = $event->getGameId();
        $gameType = $event->getGameType();

        if ($gameType->equals(SoloThrowingGame::getGameType())) {
            foreach (GameChef::getPlayerDataList($gameId) as $playerData) {
                $player = Server::getInstance()->getPlayer($playerData->getName());
                SoloThrowingGame::backToLobby($player);
            }
            //TODO:演出
        }
    }

    public function onUpdatedGameTimer(UpdatedGameTimerEvent $event) {
        $gameId = $event->getGameId();
        $gameType = $event->getGameType();

        if ($gameType->equals(SoloThrowingGame::getGameType())) {
            //ボスバーの更新
            foreach (GameChef::getPlayerDataList($gameId) as $playerData) {
                $player = Server::getInstance()->getPlayer($playerData->getName());
                $bossbar = Bossbar::findByType($player, SoloThrowingGame::getBossBarType());
                if ($bossbar === null) continue;
                if ($event->getTimeLimit() === null) {
                    $bossbar->updateTitle("経過時間:({$event->getElapsedTime()})");
                } else {
                    $bossbar->updateTitle("{$event->getElapsedTime()}/{$event->getTimeLimit()}");
                    $bossbar->updatePercentage(1 - ($event->getElapsedTime() / $event->getTimeLimit()));
                }
            }
        }
    }

    public function onAddedScore(AddScoreEvent $event) {
        $gameId = $event->getGameId();
        $gameType = $event->getGameType();

        if ($gameType->equals(SoloThrowingGame::getGameType())) {
            $game = GameChef::findFFAGameById($gameId);
            foreach (GameChef::getPlayerDataList($gameId) as $playerData) {
                $player = Server::getInstance()->getPlayer($playerData->getName());
                SoloThrowingGameScoreboard::update($player, $game);
            }
        }
    }

    public function onPlayerDeath(PlayerDeathEvent $event) {
        $player = $event->getPlayer();
        if (GameChef::isRelatedWith($player, SoloThrowingGame::getGameType())) {
            //スポーン地点を再設定
            GameChef::setFFAPlayerSpawnPoint($event->getPlayer());

            //todo　なにかドロップするように
            $event->setDrops([]);
            $event->setXpDropAmount(0);
        }
    }

    public function onPlayerKilledPlayer(PlayerKilledPlayerEvent $event) {
        $gameId = $event->getGameId();
        $gameType = $event->getGameType();
        $attacker = $event->getAttacker();
        $killedPlayer = $event->getKilledPlayer();
        if ($gameType->equals(SoloThrowingGame::getGameType())) {
            //メッセージを送信
            $message = "[{$attacker->getName()}] killed [{$killedPlayer->getName()}]";
            foreach (GameChef::getPlayerDataList($gameId) as $playerData) {
                $gamePlayer = Server::getInstance()->getPlayer($playerData->getName());
                $gamePlayer->sendMessage($message);
            }

            //スコアの追加
            GameChef::addFFAGameScore($gameId, $attacker->getName(), new Score(1));
        }
    }

    public function onTapAir(DataPacketReceiveEvent $event) {
        $packet = $event->getPacket();
        if ($packet instanceof LevelSoundEventPacket) {
            if ($packet->sound === LevelSoundEventPacket::SOUND_ATTACK_NODAMAGE) {
                $player = $event->getPlayer();
                $item = $event->getPlayer()->getInventory()->getItemInHand();
                if ($item->getId() !== ItemIds::AIR) {
                    ProjectileItem::shoot($player, $item);
                }
            }
        }
    }
}