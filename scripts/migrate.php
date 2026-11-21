<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use WhatsUP\Core\Database;
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$db=Database::connection();
$db->exec('CREATE TABLE IF NOT EXISTS schema_migrations(version VARCHAR(100) PRIMARY KEY,applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$files=glob(dirname(__DIR__).'/database/migrations/*.sql')?:[];sort($files);
foreach($files as $file){
 $version=basename($file);$check=$db->prepare('SELECT 1 FROM schema_migrations WHERE version=?');$check->execute([$version]);
 if($check->fetchColumn()){echo "skip {$version}\n";continue;}
 $sql=file_get_contents($file);$statements=preg_split('/;\s*(?:\r?\n|$)/',$sql)?:[];
 try{
  foreach($statements as $statement){$statement=trim($statement);if($statement!==''){$db->exec($statement);}}
  $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(?,UTC_TIMESTAMP())')->execute([$version]);
  echo "applied {$version}\n";
 }catch(Throwable $e){fwrite(STDERR,"Migration {$version} failed: {$e->getMessage()}\n");exit(1);}
}
