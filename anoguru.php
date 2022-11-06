<?php
#jsonデータ取得
if(!$json_string = file_get_contents('php://input'))exit;
$json_object = json_decode($json_string);
error_log($json_string);

#データ抽出
$replyToken = $json_object->{'events'}[0]->{'replyToken'};
$user = $json_object->{'events'}[0]->{'source'}->{'userId'};
$type = $json_object->{'events'}[0]->{'type'};
$message_json = $json_object->{'events'}[0]->{'message'};
$text = $message_json->{'text'};
$id = $message_json->{'id'};
$message_type = $message_json->{'type'};
$package = $message_json->{'packageId'};
$sticker = $message_json->{'stickerId'};

#ドメイン
$domain = 'https://kudkun.com/bot/';


#アクセストークン格納
$accessToken = array();
$i = 0;
$tokens = fopen('./tokens.txt','r');
if($tokens){
	if(flock($tokens,LOCK_EX)){
		while(!feof($tokens)){
			$line = fgets($tokens);
			if($line != ''){
				$accessToken[$i] = str_replace(PHP_EOL,'',$line);
				$i++;
			}
		}
	}
	flock($tokens,LOCK_UN);
}
fclose($tokens);


if($type == 'follow' || $type == 'unfollow'){
	$flag = 0;
	if($type == 'follow'){#追加（ユーザIDが無ければファイル最終行に追加）
		$users_id = fopen('./users.txt','a+');
		if($users_id){
			if(flock($users_id,LOCK_EX)){
				while(!feof($users_id)){
					$line = fgets($users_id);
					if(strpos($line,$user) !== false)$flag = 1;
				}
				if($flag === 0){
					fputs($users_id,$user."\n");
					#ユーザ追加リプライ（本人へ）
					$i = 0;
					while($accessToken[$i] != ''){
						reply('ようこそ！',$accessToken[$i],$replyToken);
						$i++;
					}
				}
			}
			flock($users_id,LOCK_UN);
		}
		fclose($users_id);
	}elseif($type == 'unfollow'){#ブロック（該当ユーザID以外を配列に格納し、空ファイルにして上書きする）
		$file = './users.txt';
		if(filesize($file) >= 10){
			$users_id = fopen($file,'a+');
			if($users_id){
				if(flock($users_id,LOCK_EX)){
					$i = 0;
					while(!feof($users_id)){
						$line = fgets($users_id);
						if($line != ''){
							if(strpos($line,$user) === false){
								$lineAry[$i] = str_replace(PHP_EOL,'',$line);
								$i++;
							}
						}
					}
					ftruncate($users_id,0);#ファイルサイズを０にする
					foreach($lineAry as $a)fputs($users_id,$a."\n");
				}
				flock($users_id,LOCK_UN);
			}
			fclose($users_id);
		}
	}
	exit;
}


switch($message_type){#メッセージ形式毎のコンテンツ作成
	case 'text':
		$content = [
			'type' => 'text',
			'text' => $text
		];
		break;
	case 'image':
	case 'video':
		$i = 0;
		while($accessToken[$i] != ''){
			$url = "https://api.line.me/v2/bot/message/$id/content";
			$ch = curl_init();
			curl_setopt($ch,CURLOPT_URL,$url);
			curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
			curl_setopt($ch,CURLOPT_HTTPHEADER,array(
				'Content-Type: application/json; charser=UTF-8',
				'Authorization: Bearer ' . $accessToken[$i]
			));
			$result = curl_exec($ch);
			curl_close($ch);
			if(strlen($result) >= 100)break;
			$i++;
		}

		if($message_type == 'image')$file = './image.png';
		elseif($message_type == 'video')$file = './video.mp4';
		$fp = fopen($file,'wb');
		if($fp){
			if(flock($fp,LOCK_EX))fwrite($fp,$result);
			flock($fp,LOCK_UN);
		}
		fclose($fp);

		$mediaUrl = $domain.$file;
		$content = [
			'type' => $message_type,
			'originalContentUrl' => $mediaUrl,
			'previewImageUrl' => $mediaUrl
		];
		break;
}


if($content != ''){#全てのbotでコンテンツ送信
	$i = 0;
	while($accessToken[$i] != ''){
		$url = 'https://api.line.me/v2/bot/message/push';
		$ch = curl_init();
		curl_setopt($ch,CURLOPT_URL,$url);
		curl_setopt($ch,CURLOPT_POST,true);
		curl_setopt($ch,CURLOPT_CUSTOMREQUEST,'POST');
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
		curl_setopt($ch,CURLOPT_HTTPHEADER,array(
			'Content-Type: application/json; charser=UTF-8',
			'Authorization: Bearer ' . $accessToken[$i]
		));

		$users_id = fopen('./users.txt','r');
		if($users_id){
			if(flock($users_id,LOCK_EX)){
				while(!feof($users_id)){
					$line = fgets($users_id);
					$line = str_replace(PHP_EOL,'',$line);
					if(strpos($line,$user) === false){
						$post_data = [
							'to' => $line,
							'messages' => [$content]
						];
						curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($post_data));
						curl_exec($ch);
					}
				}
				curl_close($ch);
			}
			flock($users_id,LOCK_UN);
		}
		fclose($users_id);
		$i++;
	}
	exit;
}


function reply($text,$accessToken,$replyToken){#ユーザ追加リプライ（本人へ）
	$content = [
		'type' => 'text',
		'text' => $text
	];
	$post_data = [
		'replyToken' => $replyToken,
		'messages' => [$content]
	];

	$url = 'https://api.line.me/v2/bot/message/reply';
	$ch = curl_init();
	curl_setopt($ch,CURLOPT_URL,$url);
	curl_setopt($ch,CURLOPT_POST,true);
	curl_setopt($ch,CURLOPT_CUSTOMREQUEST,'POST');
	curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
	curl_setopt($ch,CURLOPT_HTTPHEADER,array(
		'Content-Type: application/json; charser=UTF-8',
		'Authorization: Bearer ' . $accessToken
	));
	curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($post_data));
	curl_exec($ch);
	curl_close($ch);
}
