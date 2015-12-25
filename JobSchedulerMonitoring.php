#!/bin/env php
<?php
/*
 * JobSchedulerからjob実行時間に関する情報を取得して
 * zabbix_sender経由でzabbixに登録するPHPスクリプト
 */

require(dirname(__FILE__) . '/phplib/PhpZabbixApi_Library/ZabbixApi.class.php');            //zabbix api

include ('lib/scheduler/JobSchedulerAPI.php');        // JobSchedulerAPIをphpで使用する為のライブラリー読み込み

// 各種初期設定の実施
$SCHEDULER_URL  = "http://jobsch_host_name";
$SCHEDULER_PORT = "5555";
$ZABBIX_SERVER  = "zabbix_host_name";
$ZABBIX_APIURL  = "http://zabbix_host_name/zabbix/api_jsonrpc.php";
$ZABBIX_SENDER  = "/usr/local/bin/zabbix_sender";
$ZABBIX_USER    = "Admin";
$ZABBIX_PASS    = "zabbix";
$LOG_DIR        = "/var/log/zabbix/";
$LOG_FILE       = "jobscheudler_to_zabbix.log";
$KEY_ID         = ".id";
$KEY_ELAPSE     = ".elapse";
$NEXT_JOBS      = "100";

// zabbixへの接続
try {
    $api = new ZabbixApi\ZabbixApi($ZABBIX_APIURL, $ZABBIX_USER, $ZABBIX_PASS);
}catch(Exception $e){
    echo $e->getMessage();
}

// JobSchedulerに定義されている全Job情報を取得
$jsObj = new SOS_JobSchedulerCommand($SCHEDULER_URL, $SCHEDULER_PORT);

$showCmd = '<show_state />';    // 全Jobの情報を取得するxmlコマンド

$jsObj->command($showCmd);    // JobSchedulerに対してコマンドを発行

if ($jsObj->getAnswerError()) {
    $msg = $jsObj->getAnswerError()["text"]; 
    print('code was 12 : ' . $msg);
}
$xml = $jsObj->getAnswer();

$jobList = array();

foreach($xml->answer[0]->state[0]->jobs[0] as $jobs){    // JobSchedulerに定義されているJob情報を$jobListに配列として格納
    array_push($jobList, $jobs->attributes()->path);
}

$msg = 'sending start' . "\n";
$msg = $msg . '------------------------------------------------------------' . "\n";
// 全job情報の履歴を取得してzabbix_sender経由でzabbixへ登録する処理の開始
// zabbixへまだ登録していないJob実行履歴のみを取得してzabbixへ登録
for($i=0;$i<count($jobList);$i++){

    $job = $jobList[$i];
    // ZabbixのKeyとして利用できない文字を'.'へReplaceする。
    $tobeRepl = array('/', '#');
    $keyHeader = str_replace($tobeRepl, '.', $job);

    // Zabbixにelapseに対応するKeyが登録されていないJobはスキップする。
    $items = $api->itemGet(array(
        'output' => 'extend',
        'search' => array('key_' => $keyHeader . $KEY_ELAPSE),
        'inherited' => 'true',
        'sortfield' => 'itemid'
    ));
    if ($items == NULL) {
        $msg = $msg . "Job : " . $job . " is skipped.\n";
        continue;
    }

    // 該当jobの前回取得した最後のidを取得する
    $items = $api->itemGet(array(
        'output' => 'extend',
        'search' => array('key_' => $keyHeader . $KEY_ID),
        'inherited' => 'true',
        'sortfield' => 'itemid'
    ));
    // Zabbixにidに対応するKeyが登録されていないJobはスキップする。
    if ($items == NULL) {
        $msg = $msg . "Job : " . $job . " is skipped.\n";
        continue;
    }
    
    $lastId = $items[0]->lastvalue;
    
    // zabbixへの登録が初回の場合はlastId（前回記録したJobSchedulerでJob毎に割り振られるid）がzabbixから取得出来ない為、
    // JobSchedulerの初期値である「1」を設定する
    if($lastId < 2) $lastId = 1;

    // Jobの実行履歴情報をJobSchedulerより取得する
    $histCmd = '<show_history job="' . $job . '" id="' . $lastId . '" next="' . $NEXT_JOBS . '" />';
    $jsObj->command($histCmd);

    $xml = $jsObj->getAnswer();
    $jobHist = array();

    foreach($xml->answer[0]->history[0] as $hist){
        if ($hist->attributes()->end_time == NULL) {
          // Jobがまだ終了していないときは、end_timeが取得できないため、処理しない。
          $msg = $msg . "Job : /" . $hist->attributes()->job_name . " is running.\n";
          continue;
        }
        $obj = new JobHistoryClass();

        $obj->set_id($hist->attributes()->id);
        $obj->set_start_time($hist->attributes()->start_time);
        $obj->set_end_time($hist->attributes()->end_time);

        array_push($jobHist, $obj);
    }
 
    // zabbix_senderでJob実行履歴の情報を登録する
    for($j=0;$j<count($jobHist);$j++){
        // 該当itemが所属するhost名を取得する
        $host = $api->hostGet(array(
            'output' => 'extend',
            'filter' => array('hostid' => $items[0]->hostid)
        ));

        // elapseの計算
        $start_time = strtotime($jobHist[$j]->get_start_time());
        $end_time = strtotime($jobHist[$j]->get_end_time());
        $elapse = $end_time - $start_time; 

        // ホストが一つに特定出来た場合はzabbixへ履歴情報を登録する
        if(count($host) == 1){
            // 以下、zabbix_senderでのjob実行履歴の登録
            $msg = $msg . "[" . date( "Y/m/d (D) H:i:s", time() ) . "] " .      'echo -n -e "' . $host[0]->host . ' ' . $keyHeader . $KEY_ID . ' ' . $start_time . ' ' . $jobHist[$j]->get_id() . '" | ' . $ZABBIX_SENDER . ' -z ' . $ZABBIX_SERVER . ' -T -i -'  . "\n"; 
            $msg = $msg . "[" . date( "Y/m/d (D) H:i:s", time() ) . "] " . exec('echo -n -e "' . $host[0]->host . ' ' . $keyHeader . $KEY_ID . ' ' . $start_time . ' ' . $jobHist[$j]->get_id() . '" | ' . $ZABBIX_SENDER . ' -z ' . $ZABBIX_SERVER . ' -T -i -') . "\n"; 
            $msg = $msg . "[" . date( "Y/m/d (D) H:i:s", time() ) . "] " .      'echo -n -e "' . $host[0]->host . ' ' . $keyHeader . $KEY_ELAPSE . ' ' . $start_time . ' ' . $elapse . '" | ' . $ZABBIX_SENDER . ' -z ' . $ZABBIX_SERVER . ' -T -i -'  . "\n"; 
            $msg = $msg . "[" . date( "Y/m/d (D) H:i:s", time() ) . "] " . exec('echo -n -e "' . $host[0]->host . ' ' . $keyHeader . $KEY_ELAPSE . ' ' . $start_time . ' ' . $elapse . '" | ' . $ZABBIX_SENDER . ' -z ' . $ZABBIX_SERVER . ' -T -i -') . "\n"; 
        }
    }
}
error_log($msg, 3, $LOG_DIR . $LOG_FILE);
?>
