<?php
namespace Pmarket;

use pocketmine\event\EventListener;
use pocketmine\event\Listener;
use pocketmine\plugin\PluginBase;

use pocketmine\entity\Entity;
use pocketmine\level\Level;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\block\Block;
use pocketmine\Server;
use pocketmine\Player;
 use pocketmine\level\Position;
 use pocketmine\math\Vector3;

use pocketmine\scheduler\Task;

use pocketmine\event\block\BlockBreakEvent;

use pocketmine\event\player\{
  PlayerInteractEvent, PlayerJoinEvent
};

use pocketmine\network\mcpe\protocol\{
  AddItemActorPacket, RemoveActorPacket
};

use pocketmine\network\mcpe\protocol\{
  ModalFormRequestPacket, ModalFormResponsePacket
};
use pocketmine\event\server\DataPacketReceiveEvent;


use pocketmine\utils\Config;

use pocketmine\command\{
  Command, CommandSender
};

use onebone\economyapi\EconomyAPI;

class Main extends PluginBase implements Listener {

  private $msg = '§a[ §f상점 §a] §f: ';

  private $db = [];

    public function onEnable() {
        $this->data = new Config($this->getDataFolder()."ShopList.yml",Config::YAML,[
          '상점' => [],
        ]);
        $this->db = $this->data->getAll();

        $this->Maindata = new Config($this->getDataFolder()."MainShopList.yml",Config::YAML,[
          '상점' => [],
        ]);
        $this->tb = $this->Maindata->getAll();

        $this->Subdata = new Config($this->getDataFolder()."SubShopList.yml",Config::YAML,[
          '상점' => [],
        ]);
        $this->sb = $this->Subdata->getAll();

        $this->getScheduler()->scheduleRepeatingTask(new ItemTask($this), 20 * 5);

        $this-> getServer()-> getPluginManager()-> registerEvents($this, $this);
    }

    public function save(){
      $this->data->setAll($this->db);
      $this->data->save();

      $this->Maindata->setAll($this->tb);
      $this->Maindata->save();

      $this->Subdata->setAll($this->sb);
      $this->Subdata->save();
    }

    public function onBreak(BlockBreakEvent $event){
      $player = $event->getPlayer();
      $block = $event->getBlock();
      $pos = (int)$block->getX().":".(float)$block->getY().":".(int)$block->getZ().":".$block->getLevel()->getFolderName();
      $name = strtolower($player->getName());

      if(isset($this->tb['상점'][$pos])){
        $event->setCancelled();
        $player->sendMessage($this->msg.'상점을 부술수는 없습니다.');
      }
    }

    public function onDisable() {
      unset($this->tb['가격설정활동']);
      unset($this->db['가격설정']);
      unset($this->tb['활동']);
      $this->save();
    }

    public function ShopUi(Player $player, $ItemName, $price, $sell){
      $button = [
        'type' => 'custom_form',
        'title' => '< SHOP UI >',
        "content" => [
          [
            'text' => "\n아이템 이름 : {$ItemName}\n\n구매 가격 : {$price}§f\n판매 가격 : {$sell}§f\n\n< 구매 or 판매 >를 골라주세요.",
            'type' => 'dropdown',
            'options' => ['구매', '판매']
          ],
          [
            'text' => '갯수를 적어 주세요.',
            "type" => "input",
            'default' => '0',
            'text' => '주의 : 실수로 구매 or 판매 하신 물건들은 복구를 못해드립니다.'
          ]
        ]
      ];
      return json_encode($button);
    }

    public function PricingUi(Player $player, $ItemName, $price, $sell){
      $button = [
        'type' => 'custom_form',
        'title' => '< SHOP UI >',
        "content" => [
          [
            'text' => "< 안내 >\n판매 or 구매를 불가능하게 하시려면 가격설정을 -1 해주시면 됩니다.\n아이템 이름 : {$ItemName}\n\n현재 구매 가격 : {$price}§f\n가격을 설정해주세요.\n",
            'default' => "{$price}",
            "type" => "input"
          ],
          [
            'text' => "현재 판매 가격 : {$sell}§f\n가격을 설정해주세요.\n",
            'default' => "{$sell}",
            "type" => "input"
          ]
        ]
      ];
      return json_encode($button);
    }

    public function MainShopUI (DataPacketReceiveEvent $event) {
      $p = $event->getPacket ();
      $player = $event->getPlayer ();
      $name = strtolower($player->getName());

      if ($p instanceof ModalFormResponsePacket and $p->formId === 1125678 ) {
        $button = json_decode ( $p->formData, true );

        if($button[1] == null){
          $player->sendMessage($this->msg.'갯수를 정확하게 적어 주세요.');
          unset($this->tb['활동'][$name]);
          return true;
        }
        if(!is_numeric($button[1])){
          $player->sendMessage($this->msg.'갯수는 숫자로만 입력이 가능합니다.');
          unset($this->tb['활동'][$name]);
          return true;
        }

        if($button[0] === 0){
          if(is_numeric($this->tb['활동'][$name]['구매가'])){
          $Item = Item::jsonDeserialize($this->tb['활동'][$name]['아이템']);
          $Item->setCount($button[1]);
          if (!$player->getInventory()->canAddItem($Item)) {
              $player->sendMessage($this->msg."이아이템을 구매하기에는 인벤토리 공간이 부족합니다.");
              unset($this->tb['활동'][$name]);
          }
          $ItemName = $this->tb['활동'][$name]['아이템이름'];
          $price = $this->tb['활동'][$name]['구매가'];
          $a = $button[1] * $price;
          $economyAPI = EconomyAPI::getInstance();
          $economyAPI->reduceMoney($player , $a);
          $b = $economyAPI->myMoney($player);
          $player->getInventory()->addItem($Item);
          $player->sendMessage($this->msg."성공적으로 {$ItemName}(를)을 {$button[1]}개 구매 하셨습니다.");
          $player->sendMessage($this->msg."소비 금액 : {$a}원 │ 현재 금액 : {$b}");
          unset($this->tb['활동'][$name]);
        }else{
          $player->sendMessage($this->msg.'이아이템은 구매불가 아이템 입니다.');
          unset($this->tb['활동'][$name]);
        }
      }

        if($button[0] === 1){
          if(is_numeric($this->tb['활동'][$name]['판매가'])){
          $Item = Item::jsonDeserialize($this->tb['활동'][$name]['아이템']);
          $count =$Item->setCount($button[1]);
          if(!$player->getInventory()->contains($count)){
            $player->sendMessage($this->msg."판매하실 아이템이 갯수에 비해 부족합니다.");
            return true;
          }
          $ItemName = $this->tb['활동'][$name]['아이템이름'];
          $sell = $this->tb['활동'][$name]['판매가'];
          $a = $button[1] * $sell;
          $economyAPI = EconomyAPI::getInstance();
          $economyAPI->addMoney($player , $a);
          $b = $economyAPI->myMoney($player);
          $player->getInventory()->removeItem($Item);
          $player->sendMessage($this->msg."성공적으로 {$ItemName}(를)을 {$button[1]}개 판매 하셨습니다.");
          $player->sendMessage($this->msg."판매 금액 : {$a}원 │ 현재 금액 : {$b}");
          unset($this->tb['활동'][$name]);
        }else {
          $player->sendMessage($this->msg.'이아이템은 판매불가 아이템 입니다.');
          unset($this->tb['활동'][$name]);
        }
      }
      }
    }

    public function PricingShopUI (DataPacketReceiveEvent $event) {
      $p = $event->getPacket ();
      $player = $event->getPlayer ();
      $name = strtolower($player->getName());

      if ($p instanceof ModalFormResponsePacket and $p->formId === 1125679 ) {
        $button = json_decode ( $p->formData, true );

        if($button[0] == null or $button[1] == null){
          $player->sendMessage($this->msg.'가격을 적어주세요.');
          unset($this->tb['가격설정활동'][$name]);
          $this->save();
          return true;
        }
        if(!is_numeric($button[0]) or is_numeric([1])){
          $player->sendMessage($this->msg.'가격은 숫자로만 가능합니다.');
          unset($this->tb['가격설정활동'][$name]);
          $this->save();
          return true;
        }
        if($button[0] < 0 ){
          $this->sb['상점아이템'][$this->tb['상점'][$this->tb['가격설정활동'][$name]['좌표']]['아이템이름']]['가격'] = '§c구매불가';
          $this->tb['상점'][$this->tb['가격설정활동'][$name]['좌표']]['구매가'] = '§c구매불가';
        }else {
        $this->sb['상점아이템'][$this->tb['상점'][$this->tb['가격설정활동'][$name]['좌표']]['아이템이름']]['가격'] = $button[0];
        $this->tb['상점'][$this->tb['가격설정활동'][$name]['좌표']]['구매가'] = $button[0];
        }
        if($button[1] < 0){
          $this->sb['상점아이템'][$this->tb['상점'][$this->tb['가격설정활동'][$name]['좌표']]['아이템이름']]['판매가'] = '§c판매불가';
          $this->tb['상점'][$this->tb['가격설정활동'][$name]['좌표']]['판매가'] = '§c판매불가';
        }else{
        $this->sb['상점아이템'][$this->tb['상점'][$this->tb['가격설정활동'][$name]['좌표']]['아이템이름']]['판매가'] = $button[1];
        $this->tb['상점'][$this->tb['가격설정활동'][$name]['좌표']]['판매가'] = $button[1];
        }
        $this->getServer()->broadcastMessage('§a< §f상점의 시세가 변경 되었음을 알립니다.! §a>');
        $this->getServer()->broadcastMessage('아이템이름 : '.$this->tb['상점'][$this->tb['가격설정활동'][$name]['좌표']]['아이템이름']);
        $this->getServer()->broadcastMessage('구매가 : '.$this->tb['상점'][$this->tb['가격설정활동'][$name]['좌표']]['구매가']. '원 │ '.'판매가 : '.$this->tb['상점'][$this->tb['가격설정활동'][$name]['좌표']]['판매가'].'원');
        unset($this->tb['가격설정활동'][$name]);
        $this->save();
      }
    }


    public function onTouch(PlayerInteractEvent $event){
     $player = $event->getPlayer();
     $name = strtolower($player->getName());
     $block = $event->getBlock();
     $pos = (int)$block->getX().":".(float)$block->getY().":".(int)$block->getZ().":".$block->getLevel()->getFolderName();

     if(isset($this->db['상점생성'][$name])){
       if(!$block->getId() == 20){
         $player->sendMessage($this->msg.'유리블럭으로만 상점이 생성 가능합니다.');
         return true;
       }
       $item = $player->getInventory()->getItemInHand();
       $packet = new AddItemActorPacket ();
       $packet->entityRuntimeId = $this->db['케이스'][count($this->db['상점'])] = Entity::$entityCount++;
       $packet->item = $item;
       $packet->position = new Position($block->getX() + 0.5, (float) $block->getY() + 0.25, $block->getZ() + 0.5, $block->getLevel());
       $packet->motion = new Vector3 (0, 0, 0);
       $packet->metadata = [Entity::DATA_FLAGS => [Entity::DATA_TYPE_LONG, 1 << Entity::DATA_FLAG_IMMOBILE]];
       $player->dataPacket ($packet);
       $this->db['상점'][count($this->db['상점'])] = [
         '아이템' => $item->jsonSerialize(),
         '이름' => $item->getName(),
         '좌표' => $pos
       ];

       $this->tb['상점'][$pos] = [
         '번호' => count($this->db['상점']) -1,
         '아이템이름' => $item->getName(),
         '구매가' => '0',
         '판매가' => '0',
         '아이템' => $item->jsonSerialize()
       ];
       unset($this->db['상점생성'][$name]);
       $this->sb['상점아이템'][$item->getName()] = [
         '가격' => '0',
         '판매가' => '0',
         '아이템' => $item->jsonSerialize()
       ];
       $player->sendMessage($this->msg.'성공적으로 상점 생성을 하셨습니다.');
       $this->save();
     }

   if(isset($this->tb['상점'][$pos])){
     if(isset($this->db['가격설정'][$name])){
     if(isset($this->tb['가격설정활동'][$name])){
       return true;
     }
     $ItemName = $this->tb['상점'][$pos]['아이템이름'];
     $Item = $this->tb['상점'][$pos]['아이템'];
     $price = $this->tb['상점'][$pos]['구매가'];
     $sell = $this->tb['상점'][$pos]['판매가'];
     $this->tb['가격설정활동'][$name] = [
       '좌표' => $pos
     ];
     $this->save();
     $pk = new ModalFormRequestPacket ();
     $pk->formId = 1125679;
     $pk->formData = $this->PricingUi($player, $ItemName, $price, $sell);
     $player->dataPacket ($pk);
   }


    if(!isset($this->db['가격설정'][$name])){
     if(isset($this->tb['활동'][$name])){
       return true;
     }
     $ItemName = $this->tb['상점'][$pos]['아이템이름'];
     $Item = $this->tb['상점'][$pos]['아이템'];
     $price = $this->tb['상점'][$pos]['구매가'];
     $sell = $this->tb['상점'][$pos]['판매가'];
     $this->tb['활동'][$name] = [
       '아이템이름' => $ItemName,
       '구매가' => $this->tb['상점'][$pos]['구매가'],
       '판매가' => $this->tb['상점'][$pos]['판매가'],
       '아이템' => $Item
     ];
     $pk = new ModalFormRequestPacket ();
     $pk->formId = 1125678;
     $pk->formData = $this->ShopUi($player, $ItemName, $price, $sell);
     $player->dataPacket ($pk);
 }
}

   if(isset($this->db['상점제거'][$name])){
      if(isset($this->tb['상점'][$pos])){
       unset($this->db['상점'][$this->tb['상점'][$pos]['번호']]);
       unset($this->sb['상점아이템'][$this->tb['상점'][$pos]['아이템이름']]);
       unset($this->tb['상점'][$pos]);
       $this->save();
       $player->sendMessage($this->msg.'성공적으로 상점을 제거를 하셨습니다.');
     }
   }
 }


    public function onCommand(CommandSender $sender, Command $command, $lable, array $args) :bool {
      $cmd = $command->getName();
      $name = strtolower($sender->getName());

      if($cmd === '판매'){
        if(!isset($args[0])){
        $sender->sendMessage($this->msg.'/판매 전체 │ 판매 가능한 물건들은 전부 판매 합니다.');
        $sender->sendMessage($this->msg.'/판매 갯수 [갯수] │ 손에든 아이템을 [ 갯수 ]만큼 판매 합니다.');
        return true;
      }
      switch ($args[0]) {
        case '갯수':
        if(!isset($args[1])){
          $sender->sendMessage($this->msg.'/판매 전체 │ 판매 가능한 물건들은 전부 판매 합니다.');
          $sender->sendMessage($this->msg.'/판매 갯수 [갯수] │ 손에든 아이템을 [ 갯수 ]만큼 판매 합니다.');
          return true;
        }
        if(is_numeric($this->sb['상점아이템'][$sender->getInventory()->getItemInHand()->getName()]['판매가'])) {
          $Item = $sender->getInventory()->getItemInHand();
          $Item->setCount($args[1]);
          if(!is_numeric($args[1])){
            $sender->sendMessage($this->msg.'갯수는 숫자로만 입력이 가능합니다.');
            return true;
          }

          if($Item->getCount() < $args[1]){
            $sender->sendMessage($this->msg."판매하실 아이템이 갯수에 비해 부족합니다.");
            return true;
          }
          if(!isset($this->sb['상점아이템'][$sender->getInventory()->getItemInHand()->getName()])){
            $sender->sendMessage($this->msg.'해당아이템은 상점아이템으로 등록이 되어있지 않습니다.');
            return true;
          }
          $sender->getInventory()->removeItem($Item);
          $economyAPI = EconomyAPI::getInstance();
          $a = $this->sb['상점아이템'][$sender->getInventory()->getItemInHand()->getName()]['판매가'] * $args[1];
          $b = $economyAPI->myMoney($sender);
          $economyAPI->addMoney($sender , $a);
          $ItemName = $sender->getInventory()->getItemInHand()->getName();
          $sender->sendMessage($this->msg."성공적으로 {$ItemName}(를)을 {$args[1]}개 판매 하셨습니다.");
          $sender->sendMessage($this->msg."판매 금액 : {$a}원 │ 현재 금액 : {$b}");
        }else {
          $sender->sendMessage($this->msg.'이아이템은 판매불가 아이템 입니다.');
        }
        break;
        case '전체':
          if(!isset($args[0])){
            $sender->sendMessage($this->msg.'/판매 전체 │ 판매 가능한 물건들은 전부 판매 합니다.');
            $sender->sendMessage($this->msg.'/판매 갯수 [갯수] │ 손에든 아이템을 [ 갯수 ]만큼 판매 합니다.');
            return true;
          }
          $soldItems = [];
          $gotMoney = 0;
          foreach ($sender->getInventory()->getContents() as $item){
            if (isset ($this->sb ['상점아이템'][$item->getName()])){
              if (is_numeric($this->sb ['상점아이템'][$item->getName()]['판매가'])){
              $price = $this->sb ['상점아이템'][$item->getName()]['판매가'];
              $gotMoney += $price * $item->getCount();
              $sender->getInventory()->removeItem ($item);
              $soldItems[] = '아이템 이름 : '.$item->getName() . ' │ ' . $item->getCount() . '개';
            }
          }
        }
          EconomyAPI::getInstance()->addMoney ($sender, $gotMoney);
          $sender->sendMessage($this->msg.'아이템을 모두 팔아 ' . $gotMoney . '원을 획득하셨습니다!');
          $sender->sendMessage($this->msg.'판매된 아이템: ' . implode (', ', $soldItems));
        break;
          return true;
      }
      return true;
    }

      if($cmd === '상점'){
       if(!$sender->isOp()){
        $sender->sendMessage($this->msg.'권한이 없습니다.');
        return true;
       }
        if(!isset($args[0])){
          $sender->sendMessage($this->msg.'/상점 생성 │ 손에든 아이템으로 유리를 터치해주세요');
          $sender->sendMessage($this->msg.'/상점 제거 │ 제거할 상점을 터치해주세요.');
          $sender->sendMessage($this->msg.'/상점 활동중단 │ 상점의 활동을 중단합니다.');
          $sender->sendMessage($this->msg.'/상점 가격설정 │ 가격을 수정할 상점을 터치해주세요.');
          return true;
        }

        switch ($args[0]) {
          case '생성':
           if(isset($this->db['상점제거'][$name])){
             $sender->sendMessage($this->msg.'이미 당신은 생성 작업을 활동중입니다.');
             $sender->sendMessage($this->msg.'활동을 중단하시려면 < /상점 활동중단 >');
             return true;
           }
           if(isset($this->db['가격설정'][$name])){
             $sender->sendMessage($this->msg.'이미 당신은 생성 작업을 활동중입니다.');
             $sender->sendMessage($this->msg.'활동을 중단하시려면 < /상점 활동중단 >');
             return true;
           }
            $this->db['상점생성'][$name] = '활동시작';
            $this->save();
            $sender->sendMessage($this->msg.'유리를 터치해 손에든 아이템을 상점아이템으로 추가해주세요.');
            break;


            case '제거':
             if(isset($this->db['상점생성'][$name])){
               $sender->sendMessage($this->msg.'이미 당신은 생성 작업을 활동중입니다.');
               $sender->sendMessage($this->msg.'활동을 중단하시려면 < /상점 활동중단 >');
               return true;
             }
             if(isset($this->db['가격설정'][$name])){
               $sender->sendMessage($this->msg.'이미 당신은 생성 작업을 활동중입니다.');
               $sender->sendMessage($this->msg.'활동을 중단하시려면 < /상점 활동중단 >');
               return true;
             }
              $this->db['상점제거'][$name] = '활동시작';
              $this->save();
              $sender->sendMessage($this->msg.'생성된 상점을 터치해서 삭제를 해주세요.');
              $sender->sendMessage($this->msg.'활동을 중단하시려면 < /상점 활동중단 >');
              break;

              case '활동중단':
              if(isset($this->db['상점생성'][$name])){
                unset($this->db['상점생성'][$name]);
                $this->save();
                $sender->sendMessage($this->msg.'성공적으로 상점 생성활동을 중단하셨습니다.');
                return true;
              }else {
                $sender->sendMessage($this->msg.'당신은 상점에 대해 활동중이신게 없습니다.');
              }

              if(isset($this->db['가격설정'][$name])){
                unset($this->db['가격설정'][$name]);
                $this->save();
                $sender->sendMessage($this->msg.'성공적으로 가격 설정활동을 중단하셨습니다.');
                return true;
              }else {
                $sender->sendMessage($this->msg.'당신은 상점에 대해 활동중이신게 없습니다.');
              }


              if(isset($this->db['상점제거'][$name])){
                unset($this->db['상점제거'][$name]);
                $this->save();
                $sender->sendMessage($this->msg.'성공적으로 상점 제거활동을 중단하셨습니다.');
                return true;
              }else {
                $sender->sendMessage($this->msg.'당신은 상점에 대해 활동중이신게 없습니다.');
              }
              break;

              case '가격설정' :
              if(isset($this->db['상점생성'][$name])){
                $sender->sendMessage($this->msg.'이미 당신은 생성 작업을 활동중입니다.');
                $sender->sendMessage($this->msg.'활동을 중단하시려면 < /상점 활동중단 >');
                return true;
              }
              if(isset($this->db['상점제거'][$name])){
                $sender->sendMessage($this->msg.'이미 당신은 생성 작업을 활동중입니다.');
                $sender->sendMessage($this->msg.'활동을 중단하시려면 < /상점 활동중단 >');
                return true;
              }
              $this->db['가격설정'][$name] = '상점';
              $this->save();
              $sender->sendMessage($this->msg.'가격을 설정하실 상점을 터치해주세요.');
              $sender->sendMessage($this->msg.'가격설정이 끝난후에는 < /상점 활동중단 >');
              break;

          default:
          $sender->sendMessage($this->msg.'/상점 생성 │ 손에든 아이템으로 유리를 터치해주세요');
          $sender->sendMessage($this->msg.'/상점 제거 │ 제거할 상점을 터치해주세요.');
          $sender->sendMessage($this->msg.'/상점 활동중단 │ 상점의 활동을 중단합니다.');
          $sender->sendMessage($this->msg.'/상점 가격설정 │ 가격을 수정할 상점을 터치해주세요.');
            break;
            return true;
        }
        return true;
        }
      }

      public function onJoin(PlayerJoinEvent $ev){
        $player = $ev->getPlayer();
        if(isset($this->sub['케이스'])){
        if(!isset($this->sub['케이스소환자'])){
          $this->db['케이스소환자'] = '온';
          $this->ItemCase($player);
            }
          }
      }


      public function removeItemCase(Player $player){
        if(isset($this->db['케이스'])){
        foreach($this->db['케이스'] as $key => $id){
        $packet = new RemoveActorPacket ();
        $packet->entityUniqueId = $id;
        $player->sendDataPacket($packet);
        }
        unset($this->db['케이스']);
   }
 }

      public function ItemCase(Player $player){
              for($i = 0; $i < count($this->db['상점']); $i++){
        $xyz = $pos=explode(':', $item = $this->db['상점'][$i]['좌표']);
        $item = Item::jsonDeserialize($this->db['상점'][$i]['아이템']);
        $item->setCount(1);
        $packet = new AddItemActorPacket ();
        $packet->entityRuntimeId = $this->db['케이스'][$i] = Entity::$entityCount++;
        $this->save();

        $packet->item = $item;
        $packet->position = new Position($xyz[0] + 0.5, (float) $xyz[1] + 0.25, $xyz[2] + 0.5, $player->getLevel());
        $packet->motion = new Vector3 (0, 0, 0);
        $packet->metadata = [Entity::DATA_FLAGS => [Entity::DATA_TYPE_LONG, 1 << Entity::DATA_FLAG_IMMOBILE]];
        $player->dataPacket ($packet);
 }
}
}

    //테스크
    class ItemTask extends Task{
       private $owner;

       public function __construct(Main $owner){
          $this->owner = $owner;
       }
       public function onRun($currentTick){
            foreach($this->owner->getServer()->getOnlinePlayers() as $player){
              $this->owner->removeItemCase($player);
              $this->owner->ItemCase($player);
              if(isset($this->owner->db['케이스소환자'])){
                unset($this->owner->db['케이스소환자']);
              }
        }
       }
    }
