<?php

namespace Building;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\level\particle\ExplodeParticle;
use pocketmine\level\particle\FlameParticle;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\TaskHandler;
use pocketmine\utils\TextFormat;

class Main extends PluginBase implements Listener{

    const WAND_TAG = "BuildingWand";

    /** @var Vector3[] 选区角点1 */
    private $pos1 = [];
    /** @var Vector3[] 选区角点2 */
    private $pos2 = [];

    /**
     * 待确认的粘贴任务
     * [玩家小写名 => ['building' => 建筑名, 'origin' => Vector3, 'size' => [sx,sy,sz]]]
     */
    private $pendingPaste = [];

    private $wandId = 294;
    private $maxBlocks = 50000;
    private $skipAir = true;
    private $protectDurability = true;
    private $particleRange = 20;

    /** @var TaskHandler|null */
    private $particleTask = null;

    // ------------------------------------------------------------------
    //  生命周期
    // ------------------------------------------------------------------

    public function onEnable(){
        $this->ensureFolders();

        $this->saveDefaultConfig();
        $cfg = $this->getConfig();
        $this->wandId            = (int)  $cfg->get("wand-item", 294);
        $this->maxBlocks         = (int)  $cfg->get("max-blocks", 50000);
        $this->skipAir           = (bool) $cfg->get("skip-air", true);
        $this->protectDurability = (bool) $cfg->get("protect-durability", true);
        $this->particleRange     = (int)  $cfg->get("particle-range", 20);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $this->particleTask = $this->getServer()->getScheduler()->scheduleRepeatingTask(
            new ParticleTask($this),
            10
        );

        $this->getLogger()->info("建筑插件已启用 | 工具ID: " . $this->wandId . " | 粒子范围: " . $this->particleRange);
    }

    public function onDisable(){
        if($this->particleTask instanceof TaskHandler){
            $this->particleTask->cancel();
        }
        $this->particleTask = null;
        $this->pos1 = [];
        $this->pos2 = [];
        $this->pendingPaste = [];
        $this->getLogger()->info("建筑插件已禁用");
    }

    /** 确保数据目录和 buildings 子目录存在 */
    private function ensureFolders(){
        $data = $this->getDataFolder();
        if(!is_dir($data)){
            @mkdir($data, 0777, true);
        }
        $buildings = $data . "buildings/";
        if(!is_dir($buildings)){
            @mkdir($buildings, 0777, true);
        }
    }

    public function updateParticles(){
        $this->showSelectionParticles();
        $this->showPendingPasteParticles();
    }

    // ------------------------------------------------------------------
    //  圈地工具
    // ------------------------------------------------------------------

    private function createWand(){
        $displayTag = new CompoundTag("display", [
            new StringTag("Name", TextFormat::GOLD . "建筑圈地工具")
        ]);
        $rootTag = new CompoundTag("", [
            new StringTag(self::WAND_TAG, "1"),
            $displayTag
        ]);

        $item = Item::get($this->wandId, 0, 1, $rootTag);
        if(!$this->isBuildingWand($item)){
            $item->setNamedTag($rootTag);
        }
        return $item;
    }

    private function isBuildingWand(Item $item){
        if($item->getId() !== $this->wandId){
            return false;
        }
        $tag = $item->getNamedTag();
        if($tag === null){
            return false;
        }
        if(isset($tag->{self::WAND_TAG})){
            return true;
        }
        if($tag instanceof \ArrayAccess && $tag->offsetExists(self::WAND_TAG)){
            return true;
        }
        return false;
    }

    private function giveWand(Player $player){
        $wand = $this->createWand();
        $inv  = $player->getInventory();
        $slot = $inv->firstEmpty();
        if($slot !== -1){
            $inv->setItem($slot, $wand);
        }else{
            $inv->addItem($wand);
        }
    }

    // ------------------------------------------------------------------
    //  事件
    // ------------------------------------------------------------------

    public function onInteract(PlayerInteractEvent $event){
        $player = $event->getPlayer();
        $item   = $player->getInventory()->getItemInHand();

        if(!$this->isBuildingWand($item)){
            return;
        }

        $action = $event->getAction();
        if($action !== 0 && $action !== 1){
            return;
        }

        if($this->protectDurability && $item->getDamage() > 0){
            $item->setDamage(0);
            $player->getInventory()->setItemInHand($item);
        }

        $event->setCancelled(true);

        $block = $event->getBlock();
        $pos   = new Vector3($block->getX(), $block->getY(), $block->getZ());
        $key   = strtolower($player->getName());

        if($action === 0){
            $this->pos1[$key] = $pos;
            $player->sendMessage(TextFormat::GREEN . "[建筑] 第一个角点: " . TextFormat::YELLOW
                . $pos->getX() . ", " . $pos->getY() . ", " . $pos->getZ());
        }else{
            $this->pos2[$key] = $pos;
            $player->sendMessage(TextFormat::GREEN . "[建筑] 第二个角点: " . TextFormat::YELLOW
                . $pos->getX() . ", " . $pos->getY() . ", " . $pos->getZ());
        }

        if(isset($this->pos1[$key]) && isset($this->pos2[$key])){
            $size  = $this->getSize($this->pos1[$key], $this->pos2[$key]);
            $total = $size[0] * $size[1] * $size[2];
            $player->sendMessage(TextFormat::AQUA . "[建筑] 当前选区: "
                . $size[0] . " x " . $size[1] . " x " . $size[2]
                . " = " . $total . " 方块");
            if($total > $this->maxBlocks){
                $player->sendMessage(TextFormat::RED . "[建筑] 选区超上限 " . $this->maxBlocks . "，无法保存！");
            }
        }
    }

    public function onQuit(PlayerQuitEvent $event){
        $key = strtolower($event->getPlayer()->getName());
        unset($this->pos1[$key], $this->pos2[$key], $this->pendingPaste[$key]);
    }

    // ------------------------------------------------------------------
    //  命令
    // ------------------------------------------------------------------

    public function onCommand(CommandSender $sender, Command $command, $label, array $args){
        switch(strtolower($command->getName())){

            case "wand":
                if(!($sender instanceof Player)){
                    $sender->sendMessage(TextFormat::RED . "该命令仅限游戏内使用");
                    return true;
                }
                $this->giveWand($sender);
                $sender->sendMessage(TextFormat::GREEN . "[建筑] 已获得圈地工具（带 NBT 的金锄头）");
                $sender->sendMessage(TextFormat::YELLOW . "  §7左键§f = 角点1   §7右键§f = 角点2");
                return true;

            case "pos1":
            case "pos2":
                if(!($sender instanceof Player)){
                    $sender->sendMessage(TextFormat::RED . "该命令仅限游戏内使用");
                    return true;
                }
                $pos = new Vector3($sender->getFloorX(), $sender->getFloorY(), $sender->getFloorZ());
                $key = strtolower($sender->getName());
                if(strtolower($command->getName()) === "pos1"){
                    $this->pos1[$key] = $pos;
                    $sender->sendMessage(TextFormat::GREEN . "[建筑] 角点1 已设为 " . $pos->getX() . ", " . $pos->getY() . ", " . $pos->getZ());
                }else{
                    $this->pos2[$key] = $pos;
                    $sender->sendMessage(TextFormat::GREEN . "[建筑] 角点2 已设为 " . $pos->getX() . ", " . $pos->getY() . ", " . $pos->getZ());
                }
                return true;

            case "build":
                if(!($sender instanceof Player)){
                    $sender->sendMessage(TextFormat::RED . "该命令仅限游戏内使用");
                    return true;
                }
                if(count($args) < 1){
                    $this->sendHelp($sender);
                    return true;
                }

                $sub  = strtolower($args[0]);
                $name = isset($args[1]) ? $args[1] : null;

                switch($sub){
                    case "save":
                        if($name === null){
                            $sender->sendMessage(TextFormat::RED . "用法: /build save <名称>");
                            return true;
                        }
                        $this->saveBuilding($sender, $name);
                        return true;

                    case "paste":
                    case "load":
                        if($name === null){
                            $sender->sendMessage(TextFormat::RED . "用法: /build paste <名称>");
                            return true;
                        }
                        $this->previewPaste($sender, $name);
                        return true;

                    case "confirm":
                    case "yes":
                        $this->confirmPaste($sender);
                        return true;

                    case "cancel":
                    case "no":
                        $this->cancelPaste($sender);
                        return true;

                    case "list":
                        $this->listBuildings($sender);
                        return true;

                    case "info":
                        if($name === null){
                            $sender->sendMessage(TextFormat::RED . "用法: /build info <名称>");
                            return true;
                        }
                        $this->infoBuilding($sender, $name);
                        return true;

                    case "delete":
                    case "del":
                        if($name === null){
                            $sender->sendMessage(TextFormat::RED . "用法: /build delete <名称>");
                            return true;
                        }
                        $this->deleteBuilding($sender, $name);
                        return true;

                    default:
                        $sender->sendMessage(TextFormat::RED . "未知子命令: " . $sub);
                        return true;
                }
        }
        return false;
    }

    private function sendHelp(Player $player){
        $player->sendMessage(TextFormat::YELLOW . "===== 建筑插件 =====");
        $player->sendMessage(TextFormat::WHITE . "/build save <名称>     §7保存当前选区");
        $player->sendMessage(TextFormat::WHITE . "/build paste <名称>    §7预览粘贴范围");
        $player->sendMessage(TextFormat::WHITE . "/build confirm         §7确认粘贴");
        $player->sendMessage(TextFormat::WHITE . "/build cancel          §7取消粘贴");
        $player->sendMessage(TextFormat::WHITE . "/build list            §7列出所有建筑");
        $player->sendMessage(TextFormat::WHITE . "/build info <名称>     §7查看建筑信息");
        $player->sendMessage(TextFormat::WHITE . "/build delete <名称>   §7删除建筑");
        $player->sendMessage(TextFormat::WHITE . "/wand                  §7获得圈地工具");
    }

    // ------------------------------------------------------------------
    //  保存
    // ------------------------------------------------------------------

    private function saveBuilding(Player $player, $name){
        $this->ensureFolders();

        $key = strtolower($player->getName());

        if(!isset($this->pos1[$key]) || !isset($this->pos2[$key])){
            $player->sendMessage(TextFormat::RED . "[建筑] 请先用圈地工具设置两个角点");
            return;
        }

        $name = preg_replace('/[^A-Za-z0-9_\-\x{4e00}-\x{9fa5}]/u', '', $name);
        if($name === null || $name === ""){
            $player->sendMessage(TextFormat::RED . "[建筑] 名称不合法");
            return;
        }

        $p1 = $this->pos1[$key];
        $p2 = $this->pos2[$key];

        $minX = min($p1->getFloorX(), $p2->getFloorX());
        $minY = min($p1->getFloorY(), $p2->getFloorY());
        $minZ = min($p1->getFloorZ(), $p2->getFloorZ());
        $maxX = max($p1->getFloorX(), $p2->getFloorX());
        $maxY = max($p1->getFloorY(), $p2->getFloorY());
        $maxZ = max($p1->getFloorZ(), $p2->getFloorZ());

        $sx = $maxX - $minX + 1;
        $sy = $maxY - $minY + 1;
        $sz = $maxZ - $minZ + 1;
        $total = $sx * $sy * $sz;

        if($total > $this->maxBlocks){
            $player->sendMessage(TextFormat::RED . "[建筑] 选区过大: " . $total . " 方块，上限 " . $this->maxBlocks);
            return;
        }

        $level = $player->getLevel();
        $player->sendMessage(TextFormat::YELLOW . "[建筑] 正在读取 " . $total . " 个方块，请稍候…");

        $blocks = [];
        for($x = 0; $x < $sx; $x++){
            for($y = 0; $y < $sy; $y++){
                for($z = 0; $z < $sz; $z++){
                    $id   = $level->getBlockIdAt($minX + $x, $minY + $y, $minZ + $z);
                    $meta = $level->getBlockDataAt($minX + $x, $minY + $y, $minZ + $z);
                    if($this->skipAir && $id === 0 && $meta === 0){
                        continue;
                    }
                    $blocks[] = [$x, $y, $z, $id, $meta];
                }
            }
        }

        $data = [
            "name"   => $name,
            "author" => $player->getName(),
            "time"   => date("Y-m-d H:i:s"),
            "level"  => $level->getFolderName(),
            "size"   => [$sx, $sy, $sz],
            "count"  => count($blocks),
            "blocks" => $blocks
        ];

        $path = $this->getDataFolder() . "buildings/" . $name . ".json";
        $json = json_encode($data);

        if($json === false || file_put_contents($path, $json) === false){
            $player->sendMessage(TextFormat::RED . "[建筑] 保存失败，请检查插件目录权限");
            return;
        }

        $player->sendMessage(TextFormat::GREEN . "[建筑] 已保存建筑 " . TextFormat::YELLOW . $name
            . TextFormat::GREEN . "（" . $sx . "x" . $sy . "x" . $sz . "，共 " . count($blocks) . " 方块）");
    }

    // ------------------------------------------------------------------
    //  预览粘贴
    // ------------------------------------------------------------------

    private function previewPaste(Player $player, $name){
        $this->ensureFolders();

        $data = $this->loadBuildingData($name);
        if($data === null){
            $player->sendMessage(TextFormat::RED . "[建筑] 找不到建筑: " . $name);
            return;
        }

        $key   = strtolower($player->getName());

        // 优先用角点1；没有则用玩家脚下方块
        if(isset($this->pos1[$key])){
            $origin = $this->pos1[$key];
        }else{
            $origin = new Vector3($player->getFloorX(), $player->getFloorY(), $player->getFloorZ());
        }

        $size = isset($data["size"]) ? $data["size"] : [0, 0, 0];

        // 记入待确认
        $this->pendingPaste[$key] = [
            "building" => $data["name"],
            "origin"   => $origin,
            "size"     => $size
        ];

        $ox = $origin->getFloorX();
        $oy = $origin->getFloorY();
        $oz = $origin->getFloorZ();

        $player->sendMessage(TextFormat::GREEN . "[建筑] ===== 粘贴预览 =====");
        $player->sendMessage(TextFormat::WHITE . "建筑: " . TextFormat::YELLOW . $data["name"]);
        $player->sendMessage(TextFormat::WHITE . "作者: " . TextFormat::YELLOW . (isset($data["author"]) ? $data["author"] : "未知"));
        $player->sendMessage(TextFormat::WHITE . "尺寸: " . TextFormat::YELLOW . $size[0] . " x " . $size[1] . " x " . $size[2]);
        $player->sendMessage(TextFormat::WHITE . "原点: " . TextFormat::YELLOW . $ox . ", " . $oy . ", " . $oz);
        $player->sendMessage(TextFormat::AQUA . "白色爆炸粒子 = 即将粘贴的范围");
        $player->sendMessage(TextFormat::GREEN . "输入 " . TextFormat::YELLOW . "/build confirm" . TextFormat::GREEN . " 确认粘贴");
        $player->sendMessage(TextFormat::RED   . "输入 " . TextFormat::YELLOW . "/build cancel"  . TextFormat::RED   . " 取消");
    }

    private function confirmPaste(Player $player){
        $key = strtolower($player->getName());

        if(!isset($this->pendingPaste[$key])){
            $player->sendMessage(TextFormat::RED . "[建筑] 当前没有待确认的粘贴任务，请先用 /build paste <名称>");
            return;
        }

        $pending = $this->pendingPaste[$key];
        $data    = $this->loadBuildingData($pending["building"]);
        if($data === null){
            $player->sendMessage(TextFormat::RED . "[建筑] 建筑文件已丢失: " . $pending["building"]);
            unset($this->pendingPaste[$key]);
            return;
        }

        $level  = $player->getLevel();
        $origin = $pending["origin"];
        $ox = $origin->getFloorX();
        $oy = $origin->getFloorY();
        $oz = $origin->getFloorZ();

        $player->sendMessage(TextFormat::YELLOW . "[建筑] 正在粘贴 " . $data["name"] . " 到 "
            . $ox . ", " . $oy . ", " . $oz . " …");

        $count = 0;
        foreach($data["blocks"] as $b){
            $x = $ox + $b[0];
            $y = $oy + $b[1];
            $z = $oz + $b[2];

            if($y < 0 || $y > 127){
                continue;
            }

            if(!$level->isChunkLoaded($x >> 4, $z >> 4)){
                $level->loadChunk($x >> 4, $z >> 4);
            }

            $level->setBlockIdAt($x, $y, $z, $b[3]);
            $level->setBlockDataAt($x, $y, $z, $b[4]);
            $count++;
        }

        unset($this->pendingPaste[$key]);

        $player->sendMessage(TextFormat::GREEN . "[建筑] 粘贴完成，共放置 " . $count . " 个方块（作者: "
            . (isset($data["author"]) ? $data["author"] : "未知") . "）");
    }

    private function cancelPaste(Player $player){
        $key = strtolower($player->getName());

        if(!isset($this->pendingPaste[$key])){
            $player->sendMessage(TextFormat::YELLOW . "[建筑] 当前没有待确认的粘贴任务");
            return;
        }

        $name = $this->pendingPaste[$key]["building"];
        unset($this->pendingPaste[$key]);
        $player->sendMessage(TextFormat::GREEN . "[建筑] 已取消粘贴: " . $name);
    }

    // ------------------------------------------------------------------
    //  读取 / 列表 / 信息 / 删除
    // ------------------------------------------------------------------

    private function loadBuildingData($name){
        $name = preg_replace('/[^A-Za-z0-9_\-\x{4e00}-\x{9fa5}]/u', '', $name);
        if($name === "" || $name === null){
            return null;
        }

        $path = $this->getDataFolder() . "buildings/" . $name . ".json";
        if(!is_file($path)){
            return null;
        }

        $raw = file_get_contents($path);
        if($raw === false){
            return null;
        }

        $data = json_decode($raw, true);
        if(!is_array($data) || !isset($data["blocks"]) || !is_array($data["blocks"])){
            return null;
        }
        return $data;
    }

    private function listBuildings(Player $player){
        $this->ensureFolders();

        $files = glob($this->getDataFolder() . "buildings/*.json");
        if(empty($files)){
            $player->sendMessage(TextFormat::YELLOW . "[建筑] 目前还没有保存任何建筑");
            return;
        }

        $names = [];
        foreach($files as $f){
            $names[] = basename($f, ".json");
        }

        $player->sendMessage(TextFormat::GREEN . "[建筑] 已保存 " . count($names) . " 个建筑:");
        $player->sendMessage(TextFormat::WHITE . "  " . implode(", ", $names));
    }

    private function infoBuilding(Player $player, $name){
        $data = $this->loadBuildingData($name);
        if($data === null){
            $player->sendMessage(TextFormat::RED . "[建筑] 找不到建筑: " . $name);
            return;
        }

        $size = isset($data["size"]) ? $data["size"] : [0, 0, 0];
        $player->sendMessage(TextFormat::GREEN . "===== 建筑信息 =====");
        $player->sendMessage(TextFormat::WHITE . "名称: " . TextFormat::YELLOW . $data["name"]);
        $player->sendMessage(TextFormat::WHITE . "作者: " . TextFormat::YELLOW . (isset($data["author"]) ? $data["author"] : "未知"));
        $player->sendMessage(TextFormat::WHITE . "时间: " . TextFormat::YELLOW . (isset($data["time"]) ? $data["time"] : "未知"));
        $player->sendMessage(TextFormat::WHITE . "尺寸: " . TextFormat::YELLOW . $size[0] . " x " . $size[1] . " x " . $size[2]);
        $player->sendMessage(TextFormat::WHITE . "方块数: " . TextFormat::YELLOW . (isset($data["count"]) ? $data["count"] : count($data["blocks"])));
    }

    private function deleteBuilding(Player $player, $name){
        $name = preg_replace('/[^A-Za-z0-9_\-\x{4e00}-\x{9fa5}]/u', '', $name);
        $path = $this->getDataFolder() . "buildings/" . $name . ".json";

        if(!is_file($path)){
            $player->sendMessage(TextFormat::RED . "[建筑] 找不到建筑: " . $name);
            return;
        }

        if(unlink($path)){
            $player->sendMessage(TextFormat::GREEN . "[建筑] 已删除建筑: " . $name);
        }else{
            $player->sendMessage(TextFormat::RED . "[建筑] 删除失败");
        }
    }

    // ------------------------------------------------------------------
    //  粒子：选区（火焰）
    // ------------------------------------------------------------------

    private function showSelectionParticles(){
        $rangeSq = $this->particleRange * $this->particleRange;

        foreach($this->pos1 as $name => $p1){
            $player = $this->getServer()->getPlayerExact($name);
            if($player === null){
                continue;
            }

            $level   = $player->getLevel();
            $targets = [$player];

            $px = $player->getX();
            $py = $player->getY();
            $pz = $player->getZ();

            if($this->distanceSq($px, $py, $pz, $p1->getX(), $p1->getY(), $p1->getZ()) <= $rangeSq){
                $this->drawCornerMarker($level, $p1, $targets, $px, $py, $pz, $rangeSq);
            }

            if(isset($this->pos2[$name])){
                $p2 = $this->pos2[$name];

                if($this->distanceSq($px, $py, $pz, $p2->getX(), $p2->getY(), $p2->getZ()) <= $rangeSq){
                    $this->drawCornerMarker($level, $p2, $targets, $px, $py, $pz, $rangeSq);
                }

                $this->drawBox($level, $p1, $p2, $targets, $px, $py, $pz, $rangeSq, false);
            }
        }
    }

    // ------------------------------------------------------------------
    //  粒子：粘贴预览（爆炸）
    // ------------------------------------------------------------------

    private function showPendingPasteParticles(){
        if(empty($this->pendingPaste)){
            return;
        }

        $rangeSq = $this->particleRange * $this->particleRange;

        foreach($this->pendingPaste as $name => $pending){
            $player = $this->getServer()->getPlayerExact($name);
            if($player === null){
                continue;
            }

            $level   = $player->getLevel();
            $targets = [$player];

            $px = $player->getX();
            $py = $player->getY();
            $pz = $player->getZ();

            $origin = $pending["origin"];
            $size   = $pending["size"];

            $ox = $origin->getFloorX();
            $oy = $origin->getFloorY();
            $oz = $origin->getFloorZ();

            $x0 = $ox;
            $y0 = $oy;
            $z0 = $oz;
            $x1 = $ox + $size[0];
            $y1 = $oy + $size[1];
            $z1 = $oz + $size[2];

            // 用 ExplodeParticle（白色爆炸粒子）画预览框，和红色火焰选区区分
            $this->drawPreviewBox($level, $x0, $y0, $z0, $x1, $y1, $z1, $targets, $px, $py, $pz, $rangeSq);
        }
    }

    private function drawPreviewBox(Level $level, $x0, $y0, $z0, $x1, $y1, $z1, array $targets, $px, $py, $pz, $rangeSq){
        $this->drawPreviewLine($level, $x0, $y0, $z0, $x1, $y0, $z0, $targets, $px, $py, $pz, $rangeSq);
        $this->drawPreviewLine($level, $x1, $y0, $z0, $x1, $y0, $z1, $targets, $px, $py, $pz, $rangeSq);
        $this->drawPreviewLine($level, $x1, $y0, $z1, $x0, $y0, $z1, $targets, $px, $py, $pz, $rangeSq);
        $this->drawPreviewLine($level, $x0, $y0, $z1, $x0, $y0, $z0, $targets, $px, $py, $pz, $rangeSq);

        $this->drawPreviewLine($level, $x0, $y1, $z0, $x1, $y1, $z0, $targets, $px, $py, $pz, $rangeSq);
        $this->drawPreviewLine($level, $x1, $y1, $z0, $x1, $y1, $z1, $targets, $px, $py, $pz, $rangeSq);
        $this->drawPreviewLine($level, $x1, $y1, $z1, $x0, $y1, $z1, $targets, $px, $py, $pz, $rangeSq);
        $this->drawPreviewLine($level, $x0, $y1, $z1, $x0, $y1, $z0, $targets, $px, $py, $pz, $rangeSq);

        $this->drawPreviewLine($level, $x0, $y0, $z0, $x0, $y1, $z0, $targets, $px, $py, $pz, $rangeSq);
        $this->drawPreviewLine($level, $x1, $y0, $z0, $x1, $y1, $z0, $targets, $px, $py, $pz, $rangeSq);
        $this->drawPreviewLine($level, $x1, $y0, $z1, $x1, $y1, $z1, $targets, $px, $py, $pz, $rangeSq);
        $this->drawPreviewLine($level, $x0, $y0, $z1, $x0, $y1, $z1, $targets, $px, $py, $pz, $rangeSq);
    }

    private function drawPreviewLine(Level $level, $x1, $y1, $z1, $x2, $y2, $z2, array $targets, $px, $py, $pz, $rangeSq){
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $dz = $z2 - $z1;

        $steps = (int) max(abs($dx), abs($dy), abs($dz));
        // 预览框粒子更稀疏一点，方便和选区区分
        $step  = max(1, (int) ceil($steps / 32));

        if($steps === 0){
            if($this->distanceSq($x1, $y1, $z1, $px, $py, $pz) <= $rangeSq){
                $level->addParticle(new ExplodeParticle(new Vector3($x1, $y1, $z1)), $targets);
            }
            return;
        }

        for($i = 0; $i <= $steps; $i += $step){
            $t = $i / $steps;
            $x = $x1 + $dx * $t;
            $y = $y1 + $dy * $t;
            $z = $z1 + $dz * $t;

            if($this->distanceSq($x, $y, $z, $px, $py, $pz) > $rangeSq){
                continue;
            }

            $level->addParticle(new ExplodeParticle(new Vector3($x, $y, $z)), $targets);
        }
    }

    // ------------------------------------------------------------------
    //  粒子：公用
    // ------------------------------------------------------------------

    private function distanceSq($x1, $y1, $z1, $x2, $y2, $z2){
        $dx = $x1 - $x2;
        $dy = $y1 - $y2;
        $dz = $z1 - $z2;
        return $dx * $dx + $dy * $dy + $dz * $dz;
    }

    private function drawCornerMarker(Level $level, Vector3 $pos, array $targets, $px, $py, $pz, $rangeSq){
        $cx = $pos->getFloorX() + 0.5;
        $cy = $pos->getFloorY() + 1.1;
        $cz = $pos->getFloorZ() + 0.5;

        for($i = 0; $i < 8; $i++){
            $angle = $i * (M_PI / 4);
            $x = $cx + cos($angle) * 0.35;
            $y = $cy;
            $z = $cz + sin($angle) * 0.35;

            if($this->distanceSq($x, $y, $z, $px, $py, $pz) > $rangeSq){
                continue;
            }

            $level->addParticle(new FlameParticle(new Vector3($x, $y, $z)), $targets);
        }
    }

    /** @param bool $preview 保留参数，默认 false（选区用火焰） */
    private function drawBox(Level $level, Vector3 $a, Vector3 $b, array $targets, $px, $py, $pz, $rangeSq, $preview = false){
        $x0 = min($a->getFloorX(), $b->getFloorX());
        $y0 = min($a->getFloorY(), $b->getFloorY());
        $z0 = min($a->getFloorZ(), $b->getFloorZ());
        $x1 = max($a->getFloorX(), $b->getFloorX()) + 1;
        $y1 = max($a->getFloorY(), $b->getFloorY()) + 1;
        $z1 = max($a->getFloorZ(), $b->getFloorZ()) + 1;

        $this->drawLine($level, $x0, $y0, $z0, $x1, $y0, $z0, $targets, $px, $py, $pz, $rangeSq);
        $this->drawLine($level, $x1, $y0, $z0, $x1, $y0, $z1, $targets, $px, $py, $pz, $rangeSq);
        $this->drawLine($level, $x1, $y0, $z1, $x0, $y0, $z1, $targets, $px, $py, $pz, $rangeSq);
        $this->drawLine($level, $x0, $y0, $z1, $x0, $y0, $z0, $targets, $px, $py, $pz, $rangeSq);

        $this->drawLine($level, $x0, $y1, $z0, $x1, $y1, $z0, $targets, $px, $py, $pz, $rangeSq);
        $this->drawLine($level, $x1, $y1, $z0, $x1, $y1, $z1, $targets, $px, $py, $pz, $rangeSq);
        $this->drawLine($level, $x1, $y1, $z1, $x0, $y1, $z1, $targets, $px, $py, $pz, $rangeSq);
        $this->drawLine($level, $x0, $y1, $z1, $x0, $y1, $z0, $targets, $px, $py, $pz, $rangeSq);

        $this->drawLine($level, $x0, $y0, $z0, $x0, $y1, $z0, $targets, $px, $py, $pz, $rangeSq);
        $this->drawLine($level, $x1, $y0, $z0, $x1, $y1, $z0, $targets, $px, $py, $pz, $rangeSq);
        $this->drawLine($level, $x1, $y0, $z1, $x1, $y1, $z1, $targets, $px, $py, $pz, $rangeSq);
        $this->drawLine($level, $x0, $y0, $z1, $x0, $y1, $z1, $targets, $px, $py, $pz, $rangeSq);
    }

    private function drawLine(Level $level, $x1, $y1, $z1, $x2, $y2, $z2, array $targets, $px, $py, $pz, $rangeSq){
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $dz = $z2 - $z1;

        $steps = (int) max(abs($dx), abs($dy), abs($dz));
        $step  = max(1, (int) ceil($steps / 48));

        if($steps === 0){
            if($this->distanceSq($x1, $y1, $z1, $px, $py, $pz) <= $rangeSq){
                $level->addParticle(new FlameParticle(new Vector3($x1, $y1, $z1)), $targets);
            }
            return;
        }

        for($i = 0; $i <= $steps; $i += $step){
            $t = $i / $steps;
            $x = $x1 + $dx * $t;
            $y = $y1 + $dy * $t;
            $z = $z1 + $dz * $t;

            if($this->distanceSq($x, $y, $z, $px, $py, $pz) > $rangeSq){
                continue;
            }

            $level->addParticle(new FlameParticle(new Vector3($x, $y, $z)), $targets);
        }
    }

    private function getSize(Vector3 $a, Vector3 $b){
        return [
            abs($a->getFloorX() - $b->getFloorX()) + 1,
            abs($a->getFloorY() - $b->getFloorY()) + 1,
            abs($a->getFloorZ() - $b->getFloorZ()) + 1
        ];
    }
}