<?php
declare(strict_types=1);
namespace WhatsUP\Services;
use InvalidArgumentException;
use PDO;
use RuntimeException;
final class ContactService {
    public function __construct(private readonly PDO $pdo, private readonly PhoneNormalizer $normalizer) {}
    public function create(int $userId,array $input): int {
        $this->assertOwner($userId);$phone=$this->normalizer->normalize((string)($input['phone']??''),$input['region']??null);
        $name=trim((string)($input['name']??''));$email=trim((string)($input['email']??''));$consent=$input['consent_status']??'unknown';
        if(mb_strlen($name)>160) throw new InvalidArgumentException('Name must be at most 160 characters.');
        if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
        if(!in_array($consent,['unknown','opted_in','unsubscribed'],true)) throw new InvalidArgumentException('Invalid consent state.');
        $exists=$this->pdo->prepare('SELECT id FROM contacts WHERE user_id=? AND phone_e164=?');$exists->execute([$userId,$phone]);if($exists->fetchColumn()) throw new InvalidArgumentException('A contact with this phone number already exists.');
        $s=$this->pdo->prepare('INSERT INTO contacts(user_id,phone_e164,name,email,consent_status,consent_source,consented_at,unsubscribed_at,notes,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
        $s->execute([$userId,$phone,$name,$email!==''?$email:null,$consent,$input['consent_source']??null,$consent==='opted_in'?gmdate('Y-m-d H:i:s'):null,$consent==='unsubscribed'?gmdate('Y-m-d H:i:s'):null,trim((string)($input['notes']??''))?:null]);
        return (int)$this->pdo->lastInsertId();
    }
    public function update(int $userId,int $contactId,array $input): bool {
        $current=$this->find($userId,$contactId);if(!$current)return false;
        $phone=$this->normalizer->normalize((string)($input['phone']??$current['phone_e164']),$input['region']??null);
        $name=trim((string)($input['name']??$current['name']));$email=trim((string)($input['email']??(string)$current['email']));
        $consent=$input['consent_status']??$current['consent_status'];if(!in_array($consent,['unknown','opted_in','unsubscribed'],true))throw new InvalidArgumentException('Invalid consent state.');
        if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid email address.');
        $dup=$this->pdo->prepare('SELECT id FROM contacts WHERE user_id=? AND phone_e164=? AND id<>?');$dup->execute([$userId,$phone,$contactId]);if($dup->fetchColumn())throw new InvalidArgumentException('A contact with this phone number already exists.');
        $s=$this->pdo->prepare('UPDATE contacts SET phone_e164=?,name=?,email=?,consent_status=?,consent_source=?,consented_at=IF(?=\'opted_in\' AND consent_status<>\'opted_in\',UTC_TIMESTAMP(),consented_at),unsubscribed_at=IF(?=\'unsubscribed\' AND consent_status<>\'unsubscribed\',UTC_TIMESTAMP(),IF(?<>\'unsubscribed\',NULL,unsubscribed_at)),notes=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?');
        $s->execute([$phone,$name,$email!==''?$email:null,$consent,$input['consent_source']??$current['consent_source'],$consent,$consent,$consent,trim((string)($input['notes']??$current['notes']??''))?:null,$contactId,$userId]);
        return $s->rowCount()>0;
    }
    public function delete(int $userId,int $contactId): bool { $s=$this->pdo->prepare('DELETE FROM contacts WHERE id=? AND user_id=?');$s->execute([$contactId,$userId]);return $s->rowCount()>0; }
    public function optOut(int $userId,int $contactId): bool { $s=$this->pdo->prepare("UPDATE contacts SET consent_status='unsubscribed',unsubscribed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?");$s->execute([$contactId,$userId]);return $s->rowCount()>0; }
    public function find(int $userId,int $contactId): ?array { $s=$this->pdo->prepare('SELECT * FROM contacts WHERE id=? AND user_id=?');$s->execute([$contactId,$userId]);$r=$s->fetch();return is_array($r)?$r:null; }
    public function search(int $userId,string $q,int $page=1,int $perPage=25,?string $consent=null): array {
        $q=trim($q);$where=['user_id=?'];$args=[$userId];
        if($q!==''){$where[]='(name LIKE ? OR phone_e164 LIKE ? OR email LIKE ?)';$like='%'.str_replace(['%','_'],['\\%','\\_'],$q).'%';array_push($args,$like,$like,$like);}
        if($consent!==null&&in_array($consent,['unknown','opted_in','unsubscribed'],true)){$where[]='consent_status=?';$args[]=$consent;}
        $count=$this->pdo->prepare('SELECT COUNT(*) FROM contacts WHERE '.implode(' AND ',$where));$count->execute($args);$total=(int)$count->fetchColumn();
        $page=max(1,$page);$perPage=max(1,min(100,$perPage));$offset=($page-1)*$perPage;
        $s=$this->pdo->prepare('SELECT * FROM contacts WHERE '.implode(' AND ',$where).' ORDER BY updated_at DESC,id DESC LIMIT '.$perPage.' OFFSET '.$offset);$s->execute($args);
        return ['rows'=>$s->fetchAll(),'total'=>$total,'page'=>$page,'per_page'=>$perPage,'pages'=>max(1,(int)ceil($total/$perPage))];
    }
    /** @return list<array<string,mixed>> */
    public function exportRows(int $userId): array { $s=$this->pdo->prepare('SELECT name,phone_e164,email,consent_status,consent_source,consented_at,unsubscribed_at,notes FROM contacts WHERE user_id=? ORDER BY name,id');$s->execute([$userId]);return $s->fetchAll(); }
    public function importCsv(int $userId,array $file,int $maxRows=5000): array {
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new InvalidArgumentException('Choose a CSV file.');
        if(($file['size']??0)>5_242_880)throw new InvalidArgumentException('CSV files must be 5 MB or smaller.');
        $tmp=$file['tmp_name']??'';if(!is_string($tmp)||!is_uploaded_file($tmp))throw new InvalidArgumentException('Invalid upload.');
        $h=fopen($tmp,'rb');if(!$h)throw new RuntimeException('Could not read CSV.');
        $header=fgetcsv($h);if(!$header)throw new InvalidArgumentException('CSV is empty.');
        $header=array_map(fn($v)=>strtolower(trim((string)$v)),$header);$map=array_flip($header);
        if(!isset($map['phone']))throw new InvalidArgumentException('CSV must include a phone column.');
        $nameIdx=$map['name']??$map['full name']??null;$consentIdx=$map['consent']??null;$emailIdx=$map['email']??null;
        $created=0;$updated=0;$errors=[];$rowNo=1;$rows=[];
        while(($row=fgetcsv($h))!==false){$rowNo++;if(count($row)===1&&trim((string)$row[0])==='')continue;if(count($rows)>=$maxRows){throw new InvalidArgumentException('CSV exceeds '.$maxRows.' data rows.');}$rows[]=$row;}
        fclose($h);
        $own=!$this->pdo->inTransaction();if($own)$this->pdo->beginTransaction();
        try{
            $batch=$this->pdo->prepare('INSERT INTO import_batches(user_id,filename,total_rows,created_at) VALUES(?,?,?,UTC_TIMESTAMP())');$batch->execute([$userId,substr((string)($file['name']??'contacts.csv'),0,255),count($rows)]);$batchId=(int)$this->pdo->lastInsertId();
            $find=$this->pdo->prepare('SELECT id FROM contacts WHERE user_id=? AND phone_e164=?');$insert=$this->pdo->prepare('INSERT INTO contacts(user_id,phone_e164,name,email,consent_status,consent_source,consented_at,unsubscribed_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');$update=$this->pdo->prepare("UPDATE contacts SET name=?,email=?,consent_status=?,consent_source=?,consented_at=IF(?='opted_in' AND consent_status<>'opted_in',UTC_TIMESTAMP(),consented_at),unsubscribed_at=IF(?='unsubscribed' AND consent_status<>'unsubscribed',UTC_TIMESTAMP(),IF(?<>'unsubscribed',NULL,unsubscribed_at)),updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?");
            foreach($rows as $i=>$row){try{$raw=(string)($row[$map['phone']]??'');$phone=$this->normalizer->normalize($raw);$name=$nameIdx!==null?trim((string)($row[$nameIdx]??'')):'';$consent=$consentIdx!==null?strtolower(trim((string)($row[$consentIdx]??'unknown'))):'unknown';if(!in_array($consent,['unknown','opted_in','unsubscribed'],true))$consent='unknown';$email=$emailIdx!==null?trim((string)($row[$emailIdx]??'')):'';if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))$email='';$find->execute([$userId,$phone]);$id=$find->fetchColumn();if($id){$update->execute([$name,$email?:null,$consent,'CSV import',$consent,$consent,$consent,$id,$userId]);$updated++;}else{$insert->execute([$userId,$phone,$name,$email?:null,$consent,'CSV import',gmdate('Y-m-d H:i:s'),$consent==='unsubscribed'?gmdate('Y-m-d H:i:s'):null]);$created++;}}catch(\Throwable $e){$errors[]=['row'=>$i+2,'error'=>'Invalid phone number'];if(count($errors)>=100)break;}}
            $this->pdo->prepare('UPDATE import_batches SET created_count=?,updated_count=?,error_count=?,errors_json=? WHERE id=? AND user_id=?')->execute([$created,$updated,count($errors),json_encode($errors,JSON_INVALID_UTF8_SUBSTITUTE),$batchId,$userId]);
            if($own)$this->pdo->commit();return ['batch_id'=>$batchId,'created'=>$created,'updated'=>$updated,'errors'=>$errors,'total'=>count($rows)];
        }catch(\Throwable $e){if($own&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function assertOwner(int $userId): void { if($userId<1)throw new InvalidArgumentException('Authenticated user required.'); }
}
