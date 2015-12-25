# JobMonitoring

ZabbixによりJobSchedulerで実行されるジョブを監視するためのスクリプト

http://tech-sketch.jp/2013/04/jobscheduler-job-zabbix2.html

上記URLを参考にして作成しました。

PHPでZabbix APIを操作する為に [confirm IT solutions GmbH](https://github.com/confirm/PhpZabbixApi#contact) で作成している外部ライブラリー
とここに登録されているスクリプトをZabbixのexternalscriptsフォルダに配置し、
run_js_monitor.shをExternal checkタイプのItemを登録すれば監視する準備が完了。

監視を開始するためには、Jobの名称に対応するItemをZabbix trapperタイプで登録する。

