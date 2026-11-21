<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use WhatsUP\Core\Config;use WhatsUP\Core\Database;use WhatsUP\Services\WebhookClient;use WhatsUP\Worker\DeliveryWorker;
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$endpoint=(string)Config::required('WEBHOOK_URL');$secret=(string)Config::required('WEBHOOK_SECRET');
if(strtolower((string)parse_url($endpoint,PHP_URL_SCHEME))!=='https' && !(Config::bool('WEBHOOK_ALLOW_HTTP_LOCAL',false) && in_array(strtolower((string)parse_url($endpoint,PHP_URL_HOST)),['127.0.0.1','localhost'],true))){fwrite(STDERR,"Webhook must use HTTPS (HTTP localhost is allowed only with WEBHOOK_ALLOW_HTTP_LOCAL=true).\n");exit(2);}
$maxAttempts=max(1,(int)Config::get('WORKER_MAX_ATTEMPTS','6'));$poll=max(1,(int)Config::get('WORKER_POLL_SECONDS','2'));$once=in_array('--once',$argv,true);$running=true;
if(function_exists('pcntl_signal')){pcntl_async_signals(true);pcntl_signal(SIGTERM,function()use(&$running){$running=false;});pcntl_signal(SIGINT,function()use(&$running){$running=false;});}
$worker=new DeliveryWorker(Database::connection(),new WebhookClient($endpoint,$secret,15,Config::bool('WEBHOOK_ALLOW_HTTP_LOCAL',false)),$maxAttempts);
fwrite(STDOUT,"WhatsUP delivery worker started (".($once?'single run':'polling').").\n");
do{$worked=$worker->runOne();$worked=$worker->recoverStaleClaims()||$worked;if($once)break;if(!$worked)sleep($poll);}while($running);
fwrite(STDOUT,"WhatsUP delivery worker stopped.\n");
