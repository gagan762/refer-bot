<?php
ini_set('display_errors','0');
ini_set('log_errors','1');
error_reporting(E_ALL);

/* ================= CONFIG ================= */
$BOT_TOKEN = getenv("BOT_TOKEN");
$ADMIN_ID  = intval(getenv("ADMIN_ID"));
$FORCE_JOIN_1 = getenv("FORCE_JOIN_1");
$FORCE_JOIN_2 = getenv("FORCE_JOIN_2");
$WITHDRAW_COST = 3;

$API = "https://api.telegram.org/bot{$BOT_TOKEN}/";

/* ================= DATABASE ================= */
$dbPath = __DIR__ . "/bot.sqlite"; // use /var/data/bot.sqlite if you added Render disk
$db = new PDO("sqlite:$dbPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec("CREATE TABLE IF NOT EXISTS users(
  user_id INTEGER PRIMARY KEY,
  referred_by INTEGER,
  points INTEGER DEFAULT 0
)");

$db->exec("CREATE TABLE IF NOT EXISTS referrals(
  new_user_id INTEGER PRIMARY KEY,
  referrer_id INTEGER
)");

$db->exec("CREATE TABLE IF NOT EXISTS coupons(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT UNIQUE
)");

$db->exec("CREATE TABLE IF NOT EXISTS redemptions(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER,
  coupon_code TEXT,
  points_left INTEGER,
  redeemed_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

/* ================= TELEGRAM HELPERS ================= */
function tg($method,$data=[]){
  global $API;
  $ch=curl_init($API.$method);
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>$data,
    CURLOPT_CONNECTTIMEOUT=>5,
    CURLOPT_TIMEOUT=>10
  ]);
  $r=curl_exec($ch);
  curl_close($ch);
  return json_decode($r,true);
}

function sendMessage($chat,$text,$kb=null,$mode=null){
  $d=["chat_id"=>$chat,"text"=>$text,"disable_web_page_preview"=>true];
  if($kb)$d["reply_markup"]=json_encode($kb);
  if($mode)$d["parse_mode"]=$mode;
  tg("sendMessage",$d);
}

function editMessage($chat,$msg,$text,$kb=null,$mode=null){
  $d=["chat_id"=>$chat,"message_id"=>$msg,"text"=>$text];
  if($kb)$d["reply_markup"]=json_encode($kb);
  if($mode)$d["parse_mode"]=$mode;
  tg("editMessageText",$d);
}

function answerCb($id){
  tg("answerCallbackQuery",["callback_query_id"=>$id]);
}

/* ================= FORCE JOIN CHECK ================= */
function isJoined($chat,$uid){
  if(!$chat) return true;
  $r = tg("getChatMember",["chat_id"=>$chat,"user_id"=>$uid]);
  if(!$r || !$r["ok"]) return false;
  return in_array($r["result"]["status"],["member","administrator","creator"]);
}

/* ================= DB HELPERS ================= */
function user($db,$id){
  $s=$db->prepare("SELECT * FROM users WHERE user_id=?");
  $s->execute([$id]);
  return $s->fetch(PDO::FETCH_ASSOC);
}
function ensureUser($db,$id,$ref=null){
  $db->prepare("INSERT OR IGNORE INTO users(user_id,referred_by) VALUES(?,?)")
     ->execute([$id,$ref]);
}
function addPoint($db,$id){
  $db->prepare("UPDATE users SET points=points+1 WHERE user_id=?")->execute([$id]);
}
function deduct3($db,$id){
  $db->prepare("UPDATE users SET points=points-3 WHERE user_id=?")->execute([$id]);
}
function stock($db){
  return (int)$db->query("SELECT COUNT(*) FROM coupons")->fetchColumn();
}
function takeCoupon($db){
  try{
    $db->beginTransaction();
    $r=$db->query("SELECT id,code FROM coupons LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if(!$r){$db->rollBack();return null;}
    $db->prepare("DELETE FROM coupons WHERE id=?")->execute([$r["id"]]);
    $db->commit();
    return $r["code"];
  }catch(Exception $e){
    $db->rollBack();
    return null;
  }
}

/* ================= KEYBOARDS ================= */
function mainMenu($uid){
  global $ADMIN_ID;
  $kb=[
    [["text"=>"📊 Stats","callback_data"=>"stats"]],
    [["text"=>"🎁 Withdraw","callback_data"=>"withdraw"]],
    [["text"=>"🔗 My Referral Link","callback_data"=>"link"]],
  ];
  if($uid==$ADMIN_ID){
    $kb[]=[["text"=>"🛠 Admin Panel","callback_data"=>"admin"]];
  }
  return ["inline_keyboard"=>$kb];
}

function adminMenu(){
  return ["inline_keyboard"=>[
    [["text"=>"➕ Add Coupons","callback_data"=>"add"]],
    [["text"=>"📦 Coupon Stock","callback_data"=>"stock"]],
    [["text"=>"👥 Users & Points","callback_data"=>"users"]],
    [["text"=>"📜 Redemption Log","callback_data"=>"logs"]],
  ]];
}

function joinKeyboard(){
  global $FORCE_JOIN_1,$FORCE_JOIN_2;
  return ["inline_keyboard"=>[
    [["text"=>"📌 Join Group 1","url"=>"https://t.me/".ltrim($FORCE_JOIN_1,"@")]],
    [["text"=>"📌 Join Group 2","url"=>"https://t.me/".ltrim($FORCE_JOIN_2,"@")]],
    [["text"=>"✅ Verify","callback_data"=>"verify"]],
  ]];
}

/* ================= UPDATE ================= */
$update=json_decode(file_get_contents("php://input"),true);
if(!$update){echo"OK";exit;}

/* ================= MESSAGE ================= */
if(isset($update["message"])){
  $m=$update["message"];
  $uid=$m["from"]["id"];
  $chat=$m["chat"]["id"];
  $text=trim($m["text"]??"");

  /* /start */
  if(preg_match('/^\/start(?:\s+(\d+))?/',$text,$a)){
    $ref=$a[1]??null;
    if($ref==$uid)$ref=null;
    ensureUser($db,$uid,$ref);

    if($ref){
      $c=$db->prepare("SELECT 1 FROM referrals WHERE new_user_id=?");
      $c->execute([$uid]);
      if(!$c->fetch()){
        $db->prepare("INSERT INTO referrals VALUES(?,?)")->execute([$uid,$ref]);
        addPoint($db,$ref);
      }
    }

    if(!isJoined($FORCE_JOIN_1,$uid) || !isJoined($FORCE_JOIN_2,$uid)){
      sendMessage($chat,"🔒 Join both groups and click Verify.",joinKeyboard());
      echo"OK";exit;
    }

    sendMessage($chat,"🎉 WELCOME TO VIP REFER BOT",mainMenu($uid));
    echo"OK";exit;
  }

  /* Admin add coupons (one per line) */
  if($uid==$ADMIN_ID && strpos($text,"\n")!==false){
    $lines=preg_split("/\r?\n/",$text);
    $added=0;
    foreach($lines as $c){
      $c=trim($c);
      if(!$c)continue;
      try{$db->prepare("INSERT INTO coupons(code) VALUES(?)")->execute([$c]);$added++;}catch(Exception $e){}
    }
    sendMessage($chat,"✅ Added $added coupons",adminMenu());
    echo"OK";exit;
  }
}

/* ================= CALLBACK ================= */
if(isset($update["callback_query"])){
  $c=$update["callback_query"];
  $uid=$c["from"]["id"];
  $chat=$c["message"]["chat"]["id"];
  $msg=$c["message"]["message_id"];
  $d=$c["data"];
  $u=user($db,$uid);
  answerCb($c["id"]);

  if($d=="verify"){
    if(isJoined($FORCE_JOIN_1,$uid)&&isJoined($FORCE_JOIN_2,$uid)){
      editMessage($chat,$msg,"🎉 WELCOME TO VIP REFER BOT",mainMenu($uid));
    }else{
      editMessage($chat,$msg,"❌ Join both groups first.",joinKeyboard());
    }
  }

  if($d=="stats"){
    editMessage($chat,$msg,"⭐ Your Points: ".$u["points"],mainMenu($uid));
  }

  if($d=="link"){
    $me=tg("getMe");
    $link="https://t.me/".$me["result"]["username"]."?start=".$uid;
    editMessage($chat,$msg,"🔗 Your Referral Link:\n<code>$link</code>",mainMenu($uid),"HTML");
  }

  if($d=="withdraw"){
    if($u["points"]<3){
      editMessage($chat,$msg,"❌ Need 3 points.",mainMenu($uid));exit;
    }
    if(stock($db)<=0){
      editMessage($chat,$msg,"⚠️ No coupons available.",mainMenu($uid));exit;
    }

    deduct3($db,$uid);
    $code=takeCoupon($db);
    if(!$code){
      addPoint($db,$uid);addPoint($db,$uid);addPoint($db,$uid);
      editMessage($chat,$msg,"⚠️ Error. Try later.",mainMenu($uid));exit;
    }

    $left=user($db,$uid)["points"];
    $db->prepare("INSERT INTO redemptions(user_id,coupon_code,points_left)
                  VALUES(?,?,?)")->execute([$uid,$code,$left]);

    /* ADMIN NOTIFICATION */
    sendMessage($ADMIN_ID,
      "🔔 Coupon Redeemed\n\n👤 User: $uid\n🎁 Code: $code\n⭐ Points Left: $left"
    );

    editMessage($chat,$msg,"🎉 Your Coupon:\n<code>$code</code>",mainMenu($uid),"HTML");
  }

  if($d=="admin" && $uid==$ADMIN_ID){
    editMessage($chat,$msg,"🛠 Admin Panel",adminMenu());
  }

  if($d=="stock" && $uid==$ADMIN_ID){
    editMessage($chat,$msg,"📦 Stock: ".stock($db),adminMenu());
  }

  if($d=="users" && $uid==$ADMIN_ID){
    $t="👥 Users & Points\n\n";
    foreach($db->query("SELECT * FROM users ORDER BY points DESC") as $r){
      $t.="{$r['user_id']} → {$r['points']}\n";
    }
    editMessage($chat,$msg,$t,adminMenu());
  }

  if($d=="logs" && $uid==$ADMIN_ID){
    $t="📜 Redemption Log\n\n";
    foreach($db->query("SELECT * FROM redemptions ORDER BY id DESC LIMIT 20") as $r){
      $t.="👤 {$r['user_id']}\n🎁 {$r['coupon_code']}\n⭐ Left: {$r['points_left']}\n🕒 {$r['redeemed_at']}\n\n";
    }
    editMessage($chat,$msg,$t,adminMenu());
  }
}

echo"OK";
