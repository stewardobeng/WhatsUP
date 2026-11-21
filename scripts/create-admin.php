<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use WhatsUP\Core\Database;
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$name=$argv[1]??trim(readline('Administrator name: '));$email=strtolower(trim($argv[2]??readline('Administrator email: ')));$password=$argv[3]??readline('Password (12+ characters): ');
if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<12||strlen($name)<2){fwrite(STDERR,"Valid email, name and password of at least 12 characters required.\n");exit(2);}
$db=Database::connection();$s=$db->prepare("INSERT INTO users(name,email,password_hash,role,status,daily_limit,timezone,created_at,updated_at) VALUES(?,?,?,'admin','active',1000,'UTC',UTC_TIMESTAMP(),UTC_TIMESTAMP())");try{$s->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);echo "Administrator created (user #".$db->lastInsertId().").\n";}catch(PDOException $e){fwrite(STDERR,"Could not create administrator; email may already be registered.\n");exit(1);}
