<?php
/* JobSchedulerより実行時間履歴を取得するためのAPI
 * pecl_httpモジュールが必要
 */

class JobHistoryClass {
    private $id;
    private $start_time;
    private $end_time;
    
    public function set_id($id) {
        $this->id = $id;
    }
    public function set_start_time($start_time) {
        $this->start_time = $start_time;
    }
    public function set_end_time($end_time) {
        $this->end_time = $end_time;
    }
    public function get_id() {
        return $this->id;
    }
    public function get_start_time() {
        return $this->start_time;
    }
    public function get_end_time() {
        return $this->end_time;
    }
}

class SOS_JobSchedulerCommand {
    private $JobSchedulerBaseURL;
    private $JobSchedulerPort;
    private $answer;
    private $http_response_status;
    private $answer_error;
    
    public function __construct($URL, $Port) {
        $this->JobSchedulerBaseURL = $URL;
        $this->JobSchedulerPort = $Port;
    }
    
    public function command($cmd) {
        $request = new http\Client\Request("GET",
            $this->JobSchedulerBaseURL . ":" . $this->JobSchedulerPort . "/" . $cmd,
            ["User-Agent"=>"My Client/0.1"]
        );
        $request->setOptions(["timeout"=>60]);
        
        $client = new http\Client();
        $client->enqueue($request)->send();
        
        // pop the last retrieved response
        $response = $client->getResponse();
        
        $this->answer = simplexml_load_string($response->getBody());
        $this->http_response_status = ["code"=>$response->getResponseCode(), "info"=>$response->getInfo()];
        $this->answer_error = $this->answer->answer[0]->ERROR[0];
    }
    
    public function getAnswer() {
        return $this->answer;
    }
    
    public function getAnswerError() {
        if ($this->answer_error == NULL && $this->http_response_status["code"] != "200") {
            $this->answer_error = ["code"=>$this->http_response_status["code"],
                "text"=>$this->http_response_status["info"]];
        }
        return $this->answer_error;
    }
}
?>
