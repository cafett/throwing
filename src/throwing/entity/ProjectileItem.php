<?php


namespace throwing\item;


use pocketmine\block\Block;
use pocketmine\entity\Entity;
use pocketmine\entity\object\ItemEntity;
use pocketmine\item\Item;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use pocketmine\math\VoxelRayTrace;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\timings\Timings;

class ProjectileItem extends ItemEntity
{
    private const HIT_BLOCK = 0;
    private const HIT_ENTITY = 1;

    /** @var Vector3|null */
    protected $blockHit;
    /** @var int|null */
    protected $blockHitId;
    /** @var int|null */
    protected $blockHitData;

    public $width = 0.25;
    public $height = 0.25;
    protected $baseOffset = 0.125;

    protected $gravity = 0.05;
    protected $drag = 0.01;

    public $canCollide = true;

    static function shoot(Player $player, Item $item): void {
        $nbt = Entity::createBaseNBT($player->asVector3()->add(0, $player->getEyeHeight()), $player->getDirectionVector()->multiply(2), $player->getYaw(), $player->getPitch());

        $itemTag = $item->nbtSerialize();
        $itemTag->setName("Item");
        $nbt->setTag($itemTag);
        $entity = new ProjectileItem($player->getLevel(), $nbt);
        $entity->setOwner($player->getName());

        $entity->spawnToAll();
    }

    protected function initEntity(): void {
        parent::initEntity();

        $this->setMaxHealth(1);
        $this->setHealth(1);
        do {
            $blockHit = null;
            $blockId = null;
            $blockData = null;

            if ($this->namedtag->hasTag("tileX", IntTag::class) and $this->namedtag->hasTag("tileY", IntTag::class) and $this->namedtag->hasTag("tileZ", IntTag::class)) {
                $blockHit = new Vector3($this->namedtag->getInt("tileX"), $this->namedtag->getInt("tileY"), $this->namedtag->getInt("tileZ"));
            } else {
                break;
            }

            if ($this->namedtag->hasTag("blockId", IntTag::class)) {
                $blockId = $this->namedtag->getInt("blockId");
            } else {
                break;
            }

            if ($this->namedtag->hasTag("blockData", ByteTag::class)) {
                $blockData = $this->namedtag->getByte("blockData");
            } else {
                break;
            }

            $this->blockHit = $blockHit;
            $this->blockHitId = $blockId;
            $this->blockHitData = $blockData;
        } while (false);
    }

    public function move(float $dx, float $dy, float $dz): void {
        $this->blocksAround = null;

        Timings::$entityMoveTimer->startTiming();

        $start = $this->asVector3();
        $end = $start->add($this->motion);

        $blockHit = null;
        $entityHit = null;
        $hitResult = null;

        foreach (VoxelRayTrace::betweenPoints($start, $end) as $vector3) {
            $block = $this->level->getBlockAt($vector3->x, $vector3->y, $vector3->z);

            $blockHitResult = $this->calculateInterceptWithBlock($block, $start, $end);
            if ($blockHitResult !== null) {
                $end = $blockHitResult->hitVector;
                $blockHit = $block;
                $hitResult = $blockHitResult;
                break;
            }
        }

        $entityDistance = PHP_INT_MAX;

        $newDiff = $end->subtract($start);
        foreach ($this->level->getCollidingEntities($this->boundingBox->addCoord($newDiff->x, $newDiff->y, $newDiff->z)->expand(1, 1, 1), $this) as $entity) {
            if ($entity->getId() === $this->getOwningEntityId() and $this->ticksLived < 5) {
                continue;
            }

            $entityBB = $entity->boundingBox->expandedCopy(0.3, 0.3, 0.3);
            $entityHitResult = $entityBB->calculateIntercept($start, $end);

            if ($entityHitResult === null) {
                continue;
            }

            $distance = $this->distanceSquared($entityHitResult->hitVector);

            if ($distance < $entityDistance) {
                $entityDistance = $distance;
                $entityHit = $entity;
                $hitResult = $entityHitResult;
                $end = $entityHitResult->hitVector;
            }
        }

        $this->x = $end->x;
        $this->y = $end->y;
        $this->z = $end->z;
        $this->recalculateBoundingBox();

        if ($hitResult !== null) {
            $hitType = null;
            if ($entityHit !== null) {
                $hitType = self::HIT_ENTITY;
            } elseif ($blockHit !== null) {
                $hitType = self::HIT_BLOCK;
            } else {
                assert(false, "unknown hit type");
            }

            if ($hitType !== null) {
                if ($hitType === self::HIT_ENTITY) {
                    $this->onHitEntity($entityHit);
                } elseif ($hitType === self::HIT_BLOCK) {
                    $this->onHitBlock($blockHit);
                }
            }


            $this->isCollided = $this->onGround = true;
            $this->motion->x = $this->motion->y = $this->motion->z = 0;
        } else {
            $this->isCollided = $this->onGround = false;
            $this->blockHit = $this->blockHitId = $this->blockHitData = null;

            //recompute angles...
            $f = sqrt(($this->motion->x ** 2) + ($this->motion->z ** 2));
            $this->yaw = (atan2($this->motion->x, $this->motion->z) * 180 / M_PI);
            $this->pitch = (atan2($this->motion->y, $f) * 180 / M_PI);
        }

        $this->checkChunks();
        $this->checkBlockCollision();

        Timings::$entityMoveTimer->stopTiming();
    }

    protected function calculateInterceptWithBlock(Block $block, Vector3 $start, Vector3 $end): ?RayTraceResult {
        return $block->calculateIntercept($start, $end);
    }

    public function canCollideWith(Entity $entity): bool {
        if ($entity instanceof Player) {
            $owner = Server::getInstance()->getPlayer($this->owner);
            if ($owner !== null) {
                if ($entity->getName() === $owner->getName()) {
                    return false;
                }
            }
        }

        return !$this->justCreated and $entity !== $this;
    }

    public function canBeCollidedWith(): bool {
        return $this->isAlive();
    }

    public function onCollideWithPlayer(Player $player): void {
        return;
    }


    public function onHitEntity(Entity $entity) {
        //Todo: ダメージの変更
        //ブロックの硬さ ツールのレア度
        $entity->kill();
        $this->flagForDespawn();
    }

    public function onHitBlock($block) {
        //Todo: ブロックの種類によって変化させる
        //TNT:爆発
        $this->flagForDespawn();
    }
}